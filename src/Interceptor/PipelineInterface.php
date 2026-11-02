<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Interceptor;

/** @template-covariant TResult */
interface PipelineInterface
{
    /** @return TResult */
    public function continue(): mixed;
}
