<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Tests;

use Amp\Cancellation;
use Fedor\MessageBus\Attribute\EventSubscriber;
use Fedor\MessageBus\Attribute\MessageAlias;
use Fedor\MessageBus\Attribute\QueryHandler;
use Fedor\MessageBus\Context\DefaultMessageContext;
use Fedor\MessageBus\Context\MessageContextFactoryInterface;
use Fedor\MessageBus\Context\MessageContextInterface;
use Fedor\MessageBus\Dumper\CompiledRegistryFileWriter;
use Fedor\MessageBus\Envelope\Envelope;
use Fedor\MessageBus\Envelope\EnvelopeFactory;
use Fedor\MessageBus\Execution\SequentialExecutionStrategy;
use Fedor\MessageBus\Flow\FlowDefinition;
use Fedor\MessageBus\Flow\FlowRegistry;
use Fedor\MessageBus\Identity\RandomMessageIdGenerator;
use Fedor\MessageBus\Interceptor\InterceptorInterface;
use Fedor\MessageBus\Interceptor\PipelineInterface;
use Fedor\MessageBus\Message\Query;
use Fedor\MessageBus\Message\Event;
use Fedor\MessageBus\MessageBus;
use Fedor\MessageBus\MessageBusInterface;
use Fedor\MessageBus\Registry\CompiledMessageRegistry;
use Fedor\MessageBus\Registry\HandlerInvocationMode;
use Fedor\MessageBus\Registry\MessageRegistryCompiler;
use Fedor\MessageBus\Registry\RegistryDiagnosticCodes;
use Fedor\MessageBus\Service\ServiceReference;
use Fedor\MessageBus\Service\ServiceScopeFactoryInterface;
use Fedor\MessageBus\Service\ServiceScopeInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class ContextlessParityTest extends TestCase
{
    public function testContextlessQueryExecutesDirectlyWithoutCreatingContext(): void
    {
        ContextlessCountingFactory::$calls = 0;
        $flows = new FlowRegistry(FlowDefinition::sync('direct')->context(
            MessageContextInterface::class,
            ContextlessCountingFactory::class,
        ));
        $definition = (new MessageRegistryCompiler())->compile([ContextlessDirectHandler::class], $flows);
        $binding = array_values($definition->bindings)[0];
        $bus = self::bus($definition, [
            ContextlessDirectHandler::class => new ContextlessDirectHandler(),
        ]);

        self::assertSame(HandlerInvocationMode::Contextless, $binding->invocationMode);
        self::assertSame('direct:42', $bus->ask(new ContextlessDirectQuery(42)));
        self::assertSame(0, ContextlessCountingFactory::$calls);
    }

    public function testContextlessQueryCreatesContextOnlyForInterceptor(): void
    {
        ContextlessCountingFactory::$calls = 0;
        ContextlessPassThroughInterceptor::$calls = 0;
        $flows = new FlowRegistry(FlowDefinition::sync('intercepted')->context(
            MessageContextInterface::class,
            ContextlessCountingFactory::class,
        ));
        $definition = (new MessageRegistryCompiler())->compile([ContextlessInterceptedHandler::class], $flows);
        $bus = self::bus($definition, [
            ContextlessCountingFactory::class => new ContextlessCountingFactory(),
            SequentialExecutionStrategy::class => new SequentialExecutionStrategy(),
            ContextlessPassThroughInterceptor::class => new ContextlessPassThroughInterceptor(),
            ContextlessInterceptedHandler::class => new ContextlessInterceptedHandler(),
        ]);

        self::assertSame('intercepted:17', $bus->ask(new ContextlessInterceptedQuery(17)));
        self::assertSame(1, ContextlessCountingFactory::$calls);
        self::assertSame(1, ContextlessPassThroughInterceptor::$calls);
    }

    public function testContextlessInvocationMetadataSurvivesArtifactAndRepeatableBindings(): void
    {
        $definition = (new MessageRegistryCompiler())->compile([
            ContextlessRepeatedHandler::class,
            ContextlessDirectHandler::class,
        ], new FlowRegistry(
            FlowDefinition::sync('default'),
            FlowDefinition::sync('direct'),
        ));
        $file = sys_get_temp_dir() . '/message-bus-contextless-' . bin2hex(random_bytes(6)) . '/registry.php';

        try {
            (new CompiledRegistryFileWriter())->write($definition, $file);
            $registry = CompiledMessageRegistry::fromFile($file);

            self::assertSame(
                HandlerInvocationMode::Contextless,
                $registry->binding('contextless.repeated.first')->invocationMode,
            );
            self::assertSame(
                HandlerInvocationMode::Contextless,
                $registry->binding('contextless.repeated.second')->invocationMode,
            );
            self::assertSame('direct', $registry->bindingsForMessage(ContextlessDirectQuery::class)[0]->flow);
        } finally {
            @unlink($file);
            @rmdir(dirname($file));
        }
    }

    public function testContextlessSignatureDiagnosticsPreserveV6Behavior(): void
    {
        $compiler = new MessageRegistryCompiler();
        $async = new FlowRegistry(
            FlowDefinition::sync('default'),
            FlowDefinition::async('async')->transport('postgres', 'messages'),
        );
        $cases = [
            [[ContextlessInvalidContextHandler::class], null, RegistryDiagnosticCodes::HANDLER_INVALID_SIGNATURE],
            [[ContextlessMissingContextHandler::class], null, RegistryDiagnosticCodes::HANDLER_INVALID_SIGNATURE],
            [[ContextlessAsyncHandler::class], $async, RegistryDiagnosticCodes::QUERY_ASYNC_FLOW],
        ];

        foreach ($cases as [$classes, $flows, $code]) {
            $result = $compiler->compileWithDiagnostics($classes, $flows);

            self::assertFalse($result->hasDefinition());
            self::assertContains($code, array_map(
                static fn ($diagnostic): string => $diagnostic->code,
                $result->diagnostics,
            ));
        }
    }

    public function testLocalEventSubscribersRunByDescendingPriority(): void
    {
        $trace = new ContextlessEventTrace();
        $definition = (new MessageRegistryCompiler())->compile([
            ContextlessLowPriorityEventHandler::class,
            ContextlessHighPriorityEventHandler::class,
        ]);
        $bus = self::bus($definition, [
            ContextlessHighPriorityEventHandler::class => new ContextlessHighPriorityEventHandler($trace),
            ContextlessLowPriorityEventHandler::class => new ContextlessLowPriorityEventHandler($trace),
        ]);

        $result = $bus->publish(new ContextlessPrioritizedEvent());

        self::assertSame(['high', 'low'], $trace->order);
        self::assertCount(2, $result->executions());
    }

    /**
     * @param array<string, object> $services
     */
    private static function bus(\Fedor\MessageBus\Registry\MessageRegistryDefinition $definition, array $services): MessageBus
    {
        return new MessageBus(
            new CompiledMessageRegistry($definition),
            new ContextlessScopeFactory($services),
            new EnvelopeFactory(new RandomMessageIdGenerator()),
        );
    }
}

