<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Idempotency;

use Fedor\MessageBus\Clock\SystemClock;
use Fedor\MessageBus\Context\MessageContextInterface;
use Fedor\MessageBus\Idempotency\Decision\IdempotencyClaimed;
use Fedor\MessageBus\Idempotency\Decision\IdempotencyCompletedSame;
use Fedor\MessageBus\Idempotency\Decision\IdempotencyConflict;
use Fedor\MessageBus\Idempotency\Decision\IdempotencyInProgress;
use Fedor\MessageBus\Idempotency\Exception\IdempotencyConflictDetected;
use Fedor\MessageBus\Idempotency\Exception\IdempotencyInProgressException;
use Fedor\MessageBus\Idempotency\Exception\IdempotencyPolicyMissing;
use Fedor\MessageBus\Idempotency\Exception\IdempotencyUnsupportedHandlerKind;
use Fedor\MessageBus\Interceptor\InterceptorInterface;
use Fedor\MessageBus\Interceptor\PipelineInterface;
use Fedor\MessageBus\Registry\CompiledMessageRegistry;
use Fedor\MessageBus\Registry\HandlerKind;
use LogicException;
use Psr\Clock\ClockInterface;

final readonly class RequiresIdempotencyKey implements InterceptorInterface
{
    public function __construct(
        private ResolvedIdempotencyPolicyRegistryInterface $policies,
        private CompiledMessageRegistry $registry,
        private ClockInterface $clock = new SystemClock(),
    ) {
    }

    #[\Override]
    public function __invoke(MessageContextInterface $context, PipelineInterface $pipeline): mixed
    {
        $envelope = $context->envelope();
        if ($envelope->bindingId === null) {
            throw IdempotencyPolicyMissing::forBinding(null);
        }

        $binding = $this->registry->binding($envelope->bindingId);
        if ($binding->kind === HandlerKind::Query) {
            throw IdempotencyUnsupportedHandlerKind::forKind($binding->id, $binding->kind->value);
        }

        $policy = $this->policies->require($binding->id);
        $execution = new IdempotencyExecution(
            $binding->id,
            $binding->flow,
            $binding->kind->value,
            $binding->message,
            $this->registry->messageName($binding->message),
            $envelope->messageId,
            $envelope->correlationId,
            $envelope->causationId,
            $envelope->createdAt,
        );
        $message = $envelope->message;
        $key = $policy->keyExtractor->extract($message);
        $fingerprint = $policy->fingerprintFactory->fingerprint($message);
        $decision = $policy->store->claim($execution, $key, $fingerprint);

        if ($decision instanceof IdempotencyCompletedSame) {
            return $this->completedReplayResult();
        }
        if ($decision instanceof IdempotencyInProgress) {
            throw IdempotencyInProgressException::forExecution($execution, $key);
        }
        if ($decision instanceof IdempotencyConflict) {
            throw IdempotencyConflictDetected::forExecution($execution, $key);
        }
        if (!$decision instanceof IdempotencyClaimed) {
            throw new LogicException(sprintf('Unsupported idempotency decision `%s`.', $decision::class));
        }

        $result = $pipeline->continue();
        $effectReference = $policy->effectReferenceFactory?->effectReference($message, $execution);
        $policy->store->complete(
            $decision->claim,
            new IdempotencyCompletion($this->clock->now(), $effectReference),
        );

        return $result;
    }

    private function completedReplayResult(): mixed
    {
        return null;
    }
}
