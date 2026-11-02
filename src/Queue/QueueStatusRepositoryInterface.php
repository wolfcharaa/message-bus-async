<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Queue;

use Amp\Cancellation;

interface QueueStatusRepositoryInterface
{
    public function get(string $queueMessageId, Cancellation $cancellation): ?QueueJobStatus;

    /** @return list<QueueJobStatus> */
    public function listByMessageId(string $messageId, Cancellation $cancellation): array;

    /** @return list<QueueJobStatus> */
    public function listByCorrelationId(string $correlationId, Cancellation $cancellation): array;
}
