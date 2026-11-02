<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Service;

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

        try {
            return $execution($scope);
        } finally {
            $scope->dispose();
        }
    }
}
