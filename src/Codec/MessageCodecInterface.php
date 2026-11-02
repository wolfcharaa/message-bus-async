<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Codec;

use Fedor\MessageBus\Message\Command;
use Fedor\MessageBus\Message\Event;
use Fedor\MessageBus\Message\Query;

interface MessageCodecInterface
{
    /** @return class-string */
    public function messageClass(): string;

    public function alias(): string;

    public function version(): int;

    /** @param Query<mixed>|Command|Event $message */
    public function toPayload(Query|Command|Event $message): JsonValue;

    /** @return Query<mixed>|Command|Event */
    public function fromPayload(JsonValue $payload): Query|Command|Event;
}
