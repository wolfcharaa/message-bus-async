<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Integration\Psr;

use Fedor\MessageBus\Service\ServiceReference;
use Fedor\MessageBus\Service\ServiceResolutionFailed;
use Fedor\MessageBus\Service\ServiceResolverInterface;
use Psr\Container\ContainerInterface;

final readonly class PsrContainerServiceResolver implements ServiceResolverInterface
{
    public function __construct(private ContainerInterface $container)
    {
    }

    public function resolve(ServiceReference $reference): object
    {
        $service = $this->container->get($reference->id);
        if (!$service instanceof $reference->type) {
            throw ServiceResolutionFailed::typeMismatch($reference, $service);
        }

        return $service;
    }
}
