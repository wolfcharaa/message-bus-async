<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Service;

use RuntimeException;

final class ServiceResolutionFailed extends RuntimeException
{
    /** @param ServiceReference<object> $reference */
    public static function missing(ServiceReference $reference): self
    {
        return new self(sprintf(
            'Service `%s` required as `%s` is not registered.',
            $reference->id,
            $reference->type,
        ));
    }

    /** @param ServiceReference<object> $reference */
    public static function typeMismatch(ServiceReference $reference, mixed $service): self
    {
        return new self(sprintf(
            'Service `%s` must implement `%s`; `%s` resolved.',
            $reference->id,
            $reference->type,
            get_debug_type($service),
        ));
    }
}
