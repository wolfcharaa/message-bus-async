# PostgreSQL Queue Reference

The core package owns the durable queue schema and the application-facing
producer, status, and cancellation API. It uses `amphp/postgres` and contains no
PDO adapter. Worker execution belongs to `romanfedorskij/message-bus-async-worker`.

## Runtime Requirements

The process that constructs `PostgresQueueGateway` needs `amphp/postgres` 2.x
and either `ext-pgsql` or `ext-pq`. The application owns the
`PostgresConnectionPool`, applies schema changes through its migration system,
and closes the pool during application shutdown.

```php
<?php

declare(strict_types=1);

use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use Fedor\MessageBus\Queue\Postgres\PostgresQueueGateway;

$config = PostgresConfig::fromString((string) getenv('POSTGRES_CONFIG'));
$pool = new PostgresConnectionPool($config);
$queue = new PostgresQueueGateway($pool);

// Register $queue as QueueProviderInterface,
// QueueStatusRepositoryInterface, and QueueJobControlInterface.
// The application lifecycle closes $pool.
```

`PostgresQueueSchemaGenerator` generates the current DDL. Calling the generator
does not change the database; the host migration must apply its result before
the gateway starts. `QueueTableDefinition` changes the unqualified table name
when the application cannot use the default `message_bus__queue_jobs`.

## Delivery Guarantee

The queue contract is at-least-once. Claim persists `running` before handler
execution, successful handling requires a separate acknowledgement, and a stale
lease can be returned to retry by the worker package. A process crash may
therefore execute a message again. State-changing handlers must use an
idempotency key/policy or have a demonstrably idempotent effect.

The `0.1.0` producer does not yet provide deterministic enqueue idempotency. An
application must not blindly retry an enqueue after an unknown connection
outcome, because the original insert might have committed. Transactional outbox
and exactly-once external side effects are intentionally outside `0.1.0`.

## State Machine

The schema and `QueueJobState` preserve the v6 state vocabulary.

| Current state | Allowed next state | Operation owner |
| --- | --- | --- |
| `pending` | `running` | Worker claim |
| `pending` | `cancelled` | Producer-side cancel API |
| `running` | `succeeded` | Worker ack |
| `running` | `pending` | Worker retry or stale recovery |
| `running` | `failed` | Worker reject or exhausted retry policy |
| `running` | `cancelled` | Worker observes a cancellation request |
| `running` | `interrupted` | Worker operational shutdown/control |

`succeeded`, `failed`, and `cancelled` are terminal. `interrupted` is a public,
recoverable technical state, but its explicit recovery API remains a worker
package concern and is not defined by the core gateway.

## Producer And Batch Semantics

`enqueue()` inserts one `pending` job and returns its queue ID, creation time,
status, and table metadata. The record contains:

- stable message and binding identities;
- message/correlation IDs and flow/transport/queue routing;
- priority and availability time;
- retry policy key plus immutable retry snapshot;
- versioned serialized envelope;
- lease, heartbeat, cancellation, error, and lifecycle columns required by the
  worker package.

`enqueueMany()` uses one PostgreSQL transaction. It either inserts every message
in input order or rolls the transaction back. Cancellation is checked before
opening the transaction, before every write, and before commit.

AMPHP PostgreSQL 2.x does not accept an `Amp\Cancellation` argument for an
already-running SQL operation. The gateway therefore does not abandon an
in-flight write: doing so would return an ambiguous result while the insert may
still commit. Cancellation remains cooperative at safe boundaries; once a SQL
write begins, the method waits for its definite result.

## Status And Cancellation

`QueueStatusRepositoryInterface` exposes lookup by queue ID and ordered lists by
message or correlation ID. `QueueJobStatus` contains routing identity, attempts,
availability, start/finish/create/update timestamps, and the last error summary.

`QueueJobControlInterface::cancel()` changes only `pending` jobs to `cancelled`.
`requestCancellation()` only sets `cancellation_requested` for `running` jobs;
the worker must observe that flag and persist the final `cancelled` transition.
Both operations are idempotent no-ops when the queue ID is absent or its state
does not match the guarded update.

Heartbeat, lease renewal, claim, ack, retry, reject, stale recovery, and worker
process control are intentionally absent from this interface.

## Verification

Fast tests use typed PostgreSQL doubles and validate SQL parameters, transaction
rollback, state guards, schema columns, indexes, and every state pair. The real
database suite creates and drops a process-specific table:

```bash
MESSAGE_BUS_TEST_POSTGRES_CONFIG='host=127.0.0.1 port=5432 user=test password=test db=test' \
    composer test:integration
```

The configured database must be disposable and the user must be allowed to
create and drop tables.
