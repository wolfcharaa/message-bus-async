<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Flow\Contract;

use BackedEnum;
use Fedor\MessageBus\Interceptor\InterceptorInterface;
use InvalidArgumentException;

final class InterceptorRoleRegistry
{
    /** @var array<class-string<InterceptorInterface>, list<string>> */
    private array $rolesByInterceptor = [];

    public function register(string $interceptorClass, string|BackedEnum ...$roles): self
    {
        if (!is_a($interceptorClass, InterceptorInterface::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'Interceptor role target `%s` must implement `%s`.',
                $interceptorClass,
                InterceptorInterface::class,
            ));
        }

        $normalized = array_values(array_unique(array_map(
            static fn (string|BackedEnum $role): string => $role instanceof BackedEnum
                ? (string) $role->value
                : $role,
            $roles,
        )));

        $this->rolesByInterceptor[$interceptorClass] = array_values(array_unique([
            ...($this->rolesByInterceptor[$interceptorClass] ?? []),
            ...$normalized,
        ]));

        return $this;
    }

    /** @param class-string<InterceptorInterface> $interceptorClass
     * @return list<string>
     */
    public function rolesFor(string $interceptorClass): array
    {
        return $this->rolesByInterceptor[$interceptorClass] ?? [];
    }

    /**
     * @param list<class-string<InterceptorInterface>> $interceptors
     * @return array<string, int>
     */
    public function firstRolePositions(array $interceptors): array
    {
        $positions = [];
        foreach ($interceptors as $index => $interceptorClass) {
            foreach ($this->rolesFor($interceptorClass) as $role) {
                $positions[$role] ??= $index;
            }
        }

        return $positions;
    }
}
