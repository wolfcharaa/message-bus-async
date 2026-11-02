<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Attribute;

use Attribute;
use BackedEnum;
use Fedor\MessageBus\Interceptor\InterceptorInterface;
use Fedor\MessageBus\Message\Event;
use Fedor\MessageBus\Queue\QueueDeliveryOptions;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class EventSubscriber extends AbstractMessageHandlerAttribute
{
    /**
     * @param class-string<Event> $message
     * @param list<class-string<InterceptorInterface>> $middleware
     */
    public function __construct(
        string $message,
        string|BackedEnum $flow = 'default',
        string $method = '__invoke',
        int $priority = 0,
        string|BackedEnum|null $bindingId = null,
        array $middleware = [],
        public readonly ?int $delaySeconds = null,
        public readonly ?string $retryPolicy = null,
        bool $contextAware = true,
        ?string $ownerKind = null,
        ?string $ownerId = null,
        ?string $sourceType = null,
        ?string $sourceName = null,
        ?string $sourcePackage = null,
        ?string $sourceLocation = null,
    ) {
        parent::__construct(
            $message,
            $flow,
            $method,
            $priority,
            $bindingId,
            $middleware,
            $contextAware,
            $ownerKind,
            $ownerId,
            $sourceType,
            $sourceName,
            $sourcePackage,
            $sourceLocation,
        );
    }

    public function delivery(): ?QueueDeliveryOptions
    {
        if ($this->delaySeconds === null && $this->retryPolicy === null && $this->priority === 0) {
            return null;
        }

        return new QueueDeliveryOptions(
            $this->priority !== 0 ? $this->priority : null,
            $this->delaySeconds,
            $this->retryPolicy,
        );
    }
}
