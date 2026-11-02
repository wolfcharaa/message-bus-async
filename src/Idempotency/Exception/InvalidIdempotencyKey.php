<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Idempotency\Exception;

use Fedor\MessageBus\Exception\NonRetryableMessageExceptionInterface;

final class InvalidIdempotencyKey extends IdempotencyException implements NonRetryableMessageExceptionInterface
{
}
