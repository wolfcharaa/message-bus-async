<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Flow\Contract;

interface BindingPolicyRegistryInterface
{
    public function hasForBinding(string $bindingId): bool;
}
