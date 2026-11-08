<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Tests;

use Amp\DeferredFuture;
use Fedor\MessageBus\Integration\Psr\PsrContainerServiceResolver;
use Fedor\MessageBus\Integration\Thesis\ThesisServiceResolver;
use Fedor\MessageBus\Integration\Thesis\ThesisServiceScopeFactory;
use Fedor\MessageBus\Service\ServiceReference;
use Fedor\MessageBus\Service\ServiceResolutionFailed;
use Fedor\MessageBus\Service\ServiceScopeFailure;
use Fedor\MessageBus\Service\ServiceScopeFactoryInterface;
use Fedor\MessageBus\Service\ServiceScopeInterface;
use Fedor\MessageBus\Service\ServiceScopeRunner;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;
use Throwable;

use function Amp\async;

final class ServiceIntegrationTest extends TestCase
{
    public function testPsrContainerResolverValidatesResolvedServiceType(): void
    {
        $service = new IntegrationService();
        $resolver = new PsrContainerServiceResolver(new IntegrationPsrContainer([
            'service' => $service,
            'invalid' => new \stdClass(),
        ]));

        self::assertSame(
            $service,
            $resolver->resolve(new ServiceReference('service', IntegrationService::class)),
        );

        $this->expectException(ServiceResolutionFailed::class);
        $resolver->resolve(new ServiceReference('invalid', IntegrationService::class));
    }

    public function testThesisResolverReportsMissingTypedService(): void
    {
        $resolver = new ThesisServiceResolver([]);

        $this->expectException(ServiceResolutionFailed::class);
        $this->expectExceptionMessage('is not registered');

        $resolver->resolve(ServiceReference::forClass(IntegrationService::class));
    }

    public function testThesisAdapterIsolatesParallelScopesAndPropagatesFailureToDisposer(): void
    {
        $trace = new IntegrationLifecycleTrace(2);
        $failure = new RuntimeException('execution failed');

        Dic::run(
            new IntegrationThesisModule($trace),
            static function (ThesisServiceScopeFactory $scopeFactory) use ($trace, $failure): void {
                $runner = new ServiceScopeRunner($scopeFactory);
                $reference = ServiceReference::forClass(IntegrationService::class);
                $first = async(static fn (): int => $runner->run(
                    static function (ServiceScopeInterface $scope) use ($reference, $trace): int {
                        $service = $scope->resolve($reference);
                        $trace->awaitParallelResolution();

                        return $service->id;
                    },
                ));
                $second = async(static fn (): int => $runner->run(
                    static function (ServiceScopeInterface $scope) use ($reference, $trace): int {
                        $service = $scope->resolve($reference);
                        $trace->awaitParallelResolution();

                        return $service->id;
                    },
                ));
                $ids = [$first->await(), $second->await()];
                sort($ids);

                self::assertSame([1, 2], $ids);
                self::assertSame([null, null], $trace->disposedErrors);

                try {
                    $runner->run(static function (ServiceScopeInterface $scope) use ($reference, $failure): never {
                        $scope->resolve($reference);

                        throw $failure;
                    });
                } catch (RuntimeException $error) {
                    self::assertSame($failure, $error);
                }

                self::assertSame(3, $trace->created);
                self::assertSame([null, null, $failure], $trace->disposedErrors);
            },
        );
    }

    public function testScopeCleanupFailurePreservesThePrimaryFailure(): void
    {
        $primary = new RuntimeException('execution failed');
        $cleanup = new RuntimeException('cleanup failed');
        $runner = new ServiceScopeRunner(new FailingCleanupScopeFactory($cleanup));

        try {
            $runner->run(static function () use ($primary): never {
                throw $primary;
            });
        } catch (ServiceScopeFailure $error) {
            self::assertSame($primary, $error->primaryFailure());
            self::assertSame([$cleanup], $error->cleanupFailures());
            self::assertSame($primary, $error->getPrevious());
        }
    }

    public function testScopeCleanupFailureWithoutPrimaryHasATypedEnvelope(): void
    {
        $cleanup = new RuntimeException('cleanup failed');
        $runner = new ServiceScopeRunner(new FailingCleanupScopeFactory($cleanup));

        try {
            $runner->run(static fn (): string => 'result');
            self::fail('Expected scope cleanup to fail.');
        } catch (ServiceScopeFailure $error) {
            self::assertNull($error->primaryFailure());
            self::assertSame([$cleanup], $error->cleanupFailures());
            self::assertSame($cleanup, $error->getPrevious());
        }
    }
}

final readonly class FailingCleanupScopeFactory implements ServiceScopeFactoryInterface
{
    public function __construct(private Throwable $cleanupFailure)
    {
    }

    public function openScope(): ServiceScopeInterface
    {
        return new FailingCleanupScope($this->cleanupFailure);
    }
}

final readonly class FailingCleanupScope implements ServiceScopeInterface
{
    public function __construct(private Throwable $cleanupFailure)
    {
    }

    public function resolve(ServiceReference $reference): object
    {
        throw new RuntimeException('The cleanup fixture does not resolve services.');
    }

    public function dispose(?Throwable $error = null): void
    {
        throw $this->cleanupFailure;
    }
}

final readonly class IntegrationPsrContainer implements ContainerInterface
{
    /** @param array<string, mixed> $services */
    public function __construct(private array $services)
    {
    }

    public function get(string $id): mixed
    {
        return $this->services[$id] ?? throw new RuntimeException(sprintf('Service `%s` was not found.', $id));
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->services);
    }
}

final class IntegrationLifecycleTrace
{
    public int $created = 0;

    /** @var list<?Throwable> */
    public array $disposedErrors = [];

    private int $arrived = 0;
    /** @var DeferredFuture<void> */
    private readonly DeferredFuture $parallelReady;

    public function __construct(private readonly int $parallelParties)
    {
        $this->parallelReady = new DeferredFuture();
    }

    public function awaitParallelResolution(): void
    {
        ++$this->arrived;
        if ($this->arrived === $this->parallelParties) {
            $this->parallelReady->complete();
        }

        $this->parallelReady->getFuture()->await();
    }
}

final readonly class IntegrationService
{
    public int $id;

    public function __construct(private IntegrationLifecycleTrace $trace = new IntegrationLifecycleTrace(1))
    {
        $this->id = ++$this->trace->created;
    }

    public function dispose(?Throwable $error): void
    {
        $this->trace->disposedErrors[] = $error;
    }
}

/** @implements Module<Ref<ThesisServiceScopeFactory>> */
final readonly class IntegrationThesisModule implements Module
{
    public function __construct(private IntegrationLifecycleTrace $trace)
    {
    }

    public function configure(Dic $dic): Ref
    {
        $trace = $dic->value($this->trace);
        $service = $dic
            ->object(IntegrationService::class)
            ->arg('trace', $trace)
            ->scoped()
            ->disposer(static function (IntegrationService $service, ?Throwable $error): void {
                $service->dispose($error);
            });
        $resolver = $dic
            ->object(ThesisServiceResolver::class)
            ->arg('providers', $dic->value([
                IntegrationService::class => $dic->provider($service),
            ]))
            ->scoped();

        return $dic
            ->object(ThesisServiceScopeFactory::class)
            ->arg('resolver', $dic->scoped($resolver));
    }
}
