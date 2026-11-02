<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Tests;

use DateTimeImmutable;
use Fedor\MessageBus\Attribute\CommandHandler;
use Fedor\MessageBus\Attribute\MessageAlias;
use Fedor\MessageBus\Attribute\QueryHandler;
use Fedor\MessageBus\Context\DefaultMessageContextFactory;
use Fedor\MessageBus\Context\MessageContextInterface;
use Fedor\MessageBus\Envelope\EnvelopeFactory;
use Fedor\MessageBus\Exception\NonRetryableMessageExceptionInterface;
use Fedor\MessageBus\Exception\RetryableMessageExceptionInterface;
use Fedor\MessageBus\Execution\SequentialExecutionStrategy;
use Fedor\MessageBus\Flow\Contract\FlowContractRegistry;
use Fedor\MessageBus\Flow\Contract\FlowContractValidationRule;
use Fedor\MessageBus\Flow\Contract\InterceptorRoleRegistry;
use Fedor\MessageBus\Flow\FlowDefinition;
use Fedor\MessageBus\Flow\FlowRegistry;
use Fedor\MessageBus\Idempotency\Decision\IdempotencyClaimed;
use Fedor\MessageBus\Idempotency\Decision\IdempotencyCompletedSame;
use Fedor\MessageBus\Idempotency\Decision\IdempotencyConflict;
use Fedor\MessageBus\Idempotency\Decision\IdempotencyDecisionInterface;
use Fedor\MessageBus\Idempotency\Decision\IdempotencyInProgress;
use Fedor\MessageBus\Idempotency\Exception\IdempotencyConflictDetected;
use Fedor\MessageBus\Idempotency\Exception\IdempotencyInProgressException;
use Fedor\MessageBus\Idempotency\Exception\IdempotencyUnsupportedHandlerKind;
use Fedor\MessageBus\Idempotency\Exception\MissingIdempotencyKey;
use Fedor\MessageBus\Idempotency\IdempotencyClaim;
use Fedor\MessageBus\Idempotency\IdempotencyCompletion;
use Fedor\MessageBus\Idempotency\IdempotencyEffectReference;
use Fedor\MessageBus\Idempotency\IdempotencyEffectReferenceFactoryInterface;
use Fedor\MessageBus\Idempotency\IdempotencyExecution;
use Fedor\MessageBus\Idempotency\IdempotencyFlowContract;
use Fedor\MessageBus\Idempotency\IdempotencyInterceptorRole;
use Fedor\MessageBus\Idempotency\IdempotencyKey;
use Fedor\MessageBus\Idempotency\IdempotencyKeyExtractorInterface;
use Fedor\MessageBus\Idempotency\IdempotencyPolicyDescriptor;
use Fedor\MessageBus\Idempotency\IdempotencyPolicyProviderInterface;
use Fedor\MessageBus\Idempotency\IdempotencyPolicyResolver;
use Fedor\MessageBus\Idempotency\IdempotencyStoreInterface;
use Fedor\MessageBus\Idempotency\IntentFingerprint;
use Fedor\MessageBus\Idempotency\IntentFingerprintFactoryInterface;
use Fedor\MessageBus\Idempotency\RequiresIdempotencyKey;
use Fedor\MessageBus\Idempotency\ResolvedIdempotencyPolicy;
use Fedor\MessageBus\Idempotency\ResolvedIdempotencyPolicyRegistry;
use Fedor\MessageBus\Identity\MessageIdGeneratorInterface;
use Fedor\MessageBus\Interceptor\InterceptorInterface;
use Fedor\MessageBus\Interceptor\PipelineInterface;
use Fedor\MessageBus\Message\Command;
use Fedor\MessageBus\Message\Query;
use Fedor\MessageBus\MessageBus;
use Fedor\MessageBus\Registry\CompiledMessageRegistry;
use Fedor\MessageBus\Registry\MessageRegistryCompiler;
use Fedor\MessageBus\Registry\Metadata\RegistryOwner;
use Fedor\MessageBus\Registry\Metadata\RegistrySource;
use Fedor\MessageBus\Registry\RegistryDiagnostic;
use Fedor\MessageBus\Registry\RegistryDiagnosticCodes;
use Fedor\MessageBus\Service\ServiceReference;
use Fedor\MessageBus\Service\ServiceResolverInterface;
use Fedor\MessageBus\Service\ServiceScopeFactoryInterface;
use Fedor\MessageBus\Service\ServiceScopeInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Throwable;

