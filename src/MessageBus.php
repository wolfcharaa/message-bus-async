<?php

declare(strict_types=1);

namespace Fedor\MessageBus;

use Fedor\MessageBus\Context\MessageContextFactoryInterface;
use Fedor\MessageBus\Context\MessageContextInterface;
use Fedor\MessageBus\Context\NestedMessageBus;
use Fedor\MessageBus\Envelope\Envelope;
use Fedor\MessageBus\Envelope\EnvelopeFactory;
use Fedor\MessageBus\Envelope\EnvelopeSerializerInterface;
use Fedor\MessageBus\Execution\ExecutionEnvironment;
use Fedor\MessageBus\Execution\ExecutionOptions;
use Fedor\MessageBus\Execution\ExecutionRequest;
use Fedor\MessageBus\Execution\HandlerExecutionResult;
use Fedor\MessageBus\Execution\HandlerExecutionResultInterface;
use Fedor\MessageBus\Execution\HandlerExecutionStrategyInterface;
use Fedor\MessageBus\Execution\HandlerResult;
use Fedor\MessageBus\Execution\SequentialExecutionStrategy;
use Fedor\MessageBus\Flow\FlowDefinition;
use Fedor\MessageBus\Identity\RandomMessageIdGenerator;
use Fedor\MessageBus\Invoker\CallableInvokerInterface;
use Fedor\MessageBus\Invoker\ReflectionCallableInvoker;
use Fedor\MessageBus\Message\Command;
use Fedor\MessageBus\Message\Event;
use Fedor\MessageBus\Message\Query;
use Fedor\MessageBus\Publication\PublishFailed;
use Fedor\MessageBus\Publication\PublishFailure;
use Fedor\MessageBus\Publication\PublishedExecution;
use Fedor\MessageBus\Publication\PublishedExecutionMode;
use Fedor\MessageBus\Publication\PublishResult;
use Fedor\MessageBus\Queue\QueueEnqueueFailed;
use Fedor\MessageBus\Queue\QueueProviderInterface;
use Fedor\MessageBus\Queue\RetryPolicyRegistryInterface;
use Fedor\MessageBus\Registry\BindingNotFound;
use Fedor\MessageBus\Registry\CompiledMessageRegistry;
use Fedor\MessageBus\Registry\HandlerBinding;
use Fedor\MessageBus\Registry\HandlerInvocationMode;
use Fedor\MessageBus\Registry\HandlerKind;
use Fedor\MessageBus\Service\ServiceReference;
use Fedor\MessageBus\Service\ServiceScopeFactoryInterface;
use Fedor\MessageBus\Service\ServiceScopeInterface;
use Fedor\MessageBus\Service\ServiceScopeRunner;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Throwable;

final class MessageBus implements MessageBusInterface
{
    private readonly ServiceScopeRunner $scopeRunner;
    private readonly EnvelopeFactory $envelopeFactory;
    private readonly CallableInvokerInterface $invoker;

    public function __construct(
        private readonly CompiledMessageRegistry $registry,
        ServiceScopeFactoryInterface $scopeFactory,
        ?EnvelopeFactory $envelopeFactory = null,
        ?CallableInvokerInterface $invoker = null,
        private readonly ?EnvelopeSerializerInterface $envelopeSerializer = null,
        private readonly ?ClockInterface $clock = null,
        private readonly ?QueueProviderInterface $queueProvider = null,
        private readonly ?RetryPolicyRegistryInterface $retryPolicyRegistry = null,
    ) {
        $this->scopeRunner = new ServiceScopeRunner($scopeFactory);
        $this->envelopeFactory = $envelopeFactory ?? new EnvelopeFactory(new RandomMessageIdGenerator());
        $this->invoker = $invoker ?? new ReflectionCallableInvoker();
    }

    #[\Override]
    public function ask(Query $query, ?ExecutionOptions $options = null): mixed
    {
        return $this->scopeRunner->run(
            fn (ServiceScopeInterface $scope): mixed => $this->askInScope($query, $options, $scope),
        );
    }

