<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Idempotency;

use Fedor\MessageBus\Registry\Metadata\RegistryOwner;
use Fedor\MessageBus\Registry\Metadata\RegistrySource;

final readonly class ResolvedIdempotencyPolicy
{
    public function __construct(
        public string $bindingId,
        public RegistryOwner $owner,
        public RegistrySource $source,
        public IdempotencyKeyExtractorInterface $keyExtractor,
        public IntentFingerprintFactoryInterface $fingerprintFactory,
        public IdempotencyStoreInterface $store,
        public ?IdempotencyEffectReferenceFactoryInterface $effectReferenceFactory = null,
        public ?int $retentionSeconds = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'bindingId' => $this->bindingId,
            'owner' => $this->owner->toArray(),
            'source' => $this->source->toArray(),
            'keyExtractor' => $this->keyExtractor::class,
            'fingerprintFactory' => $this->fingerprintFactory::class,
            'store' => $this->store::class,
            'effectReferenceFactory' => $this->effectReferenceFactory !== null
                ? $this->effectReferenceFactory::class
                : null,
            'retentionSeconds' => $this->retentionSeconds,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
