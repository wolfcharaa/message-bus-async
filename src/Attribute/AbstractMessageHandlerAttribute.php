<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Attribute;

use BackedEnum;
use Fedor\MessageBus\Interceptor\InterceptorInterface;
use Fedor\MessageBus\Registry\Metadata\RegistryOwner;
use Fedor\MessageBus\Registry\Metadata\RegistrySource;
use InvalidArgumentException;

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
        public readonly ?string $ownerKind = null,
        public readonly ?string $ownerId = null,
        public readonly ?string $sourceType = null,
        public readonly ?string $sourceName = null,
        public readonly ?string $sourcePackage = null,
        public readonly ?string $sourceLocation = null,
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

    public function registrationOwner(): ?RegistryOwner
    {
        if ($this->ownerKind === null && $this->ownerId === null) {
            return null;
        }
        if ($this->ownerKind === null || $this->ownerId === null) {
            throw new InvalidArgumentException('Handler binding owner requires both ownerKind and ownerId.');
        }

        return new RegistryOwner($this->ownerKind, $this->ownerId);
    }

    public function registrationSource(string $handlerClass): ?RegistrySource
    {
        if (
            $this->sourceType === null
            && $this->sourceName === null
            && $this->sourcePackage === null
            && $this->sourceLocation === null
        ) {
            return null;
        }

        return new RegistrySource(
            $this->sourceType ?? 'handler',
            $this->sourceName ?? $handlerClass,
            providerClass: $handlerClass,
            package: $this->sourcePackage,
            location: $this->sourceLocation,
        );
    }

    private static function normalize(string|BackedEnum $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : $value;
    }
}
