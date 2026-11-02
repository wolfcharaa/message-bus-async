<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Invoker;

interface CallableInvokerInterface
{
    /** @param array<int, mixed> $arguments */
    public function invoke(object $service, string $method, array $arguments): mixed;
}
