<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Context;

use Amp\Cancellation;
use Fedor\MessageBus\Envelope\Envelope;
use Fedor\MessageBus\MessageBusInterface;

/**
 * Read-only execution context available to orchestration handlers.
 *
 * The bus implementation owns context creation and causation propagation.
 * Application code may use the inherited narrow bus capabilities for nested
 * execution, but cannot mutate the current envelope metadata.
 */
interface MessageContextInterface extends MessageBusInterface
{
    public function cancellation(): Cancellation;

    public function envelope(): Envelope;
}
