<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Attribute;

use Attribute;
use BackedEnum;
use Fedor\MessageBus\Interceptor\InterceptorInterface;
use Fedor\MessageBus\Message\Command;
use Fedor\MessageBus\Queue\QueueDeliveryOptions;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class CommandHandler extends AbstractMessageHandlerAttribute
{
    /**
     * @param class-string<Command> $message
     * @param list<class-string<InterceptorInterface>> $middleware
     */
    public function __construct(
        string $message,
        string|BackedEnum $flow = 'default',
        string $method = '__invoke',
        int $priority = 0,
        public readonly ?bool $primary = null,
        string|BackedEnum|null $bindingId = null,
        array $middleware = [],
        public readonly ?int $delaySeconds = null,
        public readonly ?string $retryPolicy = null,
        bool $contextAware = true,
    ) {
        parent::__construct($message, $flow, $method, $priority, $bindingId, $middleware, $contextAware);
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
