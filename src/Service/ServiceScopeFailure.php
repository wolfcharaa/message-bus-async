<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Service;

use RuntimeException;
use Throwable;

final class ServiceScopeFailure extends RuntimeException
{
    /** @var non-empty-list<Throwable> */
    private readonly array $cleanupFailures;

    public function __construct(
        private readonly ?Throwable $primaryFailure,
        Throwable $cleanupFailure,
        Throwable ...$additionalCleanupFailures,
    ) {
        $cleanupFailures = [$cleanupFailure];
        foreach ($additionalCleanupFailures as $additionalCleanupFailure) {
            $cleanupFailures[] = $additionalCleanupFailure;
        }
        $this->cleanupFailures = $cleanupFailures;

        parent::__construct(
            $this->primaryFailure === null
                ? 'Service scope cleanup failed.'
                : 'Service scope cleanup failed after execution failure.',
            previous: $this->primaryFailure ?? $this->cleanupFailures[0],
        );
    }

    public function primaryFailure(): ?Throwable
    {
        return $this->primaryFailure;
    }

    /** @return non-empty-list<Throwable> */
    public function cleanupFailures(): array
    {
        return $this->cleanupFailures;
    }
}
