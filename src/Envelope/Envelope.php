<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Envelope;

use Fedor\MessageBus\Message\Command;
use Fedor\MessageBus\Message\Event;
use Fedor\MessageBus\Message\Query;
use InvalidArgumentException;

final readonly class Envelope
{
    /** @param Query<mixed>|Command|Event $message */
    public function __construct(
        public Query|Command|Event $message,
        public string $messageId,
        public string $correlationId,
        public ?string $causationId,
        public Headers $headers = new Headers(),
    ) {
        self::assertNotEmpty($this->messageId, 'Message ID');
        self::assertNotEmpty($this->correlationId, 'Correlation ID');

        if ($this->causationId !== null) {
            self::assertNotEmpty($this->causationId, 'Causation ID');
        }
    }

    private static function assertNotEmpty(string $value, string $name): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('%s must be a non-empty string.', $name));
        }
    }
}
