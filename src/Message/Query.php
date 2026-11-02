<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Message;

/**
 * Marks a query message and binds it to its result type for static analysis.
 *
 * Implementations should be immutable data objects without execution logic.
 *
 * @template-covariant TResult
 */
interface Query
{
}
