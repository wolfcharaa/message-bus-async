<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Attribute;

use Fedor\MessageBus\Interceptor\InterceptorInterface;
use Fedor\MessageBus\Registry\Metadata\RegistryOwner;
use Fedor\MessageBus\Registry\Metadata\RegistrySource;

interface MessageHandlerAttributeInterface
{
    /** @return class-string */
    public function message(): string;

    public function flow(): string;

    public function method(): string;

    public function priority(): int;

    public function bindingId(): ?string;

    /** @return list<class-string<InterceptorInterface>> */
    public function interceptors(): array;

    /** @return list<class-string<InterceptorInterface>> */
    public function middleware(): array;

    public function contextAware(): bool;

    public function registrationOwner(): ?RegistryOwner;

    /** @param class-string $handlerClass */
    public function registrationSource(string $handlerClass): ?RegistrySource;
}
