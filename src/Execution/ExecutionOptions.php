<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Execution;

use Amp\Cancellation;
use Amp\NullCancellation;
use Fedor\MessageBus\Envelope\Headers;
use Fedor\MessageBus\Queue\QueueDeliveryOptions;
use InvalidArgumentException;

/**
 * Immutable input for a root bus execution.
 *
 * Nested executions inherit cancellation and headers through a context created
 * by the bus implementation. A caller never creates or mutates that context.
 */
final readonly class ExecutionOptions
{
    public Headers $headers;

    /**
     * @param array<array-key, mixed>|Headers $headers
     */
    public function __construct(
        public Cancellation $cancellation = new NullCancellation(),
        array|Headers $headers = [],
        public ?string $correlationId = null,
        public ?QueueDeliveryOptions $delivery = null,
    ) {
        if ($this->correlationId !== null && trim($this->correlationId) === '') {
            throw new InvalidArgumentException('Correlation ID must be a non-empty string when provided.');
        }

        $this->headers = $headers instanceof Headers ? $headers : new Headers($headers);
    }
}
