<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Interceptor;

use Fedor\MessageBus\Context\MessageContextInterface;

interface InterceptorInterface
{
    /**
     * @template TResult
     * @param PipelineInterface<TResult> $pipeline
     * @return TResult
     */
    public function __invoke(MessageContextInterface $context, PipelineInterface $pipeline): mixed;
}
