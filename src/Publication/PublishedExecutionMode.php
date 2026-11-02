<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Publication;

enum PublishedExecutionMode: string
{
    case Local = 'local';
    case Queued = 'queued';
}
