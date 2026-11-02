<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Invoker;

use ReflectionMethod;
use RuntimeException;

final class ReflectionCallableInvoker implements CallableInvokerInterface
{
    #[\Override]
    public function invoke(object $service, string $method, array $arguments): mixed
    {
        if (!method_exists($service, $method)) {
            throw new RuntimeException(sprintf(
                'Callable target `%s::%s` does not exist.',
                $service::class,
                $method,
            ));
        }

        return (new ReflectionMethod($service, $method))->invokeArgs($service, $arguments);
    }
}
