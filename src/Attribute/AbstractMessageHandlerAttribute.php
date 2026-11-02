<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Attribute;

use BackedEnum;
use Fedor\MessageBus\Interceptor\InterceptorInterface;

abstract class AbstractMessageHandlerAttribute implements MessageHandlerAttributeInterface
{
    /**
     * @param class-string $message
     * @param list<class-string<InterceptorInterface>> $middleware
     */
    public function __construct(
        public readonly string $message,
        public readonly string|BackedEnum $flow = 'default',
        public readonly string $method = '__invoke',
        public readonly int $priority = 0,
        public readonly string|BackedEnum|null $bindingId = null,
        public readonly array $middleware = [],
        public readonly bool $contextAware = true,
    ) {
    }

    public function message(): string
    {
        return $this->message;
    }

    public function flow(): string
    {
        return self::normalize($this->flow);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function bindingId(): ?string
    {
        return $this->bindingId === null ? null : self::normalize($this->bindingId);
    }

    public function interceptors(): array
    {
        return $this->middleware;
    }

    public function middleware(): array
    {
        return $this->middleware;
    }

    public function contextAware(): bool
    {
        return $this->contextAware;
    }

    private static function normalize(string|BackedEnum $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : $value;
    }
}
