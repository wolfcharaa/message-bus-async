# Message Bus Async

Async-first message bus for PHP 8.4 with typed query, command, and event APIs,
an explicit compiled registry, deterministic execution scopes, and a durable
PostgreSQL producer boundary built on AMPHP.

The first stable package line is `0.1.x`. It is a clean-slate successor to
`romanfedorskij/message-bus` v6, not a drop-in upgrade: applications must build
an explicit registry and choose their service-scope and queue adapters.

## What It Provides

| Capability | Public entry point |
| --- | --- |
| Typed request/reply | `QueryBusInterface::ask()` |
| State-changing local or queued work | `CommandBusInterface::dispatch()` |
| Local and queued fan-out | `EventPublisherInterface::publish()` |
| Compile-time handler validation | `MessageRegistryCompiler` |
| Nested correlation and cancellation | `MessageContextInterface` |
| Scoped service resolution | `ServiceScopeFactoryInterface` |
| Versioned durable payloads | `MessageCodecRegistry` and `JsonEnvelopeSerializer` |
| PostgreSQL enqueue/status/cancel | `PostgresQueueGateway` |

Queue consumption, heartbeat, stale recovery, process control, and the worker
CLI live in
[`romanfedorskij/message-bus-async-worker`](https://github.com/wolfcharaa/message-bus-async-worker).

## Requirements

- PHP `8.4.12` or newer within the PHP 8 line;
- `ext-json`;
- `ext-pgsql` or `ext-pq` when using the PostgreSQL gateway;
- AMPHP 3 and `amphp/postgres` 2.

Thesis DIC and PSR-11 integrations are optional. The core does not expose a
container through message contexts and does not require Symfony Console.

## Installation

The source release is available from GitHub. Until the package is registered
in Packagist, add the VCS repository explicitly:

```shell
composer config repositories.message-bus-async vcs \
  https://github.com/wolfcharaa/message-bus-async.git
composer require romanfedorskij/message-bus-async:^0.1
```

For the recommended Thesis integration:

```shell
composer require thesis/dic:^0.5
```

## First Query

Declare a typed message, bind a handler with an attribute, compile the explicit
class list, and import `MessageBusModule` into the application composition root:

```php
/** @implements Query<string> */
final readonly class Greeting implements Query
{
    public function __construct(public string $name) {}
}

#[QueryHandler(Greeting::class, contextAware: false)]
final class GreetingHandler
{
    public function __invoke(Greeting $query): string
    {
        return "Hello, {$query->name}!";
    }
}
```

The complete executable composition, including imports and scoped Thesis DIC
wiring, is in [Getting started](docs/guides/getting-started.md).

## Execution Model

1. The application compiles an explicit list of handler classes.
2. Every root `ask()`, `dispatch()`, or `publish()` opens one service scope.
3. Nested calls reuse that scope while receiving new message IDs and preserved
   correlation/causation metadata.
4. Local bindings execute through their flow pipeline. Durable bindings are
   serialized and passed to the selected queue provider.
5. The scope is disposed in `finally`, including handler or interceptor
   failures.

The queue contract is at-least-once. Handlers with state-changing effects must
be idempotent or use an explicit idempotency policy. The core does not promise
exactly-once delivery or an implicit transactional outbox.

## Documentation

- [Getting started](docs/guides/getting-started.md) — first local query with
  Thesis DIC.
- [Runtime contract](docs/reference/runtime.md) — messages, registry, scopes,
  errors, identity, cancellation, and durable execution.
- [PostgreSQL queue](docs/reference/postgres-queue.md) — schema ownership,
  at-least-once delivery, producer operations, and state transitions.
- [Idempotency and replay](docs/reference/idempotency.md) — policy, interceptor,
  transaction, and replay semantics.
- [Worker package](https://github.com/wolfcharaa/message-bus-async-worker) —
  consumers, runner, control plane, and CLI.

## Version Compatibility

`message-bus-async-worker ^0.1` is compatible with core `^0.1`. Durable message
aliases, codec versions, binding IDs, queue schema, and compiled registry
artifacts are compatibility-sensitive. Review those contracts before changing
them in a patch release.

The old `romanfedorskij/message-bus` package is only a behavioral reference.
Its runtime container integration, reflection serializer, and worker APIs must
not be mixed with this package family.

## Development

```shell
composer validate --strict
composer analyse
composer test
```

The optional PostgreSQL integration suite requires a disposable database whose
user can create and drop tables:

```shell
MESSAGE_BUS_TEST_POSTGRES_CONFIG='host=127.0.0.1 port=5432 user=test password=test db=test' \
  composer test:integration
```

## License

[MIT](LICENSE)
