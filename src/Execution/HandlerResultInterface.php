<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Execution;

/** @template-covariant TResult = mixed */
interface HandlerResultInterface
{
    public function bindingId(): string;

    /** @return class-string */
    public function actionClass(): string;

    /** @return TResult */
    public function result(): mixed;

    public function error(): ?\Throwable;

    public function isSuccessful(): bool;
}