/** @implements Query<string> */
final readonly class ContextlessDirectQuery implements Query
{
    public function __construct(public int $id)
    {
    }
}

#[QueryHandler(ContextlessDirectQuery::class, flow: 'direct', contextAware: false)]
final readonly class ContextlessDirectHandler
{
    public function __invoke(ContextlessDirectQuery $query): string
    {
        return 'direct:' . $query->id;
    }
}

/** @implements Query<string> */
final readonly class ContextlessInterceptedQuery implements Query
{
    public function __construct(public int $id)
    {
    }
}

#[QueryHandler(
    ContextlessInterceptedQuery::class,
    flow: 'intercepted',
    middleware: [ContextlessPassThroughInterceptor::class],
    contextAware: false,
)]
final readonly class ContextlessInterceptedHandler
{
    public function __invoke(ContextlessInterceptedQuery $query): string
    {
        return 'intercepted:' . $query->id;
    }
}

final class ContextlessPassThroughInterceptor implements InterceptorInterface
{
    public static int $calls = 0;

    public function __invoke(MessageContextInterface $context, PipelineInterface $pipeline): mixed
    {
        ++self::$calls;

        return $pipeline->continue();
    }
}

final class ContextlessCountingFactory implements MessageContextFactoryInterface
{
    public static int $calls = 0;

