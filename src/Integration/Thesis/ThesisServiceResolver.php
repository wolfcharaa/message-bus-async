<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Integration\Thesis;

use Closure;
use Fedor\MessageBus\Service\ServiceReference;
use Fedor\MessageBus\Service\ServiceResolutionFailed;
use Fedor\MessageBus\Service\ServiceResolverInterface;
use InvalidArgumentException;

final readonly class ThesisServiceResolver implements ServiceResolverInterface
{
    /** @var array<string, Closure(): object> */
    private array $providers;

    /** @param array<string, callable(): object> $providers */
    public function __construct(array $providers)
    {
        $normalized = [];
        foreach ($providers as $id => $provider) {
            if (trim($id) === '') {
                throw new InvalidArgumentException('Thesis service provider ID must be a non-empty string.');
            }

            $normalized[$id] = Closure::fromCallable($provider);
        }

        $this->providers = $normalized;
    }

    public function resolve(ServiceReference $reference): object
    {
        $provider = $this->providers[$reference->id] ?? null;
        if ($provider === null) {
            throw ServiceResolutionFailed::missing($reference);
        }

        $service = $provider();
        if (!$service instanceof $reference->type) {
            throw ServiceResolutionFailed::typeMismatch($reference, $service);
        }

        return $service;
    }
}
