<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Envelope;

use DateTimeImmutable;
use Fedor\MessageBus\Message\Command;
use Fedor\MessageBus\Message\Event;
use Fedor\MessageBus\Message\Query;
use InvalidArgumentException;

final readonly class Envelope
{
    public DateTimeImmutable $createdAt;

    /** @param Query<mixed>|Command|Event $message */
    public function __construct(
        public Query|Command|Event $message,
        public string $messageId,
        public string $correlationId,
        public ?string $causationId,
        public Headers $headers = new Headers(),
        ?DateTimeImmutable $createdAt = null,
        public ?string $bindingId = null,
    ) {
        $this->createdAt = $createdAt ?? new DateTimeImmutable();

        self::assertNotEmpty($this->messageId, 'Message ID');
        self::assertNotEmpty($this->correlationId, 'Correlation ID');

        if ($this->causationId !== null) {
            self::assertNotEmpty($this->causationId, 'Causation ID');
        }

        if ($this->bindingId !== null) {
            self::assertNotEmpty($this->bindingId, 'Binding ID');
        }
    }

    public function forBinding(string $bindingId): self
    {
        return new self(
            $this->message,
            $this->messageId,
            $this->correlationId,
            $this->causationId,
            $this->headers,
            $this->createdAt,
            $bindingId,
        );
    }

    private static function assertNotEmpty(string $value, string $name): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('%s must be a non-empty string.', $name));
        }
    }
}
