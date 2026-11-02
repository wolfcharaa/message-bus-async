# Message Bus Async

Async-first PHP message bus package with a typed local execution lane and a
producer-facing durable queue boundary.

> Status: pre-release `0.1.0`. Public interaction contracts are available, but
> the bus implementation is not ready for application use.

> Migration policy: `romanfedorskij/message-bus` v6.2.3 is the behavioral
> baseline. Existing registry, flow, diagnostics, envelope, and queue semantics
> are ported from its source and tests unless an AMPHP/lifecycle/package-split
> delta has already been accepted. Flow, identity, delivery, and cardinality
> semantics, diagnostic contracts, validation rules, registration metadata, and
> the registry definition/artifact lifecycle have been reconciled. The v6
> diagnostic fixture matrix, local execution, and producer queue strategy are
> covered by target-owned tests. Durable serialization follows the separately
> accepted explicit-codec contract rather than the v6 reflection serializer.

## Implemented Contract

Messages explicitly declare their interaction kind:

| Marker | Semantics |
| --- | --- |
| `Fedor\MessageBus\Message\Query<TResult>` | One result-bearing interaction; `TResult` is a static-analysis generic |
| `Fedor\MessageBus\Message\Command` | One state-changing interaction without an application result |
| `Fedor\MessageBus\Message\Event` | A notification that may have multiple subscribers |

Marker implementations are expected to be immutable data objects. Aliases,
versions, transport metadata, container references, and execution behavior do
not belong to the message marker contract.

Root execution settings are carried by immutable
`Fedor\MessageBus\Execution\ExecutionOptions`. It accepts an AMPHP
`Cancellation`, portable initial `Headers`, and an optional external correlation
seed. The bus implementation will create the internal context and propagate
those values to nested executions; callers will not construct or mutate that
context. Deadlines are expressed with AMPHP `TimeoutCancellation` instead of a
second timeout abstraction.

The library owns immutable envelope identity. A replaceable
`MessageIdGeneratorInterface` creates opaque message IDs; the built-in random
generator uses 128 bits from `random_bytes()`. A root envelope uses its message
ID as correlation ID unless the caller supplies a correlation seed. Every
nested envelope gets a new message ID, keeps the root correlation ID, and uses
the parent message ID as causation ID. A durable codec may restore the same IDs
by constructing an envelope from serialized data.

Durable serialization is explicit and versioned:

- each `MessageCodecInterface` binds one stable alias and message class to a
  positive current message version;
- codecs convert messages to and from validated `JsonValue` instances, so PHP
  objects, resources, non-finite numbers, and malformed object keys cannot enter
  the persisted payload;
- `MessageUpcasterInterface` performs one deterministic `N -> N+1` step, and
  `MessageCodecRegistry` rejects duplicate codecs or a discontinuous chain;
- `JsonEnvelopeSerializer` stores message alias/version/payload separately from
  the envelope schema version and preserves message, correlation, causation,
  and portable header metadata;
- unknown aliases and unsupported versions fail through typed exceptions; no
  payload value is treated as a PHP class name.

`MessageCodecRegistryValidationRule` can be included in
`MessageRegistryCompiler` project rules. It requires every async binding alias
to have a codec for the same message class; local sync messages do not need a
durable codec. HTTP presenters remain an application concern and do not reuse
these codecs.

The application-facing API is segregated by interaction kind:

| Interface | Method | Result |
| --- | --- | --- |
| `QueryBusInterface` | `ask(Query<TResult>, ?ExecutionOptions)` | `TResult` |
| `CommandBusInterface` | `dispatch(Command, ?ExecutionOptions)` | `void` |
| `EventPublisherInterface` | `publish(Event, ?ExecutionOptions)` | `PublishResult` |

`MessageBusInterface` is the composite facade for composition and nested
execution. Application services should depend on the narrowest interface they
use.

The concrete `MessageBus` is the orchestration root. Every public
`ask()`/`dispatch()`/`publish()` call opens one `ServiceScopeInterface` and
disposes it in `finally`. A context receives a per-execution `NestedMessageBus`
facade, so nested calls reuse the same scope while `EnvelopeFactory` creates a
new message ID, preserves correlation, records causation, and merges headers.
No fiber-local mutable state or resolver is exposed through the context.

Queries execute their single sync binding and return its typed result. Commands
execute the primary sync binding and all async bindings, which also supports an
async-only command. Event publication executes every local and queued subscriber
and returns one aggregate `PublishResult`; failures retain successful siblings
inside `PublishFailed`.

A contextless sync binding on the sequential strategy executes directly when
neither its flow nor the binding declares interceptors. This preserves the v6
contract: the handler receives only the message, bindings keep descending
priority order, and the runtime does not resolve a context factory merely to
discard its context. As soon as an interceptor is present, the normal context
and pipeline path is used.

