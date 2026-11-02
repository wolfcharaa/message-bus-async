<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Tests;

use Amp\Cancellation;
use Amp\NullCancellation;
use Fedor\MessageBus\Context\MessageContextInterface;
use Fedor\MessageBus\Envelope\Envelope;
use Fedor\MessageBus\Execution\ExecutionOptions;
use Fedor\MessageBus\Execution\ExecutionRequest;
use Fedor\MessageBus\Execution\SequentialExecutionStrategy;
use Fedor\MessageBus\Flow\FlowDefinition;
use Fedor\MessageBus\Interceptor\InterceptorInterface;
use Fedor\MessageBus\Interceptor\PipelineInterface;
use Fedor\MessageBus\Invoker\ReflectionCallableInvoker;
use Fedor\MessageBus\Message\Command;
use Fedor\MessageBus\Message\Event;
use Fedor\MessageBus\Message\Query;
use Fedor\MessageBus\Publication\PublishResult;
use Fedor\MessageBus\Registry\HandlerBinding;
use Fedor\MessageBus\Registry\HandlerInvocationMode;
use Fedor\MessageBus\Registry\HandlerKind;
use Fedor\MessageBus\Service\ServiceReference;
use Fedor\MessageBus\Service\ServiceResolverInterface;
use LogicException;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;

final class SequentialExecutionStrategyTest extends TestCase
{
    protected function setUp(): void
    {
        StrategyRecorder::$events = [];
    }

    public function testItExecutesBindingsByPriorityThroughFlowAndBindingInterceptors(): void
    {
        $high = self::binding('query.high', StrategyHighHandler::class, 20, [
            ServiceReference::forClass(StrategyBindingInterceptor::class),
        ]);
        $low = self::binding('query.low', StrategyLowHandler::class, 10);
        $resolver = new StrategyResolver([
            StrategyHighHandler::class => new StrategyHighHandler(),
            StrategyLowHandler::class => new StrategyLowHandler(),
            StrategyFlowInterceptor::class => new StrategyFlowInterceptor(),
            StrategyBindingInterceptor::class => new StrategyBindingInterceptor(),
        ]);
        $context = new StrategyContext(self::envelope());
        $request = new ExecutionRequest(
            [$low, $high],
            $context,
            FlowDefinition::sync('default')->middleware(StrategyFlowInterceptor::class),
            new ExecutionOptions(),
            $resolver,
            new ReflectionCallableInvoker(),
        );

        $result = (new SequentialExecutionStrategy())->execute($request);

        self::assertSame('high', $result->get('query.high'));
        self::assertSame('low', $result->getByAction(StrategyLowHandler::class));
        self::assertFalse($result->hasFailures());
        self::assertSame([
            'flow.before',
            'binding.before',
            'high',
            'binding.after',
            'flow.after',
            'flow.before',
            'low',
            'flow.after',
        ], StrategyRecorder::$events);
    }

    public function testItCanCollectFailuresWithoutStoppingRemainingBindings(): void
    {
        $failure = self::binding('query.failure', StrategyFailingHandler::class, 20);
        $success = self::binding('query.low', StrategyLowHandler::class, 10);
        $resolver = new StrategyResolver([
            StrategyFailingHandler::class => new StrategyFailingHandler(),
            StrategyLowHandler::class => new StrategyLowHandler(),
        ]);
        $request = new ExecutionRequest(
            [$success, $failure],
            new StrategyContext(self::envelope()),
            FlowDefinition::sync('default'),
            new ExecutionOptions(),
            $resolver,
            new ReflectionCallableInvoker(),
        );

        $result = (new SequentialExecutionStrategy(failFast: false))->execute($request);

        self::assertTrue($result->hasFailures());
        self::assertCount(1, $result->failed());
        self::assertCount(1, $result->successful());
        self::assertSame('low', $result->get('query.low'));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('handler failed');
        $result->get('query.failure');
    }

