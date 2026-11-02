<?php

declare(strict_types=1);

namespace Fedor\MessageBus;

use Fedor\MessageBus\Execution\ExecutionOptions;
use Fedor\MessageBus\Message\Query;

interface QueryBusInterface
{
    /**
     * @template TResult
     * @param Query<TResult> $query
     * @return TResult
     */
    public function ask(Query $query, ?ExecutionOptions $options = null): mixed;
}
