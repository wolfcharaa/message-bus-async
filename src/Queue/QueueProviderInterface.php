<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Queue;

use Amp\Cancellation;

interface QueueProviderInterface
{
    public function enqueue(QueueMessage $message, Cancellation $cancellation): QueueEnqueueResult;
}