    #[\Override]
    public function dispatch(Command $command, ?ExecutionOptions $options = null): void
    {
        $this->scopeRunner->run(function (ServiceScopeInterface $scope) use ($command, $options): void {
            $this->dispatchInScope($command, $options, $scope);
        });
    }

    #[\Override]
    public function publish(Event $event, ?ExecutionOptions $options = null): PublishResult
    {
        return $this->scopeRunner->run(
            fn (ServiceScopeInterface $scope): PublishResult => $this->publishInScope($event, $options, $scope),
        );
    }

    /**
     * @param Query<mixed> $query
     * @return mixed
     */
    private function askInScope(
        Query $query,
        ?ExecutionOptions $options,
        ServiceScopeInterface $scope,
        ?Envelope $parent = null,
    ): mixed {
        $bindings = array_values(array_filter(
            $this->registry->bindingsForMessage($query),
            fn (HandlerBinding $binding): bool => $binding->kind === HandlerKind::Query
                && $this->flowIsSync($binding),
        ));
        if (count($bindings) !== 1) {
            throw new BindingNotFound(sprintf(
                'Query `%s` must have exactly one sync binding; %d found.',
                $query::class,
                count($bindings),
            ));
        }

        $options ??= new ExecutionOptions();
        $envelope = $this->envelope($query, $options, $parent);
        $result = $this->executeBindings($bindings, $envelope, $options, $scope);

        return $result->get($bindings[0]->id);
    }

    private function dispatchInScope(
        Command $command,
        ?ExecutionOptions $options,
        ServiceScopeInterface $scope,
        ?Envelope $parent = null,
    ): void {
        $commandBindings = array_values(array_filter(
            $this->registry->bindingsForMessage($command),
            static fn (HandlerBinding $binding): bool => $binding->kind === HandlerKind::Command,
        ));
        $sync = array_values(array_filter(
            $commandBindings,
            fn (HandlerBinding $binding): bool => $binding->primary && $this->flowIsSync($binding),
        ));
        $async = array_values(array_filter(
            $commandBindings,
            fn (HandlerBinding $binding): bool => !$this->flowIsSync($binding),
        ));
        if (count($sync) > 1) {
            throw new BindingNotFound(sprintf(
                'Command `%s` has more than one primary sync binding.',
                $command::class,
            ));
        }
        if ($sync === [] && $async === []) {
            throw new BindingNotFound(sprintf(
                'Command `%s` has no primary sync or async bindings.',
                $command::class,
            ));
        }

        $options ??= new ExecutionOptions();
        $envelope = $this->envelope($command, $options, $parent);
        if ($sync !== []) {
            $this->throwOnExecutionFailure($this->executeBindings($sync, $envelope, $options, $scope));
        }
        if ($async !== []) {
            $this->publishBindings($async, $envelope, $options, $scope);
        }
    }

    private function publishInScope(
        Event $event,
        ?ExecutionOptions $options,
        ServiceScopeInterface $scope,
        ?Envelope $parent = null,
    ): PublishResult {
        $bindings = array_values(array_filter(
            $this->registry->bindingsForMessage($event),
            static fn (HandlerBinding $binding): bool => $binding->kind === HandlerKind::Event,
        ));
        if ($bindings === []) {
            return PublishResult::empty();
        }

        $options ??= new ExecutionOptions();
        $envelope = $this->envelope($event, $options, $parent);

        return $this->publishBindings($bindings, $envelope, $options, $scope);
    }

    /**
     * @param non-empty-list<HandlerBinding> $bindings
     */
    private function executeBindings(
        array $bindings,
        Envelope $envelope,
        ExecutionOptions $options,
        ServiceScopeInterface $scope,
    ): HandlerExecutionResultInterface {
        $result = new HandlerExecutionResult();
        foreach ($this->groupByFlow($bindings) as $flowKey => $flowBindings) {
            $flow = $this->registry->compiled()->flows->get($flowKey);
            if ($this->canExecuteDirectly($flow, $flowBindings)) {
                $flowResult = $this->executeDirectly($flowBindings, $envelope, $options, $scope);
            } else {
                $context = $this->createContext($flow, $envelope, $options, $scope);
                $strategy = $this->strategy($flow->strategy, $scope);
                $flowResult = $strategy->execute(new ExecutionRequest(
                    $flowBindings,
                    $context,
                    $flow,
                    $options,
                    $this->environment($scope),
                ));
            }

            $result = new HandlerExecutionResult(...$result->all(), ...$flowResult->all());
        }

        return $result;
    }

