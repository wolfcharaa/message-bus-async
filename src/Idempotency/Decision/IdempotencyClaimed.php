<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Idempotency\Decision;

use Fedor\MessageBus\Idempotency\IdempotencyClaim;

final readonly class IdempotencyClaimed implements IdempotencyDecisionInterface
{
    public function __construct(public IdempotencyClaim $claim)
    {
    }
}