final class FlowContractAndIdempotencyTest extends TestCase
{
    public function testValueObjectsExposeStableDigestEqualityAndEffectShape(): void
    {
        $key = new IdempotencyKey(' request-123 ');
        $same = IntentFingerprint::sha256('{"id":1}');
        $alsoSame = IntentFingerprint::sha256('{"id":1}');
        $otherVersion = IntentFingerprint::sha256('{"id":1}', version: '2');
        $effect = new IdempotencyEffectReference('queue-message', 'job-1', 'v1');

        self::assertSame('request-123', $key->value);
        self::assertSame(hash('sha256', 'request-123'), $key->digest);
        self::assertTrue($same->equals($alsoSame));
        self::assertFalse($same->equals($otherVersion));
        self::assertSame([
            'type' => 'queue-message',
            'id' => 'job-1',
            'version' => 'v1',
        ], $effect->toArray());
        self::assertSame(
            ['type' => 'queue-message', 'id' => 'job-1'],
            (new IdempotencyEffectReference('queue-message', 'job-1'))->toArray(),
        );
    }

    public function testPolicyDescriptorResolverAndRegistryPreserveOwnership(): void
    {
        $services = new IdempotencyTestServiceResolver([
            'key' => new IdempotencyTestKeyExtractor(),
            'fingerprint' => new IdempotencyTestFingerprintFactory(),
            'store' => new IdempotencyTestStore(),
            'effect' => new IdempotencyTestEffectFactory(),
        ]);
        $provider = new IdempotencyTestPolicyProvider();
        $registry = (new IdempotencyPolicyResolver($services))->resolve([$provider]);

        self::assertTrue($registry->has('orders.create'));
        self::assertTrue($registry->hasForBinding('orders.create'));
        $owner = $registry->ownerForBinding('orders.create');
        self::assertNotNull($owner);
        self::assertTrue(RegistryOwner::core('orders')->equals($owner));
        self::assertSame($registry->require('orders.create'), $registry->get('orders.create'));
        self::assertSame(['orders.create'], array_keys(iterator_to_array($registry->all())));
        self::assertSame(IdempotencyTestStore::class, $registry->toArray()['orders.create']['store']);

        try {
            IdempotencyPolicyDescriptor::forBinding(
                'orders.create',
                $provider->keyExtractor,
                $provider->fingerprintFactory,
                $provider->store,
                retentionSeconds: 0,
            );
            self::fail('Expected invalid retention to fail.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('retentionSeconds must be positive', $exception->getMessage());
        }

        try {
            (new IdempotencyPolicyResolver($services))->resolve([$provider, $provider]);
            self::fail('Expected duplicate policy to fail.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('Duplicate idempotency policy', $exception->getMessage());
        }
    }

    public function testFlowContractAcceptsTheV6InterceptorModel(): void
    {
        $policyRegistry = self::policyRegistry(RegistryOwner::core('orders'));
        $result = (new MessageRegistryCompiler(validationRules: [self::idempotencyRule($policyRegistry)]))
            ->compileWithDiagnostics(
                [IdempotencyTestCommandHandler::class],
                self::idempotentFlows(IdempotencyTestTransactionInterceptor::class, RequiresIdempotencyKey::class),
            );

        self::assertTrue($result->hasDefinition(), self::diagnosticsAsString($result->diagnostics));
        self::assertNotNull($result->definition);
        $binding = $result->definition->bindings['orders.create'];
        self::assertTrue($binding->owner?->equals(RegistryOwner::core('orders')) ?? false);
        self::assertSame('orders-package', $binding->source?->package);
    }

    public function testFlowContractRejectsMissingRoleOrderOwnerQueryAndAutoBindingId(): void
    {
        $policyRegistry = self::policyRegistry(RegistryOwner::core('orders'));
        $compiler = new MessageRegistryCompiler(validationRules: [self::idempotencyRule($policyRegistry)]);

        $missingRole = $compiler->compileWithDiagnostics(
            [IdempotencyTestCommandHandler::class],
            self::idempotentFlows(IdempotencyTestTransactionInterceptor::class),
        );
        self::assertStringContainsString('requires interceptor role', self::diagnosticsAsString($missingRole->diagnostics));

        $wrongOrder = $compiler->compileWithDiagnostics(
            [IdempotencyTestCommandHandler::class],
            self::idempotentFlows(RequiresIdempotencyKey::class, IdempotencyTestTransactionInterceptor::class),
        );
        self::assertStringContainsString('before `idempotency_guard`', self::diagnosticsAsString($wrongOrder->diagnostics));

        $missingOwner = (new MessageRegistryCompiler(validationRules: [self::idempotencyRule($policyRegistry)]))
            ->compileWithDiagnostics(
                [IdempotencyTestOwnerlessHandler::class],
                self::idempotentFlows(IdempotencyTestTransactionInterceptor::class, RequiresIdempotencyKey::class),
            );
        self::assertStringContainsString('requires binding owner metadata', self::diagnosticsAsString($missingOwner->diagnostics));

        $wrongOwner = (new MessageRegistryCompiler(validationRules: [self::idempotencyRule(
            self::policyRegistry(RegistryOwner::feature('other')),
        )]))->compileWithDiagnostics(
            [IdempotencyTestCommandHandler::class],
            self::idempotentFlows(IdempotencyTestTransactionInterceptor::class, RequiresIdempotencyKey::class),
        );
        self::assertStringContainsString('does not match policy owner', self::diagnosticsAsString($wrongOwner->diagnostics));

        $query = (new MessageRegistryCompiler(validationRules: [self::idempotencyRule(
            self::policyRegistry(RegistryOwner::core('orders'), 'orders.lookup'),
        )]))->compileWithDiagnostics(
            [IdempotencyTestQueryHandler::class],
            self::idempotentFlows(IdempotencyTestTransactionInterceptor::class, RequiresIdempotencyKey::class),
        );
        self::assertStringContainsString('does not allow `query`', self::diagnosticsAsString($query->diagnostics));

        $autoBinding = (new MessageRegistryCompiler(validationRules: [self::idempotencyRule(
            self::policyRegistry(RegistryOwner::core('orders'), 'auto.placeholder'),
        )]))->compileWithDiagnostics(
            [IdempotencyTestAutoBindingHandler::class],
            self::idempotentFlows(IdempotencyTestTransactionInterceptor::class, RequiresIdempotencyKey::class),
        );
        self::assertStringContainsString('requires stable bindingId', self::diagnosticsAsString($autoBinding->diagnostics));

        self::assertContains(
            RegistryDiagnosticCodes::FLOW_CONTRACT_VIOLATION,
            array_map(static fn (RegistryDiagnostic $diagnostic): string => $diagnostic->code, $missingRole->diagnostics),
        );
    }

    public function testInterceptorClaimsCompletesAndRecordsTheEffect(): void
    {
        IdempotencyTestRuntimeLog::$events = [];
        $store = new IdempotencyTestStore();
        $bus = self::idempotentBus($store);

        $bus->dispatch(new IdempotencyTestCommand('key-1', 'payload'));

        self::assertSame(['handled:payload'], IdempotencyTestRuntimeLog::$events);
        self::assertCount(1, $store->claims);
        self::assertCount(1, $store->completions);
        self::assertSame('orders.create', $store->claims[0][0]->bindingId);
        self::assertSame('orders.create', $store->claims[0][0]->messageAlias);
        self::assertSame('2026-11-02T19:00:00+00:00', $store->claims[0][0]->createdAt->format(DATE_ATOM));
        self::assertSame('effect', $store->completions[0]->effectReference?->type);
        self::assertSame('2026-11-02T20:00:00+00:00', $store->completions[0]->completedAt->format(DATE_ATOM));
    }

    public function testCompletedReplayIsANoOp(): void
    {
        IdempotencyTestRuntimeLog::$events = [];
        $store = new IdempotencyTestStore([
            new IdempotencyCompletedSame(new DateTimeImmutable('2026-11-02T18:00:00+00:00')),
        ]);

        self::idempotentBus($store)->dispatch(new IdempotencyTestCommand('key-1', 'payload'));

        self::assertSame([], IdempotencyTestRuntimeLog::$events);
        self::assertSame([], $store->completions);
    }

    public function testHandlerFailureDoesNotCompleteTheClaim(): void
    {
        IdempotencyTestRuntimeLog::$events = [];
        $store = new IdempotencyTestStore();

        try {
            self::idempotentBus($store)->dispatch(new IdempotencyTestCommand('key-1', 'fail'));
            self::fail('Expected handler failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('handler failed', $exception->getMessage());
        }

        self::assertCount(1, $store->claims);
        self::assertSame([], $store->completions);
    }

    public function testConflictInProgressAndMissingKeyKeepV6FailureClasses(): void
    {
        try {
            self::idempotentBus(new IdempotencyTestStore([new IdempotencyConflict()]))
                ->dispatch(new IdempotencyTestCommand('key-1', 'changed'));
            self::fail('Expected conflict.');
        } catch (IdempotencyConflictDetected $exception) {
            self::assertInstanceOf(NonRetryableMessageExceptionInterface::class, $exception);
        }

        try {
            self::idempotentBus(new IdempotencyTestStore([new IdempotencyInProgress()]))
                ->dispatch(new IdempotencyTestCommand('key-1', 'payload'));
            self::fail('Expected in-progress decision.');
        } catch (IdempotencyInProgressException $exception) {
            self::assertInstanceOf(RetryableMessageExceptionInterface::class, $exception);
        }

        $this->expectException(MissingIdempotencyKey::class);
        self::idempotentBus(new IdempotencyTestStore())
            ->dispatch(new IdempotencyTestCommand('', 'payload'));
    }

    public function testQueryIsRejectedAtRuntimeEvenWithoutCompileTimeRule(): void
    {
        $flows = new FlowRegistry(
            FlowDefinition::sync('idempotent')->middleware(RequiresIdempotencyKey::class),
        );
        $definition = (new MessageRegistryCompiler())->compile([IdempotencyTestQueryHandler::class], $flows);
        $registry = new CompiledMessageRegistry($definition);
        $policyRegistry = self::policyRegistry(RegistryOwner::core('orders'), 'orders.lookup');
        $services = [
            DefaultMessageContextFactory::class => new DefaultMessageContextFactory(),
            SequentialExecutionStrategy::class => new SequentialExecutionStrategy(),
            IdempotencyTestQueryHandler::class => new IdempotencyTestQueryHandler(),
            RequiresIdempotencyKey::class => new RequiresIdempotencyKey(
                $policyRegistry,
                $registry,
                new IdempotencyTestClock('2026-11-02T20:00:00+00:00'),
            ),
        ];
        $bus = new MessageBus($registry, new IdempotencyTestScopeFactory($services));

        $this->expectException(IdempotencyUnsupportedHandlerKind::class);

        $bus->ask(new IdempotencyTestQuery('key-1'));
    }

    private static function idempotentBus(IdempotencyTestStore $store): MessageBus
    {
        $flows = new FlowRegistry(
            FlowDefinition::sync('idempotent')->middleware(RequiresIdempotencyKey::class),
        );
        $definition = (new MessageRegistryCompiler())->compile([IdempotencyTestCommandHandler::class], $flows);
        $registry = new CompiledMessageRegistry($definition);
        $policyRegistry = self::policyRegistry(RegistryOwner::core('orders'), store: $store);
        $services = [
            DefaultMessageContextFactory::class => new DefaultMessageContextFactory(),
            SequentialExecutionStrategy::class => new SequentialExecutionStrategy(),
            IdempotencyTestCommandHandler::class => new IdempotencyTestCommandHandler(),
            RequiresIdempotencyKey::class => new RequiresIdempotencyKey(
                $policyRegistry,
                $registry,
                new IdempotencyTestClock('2026-11-02T20:00:00+00:00'),
            ),
        ];

        return new MessageBus(
            $registry,
            new IdempotencyTestScopeFactory($services),
            new EnvelopeFactory(
                new IdempotencyTestMessageIdGenerator(),
                new IdempotencyTestClock('2026-11-02T19:00:00+00:00'),
            ),
        );
    }

    private static function idempotencyRule(
        ResolvedIdempotencyPolicyRegistry $policyRegistry,
    ): FlowContractValidationRule {
        $roles = (new InterceptorRoleRegistry())
            ->register(IdempotencyTestTransactionInterceptor::class, 'transaction_boundary')
            ->register(RequiresIdempotencyKey::class, IdempotencyInterceptorRole::Guard);
        $contracts = new FlowContractRegistry(
            IdempotencyFlowContract::requiresIdempotencyKey('idempotent')
                ->requiresOrder('transaction_boundary', IdempotencyInterceptorRole::Guard),
        );

        return new FlowContractValidationRule($contracts, $roles, [$policyRegistry]);
    }

    /** @param class-string<InterceptorInterface> ...$interceptors */
    private static function idempotentFlows(string ...$interceptors): FlowRegistry
    {
        return new FlowRegistry(FlowDefinition::sync('idempotent')->middleware(...$interceptors));
    }

    private static function policyRegistry(
        RegistryOwner $owner,
        string $bindingId = 'orders.create',
        ?IdempotencyTestStore $store = null,
    ): ResolvedIdempotencyPolicyRegistry {
        return new ResolvedIdempotencyPolicyRegistry(new ResolvedIdempotencyPolicy(
            $bindingId,
            $owner,
            RegistrySource::provider(IdempotencyTestPolicyProvider::class, 'orders-package'),
            new IdempotencyTestKeyExtractor(),
            new IdempotencyTestFingerprintFactory(),
            $store ?? new IdempotencyTestStore(),
            new IdempotencyTestEffectFactory(),
            3600,
        ));
    }

    /** @param list<RegistryDiagnostic> $diagnostics */
    private static function diagnosticsAsString(array $diagnostics): string
    {
        return implode("\n", array_map(
            static fn (RegistryDiagnostic $diagnostic): string => $diagnostic->code . ': ' . $diagnostic->message,
            $diagnostics,
        ));
    }
}

#[MessageAlias('orders.create')]
final readonly class IdempotencyTestCommand implements Command
{
    public function __construct(public string $key, public string $payload)
    {
    }
}

/** @implements Query<string> */
final readonly class IdempotencyTestQuery implements Query
{
    public function __construct(public string $key)
    {
    }
}

final readonly class IdempotencyTestAutoBindingCommand implements Command
{
    public function __construct(public string $key)
    {
    }
}

final class IdempotencyTestRuntimeLog
{
    /** @var list<string> */
    public static array $events = [];
}

#[CommandHandler(
    IdempotencyTestCommand::class,
    flow: 'idempotent',
    bindingId: 'orders.create',
    contextAware: false,
    ownerKind: 'core',
    ownerId: 'orders',
    sourcePackage: 'orders-package',
)]
final class IdempotencyTestCommandHandler
{
    public function __invoke(IdempotencyTestCommand $message): void
    {
        if ($message->payload === 'fail') {
            throw new RuntimeException('handler failed');
        }
        IdempotencyTestRuntimeLog::$events[] = 'handled:' . $message->payload;
    }
}

#[CommandHandler(
    IdempotencyTestCommand::class,
    flow: 'idempotent',
    bindingId: 'orders.create',
    contextAware: false,
)]
final class IdempotencyTestOwnerlessHandler
{
    public function __invoke(IdempotencyTestCommand $message): void
    {
    }
}

