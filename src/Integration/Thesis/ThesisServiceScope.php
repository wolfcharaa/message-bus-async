<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Integration\Thesis;

use Closure;
use Fedor\MessageBus\Service\ServiceReference;
use Fedor\MessageBus\Service\ServiceResolverInterface;
use Fedor\MessageBus\Service\ServiceScopeInterface;
use Fiber;
use LogicException;
use Thesis\Dic\Scoped;
use Throwable;

final class ThesisServiceScope implements ServiceScopeInterface
{
    private bool $disposed = false;

    private function __construct(
        private readonly ServiceResolverInterface $resolver,
        /** @var Closure(?Throwable): void */
        private readonly Closure $dispose,
    ) {
    }

    /** @param Scoped<ServiceResolverInterface> $resolver */
    public static function open(Scoped $resolver): self
    {
        $fiber = new Fiber(static function () use ($resolver): void {
            $resolver->run(static function (ServiceResolverInterface $current): void {
                $error = Fiber::suspend($current);
                if ($error instanceof Throwable) {
                    throw $error;
                }
            });
        });
        $current = $fiber->start();
        if (!$current instanceof ServiceResolverInterface || !$fiber->isSuspended()) {
            throw new LogicException('Thesis DIC scope did not provide a suspended service resolver.');
        }

        return new self($current, static function (?Throwable $error) use ($fiber): void {
            try {
                $fiber->resume($error);
            } catch (Throwable $scopeError) {
                if ($error !== null && $scopeError === $error) {
                    return;
                }

                throw $scopeError;
            }

            if (!$fiber->isTerminated()) {
                throw new LogicException('Thesis DIC scope did not terminate during disposal.');
            }
        });
    }

    public function resolve(ServiceReference $reference): object
    {
        if ($this->disposed) {
            throw new LogicException('Cannot resolve a service from a disposed Thesis DIC scope.');
        }

        return $this->resolver->resolve($reference);
    }

    public function dispose(?Throwable $error = null): void
    {
        if ($this->disposed) {
            return;
        }

        $this->disposed = true;

        ($this->dispose)($error);
    }
}
