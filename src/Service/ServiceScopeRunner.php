<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Service;

use Throwable;

final readonly class ServiceScopeRunner
{
    public function __construct(private ServiceScopeFactoryInterface $scopeFactory)
    {
    }

    /**
     * @template TResult
     * @param callable(ServiceScopeInterface): TResult $execution
     * @return TResult
     */
    public function run(callable $execution): mixed
    {
        $scope = $this->scopeFactory->openScope();
        $error = null;

        try {
            return $execution($scope);
        } catch (Throwable $caught) {
            $error = $caught;

            throw $caught;
        } finally {
            $scope->dispose($error);
        }
    }
}
