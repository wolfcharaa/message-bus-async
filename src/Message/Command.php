<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Message;

/**
 * Marks a command message that completes without an application result.
 *
 * Implementations should be immutable data objects without execution logic.
 */
interface Command
{
}
