<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Publication;

use InvalidArgumentException;

final readonly class PublishedExecution
{
    public function __construct(
        public string $bindingId,
        public PublishedExecutionMode $mode,
        public string $messageId,
        public string $correlationId,
        public ?string $queueMessageId = null,
    ) {
        self::assertNotEmpty($this->bindingId, 'Binding ID');
        self::assertNotEmpty($this->messageId, 'Message ID');
        self::assertNotEmpty($this->correlationId, 'Correlation ID');

        if ($this->mode === PublishedExecutionMode::Queued) {
            self::assertNotEmpty($this->queueMessageId, 'Queue message ID');
        } elseif ($this->queueMessageId !== null) {
            throw new InvalidArgumentException('A local execution cannot have a queue message ID.');
        }
    }

    private static function assertNotEmpty(?string $value, string $name): void
    {
        if ($value === null || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('%s must be a non-empty string.', $name));
        }
    }
}
