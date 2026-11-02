<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Execution;

interface HandlerExecutionStrategyInterface
{
    public function execute(ExecutionRequest $request): HandlerExecutionResultInterface;
}
