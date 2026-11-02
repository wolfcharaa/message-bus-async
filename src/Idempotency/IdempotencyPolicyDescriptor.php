<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Idempotency;

use Fedor\MessageBus\Service\ServiceReference;
use InvalidArgumentException;

final readonly class IdempotencyPolicyDescriptor
{
    /**
     * @param ServiceReference<IdempotencyKeyExtractorInterface> $keyExtractor
     * @param ServiceReference<IntentFingerprintFactoryInterface> $fingerprintFactory
     * @param ServiceReference<IdempotencyStoreInterface> $store
     * @param ServiceReference<IdempotencyEffectReferenceFactoryInterface>|null $effectReferenceFactory
     */
    public function __construct(
        public string $bindingId,
        public ServiceReference $keyExtractor,
        public ServiceReference $fingerprintFactory,
        public ServiceReference $store,
        public ?ServiceReference $effectReferenceFactory = null,
        public ?int $retentionSeconds = null,
    ) {
        if (trim($this->bindingId) === '') {
            throw new InvalidArgumentException('Idempotency policy bindingId must be non-empty.');
        }
        if ($this->retentionSeconds !== null && $this->retentionSeconds <= 0) {
            throw new InvalidArgumentException('Idempotency retentionSeconds must be positive.');
        }

        $this->assertReference($this->keyExtractor, IdempotencyKeyExtractorInterface::class, 'keyExtractor');
        $this->assertReference($this->fingerprintFactory, IntentFingerprintFactoryInterface::class, 'fingerprintFactory');
        $this->assertReference($this->store, IdempotencyStoreInterface::class, 'store');
        if ($this->effectReferenceFactory !== null) {
            $this->assertReference(
                $this->effectReferenceFactory,
                IdempotencyEffectReferenceFactoryInterface::class,
                'effectReferenceFactory',
            );
        }
    }

    /**
     * @param ServiceReference<IdempotencyKeyExtractorInterface> $keyExtractor
     * @param ServiceReference<IntentFingerprintFactoryInterface> $fingerprintFactory
     * @param ServiceReference<IdempotencyStoreInterface> $store
     * @param ServiceReference<IdempotencyEffectReferenceFactoryInterface>|null $effectReferenceFactory
     */
    public static function forBinding(
        string $bindingId,
        ServiceReference $keyExtractor,
        ServiceReference $fingerprintFactory,
        ServiceReference $store,
        ?ServiceReference $effectReferenceFactory = null,
        ?int $retentionSeconds = null,
    ): self {
        return new self(
            $bindingId,
            $keyExtractor,
            $fingerprintFactory,
            $store,
            $effectReferenceFactory,
            $retentionSeconds,
        );
    }

    /** @param ServiceReference<object> $reference */
    private function assertReference(ServiceReference $reference, string $expectedType, string $field): void
    {
        if (!is_a($reference->type, $expectedType, true)) {
            throw new InvalidArgumentException(sprintf(
                'Idempotency policy %s reference must expect `%s`, got `%s`.',
                $field,
                $expectedType,
                $reference->type,
            ));
        }
    }
}
