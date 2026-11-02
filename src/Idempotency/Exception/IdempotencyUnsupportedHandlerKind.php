<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Idempotency\Exception;

use Fedor\MessageBus\Exception\NonRetryableMessageExceptionInterface;

final class IdempotencyUnsupportedHandlerKind extends IdempotencyException implements NonRetryableMessageExceptionInterface
{
    public static function forKind(string $bindingId, string $kind): self
    {
        return new self(sprintf('Idempotency does not support `%s` handler binding `%s`.', $kind, $bindingId));
    }
}
