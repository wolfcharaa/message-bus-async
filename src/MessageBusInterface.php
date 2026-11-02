<?php

declare(strict_types=1);

namespace Fedor\MessageBus;

interface MessageBusInterface extends QueryBusInterface, CommandBusInterface, EventPublisherInterface
{
}
