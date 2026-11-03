<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Execution;

use Amp\Cancellation;
use Fedor\MessageBus\Envelope\Envelope;
use Fedor\MessageBus\Service\ServiceResolverInterface;

interface BindingEnvelopeExecutorInterface
{
    public function executeEnvelopeToBinding(
        Envelope $envelope,
        ServiceResolverInterface $services,
        Cancellation $cancellation,
    ): mixed;
}
