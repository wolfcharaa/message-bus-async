<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Idempotency;

use Fedor\MessageBus\Registry\Metadata\RegistryOwner;
use Fedor\MessageBus\Registry\Metadata\RegistrySource;

interface IdempotencyPolicyProviderInterface
{
    public function owner(): RegistryOwner;

    public function source(): RegistrySource;

    /** @return iterable<IdempotencyPolicyDescriptor> */
    public function policies(): iterable;
}
