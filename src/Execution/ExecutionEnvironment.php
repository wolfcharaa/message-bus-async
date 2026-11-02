<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Execution;

use Fedor\MessageBus\Envelope\EnvelopeSerializerInterface;
use Fedor\MessageBus\Invoker\CallableInvokerInterface;
use Fedor\MessageBus\Queue\QueueProviderInterface;
use Fedor\MessageBus\Queue\RetryPolicyRegistryInterface;
use Fedor\MessageBus\Service\ServiceResolverInterface;
use Psr\Clock\ClockInterface;

final readonly class ExecutionEnvironment
{
    public function __construct(
        public ServiceResolverInterface $services,
        public CallableInvokerInterface $invoker,
        public ?EnvelopeSerializerInterface $envelopeSerializer = null,
        public ?ClockInterface $clock = null,
        public ?QueueProviderInterface $queueProvider = null,
        public ?RetryPolicyRegistryInterface $retryPolicyRegistry = null,
    ) {
    }
}
