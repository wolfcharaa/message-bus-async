<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Message;

/**
 * Marks an event message that may be delivered to multiple subscribers.
 *
 * Implementations should be immutable data objects without execution logic.
 */
interface Event
{
}
