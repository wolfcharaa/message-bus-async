<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Context;

use Closure;
use Fedor\MessageBus\Execution\ExecutionOptions;
use Fedor\MessageBus\Message\Command;
use Fedor\MessageBus\Message\Event;
use Fedor\MessageBus\Message\Query;
use Fedor\MessageBus\MessageBusInterface;
use Fedor\MessageBus\Publication\PublishResult;

final readonly class NestedMessageBus implements MessageBusInterface
{
    /** @var Closure(Query<mixed>, ?ExecutionOptions): mixed */
    private Closure $ask;

    /** @var Closure(Command, ?ExecutionOptions): void */
    private Closure $dispatch;

    /** @var Closure(Event, ?ExecutionOptions): PublishResult */
    private Closure $publish;

    /**
     * @param callable(Query<mixed>, ?ExecutionOptions): mixed $ask
     * @param callable(Command, ?ExecutionOptions): void $dispatch
     * @param callable(Event, ?ExecutionOptions): PublishResult $publish
     */
    public function __construct(callable $ask, callable $dispatch, callable $publish)
    {
        $this->ask = Closure::fromCallable($ask);
        $this->dispatch = Closure::fromCallable($dispatch);
        $this->publish = Closure::fromCallable($publish);
    }

    #[\Override]
    public function ask(Query $query, ?ExecutionOptions $options = null): mixed
    {
        return ($this->ask)($query, $options);
    }

    #[\Override]
    public function dispatch(Command $command, ?ExecutionOptions $options = null): void
    {
        ($this->dispatch)($command, $options);
    }

    #[\Override]
    public function publish(Event $event, ?ExecutionOptions $options = null): PublishResult
    {
        return ($this->publish)($event, $options);
    }
}
