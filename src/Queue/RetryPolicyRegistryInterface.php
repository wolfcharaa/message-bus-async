<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Queue;

interface RetryPolicyRegistryInterface
{
    public function get(string $key): RetryPolicy;
}
