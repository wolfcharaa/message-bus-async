<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Interceptor;

use Closure;
use Fedor\MessageBus\Context\MessageContextInterface;
use InvalidArgumentException;
use LogicException;

/**
 * Per-execution interceptor chain.
 *
 * @template TResult
 * @implements PipelineInterface<TResult>
 */
final class Pipeline implements PipelineInterface
{
    /** @var list<InterceptorInterface> */
    private readonly array $interceptors;

    /** @var Closure(): TResult */
    private readonly Closure $handler;

    private bool $consumed = false;

    /**
     * @param array<array-key, mixed> $interceptors
     * @param callable(): TResult $handler
     */
    public function __construct(
        private readonly MessageContextInterface $context,
        array $interceptors,
        callable $handler,
    ) {
        $this->interceptors = self::validateInterceptors($interceptors);
        $this->handler = Closure::fromCallable($handler);
    }

    #[\Override]
    public function continue(): mixed
    {
        if ($this->consumed) {
            throw new LogicException('The interceptor pipeline continuation has already been used.');
        }

        $this->consumed = true;

        return $this->continueAt(0);
    }

    /** @return TResult */
    private function continueAt(int $position): mixed
    {
        $interceptor = $this->interceptors[$position] ?? null;

        if ($interceptor === null) {
            return ($this->handler)();
        }

        return $interceptor(
            $this->context,
            new PipelineContinuation(fn (): mixed => $this->continueAt($position + 1)),
        );
    }

    /**
     * @param array<array-key, mixed> $interceptors
     * @return list<InterceptorInterface>
     */
    private static function validateInterceptors(array $interceptors): array
    {
        if (!array_is_list($interceptors)) {
            throw new InvalidArgumentException('Pipeline interceptors must be a list.');
        }

        $validated = [];

        foreach ($interceptors as $interceptor) {
            if (!$interceptor instanceof InterceptorInterface) {
                throw new InvalidArgumentException(sprintf(
                    'Pipeline interceptors must implement %s.',
                    InterceptorInterface::class,
                ));
            }

            $validated[] = $interceptor;
        }

        return $validated;
    }
}

/**
 * @template TResult
 * @implements PipelineInterface<TResult>
 */
final class PipelineContinuation implements PipelineInterface
{
    /** @var Closure(): TResult */
    private readonly Closure $next;

    private bool $consumed = false;

    /** @param callable(): TResult $next */
    public function __construct(callable $next)
    {
        $this->next = Closure::fromCallable($next);
    }

    /** @return TResult */
    #[\Override]
    public function continue(): mixed
    {
        if ($this->consumed) {
            throw new LogicException('The interceptor pipeline continuation has already been used.');
        }

        $this->consumed = true;

        return ($this->next)();
    }
}
