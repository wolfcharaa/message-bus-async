<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Queue;

use DateTimeImmutable;
use Fedor\MessageBus\Envelope\SerializedEnvelope;

final readonly class QueueMessage
{
    public function __construct(
        public string $transport,
        public string $queue,
        public SerializedEnvelope $envelope,
        public string $messageId,
        public string $correlationId,
        public string $flow,
        public string $bindingId,
        public DateTimeImmutable $availableAt,
        public int $priority = 0,
        public string $retryPolicyKey = RetryPolicySnapshot::DEFAULT_KEY,
        public RetryPolicySnapshot $retryPolicySnapshot = new RetryPolicySnapshot(),
    ) {
    }
}
