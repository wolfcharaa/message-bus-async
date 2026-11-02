<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Service;

interface ServiceResolverInterface
{
    /**
     * @template TService of object
     * @param ServiceReference<TService> $reference
     * @return TService
     */
    public function resolve(ServiceReference $reference): object;
}
