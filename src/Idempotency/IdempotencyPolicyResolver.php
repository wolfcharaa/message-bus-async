<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Idempotency;

use Fedor\MessageBus\Service\ServiceReference;
use Fedor\MessageBus\Service\ServiceResolverInterface;
use InvalidArgumentException;

final readonly class IdempotencyPolicyResolver
{
    public function __construct(private ServiceResolverInterface $services)
    {
    }

    /** @param iterable<IdempotencyPolicyProviderInterface> $providers */
    public function resolve(iterable $providers): ResolvedIdempotencyPolicyRegistry
    {
        $resolved = [];
        $sources = [];

        foreach ($providers as $provider) {
            $owner = $provider->owner();
            $source = $provider->source();

            foreach ($provider->policies() as $descriptor) {
                if (isset($resolved[$descriptor->bindingId])) {
                    throw new InvalidArgumentException(sprintf(
                        'Duplicate idempotency policy for binding `%s` from `%s` and `%s`.',
                        $descriptor->bindingId,
                        $sources[$descriptor->bindingId],
                        $source->name,
                    ));
                }

                $resolved[$descriptor->bindingId] = new ResolvedIdempotencyPolicy(
                    $descriptor->bindingId,
                    $owner,
                    $source,
                    $this->keyExtractor($descriptor->keyExtractor),
                    $this->fingerprintFactory($descriptor->fingerprintFactory),
                    $this->store($descriptor->store),
                    $descriptor->effectReferenceFactory !== null
                        ? $this->effectReferenceFactory($descriptor->effectReferenceFactory)
                        : null,
                    $descriptor->retentionSeconds,
                );
                $sources[$descriptor->bindingId] = $source->name;
            }
        }

        return new ResolvedIdempotencyPolicyRegistry(...array_values($resolved));
    }

    /** @param ServiceReference<IdempotencyKeyExtractorInterface> $reference */
    private function keyExtractor(ServiceReference $reference): IdempotencyKeyExtractorInterface
    {
        return $this->services->resolve($reference);
    }

    /** @param ServiceReference<IntentFingerprintFactoryInterface> $reference */
    private function fingerprintFactory(ServiceReference $reference): IntentFingerprintFactoryInterface
    {
        return $this->services->resolve($reference);
    }

    /** @param ServiceReference<IdempotencyStoreInterface> $reference */
    private function store(ServiceReference $reference): IdempotencyStoreInterface
    {
        return $this->services->resolve($reference);
    }

    /** @param ServiceReference<IdempotencyEffectReferenceFactoryInterface> $reference */
    private function effectReferenceFactory(
        ServiceReference $reference,
    ): IdempotencyEffectReferenceFactoryInterface {
        return $this->services->resolve($reference);
    }
}
