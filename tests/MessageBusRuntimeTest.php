<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Tests;

use Fedor\MessageBus\Attribute\CommandHandler;
use Fedor\MessageBus\Attribute\EventSubscriber;
use Fedor\MessageBus\Attribute\MessageAlias;
use Fedor\MessageBus\Attribute\QueryHandler;
use Fedor\MessageBus\Context\DefaultMessageContextFactory;
use Fedor\MessageBus\Context\MessageContextInterface;
use Fedor\MessageBus\Envelope\Envelope;
use Fedor\MessageBus\Envelope\EnvelopeFactory;
use Fedor\MessageBus\Execution\ExecutionOptions;
use Fedor\MessageBus\Execution\ExecutionRequest;
use Fedor\MessageBus\Execution\HandlerExecutionResult;
use Fedor\MessageBus\Execution\HandlerExecutionResultInterface;
use Fedor\MessageBus\Execution\HandlerExecutionStrategyInterface;
use Fedor\MessageBus\Execution\HandlerResult;
use Fedor\MessageBus\Execution\SequentialExecutionStrategy;
use Fedor\MessageBus\Flow\FlowDefinition;
use Fedor\MessageBus\Flow\FlowRegistry;
use Fedor\MessageBus\Identity\MessageIdGeneratorInterface;
use Fedor\MessageBus\Message\Command;
use Fedor\MessageBus\Message\Event;
use Fedor\MessageBus\Message\Query;
use Fedor\MessageBus\MessageBus;
use Fedor\MessageBus\Publication\PublishFailed;
use Fedor\MessageBus\Publication\PublishedExecution;
use Fedor\MessageBus\Publication\PublishedExecutionMode;
use Fedor\MessageBus\Registry\CompiledMessageRegistry;
use Fedor\MessageBus\Registry\MessageRegistryCompiler;
use Fedor\MessageBus\Service\ServiceReference;
use Fedor\MessageBus\Service\ServiceScopeFactoryInterface;
use Fedor\MessageBus\Service\ServiceScopeInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MessageBusRuntimeTest extends TestCase
{
    public function testNestedQueryReusesRootScopeAndPreservesCausation(): void
    {
        $trace = new RuntimeTrace();
        $scopeFactory = new RuntimeScopeFactory([
            DefaultMessageContextFactory::class => new DefaultMessageContextFactory(),
            SequentialExecutionStrategy::class => new SequentialExecutionStrategy(),
            RuntimeRootCommandHandler::class => new RuntimeRootCommandHandler($trace),
            RuntimeNestedQueryHandler::class => new RuntimeNestedQueryHandler($trace),
        ]);
        $bus = self::bus(
            [RuntimeRootCommandHandler::class, RuntimeNestedQueryHandler::class],
            new FlowRegistry(),
            $scopeFactory,
        );

        $bus->dispatch(new RuntimeRootCommand(), new ExecutionOptions(headers: ['root' => 'header']));

        self::assertSame('nested-result', $trace->nestedResult);
        self::assertNotNull($trace->rootEnvelope);
        self::assertNotNull($trace->nestedEnvelope);
        self::assertSame('message-1', $trace->rootEnvelope->messageId);
        self::assertSame('message-2', $trace->nestedEnvelope->messageId);
        self::assertSame('message-1', $trace->nestedEnvelope->correlationId);
        self::assertSame('message-1', $trace->nestedEnvelope->causationId);
        self::assertSame('header', $trace->nestedEnvelope->headers->get('root'));
        self::assertSame('header', $trace->nestedEnvelope->headers->get('nested'));
        self::assertSame(1, $scopeFactory->opened);
        self::assertSame(1, $scopeFactory->disposed);
    }

    public function testEventPublishAggregatesLocalAndQueuedExecutions(): void
    {
        $trace = new RuntimeTrace();
        $queued = new RuntimeQueuedStrategy();
        $flows = self::flows();
        $scopeFactory = new RuntimeScopeFactory([
            DefaultMessageContextFactory::class => new DefaultMessageContextFactory(),
            SequentialExecutionStrategy::class => new SequentialExecutionStrategy(),
            RuntimeQueuedStrategy::class => $queued,
            RuntimeLocalEventHandler::class => new RuntimeLocalEventHandler($trace),
            RuntimeQueuedEventHandler::class => new RuntimeQueuedEventHandler(),
        ]);
        $bus = self::bus(
            [RuntimeLocalEventHandler::class, RuntimeQueuedEventHandler::class],
            $flows,
            $scopeFactory,
        );

        $result = $bus->publish(new RuntimePublishedEvent());

        self::assertCount(2, $result->executions());
        self::assertSame(
            [PublishedExecutionMode::Local, PublishedExecutionMode::Queued],
            array_map(
                static fn (PublishedExecution $execution): PublishedExecutionMode => $execution->mode,
                $result->executions(),
            ),
        );
        self::assertSame('message-1', $result->executions()[0]->messageId);
        self::assertSame('message-1', $result->executions()[1]->messageId);
        self::assertSame(1, $trace->localEvents);
        self::assertSame(1, $queued->executions);
        self::assertSame(1, $scopeFactory->opened);
        self::assertSame(1, $scopeFactory->disposed);
    }

    public function testEventPublishKeepsSuccessesWhenAnotherBindingFails(): void
    {
        $trace = new RuntimeTrace();
        $scopeFactory = new RuntimeScopeFactory([
            DefaultMessageContextFactory::class => new DefaultMessageContextFactory(),
            SequentialExecutionStrategy::class => new SequentialExecutionStrategy(),
            RuntimeSuccessfulEventHandler::class => new RuntimeSuccessfulEventHandler($trace),
            RuntimeFailingEventHandler::class => new RuntimeFailingEventHandler(),
        ]);
        $bus = self::bus(
            [RuntimeSuccessfulEventHandler::class, RuntimeFailingEventHandler::class],
            new FlowRegistry(),
            $scopeFactory,
        );

        try {
            $bus->publish(new RuntimePartialEvent());
            self::fail('Expected partial event publication to fail.');
        } catch (PublishFailed $exception) {
            self::assertCount(1, $exception->result()->executions());
            self::assertCount(1, $exception->result()->failures());
            self::assertSame(RuntimeFailingEventHandler::class, $exception->getPrevious()?->getMessage());
        }

        self::assertSame(1, $trace->localEvents);
        self::assertSame(1, $scopeFactory->opened);
        self::assertSame(1, $scopeFactory->disposed);
    }

    public function testAsyncOnlyCommandUsesQueuedStrategyWithinRootScope(): void
    {
        $queued = new RuntimeQueuedStrategy();
        $scopeFactory = new RuntimeScopeFactory([
            DefaultMessageContextFactory::class => new DefaultMessageContextFactory(),
            RuntimeQueuedStrategy::class => $queued,
            RuntimeAsyncCommandHandler::class => new RuntimeAsyncCommandHandler(),
        ]);
        $bus = self::bus(
            [RuntimeAsyncCommandHandler::class],
            self::flows(),
            $scopeFactory,
        );

        $bus->dispatch(new RuntimeAsyncCommand());

        self::assertSame(1, $queued->executions);
        self::assertSame(1, $scopeFactory->opened);
        self::assertSame(1, $scopeFactory->disposed);
    }

    /**
     * @param list<class-string> $classes
     */
    private static function bus(
        array $classes,
        FlowRegistry $flows,
        RuntimeScopeFactory $scopeFactory,
    ): MessageBus {
        $definition = (new MessageRegistryCompiler())->compile($classes, $flows);

        return new MessageBus(
            new CompiledMessageRegistry($definition),
            $scopeFactory,
            new EnvelopeFactory(new RuntimeMessageIdGenerator()),
        );
    }

    private static function flows(): FlowRegistry
    {
        return new FlowRegistry(
            FlowDefinition::sync('default'),
            FlowDefinition::async('async')
                ->strategy(RuntimeQueuedStrategy::class)
                ->transport('postgres', 'messages'),
        );
    }
}

