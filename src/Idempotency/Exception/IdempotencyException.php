<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Idempotency\Exception;

use RuntimeException;

abstract class IdempotencyException extends RuntimeException
{
}
