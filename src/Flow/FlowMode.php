<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Flow;

enum FlowMode: string
{
    case Sync = 'sync';
    case Async = 'async';
}
