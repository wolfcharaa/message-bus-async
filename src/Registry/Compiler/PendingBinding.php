<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Registry\Compiler;

use Fedor\MessageBus\Interceptor\InterceptorInterface;
use Fedor\MessageBus\Registry\HandlerBinding;
use Fedor\MessageBus\Registry\HandlerInvocationMode;
use Fedor\MessageBus\Registry\HandlerKind;
use Fedor\MessageBus\Queue\QueueDeliveryOptions;
use Fedor\MessageBus\Service\ServiceReference;

/** @internal */
final readonly class PendingBinding
{
    /**
     * @param class-string $message
     * @param class-string $handler
     * @param list<class-string<InterceptorInterface>> $interceptors
     */
    public function __construct(
        public string $id,
        public string $message,
        public HandlerKind $kind,
        public string $handler,
        public string $method,
        public string $flow,
        public int $priority,
        public ?bool $primary,
        public HandlerInvocationMode $invocationMode,
        public array $interceptors,
        public ?QueueDeliveryOptions $delivery,
    ) {
    }

    public function compile(bool $primary): HandlerBinding
    {
        $interceptors = array_map(
            static fn (string $class): ServiceReference => ServiceReference::forClass($class),
            $this->interceptors,
        );

        return new HandlerBinding(
            $this->id,
            $this->message,
            $this->kind,
            ServiceReference::forClass($this->handler),
            $this->method,
            $this->flow,
            $this->priority,
            $primary,
            $this->invocationMode,
            $interceptors,
            $this->delivery,
        );
    }
}
