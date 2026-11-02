<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Queue;

use DateTimeImmutable;

final readonly class QueueEnqueueResult
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $queueMessageId,
        public ?string $backendId = null,
        public ?QueueJobState $status = null,
        public ?DateTimeImmutable $createdAt = null,
        public array $metadata = [],
    ) {
    }
}
