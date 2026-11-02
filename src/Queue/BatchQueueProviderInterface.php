<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Queue;

use Amp\Cancellation;

interface BatchQueueProviderInterface extends QueueProviderInterface
{
    /** @param iterable<QueueMessage> $messages */
    public function enqueueMany(iterable $messages, Cancellation $cancellation): QueueBatchEnqueueResult;
}
