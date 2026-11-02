<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Idempotency;

use Fedor\MessageBus\Idempotency\Decision\IdempotencyDecisionInterface;

interface IdempotencyStoreInterface
{
    public function claim(
        IdempotencyExecution $execution,
        IdempotencyKey $key,
        IntentFingerprint $fingerprint,
    ): IdempotencyDecisionInterface;

    public function complete(IdempotencyClaim $claim, IdempotencyCompletion $completion): void;
}
