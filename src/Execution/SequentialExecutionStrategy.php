<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Execution;

use Fedor\MessageBus\Context\MessageContextInterface;
use Fedor\MessageBus\Interceptor\InterceptorInterface;
use Fedor\MessageBus\Interceptor\Pipeline;
use Fedor\MessageBus\Registry\HandlerBinding;
use Fedor\MessageBus\Registry\HandlerInvocationMode;
use Fedor\MessageBus\Service\ServiceReference;
use RuntimeException;

final readonly class SequentialExecutionStrategy implements HandlerExecutionStrategyInterface
{
    public function __construct(private bool $failFast = true)
    {
    }

    #[\Override]
    public function execute(ExecutionRequest $request): HandlerExecutionResultInterface
    {
        $results = [];
        $bindings = $request->bindings;
        usort($bindings, static fn (HandlerBinding $left, HandlerBinding $right): int => $right->priority <=> $left->priority);

        foreach ($bindings as $binding) {
            try {
                $context = $request->contextForBinding($binding);
                $context->cancellation()->throwIfRequested();
                $handler = $request->services->resolve($binding->handler);
                $interceptors = $this->interceptors($request, $binding);
                $pipeline = new Pipeline(
                    $context,
                    $interceptors,
                    fn (): mixed => $request->invoker->invoke(
                        $handler,
                        $binding->method,
                        $this->arguments($binding, $context),
                    ),
                );
                $results[] = HandlerResult::success(
                    $binding->id,
                    $binding->handler->type,
                    $pipeline->continue(),
                );
            } catch (\Throwable $error) {
                if ($this->failFast) {
                    throw $error;
                }

                $results[] = HandlerResult::failure(
                    $binding->id,
                    $binding->handler->type,
                    $error,
                );
            }
        }

        return new HandlerExecutionResult(...$results);
    }

    /** @return list<InterceptorInterface> */
    private function interceptors(ExecutionRequest $request, HandlerBinding $binding): array
    {
        $references = [
            ...array_map(
                static fn (string $class): ServiceReference => ServiceReference::forClass($class),
                $request->flow->middleware,
            ),
            ...$binding->interceptors,
        ];
        $interceptors = [];

        foreach ($references as $reference) {
            $interceptor = $request->services->resolve($reference);
            if (!$interceptor instanceof InterceptorInterface) {
                throw new RuntimeException(sprintf(
                    'Resolved interceptor `%s` must implement %s.',
                    $reference->id,
                    InterceptorInterface::class,
                ));
            }

            $interceptors[] = $interceptor;
        }

        return $interceptors;
    }

    /** @return list<object> */
    private function arguments(HandlerBinding $binding, MessageContextInterface $context): array
    {
        $arguments = [$context->envelope()->message];
        if ($binding->invocationMode === HandlerInvocationMode::ContextAware) {
            $arguments[] = $context;
        }

        return $arguments;
    }
}
