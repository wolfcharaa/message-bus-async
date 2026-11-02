<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Context;

use Amp\Cancellation;
use Amp\CompositeCancellation;
use Fedor\MessageBus\Envelope\Envelope;
use Fedor\MessageBus\Execution\ExecutionOptions;
use Fedor\MessageBus\Message\Command;
use Fedor\MessageBus\Message\Event;
use Fedor\MessageBus\Message\Query;
use Fedor\MessageBus\MessageBusInterface;
use Fedor\MessageBus\Publication\PublishResult;

final readonly class DefaultMessageContext implements MessageContextInterface
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private Envelope $envelope,
        private Cancellation $cancellation,
    ) {
    }

    #[\Override]
    public function cancellation(): Cancellation
    {
        return $this->cancellation;
    }

    #[\Override]
    public function envelope(): Envelope
    {
        return $this->envelope;
    }

    #[\Override]
    public function ask(Query $query, ?ExecutionOptions $options = null): mixed
    {
        return $this->messageBus->ask($query, $this->nestedOptions($options));
    }

    #[\Override]
    public function dispatch(Command $command, ?ExecutionOptions $options = null): void
    {
        $this->messageBus->dispatch($command, $this->nestedOptions($options));
    }

    #[\Override]
    public function publish(Event $event, ?ExecutionOptions $options = null): PublishResult
    {
        return $this->messageBus->publish($event, $this->nestedOptions($options));
    }

    private function nestedOptions(?ExecutionOptions $options): ExecutionOptions
    {
        if ($options === null) {
            return new ExecutionOptions(cancellation: $this->cancellation);
        }

        return new ExecutionOptions(
            cancellation: new CompositeCancellation($this->cancellation, $options->cancellation),
            headers: $options->headers,
            correlationId: $options->correlationId,
            delivery: $options->delivery,
        );
    }
}
