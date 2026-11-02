<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Idempotency;

interface IdempotencyKeyExtractorInterface
{
    public function extract(object $message): IdempotencyKey;
}
