# Runtime Contract

This reference describes the public runtime guarantees of the `0.1.x` line.
Source declarations and tests remain authoritative for exact signatures.

## Message Kinds And APIs

| Marker | API | Cardinality |
| --- | --- | --- |
| `Query<TResult>` | `QueryBusInterface::ask()` | exactly one synchronous result |
| `Command` | `CommandBusInterface::dispatch()` | one primary local binding and zero or more durable bindings |
| `Event` | `EventPublisherInterface::publish()` | zero or more local or durable subscribers |

Message objects should be immutable data. Transport routing, service IDs,
retry settings, and execution behavior belong to registry metadata rather than
message instances. Application services should depend on the narrowest bus
interface they use; `MessageBusInterface` is the composite facade.

## Registry Lifecycle

`MessageRegistryCompiler` receives an explicit list of message and handler
classes. It does not scan Composer namespaces or the filesystem at runtime.
Attributes declare bindings:

- `QueryHandler` for a query handler;
- `CommandHandler` for command bindings and primary selection;
- `EventSubscriber` for event subscribers;
- `MessageAlias` for a stable durable message identity.

Local synchronous bindings may use generated `auto.*` binding IDs. Durable
bindings require explicit stable binding IDs and message aliases. Compilation
validates message/handler kind, callable signature, flow, result cardinality,
interceptor types, primary command selection, and project validation rules.

`compileWithDiagnostics()` returns structured diagnostics and never returns a
definition when errors exist. `compile()` throws
`RegistryCompilationException`. Warnings become fatal only when requested by
`MessageRegistryCompilerOptions`.

The definition can be written atomically with `CompiledRegistryFileWriter` and
loaded with `CompiledMessageRegistry::fromFile()`. A compiled registry is a
trusted build artifact because loading executes a PHP file.

## Scopes And Service Resolution

Every root bus operation opens one `ServiceScopeInterface` through
`ServiceScopeFactoryInterface`. Disposal happens in `finally`. Nested execution
reuses the current scope; concurrent root fibers open independent scopes.

Handlers and interceptors are referenced by typed `ServiceReference` values.
`ServiceResolverInterface` is infrastructure for the bus and is not exposed
through `MessageContextInterface`. The package includes optional adapters for
PSR-11 and Thesis DIC.

The Thesis `MessageBusModule` is the recommended ready-made composition. It
accepts a compiled registry plus application-owned scoped providers. A custom
application lifecycle can provide its own scope factory instead.

## Handler Shapes

The compiler accepts two handler shapes:

```php
public function __invoke(Message $message): mixed;
public function __invoke(Message $message, MessageContextInterface $context): mixed;
```

Use `contextAware: false` for the first shape. Context-aware handlers can
perform nested bus operations and read immutable envelope metadata. Runtime
dispatch uses the invocation mode stored in the compiled registry and does not
rediscover signatures.

## Identity, Headers, And Cancellation

Each root envelope receives a message ID. Its correlation ID defaults to that
message ID unless `ExecutionOptions` supplies an external seed. A nested
envelope receives a new message ID, preserves correlation, and records the
parent message ID as causation.

Portable headers are immutable and inherited by nested execution. Cancellation
uses AMPHP `Cancellation`; nested execution combines parent and nested
cancellation signals. The package does not define a second timeout abstraction.

## Local Execution And Publication Failures

Flows define execution strategy and interceptor order. A contextless local
binding without interceptors executes directly. Otherwise the bus creates a
context and runs the interceptor pipeline.

Event publication aggregates every successful local or queued branch. If one
branch fails, `PublishFailed` retains the partial `PublishResult` and structured
failures so callers can observe successful siblings.

## Durable Execution

Durable codecs bind one stable alias and message class to a positive version.
`JsonEnvelopeSerializer` stores message alias, version, JSON payload, and
envelope metadata without treating payload values as PHP class names. Upcasters
perform deterministic one-version steps.

`QueueExecutionStrategy` merges flow, binding, and execution delivery options,
then passes a complete `QueueMessage` to `QueueProviderInterface`. The built-in
`PostgresQueueGateway` owns producer enqueue, batch enqueue, status, pending
cancellation, and running cancellation requests.

Queue consumption is intentionally outside the core package. The worker calls
`BindingEnvelopeExecutorInterface::executeEnvelopeToBinding()` with an already
opened job scope and the exact binding stored in the queue row. This forces
local execution and prevents a consumed durable job from being enqueued again.

## Guarantees And Limits

- PostgreSQL delivery is at-least-once.
- Batch enqueue is atomic and preserves input order.
- Cancellation is cooperative at safe I/O boundaries.
- The package does not abandon an in-flight PostgreSQL write with an ambiguous
  result.
- Deterministic enqueue deduplication and a built-in transactional outbox are
  outside `0.1.x`.
- Exactly-once external side effects are not promised.
- Stateful handlers must be idempotent or use an explicit idempotency policy.

See [PostgreSQL queue](postgres-queue.md) and
[Idempotency and replay](idempotency.md) for the detailed storage contracts.