final class RuntimeTrace
{
    public ?Envelope $rootEnvelope = null;
    public ?Envelope $nestedEnvelope = null;
    public ?string $nestedResult = null;
    public int $localEvents = 0;
}

final readonly class RuntimeRootCommand implements Command
{
}

/** @implements Query<string> */
final readonly class RuntimeNestedQuery implements Query
{
}

#[CommandHandler(RuntimeRootCommand::class)]
final readonly class RuntimeRootCommandHandler
{
    public function __construct(private RuntimeTrace $trace)
    {
    }

    public function __invoke(RuntimeRootCommand $command, MessageContextInterface $context): void
    {
        $this->trace->rootEnvelope = $context->envelope();
        $this->trace->nestedResult = $context->ask(
            new RuntimeNestedQuery(),
            new ExecutionOptions(headers: ['nested' => 'header']),
        );
    }
}

#[QueryHandler(RuntimeNestedQuery::class)]
final readonly class RuntimeNestedQueryHandler
{
    public function __construct(private RuntimeTrace $trace)
    {
    }

    public function __invoke(RuntimeNestedQuery $query, MessageContextInterface $context): string
    {
        $this->trace->nestedEnvelope = $context->envelope();

        return 'nested-result';
    }
}

#[MessageAlias('runtime.published')]
final readonly class RuntimePublishedEvent implements Event
{
}

