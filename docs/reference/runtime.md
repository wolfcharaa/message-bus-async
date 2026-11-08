# Runtime Contract

Этот справочник описывает публичные гарантии линии `0.1.x`. Точные сигнатуры
определяются исходным кодом и тестами.

## Виды Сообщений И API

| Marker | API | Результат |
| --- | --- | --- |
| `Query<TResult>` | `QueryBusInterface::ask()` | ровно один синхронный результат |
| `Command` | `CommandBusInterface::dispatch()` | один primary local binding и любое число durable bindings |
| `Event` | `EventPublisherInterface::publish()` | любое число local или durable subscribers |

Message object должен быть неизменяемыми данными. Маршрутизация transport,
service IDs, retry и правила выполнения хранятся в registry metadata, а не в
сообщении. Прикладной сервис зависит от самого узкого bus interface;
`MessageBusInterface` объединяет все три API.

## Жизненный Цикл Registry

`MessageRegistryCompiler` принимает явный список классов сообщений и
обработчиков. Он не сканирует Composer namespaces или файловую систему во время
работы. Bindings задаются атрибутами:

- `QueryHandler` — обработчик query;
- `CommandHandler` — command bindings и выбор primary;
- `EventSubscriber` — подписчик event;
- `MessageAlias` — стабильный идентификатор durable message.

Local sync binding может иметь сгенерированный `auto.*` binding ID. Для durable
binding обязательны явные стабильные binding ID и message alias. Компиляция
проверяет виды сообщения и обработчика, callable signature, flow, количество
результатов, типы interceptors, primary command и project validation rules.

`compileWithDiagnostics()` возвращает структурированные diagnostics и не
возвращает definition при наличии ошибок. `compile()` выбрасывает
`RegistryCompilationException`. Warnings становятся фатальными, только если
это включено в `MessageRegistryCompilerOptions`.

Definition можно атомарно записать через `CompiledRegistryFileWriter` и
загрузить через `CompiledMessageRegistry::fromFile()`. Compiled registry —
доверенный build artifact: при загрузке выполняется PHP-файл.

## Scopes И Разрешение Сервисов

Каждая корневая bus operation открывает один `ServiceScopeInterface` через
`ServiceScopeFactoryInterface`. Scope закрывается после выполнения независимо
от результата. Вложенное
выполнение переиспользует текущий scope, а параллельные корневые fibers получают
разные scopes.

Если одновременно падают выполнение и cleanup scope, `ServiceScopeFailure`
сохраняет исходную ошибку как `primaryFailure()` и все наблюдённые ошибки
очистки как `cleanupFailures()`. Ошибка cleanup не заменяет исходную причину.

Handlers и interceptors задаются типизированными `ServiceReference`.
`ServiceResolverInterface` является внутренней инфраструктурой bus и не
доступен через `MessageContextInterface`. В пакете есть optional adapters для
PSR-11 и Thesis DIC.

`MessageBusModule` — готовая рекомендуемая композиция для Thesis DIC. Она
получает compiled registry и application-owned scoped providers. Приложение с
другим lifecycle может передать собственную scope factory.

## Формы Обработчика

Compiler принимает две формы:

```php
public function __invoke(Message $message): mixed;
public function __invoke(Message $message, MessageContextInterface $context): mixed;
```

Для первой формы укажите `contextAware: false`. Context-aware handler может
выполнять вложенные bus calls и читать неизменяемые metadata envelope. Runtime
использует invocation mode из compiled registry и не исследует сигнатуру заново.

## Identity, Headers И Cancellation

Каждый корневой envelope получает message ID. По умолчанию correlation ID равен
ему, если `ExecutionOptions` не передаёт внешний seed. Вложенный envelope
получает новый message ID, сохраняет correlation и записывает родительский
message ID как causation.

Portable headers неизменяемы и наследуются вложенным выполнением. Cancellation
использует AMPHP `Cancellation`; вложенное выполнение объединяет parent и
nested signals. Второй механизм timeout библиотека не вводит.

## Local Execution И Ошибки Publication

Flow задаёт execution strategy и порядок interceptors. Contextless local binding
без interceptors выполняется напрямую. В остальных случаях bus создаёт context
и запускает interceptor pipeline. Переданный interceptor continuation можно
вызвать не более одного раза, в том числе если следующий interceptor завершил
цепочку досрочно.

Event publication агрегирует успешные local и queued branches. Если одна ветка
падает, `PublishFailed` сохраняет частичный `PublishResult` и
структурированные failures, поэтому вызывающий код видит успешные соседние
ветки.

## Durable Execution

Durable codec связывает один стабильный alias, message class и положительную
версию. `JsonEnvelopeSerializer` хранит alias, version, JSON payload и envelope
metadata, не считая значения payload именами PHP-классов. Upcasters выполняют
детерминированные шаги по одной версии.

`QueueExecutionStrategy` объединяет delivery options flow, binding и текущего
выполнения, затем передаёт полный `QueueMessage` в `QueueProviderInterface`.
Встроенный `PostgresQueueGateway` отвечает за enqueue, batch enqueue, status,
отмену pending job и запрос отмены running job.

Consumption намеренно вынесен из core. Worker вызывает
`BindingEnvelopeExecutorInterface::executeEnvelopeToBinding()` с уже открытым
job scope и точным binding из строки очереди. Поэтому consumed durable job
выполняется локально и не ставится в очередь повторно.

## Гарантии И Ограничения

- PostgreSQL delivery — at-least-once.
- Batch enqueue атомарен и сохраняет входной порядок.
- Cancellation кооперативна на безопасных границах I/O.
- Пакет не бросает незавершённую PostgreSQL write operation с неоднозначным
  результатом.
- Deterministic enqueue deduplication и встроенный transactional outbox не
  входят в `0.1.x`.
- Exactly-once для внешних эффектов не обещается.
- Stateful handler должен быть идемпотентным или использовать явную policy.

Подробности: [очередь PostgreSQL](postgres-queue.md) и
[идемпотентность и повтор](idempotency.md).