#[CommandHandler(
    IdempotencyTestAutoBindingCommand::class,
    flow: 'idempotent',
    contextAware: false,
    ownerKind: 'core',
    ownerId: 'orders',
)]
final class IdempotencyTestAutoBindingHandler
{
    public function __invoke(IdempotencyTestAutoBindingCommand $message): void
    {
    }
}

#[QueryHandler(
    IdempotencyTestQuery::class,
    flow: 'idempotent',
    bindingId: 'orders.lookup',
    contextAware: false,
    ownerKind: 'core',
    ownerId: 'orders',
)]
final class IdempotencyTestQueryHandler
{
    public function __invoke(IdempotencyTestQuery $message): string
    {
        return 'query';
    }
}

final readonly class IdempotencyTestTransactionInterceptor implements InterceptorInterface
{
    public function __invoke(MessageContextInterface $context, PipelineInterface $pipeline): mixed
    {
        return $pipeline->continue();
    }
}

final readonly class IdempotencyTestKeyExtractor implements IdempotencyKeyExtractorInterface
{
    public function extract(object $message): IdempotencyKey
    {
        if (!$message instanceof IdempotencyTestCommand && !$message instanceof IdempotencyTestQuery) {
            throw new InvalidArgumentException('Unsupported test message.');
        }
        if ($message->key === '') {
            throw MissingIdempotencyKey::forMessage($message);
        }

        return new IdempotencyKey($message->key);
    }
}