Handlers are invokable objects with one of two supported shapes:

- `__invoke(Message $message): TResult|void` for a contextless handler;
- `__invoke(Message $message, MessageContextInterface $context): TResult|void`
  when nested execution or metadata is required.

The registry compiler will validate the chosen signature once and store its
invocation mode; runtime dispatch will not rediscover it through reflection.
`MessageContextInterface` is read-only and exposes nested bus capabilities,
AMPHP cancellation, and the immutable envelope containing
message/correlation/causation IDs and inherited headers. Handlers never
construct the context themselves. `MessageContextFactoryInterface` preserves
the v6 per-flow context extension point with AMPHP `Cancellation` replacing the
old worker-control dependency. `DefaultMessageContext` delegates only the three
public bus capabilities and combines parent and nested cancellation signals.

Registry declarations preserve the attribute model of the previous package:

- `MessageAlias` assigns a stable external name to a message class;
- `QueryHandler`, `CommandHandler`, and `EventSubscriber` describe repeatable
  handler bindings on action classes;
- handler attributes retain the v6 `middleware` argument for interceptor classes
  and declare flow, method, priority, optional binding ID, and context mode;
- `CommandHandler` additionally declares the optional primary binding marker;
- command/event attributes retain delay and retry-policy delivery overrides.

Attributes are compile-time metadata only. The compiler receives an
explicit list of message and handler classes, inspects only those classes, and
produces a compiled registry definition. Composer namespace traversal,
filesystem discovery, and reflection-based runtime scanning are not part of the
contract. Queue delivery settings are compiled from the v6 attributes and flows.
`RegistryOwner`, `RegistrySource`, and `BindingRegistrationContext` preserve
registration provenance across binding serialization.

`FlowDefinition` and `FlowRegistry` preserve the v6 sync/async distinction,
custom context/factory metadata, global middleware chain, transport/queue
selection, and delivery defaults. The default registry contains one sync
`default` flow backed by `DefaultMessageContextFactory`.

`MessageRegistryCompiler` preserves the v6 identity boundary: local sync bindings may
omit `MessageAlias` and `bindingId`, in which case the binding receives a
deterministic `auto.*` ID. Async bindings require an explicit stable binding ID,
and their messages require `MessageAlias`. A referenced message may be hydrated
from its handler attribute without being repeated in the explicit class list.

Compilation also validates handler/message kind compatibility, the configured
method and flow-specific context mode, context/factory contracts,
query/command/event return semantics, interceptor types, query cardinality, and
command primary selection. A single command handler becomes primary unless it
explicitly opts out; multiple command handlers require exactly one
`primary: true` binding.

`compileWithDiagnostics()` returns structured diagnostics with origin, target,
hint, severity, and the last `RegistryCompilationStage`. Core validation is
followed by project `RegistryValidationRuleInterface` rules; warnings can be
promoted to strict compilation failure through `MessageRegistryCompilerOptions`.
An error result never contains a definition. `compile()` throws a typed
`RegistryCompilationException` that retains all diagnostics. Discovery, alias
hydration, binding normalization, core validation, project rules, and definition
building are separate early-exit stages with partial `HandlerBindingDefinition`
graphs available to diagnostics tooling.

`MessageRegistryDefinition` preserves the v6 schema metadata: schema and library
versions, generation time, source hash, flows, message-to-binding index,
bindings, aliases, and reverse message names. Compile-time bindings retain the
v6 nullable normalization state; hydrated runtime bindings use typed handler and
interceptor `ServiceReference` values as the accepted lifecycle adaptation.

`SymfonyVarExporterRegistryDumper` writes the definition as a PHP file returning
scalar arrays, and `CompiledRegistryFileWriter` replaces the target atomically.
`CompiledMessageRegistry::fromFile()` hydrates the definition. The v6
`RegistryRuntimeLoader` prefers a supplied compiled file and can either compile
the explicit class list when it is absent or enforce build-only artifacts with
`requireCompiled: true`. Dispatch never performs discovery after loading.
Because loading uses PHP `require`, compiled registries must be trusted build
outputs.

Cross-cutting execution reuses the interceptor model:

- `InterceptorInterface` wraps a context and `PipelineInterface<TResult>`;
- an interceptor may run logic before and after `continue()`, transform the
  result, short-circuit execution, or rethrow a failure;
- registry compilation determines the ordered interceptor chain for a binding;
- the concrete `Pipeline<TResult>` is created per execution from resolved
  interceptor instances and a terminal handler callable.

The pipeline has no container or registry dependency and cannot execute its
terminal handler twice. Logging, tracing, cache, validation, and transaction
behavior are interceptors; no parallel middleware abstraction is introduced.

