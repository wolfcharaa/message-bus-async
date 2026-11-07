# Message Bus Async

Асинхронная шина сообщений для PHP 8.4 на AMPHP. Библиотека разделяет запросы,
команды и события, заранее компилирует карту обработчиков, создаёт отдельный
scope на корневое выполнение и умеет ставить сообщения в надёжную очередь
PostgreSQL.

Линия `0.1.x` — самостоятельный преемник `romanfedorskij/message-bus` v6, а не
drop-in обновление. Приложение явно перечисляет обработчики, выбирает правила
scope и подключает адаптер очереди.

## Когда Библиотека Полезна

- обработчики нужно вызывать через типизированные `query`, `command` и `event`;
- runtime-сканирование классов и неявный service locator недопустимы;
- локальное выполнение и отправку в очередь нужно описывать одним registry;
- вложенные вызовы должны сохранять correlation/causation и cancellation;
- ресурсы обработчика должны жить в scope одного выполнения;
- сообщения в очереди должны иметь стабильные aliases, версии и `bindingId`.

## Возможности

| Задача | Публичная точка входа | Документ |
| --- | --- | --- |
| Получить синхронный результат | `QueryBusInterface::ask()` | [Runtime](docs/reference/runtime.md) |
| Выполнить команду локально или поставить её в очередь | `CommandBusInterface::dispatch()` | [Runtime](docs/reference/runtime.md) |
| Опубликовать событие нескольким подписчикам | `EventPublisherInterface::publish()` | [Runtime](docs/reference/runtime.md) |
| Проверить обработчики до запуска | `MessageRegistryCompiler` | [Первый запрос](docs/guides/getting-started.md) |
| Управлять scope сервисов | `ServiceScopeFactoryInterface` | [Runtime](docs/reference/runtime.md) |
| Кодировать версионированные сообщения | `MessageCodecRegistry` | [Runtime](docs/reference/runtime.md) |
| Поставить, проверить или отменить PostgreSQL job | `PostgresQueueGateway` | [Очередь PostgreSQL](docs/reference/postgres-queue.md) |
| Защитить эффект от повторного выполнения | idempotency policy и interceptor | [Идемпотентность](docs/reference/idempotency.md) |

Получение jobs, heartbeat, восстановление stale lease, управление процессами и
CLI находятся в отдельном пакете
[`romanfedorskij/message-bus-async-worker`](https://github.com/wolfcharaa/message-bus-async-worker).

## Требования

- PHP `8.4.12` или новее в пределах PHP 8;
- `ext-json`;
- AMPHP 3 и `amphp/postgres` 2;
- `ext-pgsql` или `ext-pq` при использовании PostgreSQL gateway.

Thesis DIC и PSR-11 подключаются опционально. Core не открывает контейнер через
message context и не зависит от Symfony Console.

## Установка

Пока пакет не зарегистрирован в Packagist, добавьте GitHub VCS repository:

```shell
composer config repositories.message-bus-async vcs \
  https://github.com/wolfcharaa/message-bus-async.git
composer require romanfedorskij/message-bus-async:^0.1
```

Для рекомендуемой интеграции с Thesis DIC:

```shell
composer require thesis/dic:^0.5
```

## Первый Запрос

Сообщение реализует типизированный marker interface, а атрибут связывает его с
обработчиком:

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
        return "Привет, {$query->name}!";
    }
}
```

Полный копируемый пример с imports, компиляцией registry и scoped-подключением
Thesis DIC приведён в руководстве [«Первый запрос»](docs/guides/getting-started.md).

## Как Выполняется Сообщение

1. Приложение компилирует явный список обработчиков.
2. Корневой `ask()`, `dispatch()` или `publish()` открывает service scope.
3. Вложенные вызовы используют тот же scope, новый message ID и исходный
   correlation ID.
4. Локальный binding проходит свой flow; durable binding сериализуется и
   передаётся выбранному queue provider.
5. Scope закрывается в `finally`, в том числе при ошибке обработчика.

Очередь даёт гарантию at-least-once. Изменяющий состояние обработчик должен
быть идемпотентным или применять явную idempotency policy. Core не обещает
exactly-once и не создаёт неявный transactional outbox.

## Границы Ответственности

Библиотека отвечает за registry, dispatch/publish, metadata envelope, scope,
кодеки и producer-контракт очереди. Приложение отвечает за:

- состав сообщений и бизнес-логику обработчиков;
- DI container и время жизни инфраструктурных ресурсов;
- migrations для сгенерированной PostgreSQL schema;
- транзакционные границы, outbox и идемпотентность внешних эффектов;
- worker processes и их supervisor.

## Документация

Читайте по задаче:

1. [Первый запрос](docs/guides/getting-started.md) — минимальная интеграция с
   Thesis DIC.
2. [Runtime contract](docs/reference/runtime.md) — сообщения, registry, scopes,
   ошибки, identity, cancellation и durable execution.
3. [Очередь PostgreSQL](docs/reference/postgres-queue.md) — schema, producer API,
   состояния и at-least-once.
4. [Идемпотентность и повтор](docs/reference/idempotency.md) — policy,
   interceptor, transaction и replay.
5. [Worker package](https://github.com/wolfcharaa/message-bus-async-worker) —
   consumer loop, control plane и CLI.

## Совместимость Версий

`message-bus-async-worker ^0.1` совместим с core `^0.1`. Durable aliases,
codec versions, binding IDs, queue schema и compiled registry являются
контрактами совместимости.

Старый пакет `romanfedorskij/message-bus` служит только поведенческим
ориентиром. Его container integration, reflection serializer и worker API
нельзя смешивать с новой линейкой.

## Разработка

```shell
composer validate --strict
composer analyse
composer test
```

Integration suite требует отдельную disposable PostgreSQL database, в которой
пользователь может создавать и удалять таблицы:

```shell
MESSAGE_BUS_TEST_POSTGRES_CONFIG='host=127.0.0.1 port=5432 user=test password=test db=test' \
  composer test:integration
```

## Лицензия

[MIT](LICENSE)
