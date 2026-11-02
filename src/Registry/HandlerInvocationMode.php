<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Registry;

enum HandlerInvocationMode: string
{
    case Contextless = 'contextless';
    case ContextAware = 'context_aware';
}
