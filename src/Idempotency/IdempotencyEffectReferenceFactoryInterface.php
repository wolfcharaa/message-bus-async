<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Idempotency;

interface IdempotencyEffectReferenceFactoryInterface
{
    public function effectReference(object $message, IdempotencyExecution $execution): ?IdempotencyEffectReference;
}