#[EventSubscriber(RuntimePublishedEvent::class, contextAware: false)]
final readonly class RuntimeLocalEventHandler
{
    public function __construct(private RuntimeTrace $trace)
    {
    }

    public function __invoke(RuntimePublishedEvent $event): void
    {
        ++$this->trace->localEvents;
    }
}

#[EventSubscriber(
    RuntimePublishedEvent::class,
    flow: 'async',
    bindingId: 'runtime.published.queued',
    contextAware: false,
)]
final class RuntimeQueuedEventHandler
{
    public function __invoke(RuntimePublishedEvent $event): void
    {
    }
}

final readonly class RuntimePartialEvent implements Event
{
}

#[EventSubscriber(RuntimePartialEvent::class, contextAware: false)]
final readonly class RuntimeSuccessfulEventHandler
{
    public function __construct(private RuntimeTrace $trace)
    {
    }

    public function __invoke(RuntimePartialEvent $event): void
    {
        ++$this->trace->localEvents;
    }
}

#[EventSubscriber(RuntimePartialEvent::class, contextAware: false)]
final class RuntimeFailingEventHandler
{
    public function __invoke(RuntimePartialEvent $event): void
    {
        throw new RuntimeException(self::class);
    }
}

#[MessageAlias('runtime.async-command')]
final readonly class RuntimeAsyncCommand implements Command
{
}

#[CommandHandler(
    RuntimeAsyncCommand::class,
    flow: 'async',
    bindingId: 'runtime.async-command.execute',
    contextAware: false,
)]
final class RuntimeAsyncCommandHandler
{
    public function __invoke(RuntimeAsyncCommand $command): void
    {
    }
}

final class RuntimeQueuedStrategy implements HandlerExecutionStrategyInterface
{
    public int $executions = 0;

    public function execute(ExecutionRequest $request): HandlerExecutionResultInterface
    {
        ++$this->executions;
        $results = [];
        foreach ($request->bindings as $binding) {
            $envelope = $request->contextForBinding($binding)->envelope();
            $results[] = HandlerResult::success(
                $binding->id,
                $binding->handler->type,
                new PublishedExecution(
                    $binding->id,
                    PublishedExecutionMode::Queued,
                    $envelope->messageId,
                    $envelope->correlationId,
                    'queue-' . $binding->id,
                ),
            );
        }

        return new HandlerExecutionResult(...$results);
    }
}

final class RuntimeMessageIdGenerator implements MessageIdGeneratorInterface
{
    private int $next = 0;

    public function generate(): string
    {
        return 'message-' . ++$this->next;
    }
}

final class RuntimeScopeFactory implements ServiceScopeFactoryInterface
{
    public int $opened = 0;
    public int $disposed = 0;

    /** @param array<string, object> $services */
    public function __construct(private readonly array $services)
    {
    }

    public function openScope(): ServiceScopeInterface
    {
        ++$this->opened;

        return new RuntimeScope($this->services, function (): void {
            ++$this->disposed;
        });
    }
}

final class RuntimeScope implements ServiceScopeInterface
{
    /** @var \Closure(): void */
    private readonly \Closure $onDispose;

    /**
     * @param array<string, object> $services
     * @param callable(): void $onDispose
     */
    public function __construct(
        private readonly array $services,
        callable $onDispose,
    ) {
        $this->onDispose = \Closure::fromCallable($onDispose);
    }

    public function resolve(ServiceReference $reference): object
    {
        $service = $this->services[$reference->id] ?? throw new RuntimeException(sprintf(
            'Runtime service `%s` was not found.',
            $reference->id,
        ));
        if (!$service instanceof $reference->type) {
            throw new RuntimeException(sprintf(
                'Runtime service `%s` must implement `%s`.',
                $reference->id,
                $reference->type,
            ));
        }

        return $service;
    }

    public function dispose(): void
    {
        ($this->onDispose)();
    }
}