    public function create(
        MessageBusInterface $messageBus,
        Envelope $envelope,
        FlowDefinition $flow,
        Cancellation $cancellation,
    ): MessageContextInterface {
        ++self::$calls;

        return new DefaultMessageContext($messageBus, $envelope, $cancellation);
    }
}

/** @implements Query<string> */
final readonly class ContextlessRepeatedFirstQuery implements Query
{
}

/** @implements Query<string> */
final readonly class ContextlessRepeatedSecondQuery implements Query
{
}

#[QueryHandler(
    ContextlessRepeatedFirstQuery::class,
    method: 'first',
    bindingId: 'contextless.repeated.first',
    contextAware: false,
)]
#[QueryHandler(
    ContextlessRepeatedSecondQuery::class,
    method: 'second',
    bindingId: 'contextless.repeated.second',
    contextAware: false,
)]
final readonly class ContextlessRepeatedHandler
{
    public function first(ContextlessRepeatedFirstQuery $query): string
    {
        return 'first';
    }

    public function second(ContextlessRepeatedSecondQuery $query): string
    {
        return 'second';
    }
}

/** @implements Query<string> */
final readonly class ContextlessInvalidContextQuery implements Query
{
}

#[QueryHandler(ContextlessInvalidContextQuery::class, contextAware: false)]
final readonly class ContextlessInvalidContextHandler
{
    public function __invoke(ContextlessInvalidContextQuery $query, MessageContextInterface $context): string
    {
        return 'invalid';
    }
}

/** @implements Query<string> */
final readonly class ContextlessMissingContextQuery implements Query
{
}

#[QueryHandler(ContextlessMissingContextQuery::class)]
final readonly class ContextlessMissingContextHandler
{
    public function __invoke(ContextlessMissingContextQuery $query): string
    {
        return 'invalid';
    }
}

/** @implements Query<string> */
#[MessageAlias('contextless.async')]
final readonly class ContextlessAsyncQuery implements Query
{
}

#[QueryHandler(
    ContextlessAsyncQuery::class,
    flow: 'async',
    bindingId: 'contextless.async',
    contextAware: false,
)]
final readonly class ContextlessAsyncHandler
{
    public function __invoke(ContextlessAsyncQuery $query): string
    {
        return 'invalid';
    }
}

final readonly class ContextlessPrioritizedEvent implements Event
{
}

final class ContextlessEventTrace
{
    /** @var list<string> */
    public array $order = [];
}

#[EventSubscriber(ContextlessPrioritizedEvent::class, priority: 10, contextAware: false)]
final readonly class ContextlessHighPriorityEventHandler
{
    public function __construct(private ContextlessEventTrace $trace)
    {
    }

    public function __invoke(ContextlessPrioritizedEvent $event): void
    {
        $this->trace->order[] = 'high';
    }
}

#[EventSubscriber(ContextlessPrioritizedEvent::class, priority: -10, contextAware: false)]
final readonly class ContextlessLowPriorityEventHandler
{
    public function __construct(private ContextlessEventTrace $trace)
    {
    }

    public function __invoke(ContextlessPrioritizedEvent $event): void
    {
        $this->trace->order[] = 'low';
    }
}

final readonly class ContextlessScopeFactory implements ServiceScopeFactoryInterface
{
    /** @param array<string, object> $services */
    public function __construct(private array $services)
    {
    }

    public function openScope(): ServiceScopeInterface
    {
        return new ContextlessScope($this->services);
    }
}

final readonly class ContextlessScope implements ServiceScopeInterface
{
    /** @param array<string, object> $services */
    public function __construct(private array $services)
    {
    }

    public function resolve(ServiceReference $reference): object
    {
        $service = $this->services[$reference->id] ?? throw new RuntimeException(sprintf(
            'Contextless service `%s` was not found.',
            $reference->id,
        ));
        if (!$service instanceof $reference->type) {
            throw new RuntimeException(sprintf(
                'Contextless service `%s` must implement `%s`.',
                $reference->id,
                $reference->type,
            ));
        }

        return $service;
    }

    public function dispose(?Throwable $error = null): void
    {
    }
}
