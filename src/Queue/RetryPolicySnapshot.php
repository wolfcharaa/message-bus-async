<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Queue;

final readonly class RetryPolicySnapshot
{
    public const DEFAULT_KEY = 'default';

    /** @param array<string, int|float|string|null> $parameters */
    public function __construct(
        public int $maxAttempts = 3,
        public string $strategy = 'exponential',
        public array $parameters = [
            'initialDelaySeconds' => 30,
            'multiplier' => 2.0,
            'maxDelaySeconds' => 300,
        ],
    ) {
    }

    public static function default(): self
    {
        return new self();
    }

    public static function fromPolicy(RetryPolicy $policy): self
    {
        $strategy = $policy->delayStrategy;

        if ($strategy instanceof FixedRetryDelayStrategy) {
            return new self(
                $policy->maxAttempts,
                'fixed',
                ['delaySeconds' => $strategy->delaySecondsValue()],
            );
        }

        if ($strategy instanceof ExponentialRetryDelayStrategy) {
            return new self(
                $policy->maxAttempts,
                'exponential',
                [
                    'initialDelaySeconds' => $strategy->initialDelaySeconds(),
                    'multiplier' => $strategy->multiplier(),
                    'maxDelaySeconds' => $strategy->maxDelaySeconds(),
                ],
            );
        }

        return new self($policy->maxAttempts, $strategy::class, []);
    }

    /** @return array{maxAttempts: int, strategy: string, parameters: array<string, int|float|string|null>} */
    public function toArray(): array
    {
        return [
            'maxAttempts' => $this->maxAttempts,
            'strategy' => $this->strategy,
            'parameters' => $this->parameters,
        ];
    }
}