final readonly class IdempotencyTestFingerprintFactory implements IntentFingerprintFactoryInterface
{
    public function fingerprint(object $message): IntentFingerprint
    {
        return IntentFingerprint::sha256(serialize($message));
    }
}

final readonly class IdempotencyTestEffectFactory implements IdempotencyEffectReferenceFactoryInterface
{
    public function effectReference(object $message, IdempotencyExecution $execution): IdempotencyEffectReference
    {
        return new IdempotencyEffectReference('effect', $execution->bindingId);
    }
}

final class IdempotencyTestStore implements IdempotencyStoreInterface
{
    /** @var list<IdempotencyDecisionInterface> */
    private array $decisions;

    /** @var list<array{IdempotencyExecution, IdempotencyKey, IntentFingerprint}> */
    public array $claims = [];

    /** @var list<IdempotencyCompletion> */
    public array $completions = [];

    /** @param list<IdempotencyDecisionInterface> $decisions */
    public function __construct(array $decisions = [])
    {
        $this->decisions = $decisions;
    }

    public function claim(
        IdempotencyExecution $execution,
        IdempotencyKey $key,
        IntentFingerprint $fingerprint,
    ): IdempotencyDecisionInterface {
        $this->claims[] = [$execution, $key, $fingerprint];

        return array_shift($this->decisions)
            ?? new IdempotencyClaimed(new IdempotencyClaim(
                $execution->bindingId,
                $key,
                $fingerprint,
                'claim-1',
            ));
    }

