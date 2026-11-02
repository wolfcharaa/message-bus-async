<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Idempotency\Decision;

use Fedor\MessageBus\Idempotency\IntentFingerprint;

final readonly class IdempotencyConflict implements IdempotencyDecisionInterface
{
    public function __construct(public ?IntentFingerprint $existingFingerprint = null)
    {
    }
}
