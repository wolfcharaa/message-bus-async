<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Idempotency\Exception;

use Fedor\MessageBus\Exception\NonRetryableMessageExceptionInterface;
use Fedor\MessageBus\Idempotency\IdempotencyExecution;
use Fedor\MessageBus\Idempotency\IdempotencyKey;

final class IdempotencyConflictDetected extends IdempotencyException implements NonRetryableMessageExceptionInterface
{
    public static function forExecution(IdempotencyExecution $execution, IdempotencyKey $key): self
    {
        return new self(sprintf(
            'Idempotency conflict for binding `%s` and key digest `%s`.',
            $execution->bindingId,
            $key->digest,
        ));
    }
}
