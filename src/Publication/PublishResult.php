<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Publication;

use InvalidArgumentException;

final readonly class PublishResult
{
    /**
     * @param list<PublishedExecution> $executions
     * @param list<PublishFailure> $failures
     */
    public function __construct(
        private array $executions = [],
        private array $failures = [],
    ) {
        self::assertListOf($this->executions, PublishedExecution::class, 'executions');
        self::assertListOf($this->failures, PublishFailure::class, 'failures');
    }

    public static function empty(): self
    {
        return new self();
    }

    /** @return list<PublishedExecution> */
    public function executions(): array
    {
        return $this->executions;
    }

    /** @return list<PublishFailure> */
    public function failures(): array
    {
        return $this->failures;
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }

    public function isEmpty(): bool
    {
        return $this->executions === [] && $this->failures === [];
    }

    public function merge(self $result): self
    {
        return new self(
            [...$this->executions, ...$result->executions],
            [...$this->failures, ...$result->failures],
        );
    }

    /**
     * @param array<array-key, mixed> $items
     * @param class-string $expectedClass
     */
    private static function assertListOf(array $items, string $expectedClass, string $name): void
    {
        if (!array_is_list($items)) {
            throw new InvalidArgumentException(sprintf('Publish result %s must be a list.', $name));
        }

        foreach ($items as $item) {
            if (!$item instanceof $expectedClass) {
                throw new InvalidArgumentException(sprintf(
                    'Publish result %s must contain only %s instances.',
                    $name,
                    $expectedClass,
                ));
            }
        }
    }
}
