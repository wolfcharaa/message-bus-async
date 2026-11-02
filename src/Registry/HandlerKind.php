<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Registry;

enum HandlerKind: string
{
    case Query = 'query';
    case Command = 'command';
    case Event = 'event';
}
