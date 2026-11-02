<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Context;

use Amp\Cancellation;
use Fedor\MessageBus\Envelope\Envelope;
use Fedor\MessageBus\Flow\FlowDefinition;
use Fedor\MessageBus\MessageBusInterface;

final class DefaultMessageContextFactory implements MessageContextFactoryInterface
{
    #[\Override]
    public function create(
        MessageBusInterface $messageBus,
        Envelope $envelope,
        FlowDefinition $flow,
        Cancellation $cancellation,
    ): MessageContextInterface {
        return new DefaultMessageContext($messageBus, $envelope, $cancellation);
    }
}