`SequentialExecutionStrategy` preserves the v6 local execution contract:
bindings run by descending priority, flow interceptors wrap binding interceptors,
and fail-fast can be disabled to collect per-binding failures. `ExecutionRequest`
uses the neutral service resolver and a container-free callable invoker;
`HandlerExecutionResult` keeps lookup by stable binding ID or action class.

`QueueExecutionStrategy` preserves the producer side of the v6 async contract:
flow, binding, and execution delivery options are merged; retry policies are
snapshotted into each `QueueMessage`; and single or batch enqueue failures are
reported per binding. Queue providers receive the AMPHP cancellation directly.
`PostgresQueueGateway` implements enqueue, atomic batch enqueue, status polling,
pending cancellation, and cooperative cancellation requests through
`amphp/postgres`; no PDO path exists in the package. The core owns the shared
queue schema, while claim, heartbeat, ack/retry/reject, stale recovery, and
process control remain worker-package responsibilities. See the
[PostgreSQL queue reference](docs/reference/postgres-queue.md) for state
transitions, at-least-once semantics, cancellation limits, and schema ownership.

Compiled bindings refer to handlers and interceptors through typed
`ServiceReference<T>` values. Core resolution is expressed by three neutral
contracts:

- `ServiceResolverInterface` resolves a typed reference;
- `ServiceScopeInterface` adds deterministic disposal;
- `ServiceScopeFactoryInterface` opens a scope for a root execution.

`ServiceScopeRunner` guarantees disposal in `finally`, including when execution
fails. Nested executions reuse the current scope. A DI integration adapts its
native scoped providers to these contracts; the core package does not require
Thesis DIC or PSR-11. The resolver is infrastructure for the bus and is never
exposed through `MessageContextInterface` as a service locator.

Optional adapters are available without making either container a runtime
dependency of the core package. `PsrContainerServiceResolver` validates PSR-11
entries against the compiled `ServiceReference` type. For Thesis DIC,
`ThesisServiceResolver` accepts provider closures created by `Dic::provider()`,
while `ThesisServiceScopeFactory` adapts a `Scoped<ServiceResolverInterface>`
handle to the bus scope contract. Each root execution owns a fresh Thesis scope;
parallel fibers remain isolated, nested dispatch reuses the same resolver, and
the execution failure reaches Thesis disposers before teardown completes.

Event publication reports every matched binding as a local or queued
`PublishedExecution`. A partial failure throws `PublishFailed`; the exception
retains the partial `PublishResult`, including successful executions and
structured failures. Callers therefore cannot silently ignore a failed fan-out
branch, while queue message IDs remain available for status polling.

## Responsibility Boundary

The core package will own:

- typed query, command, and event dispatch contracts;
- nested execution context and middleware pipeline;
- deterministic registry and stable message aliases;
- versioned JSON codecs and upcasters;
- durable publish, status, cancellation, retry, and queue schema contracts;
- the AMPHP PostgreSQL producer/status/cancel gateway.

Worker execution, heartbeat, stale recovery, process control, and worker CLI
belong to the optional `romanfedorskij/message-bus-async-worker` package.

The core will not require a DI container or Symfony Console. Container-specific
resolution and lifecycle behavior will be connected through adapters.

## Platform

- PHP `8.4.12` or newer within the PHP 8 major line;
- JSON extension;
- `amphp/amp` `3.1.x` or a compatible 3.x release;
- `amphp/postgres` `2.2.1` or a compatible 2.x release, with `ext-pgsql` or
  `ext-pq` available in the process using the PostgreSQL gateway;
- `psr/clock` `1.0.x` for deterministic delayed delivery;
- `symfony/var-exporter` `7.4.x` or a compatible 7.x release.

Additional runtime dependencies will be declared only with the implementation
slice that uses them.

## Development State

The repository currently contains public interaction contracts, compile-time
attributes, deterministic registry compilation, neutral scoped resolution, the
versioned compiled-registry artifact, custom flow contexts, local sequential and
queue-producer strategies, explicit versioned JSON codecs/upcasters, and the
composite bus runtime with nested causation and aggregate fan-out. Optional
PSR-11 and Thesis DIC adapters preserve typed resolution and per-root scope
isolation. The shared PostgreSQL schema and AMP producer/status/cancel gateway
are implemented and covered by an opt-in real PostgreSQL integration suite.
Worker consumption transitions and deterministic enqueue idempotency remain the
next implementation slices.

Run the package quality gates with:

```bash
composer validate --strict
composer analyse
composer test
```

`composer analyse` runs the package-owned PHPStan dependency at level max over
`src`, `tests`, and `integration-tests`; it does not rely on a globally
installed binary. The PostgreSQL integration gate expects a disposable database:

```bash
MESSAGE_BUS_TEST_POSTGRES_CONFIG='host=127.0.0.1 port=5432 user=test password=test db=test' \
    composer test:integration
```
