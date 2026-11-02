<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Service;

use Throwable;

interface ServiceScopeInterface extends ServiceResolverInterface
{
    public function dispose(?Throwable $error = null): void;
}