    public function complete(IdempotencyClaim $claim, IdempotencyCompletion $completion): void
    {
        $this->completions[] = $completion;
    }
}

final readonly class IdempotencyTestClock implements ClockInterface
{
    public function __construct(private string $time)
    {
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->time);
    }
}

final class IdempotencyTestMessageIdGenerator implements MessageIdGeneratorInterface
{
    private int $sequence = 0;

    public function generate(): string
    {
        return 'message-' . ++$this->sequence;
    }
}

final readonly class IdempotencyTestScopeFactory implements ServiceScopeFactoryInterface
{
    /** @param array<string, object> $services */
    public function __construct(private array $services)
    {
    }

    public function openScope(): ServiceScopeInterface
    {
        return new IdempotencyTestScope($this->services);
    }
}

final readonly class IdempotencyTestScope implements ServiceScopeInterface
{
    /** @param array<string, object> $services */
    public function __construct(private array $services)
    {
    }

    public function resolve(ServiceReference $reference): object
    {
        $service = $this->services[$reference->id] ?? throw new RuntimeException(
            'Unknown test service ' . $reference->id,
        );
        if (!$service instanceof $reference->type) {
            throw new RuntimeException('Unexpected test service type.');
        }

        return $service;
    }

    public function dispose(?Throwable $error = null): void
    {
    }
}

