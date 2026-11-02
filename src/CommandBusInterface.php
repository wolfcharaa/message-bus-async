<?php

declare(strict_types=1);

namespace Fedor\MessageBus;

use Fedor\MessageBus\Execution\ExecutionOptions;
use Fedor\MessageBus\Message\Command;

interface CommandBusInterface
{
    public function dispatch(Command $command, ?ExecutionOptions $options = null): void;
}
