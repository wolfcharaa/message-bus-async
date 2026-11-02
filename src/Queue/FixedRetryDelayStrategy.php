<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Queue;

final readonly class FixedRetryDelayStrategy implements RetryDelayStrategy
{
    public function __construct(private int $delaySeconds)
    {
    }

    #[\Override]
    public function delaySeconds(int $attempt): int
    {
        return $this->delaySeconds;
    }

    public function delaySecondsValue(): int
    {
        return $this->delaySeconds;
    }
}
