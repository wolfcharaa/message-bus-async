<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Execution;

/**
 * @template-covariant TResult = mixed
 * @implements HandlerResultInterface<TResult>
 */
final readonly class HandlerResult implements HandlerResultInterface
{
    /**
     * @param class-string $actionClass
     * @param TResult $result
     */
    private function __construct(
        private string $bindingId,
        private string $actionClass,
        private mixed $result = null,
        private ?\Throwable $error = null,
    ) {
    }

    /**
     * @template TSuccess
     * @param class-string $actionClass
     * @param TSuccess $result
     * @return self<TSuccess>
     */
    public static function success(string $bindingId, string $actionClass, mixed $result): self
    {
        return new self($bindingId, $actionClass, $result);
    }

    /** @param class-string $actionClass */
    public static function failure(string $bindingId, string $actionClass, \Throwable $error): self
    {
        return new self($bindingId, $actionClass, error: $error);
    }

    #[\Override]
    public function bindingId(): string
    {
        return $this->bindingId;
    }

    #[\Override]
    public function actionClass(): string
    {
        return $this->actionClass;
    }

    #[\Override]
    public function result(): mixed
    {
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->result;
    }

    #[\Override]
    public function error(): ?\Throwable
    {
        return $this->error;
    }

    #[\Override]
    public function isSuccessful(): bool
    {
        return $this->error === null;
    }
}
