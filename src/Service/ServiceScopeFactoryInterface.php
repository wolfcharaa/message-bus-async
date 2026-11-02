<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Service;

interface ServiceScopeFactoryInterface
{
    public function openScope(): ServiceScopeInterface;
}
