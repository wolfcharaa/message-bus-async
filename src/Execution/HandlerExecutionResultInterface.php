<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Execution;

use BackedEnum;

interface HandlerExecutionResultInterface
{
    /** @return list<HandlerResultInterface<mixed>> */
    public function all(): array;

    public function get(string|BackedEnum $bindingId): mixed;

    /** @param class-string $actionClass */
    public function getByAction(string $actionClass): mixed;

    /** @return list<HandlerResultInterface<mixed>> */
    public function successful(): array;

    /** @return list<HandlerResultInterface<mixed>> */
    public function failed(): array;

    public function hasFailures(): bool;
}
