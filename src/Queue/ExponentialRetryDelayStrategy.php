<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Queue;

final readonly class ExponentialRetryDelayStrategy implements RetryDelayStrategy
{
    public function __construct(
        private int $initialDelaySeconds,
        private float $multiplier = 2.0,
        private ?int $maxDelaySeconds = null,
    ) {
    }

    #[\Override]
    public function delaySeconds(int $attempt): int
    {
        $attempt = max(1, $attempt);
        $delay = (int) round($this->initialDelaySeconds * ($this->multiplier ** ($attempt - 1)));

        return $this->maxDelaySeconds === null ? $delay : min($delay, $this->maxDelaySeconds);
    }

    public function initialDelaySeconds(): int
    {
        return $this->initialDelaySeconds;
    }

    public function multiplier(): float
    {
        return $this->multiplier;
    }

    public function maxDelaySeconds(): ?int
    {
        return $this->maxDelaySeconds;
    }
}
