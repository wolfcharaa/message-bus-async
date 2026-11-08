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

        try {
            $result = $execution($scope);
        } catch (Throwable $primaryFailure) {
            try {
                $scope->dispose($primaryFailure);
            } catch (Throwable $cleanupFailure) {
                throw new ServiceScopeFailure($primaryFailure, $cleanupFailure);
            }

            throw $primaryFailure;
        }

        try {
            $scope->dispose();
        } catch (Throwable $cleanupFailure) {
            throw new ServiceScopeFailure(null, $cleanupFailure);
        }

        return $result;
    }
}
