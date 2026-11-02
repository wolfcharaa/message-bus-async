<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Queue;

use DateTimeImmutable;

final readonly class QueueJobStatus
{
    public function __construct(
        public string $queueMessageId,
        public QueueJobState $status,
        public string $messageId,
        public string $correlationId,
        public string $flow,
        public string $bindingId,
        public string $transport,
        public string $queue,
        public int $attempts,
        public int $maxAttempts,
        public DateTimeImmutable $availableAt,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?string $lastError = null,
    ) {
    }
}
