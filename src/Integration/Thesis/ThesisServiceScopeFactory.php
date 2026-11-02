<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Integration\Thesis;

use Fedor\MessageBus\Service\ServiceResolverInterface;
use Fedor\MessageBus\Service\ServiceScopeFactoryInterface;
use Fedor\MessageBus\Service\ServiceScopeInterface;
use Thesis\Dic\Scoped;

final readonly class ThesisServiceScopeFactory implements ServiceScopeFactoryInterface
{
    /** @param Scoped<ServiceResolverInterface> $resolver */
    public function __construct(private Scoped $resolver)
    {
    }

    public function openScope(): ServiceScopeInterface
    {
        return ThesisServiceScope::open($this->resolver);
    }
}
