<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Tests;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Amp\NullCancellation;
use DateTimeImmutable;
use Fedor\MessageBus\Context\MessageContextInterface;
use Fedor\MessageBus\Envelope\Envelope;
use Fedor\MessageBus\Envelope\EnvelopeSerializerInterface;
use Fedor\MessageBus\Envelope\SerializedEnvelope;
use Fedor\MessageBus\Execution\ExecutionEnvironment;
use Fedor\MessageBus\Execution\ExecutionOptions;
use Fedor\MessageBus\Execution\ExecutionRequest;
use Fedor\MessageBus\Execution\QueueExecutionStrategy;
use Fedor\MessageBus\Flow\FlowDefinition;
use Fedor\MessageBus\Invoker\CallableInvokerInterface;
use Fedor\MessageBus\Message\Command;
use Fedor\MessageBus\Message\Event;
use Fedor\MessageBus\Message\Query;
use Fedor\MessageBus\Publication\PublishedExecution;
use Fedor\MessageBus\Publication\PublishResult;
use Fedor\MessageBus\Queue\BatchQueueProviderInterface;
use Fedor\MessageBus\Queue\QueueBatchEnqueueResult;
use Fedor\MessageBus\Queue\QueueDeliveryOptions;
use Fedor\MessageBus\Queue\QueueEnqueueFailed;
use Fedor\MessageBus\Queue\QueueEnqueueResult;
use Fedor\MessageBus\Queue\QueueMessage;
use Fedor\MessageBus\Queue\QueueProviderInterface;
use Fedor\MessageBus\Queue\RetryPolicy;
use Fedor\MessageBus\Queue\RetryPolicyRegistryInterface;
use Fedor\MessageBus\Registry\HandlerBinding;
use Fedor\MessageBus\Registry\HandlerInvocationMode;
use Fedor\MessageBus\Registry\HandlerKind;
use Fedor\MessageBus\Service\ServiceReference;
use Fedor\MessageBus\Service\ServiceResolverInterface;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;

final class QueueExecutionStrategyTest extends TestCase
{
    public function testItEnqueuesWithMergedDeliveryAndRetrySnapshot(): void
    {
        $provider = new QueueRecordingProvider();
        $request = $this->request(
            [self::binding(
                'queue.one',
                QueueHandler::class,
                new QueueDeliveryOptions(priority: 5),
            )],
            FlowDefinition::async('async')
                ->transport('postgres', 'mail')
                ->delivery(new QueueDeliveryOptions(priority: 1, delaySeconds: 30, retryPolicy: 'flow-default')),
            new ExecutionOptions(delivery: new QueueDeliveryOptions(delaySeconds: 7, retryPolicy: 'fast')),
            $provider,
            new QueueRetryRegistry(),
        );

        $result = (new QueueExecutionStrategy())->execute($request);

        self::assertFalse($result->hasFailures());
        self::assertCount(1, $provider->messages);
        $message = $provider->messages[0];
        self::assertSame('postgres', $message->transport);
        self::assertSame('mail', $message->queue);
        self::assertSame('queue.one', $message->bindingId);
        self::assertSame(5, $message->priority);
        self::assertSame('fast', $message->retryPolicyKey);
        self::assertSame(['delaySeconds' => 2], $message->retryPolicySnapshot->parameters);
        self::assertSame('2026-09-11T10:00:07+00:00', $message->availableAt->format(DATE_ATOM));
        $published = $result->get('queue.one');
        self::assertInstanceOf(PublishedExecution::class, $published);
        self::assertSame('queue-1', $published->queueMessageId);
    }

    public function testItCapturesSingleEnqueueFailurePerBinding(): void
    {
        $provider = new QueueRecordingProvider(fail: true);
        $result = (new QueueExecutionStrategy())->execute($this->request(
            [self::binding('queue.failure', QueueHandler::class)],
            provider: $provider,
        ));

        self::assertTrue($result->hasFailures());
        $failure = $result->failed()[0]->error();
        self::assertInstanceOf(QueueEnqueueFailed::class, $failure);
        self::assertSame('queue.failure', $failure->queueMessage->bindingId);
        self::assertSame('enqueue failed', $failure->getPrevious()?->getMessage());
    }

