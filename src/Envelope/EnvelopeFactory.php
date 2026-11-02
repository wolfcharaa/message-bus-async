<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Envelope;

use Fedor\MessageBus\Execution\ExecutionOptions;
use Fedor\MessageBus\Identity\MessageIdGeneratorInterface;
use Fedor\MessageBus\Message\Command;
use Fedor\MessageBus\Message\Event;
use Fedor\MessageBus\Message\Query;

final readonly class EnvelopeFactory
{
    public function __construct(private MessageIdGeneratorInterface $messageIdGenerator)
    {
    }

    /** @param Query<mixed>|Command|Event $message */
    public function createRoot(
        Query|Command|Event $message,
        ?ExecutionOptions $options = null,
    ): Envelope {
        $options ??= new ExecutionOptions();
        $messageId = $this->messageIdGenerator->generate();

        return new Envelope(
            message: $message,
            messageId: $messageId,
            correlationId: $options->correlationId ?? $messageId,
            causationId: null,
            headers: $options->headers,
        );
    }

    /** @param Query<mixed>|Command|Event $message */
    public function createNested(
        Query|Command|Event $message,
        Envelope $parent,
        ?ExecutionOptions $options = null,
    ): Envelope {
        $options ??= new ExecutionOptions();

        return new Envelope(
            message: $message,
            messageId: $this->messageIdGenerator->generate(),
            correlationId: $parent->correlationId,
            causationId: $parent->messageId,
            headers: $parent->headers->merge($options->headers),
        );
    }
}
