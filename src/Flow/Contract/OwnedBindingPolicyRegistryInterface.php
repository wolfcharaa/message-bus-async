<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Flow\Contract;

use Fedor\MessageBus\Registry\Metadata\RegistryOwner;

interface OwnedBindingPolicyRegistryInterface extends BindingPolicyRegistryInterface
{
    public function ownerForBinding(string $bindingId): ?RegistryOwner;
}
