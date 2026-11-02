<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Service;

interface ServiceScopeInterface extends ServiceResolverInterface
{
    public function dispose(): void;
}
