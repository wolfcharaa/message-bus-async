<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Codec;

use RuntimeException;

final class MessageCodecNotFound extends RuntimeException
{
    public static function forAlias(string $alias): self
    {
        return new self(sprintf('No message codec is registered for alias "%s".', $alias));
    }

    public static function forMessage(string $messageClass): self
    {
        return new self(sprintf('No message codec is registered for message "%s".', $messageClass));
    }
}