final readonly class IdempotencyTestServiceResolver implements ServiceResolverInterface
{
    /** @param array<string, object> $services */
    public function __construct(private array $services)
    {
    }

    public function resolve(ServiceReference $reference): object
    {
        $service = $this->services[$reference->id] ?? throw new RuntimeException(
            'Unknown test service ' . $reference->id,
        );
        if (!$service instanceof $reference->type) {
            throw new RuntimeException('Unexpected test service type.');
        }

        return $service;
    }
}

final readonly class IdempotencyTestPolicyProvider implements IdempotencyPolicyProviderInterface
{
    /** @var ServiceReference<IdempotencyKeyExtractorInterface> */
    public ServiceReference $keyExtractor;
    /** @var ServiceReference<IntentFingerprintFactoryInterface> */
    public ServiceReference $fingerprintFactory;
    /** @var ServiceReference<IdempotencyStoreInterface> */
    public ServiceReference $store;
    /** @var ServiceReference<IdempotencyEffectReferenceFactoryInterface> */
    public ServiceReference $effectReferenceFactory;

    public function __construct()
    {
        $this->keyExtractor = new ServiceReference('key', IdempotencyKeyExtractorInterface::class);
        $this->fingerprintFactory = new ServiceReference('fingerprint', IntentFingerprintFactoryInterface::class);
        $this->store = new ServiceReference('store', IdempotencyStoreInterface::class);
        $this->effectReferenceFactory = new ServiceReference('effect', IdempotencyEffectReferenceFactoryInterface::class);
    }

    public function owner(): RegistryOwner
    {
        return RegistryOwner::core('orders');
    }

    public function source(): RegistrySource
    {
        return RegistrySource::provider(self::class, 'orders-package', 'config/idempotency.php');
    }

    public function policies(): iterable
    {
        yield IdempotencyPolicyDescriptor::forBinding(
            'orders.create',
            $this->keyExtractor,
            $this->fingerprintFactory,
            $this->store,
            $this->effectReferenceFactory,
            600,
        );
    }
}
