<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Registry\Metadata;

use Fedor\MessageBus\Registry\HandlerBinding;
use LogicException;

final readonly class BindingRegistrationContext
{
    public function __construct(
        public RegistryOwner $owner,
        public RegistrySource $source,
    ) {
    }

    public function stamp(HandlerBinding $binding, ?RegistrySource $source = null): HandlerBinding
    {
        if ($binding->owner !== null && !$binding->owner->equals($this->owner)) {
            throw new LogicException(sprintf(
                'Binding `%s` already belongs to `%s:%s`, cannot stamp as `%s:%s`.',
                $binding->id,
                $binding->owner->kind,
                $binding->owner->id,
                $this->owner->kind,
                $this->owner->id,
            ));
        }

        return $binding->withRegistrationMetadata(
            $this->owner,
            $source ?? $binding->source ?? $this->source,
        );
    }
}
