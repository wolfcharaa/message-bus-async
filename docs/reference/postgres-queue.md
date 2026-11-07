# Очередь PostgreSQL

Core package владеет schema durable queue и прикладными producer, status и
cancellation API. Реализация использует `amphp/postgres` и не содержит PDO
adapter. Выполнение jobs находится в
`romanfedorskij/message-bus-async-worker`.

## Требования Runtime

Процесс, создающий `PostgresQueueGateway`, требует `amphp/postgres` 2.x и
`ext-pgsql` либо `ext-pq`. Приложение владеет
`PostgresConnectionPool`, применяет schema через свою систему migrations и
закрывает pool при завершении.

```php
<?php

declare(strict_types=1);

use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use Fedor\MessageBus\Queue\Postgres\PostgresQueueGateway;

$config = PostgresConfig::fromString((string) getenv('POSTGRES_CONFIG'));
$pool = new PostgresConnectionPool($config);
$queue = new PostgresQueueGateway($pool);

// Зарегистрируйте $queue как QueueProviderInterface,
// QueueStatusRepositoryInterface и QueueJobControlInterface.
// Application lifecycle должен закрыть $pool.
```

`PostgresQueueSchemaGenerator` генерирует актуальный DDL, но не изменяет базу.
Host migration должна применить результат до старта gateway.
`QueueTableDefinition` позволяет изменить имя таблицы
`message_bus__queue_jobs`.

## Гарантия Доставки

Queue contract — at-least-once. Claim сохраняет `running` до вызова handler,
успех подтверждается отдельным acknowledgement, а stale lease может вернуться
в retry. После падения процесса сообщение может выполниться повторно.
Изменяющий состояние handler должен иметь idempotency key/policy или доказуемо
идемпотентный эффект.

Producer версии `0.1.0` не предоставляет deterministic enqueue idempotency.
Нельзя вслепую повторять enqueue после неизвестного результата соединения:
исходный INSERT мог зафиксироваться. Transactional outbox и exactly-once для
внешних эффектов не входят в `0.1.0`.

## Точка Расширения Для Outbox

Core не объявляет `OutboxInterface`. `QueueExecutionStrategy` зависит только
от `QueueProviderInterface` и передаёт туда полный `QueueMessage`: стабильную
identity сообщения и binding, routing, retry snapshot, availability и
serialized envelope.

Приложение с доказанной transactional dual-write задачей может заменить прямой
PostgreSQL provider адаптером, который сохраняет `QueueMessage` в application
outbox и возвращает ID записи как queue message ID. Адаптер и relay отвечают за
присоединение транзакции, schema, deduplication, recovery и observability.
`MessageBus`, `QueueExecutionStrategy` и codecs менять не нужно.

Это прикладной adapter seam, а не неактивная core abstraction. Core не принимает
application transaction object, не опирается на ambient global transaction и
не обещает неподтверждённую выбранным адаптером atomicity.

## Машина Состояний

| Текущее | Следующее | Кто меняет |
| --- | --- | --- |
| `pending` | `running` | worker claim |
| `pending` | `cancelled` | producer cancel API |
| `running` | `succeeded` | worker ack |
| `running` | `pending` | retry или stale recovery |
| `running` | `failed` | reject или исчерпанный retry |
| `running` | `cancelled` | worker заметил cancellation request |
| `running` | `interrupted` | operational shutdown/control |

`succeeded`, `failed` и `cancelled` терминальны. `interrupted` —
публичное восстанавливаемое техническое состояние; его recovery API относится
к worker package.

## Producer И Batch Semantics

`enqueue()` вставляет один `pending` job и возвращает queue ID, время
создания, status и table metadata. В строке хранятся:

- stable message и binding identity;
- message/correlation IDs и flow/transport/queue routing;
- priority и availability;
- retry policy key и неизменяемый retry snapshot;
- versioned serialized envelope;
- lease, heartbeat, cancellation, error и lifecycle поля worker package.

`enqueueMany()` использует одну PostgreSQL transaction: либо вставляет все
сообщения в исходном порядке, либо откатывает всё. Cancellation проверяется до
транзакции, перед каждой записью и перед commit.

AMPHP PostgreSQL 2.x не принимает `Amp\Cancellation` для уже выполняющегося
SQL. Gateway не бросает in-flight write с неоднозначным результатом и ждёт её
определённого завершения. Cancellation действует на безопасных границах.

## Status И Cancellation

`QueueStatusRepositoryInterface` ищет job по queue ID и возвращает
упорядоченные списки по message или correlation ID. `QueueJobStatus` содержит
routing identity, attempts, availability, timestamps и последнюю ошибку.

`QueueJobControlInterface::cancel()` переводит только `pending` в
`cancelled`. `requestCancellation()` ставит
`cancellation_requested` только для `running`; worker должен заметить флаг
и сохранить финальный `cancelled`. Обе операции являются idempotent no-op,
если ID отсутствует или guarded state не совпал.

Heartbeat, lease renewal, claim, ack, retry, reject, stale recovery и process
control не входят в этот interface.

## Проверка

Fast tests проверяют SQL parameters, rollback, state guards, schema columns,
indexes и все пары состояний. Real database suite создаёт и удаляет отдельную
таблицу процесса:

```shell
MESSAGE_BUS_TEST_POSTGRES_CONFIG='host=127.0.0.1 port=5432 user=test password=test db=test' \
  composer test:integration
```

База должна быть disposable, а пользователь — иметь право создавать и удалять
таблицы.