    public function testItMapsBatchResultsAndRejectsUnexpectedResultCount(): void
    {
        $provider = new QueueBatchProvider();
        $request = $this->request([
            self::binding('queue.first', QueueHandler::class),
            self::binding('queue.second', QueueOtherHandler::class),
        ], provider: $provider);

        $result = (new QueueExecutionStrategy())->execute($request);

        $first = $result->get('queue.first');
        $second = $result->get('queue.second');
        self::assertInstanceOf(PublishedExecution::class, $first);
        self::assertInstanceOf(PublishedExecution::class, $second);
        self::assertSame('batch-1', $first->queueMessageId);
        self::assertSame('batch-2', $second->queueMessageId);

        $invalid = (new QueueExecutionStrategy())->execute($this->request([
            self::binding('queue.first', QueueHandler::class),
            self::binding('queue.second', QueueOtherHandler::class),
        ], provider: new QueueBatchProvider(resultCount: 1)));
        self::assertTrue($invalid->hasFailures());
        self::assertCount(2, $invalid->failed());
        self::assertSame(
            'Batch queue provider returned unexpected number of enqueue results.',
            $invalid->failed()[0]->error()?->getPrevious()?->getMessage(),
        );
    }

    public function testItRequiresProducerDependenciesAndTransport(): void
    {
        $strategy = new QueueExecutionStrategy();

        try {
            $strategy->execute($this->request(provider: null));
            self::fail('Missing provider must fail.');
        } catch (RuntimeException $error) {
            self::assertSame('Queue provider is required for async flow execution.', $error->getMessage());
        }

        try {
            $strategy->execute($this->request(serializer: null));
            self::fail('Missing serializer must fail.');
        } catch (RuntimeException $error) {
            self::assertSame('Envelope serializer is required for async flow execution.', $error->getMessage());
        }

        try {
            $strategy->execute($this->request(clock: null));
            self::fail('Missing clock must fail.');
        } catch (RuntimeException $error) {
            self::assertSame('Clock is required for async flow execution.', $error->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Async flow `async` has no transport configuration.');
        $strategy->execute($this->request(flow: FlowDefinition::async('async')));
    }

    public function testCustomRetryPolicyRequiresRegistry(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Retry policy `fast` requires retry policy registry.');

        (new QueueExecutionStrategy())->execute($this->request(
            flow: FlowDefinition::async('async')
                ->transport('postgres', 'mail')
                ->delivery(new QueueDeliveryOptions(retryPolicy: 'fast')),
        ));
    }

    public function testCancellationIsPropagatedBeforeSerializationAndEnqueue(): void
    {
        $deferred = new DeferredCancellation();
        $deferred->cancel();
        $provider = new QueueRecordingProvider();

        try {
            (new QueueExecutionStrategy())->execute($this->request(
                provider: $provider,
                cancellation: $deferred->getCancellation(),
            ));
            self::fail('Cancelled producer execution must fail.');
        } catch (\Amp\CancelledException) {
        }

        self::assertSame([], $provider->messages);
    }

    public function testProviderPortKeepsTransactionalOutboxAsAnExternalAdapter(): void
    {
        $provider = new QueueOutboxStyleProvider();

        $result = (new QueueExecutionStrategy())->execute($this->request(
            [self::binding('queue.outbox', QueueHandler::class)],
            provider: $provider,
        ));

        self::assertCount(1, $provider->records);
        self::assertSame('queue.outbox', $provider->records[0]->bindingId);
        self::assertSame('{"schemaVersion":1}', $provider->records[0]->envelope->payload);
        $published = $result->get('queue.outbox');
        self::assertInstanceOf(PublishedExecution::class, $published);
        self::assertSame('outbox-1', $published->queueMessageId);
    }

    /**
     * @param non-empty-list<HandlerBinding>|null $bindings
     */
    private function request(
        ?array $bindings = null,
        ?FlowDefinition $flow = null,
        ?ExecutionOptions $options = null,
        ?QueueProviderInterface $provider = new QueueRecordingProvider(),
        ?RetryPolicyRegistryInterface $retryRegistry = null,
        ?EnvelopeSerializerInterface $serializer = new QueueEnvelopeSerializer(),
        ?ClockInterface $clock = new QueueClock(),
        Cancellation $cancellation = new NullCancellation(),
    ): ExecutionRequest {
        $flow ??= FlowDefinition::async('async')->transport('postgres', 'default');
        $context = new QueueContext(
            new Envelope(new QueueEvent(), 'message-1', 'correlation-1', null),
            $cancellation,
        );

        return new ExecutionRequest(
            $bindings ?? [self::binding('queue.default', QueueHandler::class)],
            $context,
            $flow,
            $options ?? new ExecutionOptions(),
            new ExecutionEnvironment(
                new QueueNoopResolver(),
                new QueueNoopInvoker(),
                $serializer,
                $clock,
                $provider,
                $retryRegistry,
            ),
        );
    }

    /** @param class-string<object> $handler */
    private static function binding(
        string $id,
        string $handler,
        ?QueueDeliveryOptions $delivery = null,
    ): HandlerBinding {
        return new HandlerBinding(
            $id,
            QueueEvent::class,
            HandlerKind::Event,
            ServiceReference::forClass($handler),
            '__invoke',
            'async',
            0,
            false,
            HandlerInvocationMode::Contextless,
            delivery: $delivery,
        );
    }
}

final readonly class QueueEvent implements Event
{
}

final class QueueHandler
{
}

final class QueueOtherHandler
{
}

final class QueueRecordingProvider implements QueueProviderInterface
{
    /** @var list<QueueMessage> */
    public array $messages = [];

    public function __construct(private readonly bool $fail = false)
    {
    }

    #[\Override]
    public function enqueue(QueueMessage $message, Cancellation $cancellation): QueueEnqueueResult
    {
        $cancellation->throwIfRequested();
        $this->messages[] = $message;

        if ($this->fail) {
            throw new LogicException('enqueue failed');
        }

        return new QueueEnqueueResult('queue-' . count($this->messages));
    }
}

final class QueueBatchProvider implements BatchQueueProviderInterface
{
    /** @var list<QueueMessage> */
    public array $messages = [];

    public function __construct(private readonly ?int $resultCount = null)
    {
    }

    #[\Override]
    public function enqueue(QueueMessage $message, Cancellation $cancellation): QueueEnqueueResult
    {
        $this->messages[] = $message;

        return new QueueEnqueueResult('queue-' . count($this->messages));
    }

    #[\Override]
    public function enqueueMany(iterable $messages, Cancellation $cancellation): QueueBatchEnqueueResult
    {
        $cancellation->throwIfRequested();
        $this->messages = is_array($messages) ? array_values($messages) : iterator_to_array($messages, false);
        $count = $this->resultCount ?? count($this->messages);
        $results = [];
        for ($index = 1; $index <= $count; ++$index) {
            $results[] = new QueueEnqueueResult('batch-' . $index);
        }

        return new QueueBatchEnqueueResult(...$results);
    }
}

final class QueueOutboxStyleProvider implements QueueProviderInterface
{
    /** @var list<QueueMessage> */
    public array $records = [];

    public function enqueue(QueueMessage $message, Cancellation $cancellation): QueueEnqueueResult
    {
        $cancellation->throwIfRequested();
        $this->records[] = $message;

        return new QueueEnqueueResult('outbox-' . count($this->records));
    }
}

final class QueueRetryRegistry implements RetryPolicyRegistryInterface
{
    #[\Override]
    public function get(string $key): RetryPolicy
    {
        TestCase::assertSame('fast', $key);

        return RetryPolicy::fixed(5, 2);
    }
}

final class QueueEnvelopeSerializer implements EnvelopeSerializerInterface
{
    #[\Override]
    public function serialize(Envelope $envelope): SerializedEnvelope
    {
        return new SerializedEnvelope('queue.event', '{"schemaVersion":1}');
    }

    #[\Override]
    public function deserialize(SerializedEnvelope $envelope): Envelope
    {
        throw new LogicException('Deserialize is not used by producer strategy fixtures.');
    }
}

final class QueueClock implements ClockInterface
{
    #[\Override]
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-11T10:00:00+00:00');
    }
}

final class QueueNoopInvoker implements CallableInvokerInterface
{
    #[\Override]
    public function invoke(object $service, string $method, array $arguments): mixed
    {
        throw new LogicException('Invoker is not used by producer strategy fixtures.');
    }
}

final class QueueNoopResolver implements ServiceResolverInterface
{
    #[\Override]
    public function resolve(ServiceReference $reference): object
    {
        throw new LogicException('Resolver is not used by producer strategy fixtures.');
    }
}

final readonly class QueueContext implements MessageContextInterface
{
    public function __construct(
        private Envelope $envelope,
        private Cancellation $cancellation,
    ) {
    }

    #[\Override]
    public function cancellation(): Cancellation
    {
        return $this->cancellation;
    }

    #[\Override]
    public function envelope(): Envelope
    {
        return $this->envelope;
    }

    #[\Override]
    public function ask(Query $query, ?ExecutionOptions $options = null): mixed
    {
        throw new LogicException('Nested bus is not used by producer strategy fixtures.');
    }

    #[\Override]
    public function dispatch(Command $command, ?ExecutionOptions $options = null): void
    {
        throw new LogicException('Nested bus is not used by producer strategy fixtures.');
    }

    #[\Override]
    public function publish(Event $event, ?ExecutionOptions $options = null): PublishResult
    {
        throw new LogicException('Nested bus is not used by producer strategy fixtures.');
    }
}