    public function testMissingResultKeepsV6OutOfBoundsContract(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('Handler result for binding `missing` was not found.');

        $request = new ExecutionRequest(
            [self::binding('query.low', StrategyLowHandler::class, 0)],
            new StrategyContext(self::envelope()),
            FlowDefinition::sync('default'),
            new ExecutionOptions(),
            new StrategyResolver([StrategyLowHandler::class => new StrategyLowHandler()]),
            new ReflectionCallableInvoker(),
        );
        (new SequentialExecutionStrategy())->execute($request)->get('missing');
    }

    /**
     * @param class-string $handler
     * @param list<ServiceReference<InterceptorInterface>> $interceptors
     */
    private static function binding(
        string $id,
        string $handler,
        int $priority,
        array $interceptors = [],
    ): HandlerBinding {
        return new HandlerBinding(
            $id,
            StrategyQuery::class,
            HandlerKind::Query,
            ServiceReference::forClass($handler),
            '__invoke',
            'default',
            $priority,
            true,
            HandlerInvocationMode::ContextAware,
            $interceptors,
        );
    }

    private static function envelope(): Envelope
    {
        return new Envelope(new StrategyQuery(), 'message-1', 'correlation-1', null);
    }
}

/** @implements Query<string> */
final readonly class StrategyQuery implements Query
{
}

final class StrategyRecorder
{
    /** @var list<string> */
    public static array $events = [];
}

final class StrategyHighHandler
{
    public function __invoke(StrategyQuery $query, MessageContextInterface $context): string
    {
        StrategyRecorder::$events[] = 'high';

        return 'high';
    }
}

final class StrategyLowHandler
{
    public function __invoke(StrategyQuery $query, MessageContextInterface $context): string
    {
        StrategyRecorder::$events[] = 'low';

        return 'low';
    }
}

final class StrategyFailingHandler
{
    public function __invoke(StrategyQuery $query, MessageContextInterface $context): string
    {
        throw new LogicException('handler failed');
    }
}

final class StrategyFlowInterceptor implements InterceptorInterface
{
    #[\Override]
    public function __invoke(MessageContextInterface $context, PipelineInterface $pipeline): mixed
    {
        StrategyRecorder::$events[] = 'flow.before';
        $result = $pipeline->continue();
        StrategyRecorder::$events[] = 'flow.after';

        return $result;
    }
}

final class StrategyBindingInterceptor implements InterceptorInterface
{
    #[\Override]
    public function __invoke(MessageContextInterface $context, PipelineInterface $pipeline): mixed
    {
        StrategyRecorder::$events[] = 'binding.before';
        $result = $pipeline->continue();
        StrategyRecorder::$events[] = 'binding.after';

        return $result;
    }
}

final readonly class StrategyContext implements MessageContextInterface
{
    public function __construct(private Envelope $envelope)
    {
    }

    #[\Override]
    public function cancellation(): Cancellation
    {
        return new NullCancellation();
    }

    #[\Override]
    public function envelope(): Envelope
    {
        return $this->envelope;
    }

    #[\Override]
    public function ask(Query $query, ?ExecutionOptions $options = null): mixed
    {
        throw new LogicException('Nested bus is not used by this fixture.');
    }

    #[\Override]
    public function dispatch(Command $command, ?ExecutionOptions $options = null): void
    {
        throw new LogicException('Nested bus is not used by this fixture.');
    }

    #[\Override]
    public function publish(Event $event, ?ExecutionOptions $options = null): PublishResult
    {
        throw new LogicException('Nested bus is not used by this fixture.');
    }
}

final readonly class StrategyResolver implements ServiceResolverInterface
{
    /** @param array<string, object> $services */
    public function __construct(private array $services)
    {
    }

    #[\Override]
    public function resolve(ServiceReference $reference): object
    {
        $service = $this->services[$reference->id] ?? throw new LogicException(sprintf(
            'Service `%s` was not registered.',
            $reference->id,
        ));

        if (!is_a($service, $reference->type)) {
            throw new LogicException(sprintf(
                'Service `%s` must implement `%s`.',
                $reference->id,
                $reference->type,
            ));
        }

        return $service;
    }
}
