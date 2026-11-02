<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Idempotency;

use BackedEnum;
use Fedor\MessageBus\Flow\Contract\FlowContract;
use Fedor\MessageBus\Registry\HandlerKind;

final class IdempotencyFlowContract
{
    private function __construct()
    {
    }

    public static function requiresIdempotencyKey(string|BackedEnum $flow): FlowContract
    {
        return FlowContract::forFlow($flow)
            ->requiresStableBindingId()
            ->requiresBindingOwner()
            ->requiresRole(IdempotencyInterceptorRole::Guard)
            ->requiresPolicy(ResolvedIdempotencyPolicyRegistryInterface::class)
            ->allowsHandlerKinds(HandlerKind::Command, HandlerKind::Event);
    }
}
