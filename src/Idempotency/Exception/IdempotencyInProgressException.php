<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Idempotency\Exception;

use Fedor\MessageBus\Exception\RetryableMessageExceptionInterface;
use Fedor\MessageBus\Idempotency\IdempotencyExecution;
use Fedor\MessageBus\Idempotency\IdempotencyKey;

final class IdempotencyInProgressException extends IdempotencyException implements RetryableMessageExceptionInterface
{
    public static function forExecution(IdempotencyExecution $execution, IdempotencyKey $key): self
    {
        return new self(sprintf(
            'Idempotency execution is already in progress for binding `%s` and key digest `%s`.',
            $execution->bindingId,
            $key->digest,
        ));
    }
}
