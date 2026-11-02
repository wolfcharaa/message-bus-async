<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Queue;

use Amp\Cancellation;

interface QueueJobControlInterface
{
    public function cancel(string $queueMessageId, Cancellation $cancellation): void;

    public function requestCancellation(string $queueMessageId, Cancellation $cancellation): void;
}
