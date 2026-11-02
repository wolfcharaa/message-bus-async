<?php

declare(strict_types=1);

namespace Fedor\MessageBus;

use Fedor\MessageBus\Execution\ExecutionOptions;
use Fedor\MessageBus\Message\Event;
use Fedor\MessageBus\Publication\PublishFailed;
use Fedor\MessageBus\Publication\PublishResult;

interface EventPublisherInterface
{
    /** @throws PublishFailed when at least one binding cannot be published */
    public function publish(Event $event, ?ExecutionOptions $options = null): PublishResult;
}