    /** @param non-empty-list<HandlerBinding> $bindings */
    private function executeDirectly(
        array $bindings,
        Envelope $envelope,
        ExecutionOptions $options,
        ServiceScopeInterface $scope,
    ): HandlerExecutionResultInterface {
        $results = [];
        foreach ($this->sortBindingsByPriority($bindings) as $binding) {
            $options->cancellation->throwIfRequested();
            $handler = $scope->resolve($binding->handler);
            $results[] = HandlerResult::success(
                $binding->id,
                $binding->handler->type,
                $this->invoker->invoke($handler, $binding->method, [$envelope->message]),
            );
        }

        return new HandlerExecutionResult(...$results);
    }

    /** @param non-empty-list<HandlerBinding> $bindings */
    private function canExecuteDirectly(FlowDefinition $flow, array $bindings): bool
    {
        if ($flow->strategy !== SequentialExecutionStrategy::class || $flow->middleware !== []) {
            return false;
        }

        foreach ($bindings as $binding) {
            if ($binding->invocationMode !== HandlerInvocationMode::Contextless || $binding->interceptors !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param non-empty-list<HandlerBinding> $bindings
     * @return non-empty-list<HandlerBinding>
     */
    private function sortBindingsByPriority(array $bindings): array
    {
        usort(
            $bindings,
            static fn (HandlerBinding $left, HandlerBinding $right): int => $right->priority <=> $left->priority,
        );

        return $bindings;
    }

    /**
     * @param non-empty-list<HandlerBinding> $bindings
     */
    private function publishBindings(
        array $bindings,
        Envelope $envelope,
        ExecutionOptions $options,
        ServiceScopeInterface $scope,
    ): PublishResult {
        $executions = [];
        $failures = [];
        $previous = null;

        foreach ($this->groupByFlow($bindings) as $flowKey => $flowBindings) {
            $flow = $this->registry->compiled()->flows->get($flowKey);
            if ($flow->isSync()) {
                foreach ($this->sortBindingsByPriority($flowBindings) as $binding) {
                    try {
                        $execution = $this->executeBindings([$binding], $envelope, $options, $scope);
                        foreach ($execution->all() as $handlerResult) {
                            if ($handlerResult->isSuccessful()) {
                                $executions[] = new PublishedExecution(
                                    $binding->id,
                                    PublishedExecutionMode::Local,
                                    $envelope->messageId,
                                    $envelope->correlationId,
                                );
                                continue;
                            }

                            $error = $handlerResult->error() ?? new RuntimeException('Local execution failed.');
                            $previous ??= $error;
                            $failures[] = $this->publishFailure($binding, $envelope, $error);
                        }
                    } catch (Throwable $error) {
                        $previous ??= $error;
                        $failures[] = $this->publishFailure($binding, $envelope, $error);
                    }
                }

                continue;
            }

            try {
                $execution = $this->executeBindings($flowBindings, $envelope, $options, $scope);
                foreach ($execution->all() as $handlerResult) {
                    $binding = $this->registry->binding($handlerResult->bindingId());
                    if (!$handlerResult->isSuccessful()) {
                        $error = $handlerResult->error() ?? new RuntimeException('Queued execution failed.');
                        $previous ??= $error;
                        $failures[] = $this->publishFailure($binding, $envelope, $error);
                        continue;
                    }

                    $published = $handlerResult->result();
                    if (!$published instanceof PublishedExecution) {
                        $error = new RuntimeException(sprintf(
                            'Async strategy for flow `%s` must return PublishedExecution results.',
                            $flow->key,
                        ));
                        $previous ??= $error;
                        $failures[] = $this->publishFailure($binding, $envelope, $error);
                        continue;
                    }

                    $executions[] = $published;
                }
            } catch (Throwable $error) {
                $previous ??= $error;
                foreach ($flowBindings as $binding) {
                    $failures[] = $this->publishFailure($binding, $envelope, $error);
                }
            }
        }

        $result = new PublishResult($executions, $failures);
        if ($result->hasFailures()) {
            throw new PublishFailed($result, $previous);
        }

        return $result;
    }

    private function createContext(
        FlowDefinition $flow,
        Envelope $envelope,
        ExecutionOptions $options,
        ServiceScopeInterface $scope,
    ): MessageContextInterface {
        $factoryId = $flow->contextFactory ?? throw new RuntimeException(sprintf(
            'Flow `%s` has no context factory.',
            $flow->key,
        ));
        $factory = $scope->resolve(new ServiceReference($factoryId, MessageContextFactoryInterface::class));

        $nestedBus = new NestedMessageBus(
            fn (Query $query, ?ExecutionOptions $nestedOptions): mixed => $this->askInScope(
                $query,
                $nestedOptions,
                $scope,
                $envelope,
            ),
            function (Command $command, ?ExecutionOptions $nestedOptions) use ($scope, $envelope): void {
                $this->dispatchInScope($command, $nestedOptions, $scope, $envelope);
            },
            fn (Event $event, ?ExecutionOptions $nestedOptions): PublishResult => $this->publishInScope(
                $event,
                $nestedOptions,
                $scope,
                $envelope,
            ),
        );
        $context = $factory->create($nestedBus, $envelope, $flow, $options->cancellation);
        if (!$context instanceof $flow->contextInterface) {
            throw new RuntimeException(sprintf(
                'Flow `%s` context factory returned `%s`, expected `%s`.',
                $flow->key,
                $context::class,
                $flow->contextInterface,
            ));
        }

        return $context;
    }

    private function strategy(string $strategyId, ServiceScopeInterface $scope): HandlerExecutionStrategyInterface
    {
        return $scope->resolve(new ServiceReference($strategyId, HandlerExecutionStrategyInterface::class));
    }

    private function environment(ServiceScopeInterface $scope): ExecutionEnvironment
    {
        return new ExecutionEnvironment(
            $scope,
            $this->invoker,
            $this->envelopeSerializer,
            $this->clock,
            $this->queueProvider,
            $this->retryPolicyRegistry,
        );
    }

    /** @param Query<mixed>|Command|Event $message */
    private function envelope(
        Query|Command|Event $message,
        ExecutionOptions $options,
        ?Envelope $parent,
    ): Envelope {
        return $parent === null
            ? $this->envelopeFactory->createRoot($message, $options)
            : $this->envelopeFactory->createNested($message, $parent, $options);
    }

    private function flowIsSync(HandlerBinding $binding): bool
    {
        return $this->registry->compiled()->flows->get($binding->flow)->isSync();
    }

    /**
     * @param non-empty-list<HandlerBinding> $bindings
     * @return array<string, non-empty-list<HandlerBinding>>
     */
    private function groupByFlow(array $bindings): array
    {
        $grouped = [];
        foreach ($bindings as $binding) {
            $grouped[$binding->flow][] = $binding;
        }

        return $grouped;
    }

    private function throwOnExecutionFailure(HandlerExecutionResultInterface $result): void
    {
        foreach ($result->failed() as $failure) {
            $error = $failure->error();
            if ($error !== null) {
                throw $error;
            }
        }
    }

    private function publishFailure(
        HandlerBinding $binding,
        Envelope $envelope,
        Throwable $error,
    ): PublishFailure {
        $mode = $this->flowIsSync($binding)
            ? PublishedExecutionMode::Local
            : PublishedExecutionMode::Queued;
        if ($error instanceof QueueEnqueueFailed) {
            $queueMessage = $error->queueMessage;

            return PublishFailure::fromThrowable(
                $error->getPrevious() ?? $error,
                $binding->id,
                $mode,
                $queueMessage->messageId,
                $queueMessage->correlationId,
            );
        }

        return PublishFailure::fromThrowable(
            $error,
            $binding->id,
            $mode,
            $envelope->messageId,
            $envelope->correlationId,
        );
    }
}
