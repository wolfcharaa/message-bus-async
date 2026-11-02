<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Execution;

use Fedor\MessageBus\Publication\PublishedExecution;
use Fedor\MessageBus\Publication\PublishedExecutionMode;
use Fedor\MessageBus\Queue\BatchQueueProviderInterface;
use Fedor\MessageBus\Queue\QueueBatchEnqueueResult;
use Fedor\MessageBus\Queue\QueueDeliveryOptions;
use Fedor\MessageBus\Queue\QueueEnqueueFailed;
use Fedor\MessageBus\Queue\QueueMessage;
use Fedor\MessageBus\Queue\QueueProviderInterface;
use Fedor\MessageBus\Queue\RetryPolicy;
use Fedor\MessageBus\Queue\RetryPolicyRegistryInterface;
use Fedor\MessageBus\Queue\RetryPolicySnapshot;
use Fedor\MessageBus\Registry\HandlerBinding;
use RuntimeException;

final readonly class QueueExecutionStrategy implements HandlerExecutionStrategyInterface
{
    #[\Override]
    public function execute(ExecutionRequest $request): HandlerExecutionResultInterface
    {
        $provider = $request->environment->queueProvider
            ?? throw new RuntimeException('Queue provider is required for async flow execution.');
        $serializer = $request->environment->envelopeSerializer
            ?? throw new RuntimeException('Envelope serializer is required for async flow execution.');
        $clock = $request->environment->clock
            ?? throw new RuntimeException('Clock is required for async flow execution.');
        $transport = $request->flow->transport
            ?? throw new RuntimeException(sprintf(
                'Async flow `%s` has no transport configuration.',
                $request->flow->key,
            ));
        $cancellation = $request->context->cancellation();
        $cancellation->throwIfRequested();
        $serialized = $serializer->serialize($request->context->envelope());
        $queueMessages = [];
        $bindings = [];

        foreach ($request->bindings as $binding) {
            $delivery = ($request->flow->delivery ?? new QueueDeliveryOptions())
                ->merge($binding->delivery)
                ->merge($request->options->delivery);
            $retryPolicyKey = $delivery->retryPolicy ?? RetryPolicySnapshot::DEFAULT_KEY;
            $retryPolicySnapshot = $this->retryPolicySnapshot(
                $retryPolicyKey,
                $request->environment->retryPolicyRegistry,
            );
            $envelope = $request->contextForBinding($binding)->envelope();

            $queueMessages[] = new QueueMessage(
                $transport->transport,
                $transport->queue,
                $serialized,
                $envelope->messageId,
                $envelope->correlationId,
                $binding->flow,
                $binding->id,
                $clock->now()->modify(sprintf('+%d seconds', $delivery->delaySeconds ?? 0)),
                $delivery->priority ?? 0,
                $retryPolicyKey,
                $retryPolicySnapshot,
            );
            $bindings[] = $binding;
        }

        if ($provider instanceof BatchQueueProviderInterface) {
            return $this->enqueueBatch($provider, $queueMessages, $bindings, $cancellation);
        }

        $results = [];
        foreach ($queueMessages as $index => $queueMessage) {
            $binding = $bindings[$index];
            try {
                $enqueueResult = $provider->enqueue($queueMessage, $cancellation);
                $results[] = HandlerResult::success(
                    $binding->id,
                    $binding->handler->type,
                    $this->publishedExecution($queueMessage, $enqueueResult->queueMessageId),
                );
            } catch (\Throwable $error) {
                $results[] = HandlerResult::failure(
                    $binding->id,
                    $binding->handler->type,
                    new QueueEnqueueFailed($queueMessage, $error),
                );
            }
        }

        return new HandlerExecutionResult(...$results);
    }

    /**
     * @param list<QueueMessage> $queueMessages
     * @param list<HandlerBinding> $bindings
     */
    private function enqueueBatch(
        BatchQueueProviderInterface $provider,
        array $queueMessages,
        array $bindings,
        \Amp\Cancellation $cancellation,
    ): HandlerExecutionResultInterface {
        try {
            $batch = $provider->enqueueMany($queueMessages, $cancellation);
            $enqueueResults = $batch->all();
            $this->assertBatchResultCount($batch, $queueMessages);
        } catch (\Throwable $error) {
            $results = [];
            foreach ($queueMessages as $index => $queueMessage) {
                $binding = $bindings[$index];
                $results[] = HandlerResult::failure(
                    $binding->id,
                    $binding->handler->type,
                    new QueueEnqueueFailed($queueMessage, $error),
                );
            }

            return new HandlerExecutionResult(...$results);
        }

        $results = [];
        foreach ($enqueueResults as $index => $enqueueResult) {
            $binding = $bindings[$index];
            $results[] = HandlerResult::success(
                $binding->id,
                $binding->handler->type,
                $this->publishedExecution($queueMessages[$index], $enqueueResult->queueMessageId),
            );
        }

        return new HandlerExecutionResult(...$results);
    }

    /** @param list<QueueMessage> $queueMessages */
    private function assertBatchResultCount(QueueBatchEnqueueResult $batch, array $queueMessages): void
    {
        if (count($batch->all()) !== count($queueMessages)) {
            throw new RuntimeException('Batch queue provider returned unexpected number of enqueue results.');
        }
    }

    private function retryPolicySnapshot(
        string $key,
        ?RetryPolicyRegistryInterface $registry,
    ): RetryPolicySnapshot {
        if ($registry !== null) {
            return RetryPolicySnapshot::fromPolicy($registry->get($key));
        }

        if ($key !== RetryPolicySnapshot::DEFAULT_KEY) {
            throw new RuntimeException(sprintf(
                'Retry policy `%s` requires retry policy registry.',
                $key,
            ));
        }

        return RetryPolicySnapshot::fromPolicy(RetryPolicy::exponential(3, 30, 2.0, 300));
    }

    private function publishedExecution(QueueMessage $message, string $queueMessageId): PublishedExecution
    {
        return new PublishedExecution(
            $message->bindingId,
            PublishedExecutionMode::Queued,
            $message->messageId,
            $message->correlationId,
            $queueMessageId,
        );
    }
}
