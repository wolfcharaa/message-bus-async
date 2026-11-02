<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Tests;

use Fedor\MessageBus\Attribute\CommandHandler;
use Fedor\MessageBus\Attribute\EventSubscriber;
use Fedor\MessageBus\Attribute\MessageAlias;
use Fedor\MessageBus\Attribute\QueryHandler;
use Fedor\MessageBus\Context\MessageContextInterface;
use Fedor\MessageBus\Dumper\CompiledRegistryFileWriter;
use Fedor\MessageBus\Flow\FlowDefinition;
use Fedor\MessageBus\Flow\FlowRegistry;
use Fedor\MessageBus\Interceptor\InterceptorInterface;
use Fedor\MessageBus\Interceptor\PipelineInterface;
use Fedor\MessageBus\Message\Command;
use Fedor\MessageBus\Message\Event;
use Fedor\MessageBus\Message\Query;
use Fedor\MessageBus\Queue\QueueDeliveryOptions;
use Fedor\MessageBus\Registry\CompiledMessageRegistry;
use Fedor\MessageBus\Registry\HandlerBinding;
use Fedor\MessageBus\Registry\HandlerBindingDefinition;
use Fedor\MessageBus\Registry\MessageRegistryCompiler;
use Fedor\MessageBus\Registry\MessageRegistryCompilerOptions;
use Fedor\MessageBus\Registry\Metadata\BindingRegistrationContext;
use Fedor\MessageBus\Registry\Metadata\RegistryOwner;
use Fedor\MessageBus\Registry\Metadata\RegistrySource;
use Fedor\MessageBus\Registry\RegistryCompilationException;
use Fedor\MessageBus\Registry\RegistryCompilationGraphContext;
use Fedor\MessageBus\Registry\RegistryCompilationStage;
use Fedor\MessageBus\Registry\RegistryDiagnostic;
use Fedor\MessageBus\Registry\RegistryDiagnosticCodes;
use Fedor\MessageBus\Registry\RegistryDiagnosticSeverity;
use Fedor\MessageBus\Registry\RegistryRuntimeLoader;
use Fedor\MessageBus\Registry\RegistryValidationRuleInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class RegistryParityTest extends TestCase
{
    public function testSyncBindingPreservesV6AutoIdentityAndPrimarySelection(): void
    {
        $definition = (new MessageRegistryCompiler())->compile([ParityLocalQueryHandler::class]);
        $registry = new CompiledMessageRegistry($definition);
        $binding = $registry->bindingsForMessage(ParityLocalQuery::class)[0] ?? null;

        self::assertInstanceOf(HandlerBinding::class, $binding);
        self::assertSame($binding->id, array_values($definition->bindings)[0]->bindingId);
        self::assertStringStartsWith('auto.', $binding->id);
        self::assertTrue($binding->primary);
        self::assertNull($registry->messageName(ParityLocalQuery::class));
        self::assertSame(RegistryCompilationStage::DefinitionBuilt, (new MessageRegistryCompiler())
            ->compileWithDiagnostics([ParityLocalQueryHandler::class])
            ->graphContext?->stage);
    }

    public function testAsyncFlowIdentityDeliveryAndArtifactLifecycleMatchV6(): void
    {
        $flows = self::flows();
        $compiler = new MessageRegistryCompiler();
        $definition = $compiler->compile(
            [ParityDurableEventHandler::class],
            $flows,
            sourceHash: 'compiled-source',
        );

        $file = sys_get_temp_dir() . '/message-bus-async-registry-' . bin2hex(random_bytes(6)) . '/registry.php';
        try {
            self::assertSame($file, (new CompiledRegistryFileWriter())->write($definition, $file));

            $loaded = CompiledMessageRegistry::fromFile($file);
            $binding = $loaded->binding('event.durable.audit');

            self::assertSame(ParityDurableEvent::class, $loaded->messageClassForName('event.durable'));
            self::assertNotNull($binding->delivery);
            self::assertSame(7, $binding->delivery->priority);
            self::assertSame(15, $binding->delivery->delaySeconds);
            self::assertSame('durable', $binding->delivery->retryPolicy);
            self::assertCount(1, $binding->interceptors);
            self::assertSame(3, $loaded->definition()->flows->get('durable')->delivery?->priority);
            self::assertSame('compiled-source', $loaded->definition()->sourceHash);

            $runtime = (new RegistryRuntimeLoader())->load(
                [],
                compiledFile: $file,
                sourceHash: 'runtime-source',
            );
            self::assertSame('compiled-source', $runtime->definition()->sourceHash);
        } finally {
            @unlink($file);
            @rmdir(dirname($file));
        }
    }

    public function testV6DiagnosticsKeepCodesAndCompilationStages(): void
    {
        $compiler = new MessageRegistryCompiler();
        $flows = self::flows();

        $cases = [
            [[ParityMissingBindingIdHandler::class], $flows, RegistryDiagnosticCodes::BINDING_MISSING_ID, RegistryCompilationStage::BindingsNormalized],
            [[ParityMissingAliasHandler::class], $flows, RegistryDiagnosticCodes::MESSAGE_ALIAS_REQUIRED, RegistryCompilationStage::BindingsNormalized],
            [[ParityAsyncQueryHandler::class], $flows, RegistryDiagnosticCodes::QUERY_ASYNC_FLOW, RegistryCompilationStage::CoreValidated],
            [[ParityDurableEventHandler::class], null, RegistryDiagnosticCodes::FLOW_MISSING, RegistryCompilationStage::BindingsNormalized],
            [[ParityMultiCommandHandler::class], null, RegistryDiagnosticCodes::COMMAND_PRIMARY_MISSING, RegistryCompilationStage::BindingsNormalized],
        ];

        foreach ($cases as [$classes, $caseFlows, $code, $stage]) {
            $result = $compiler->compileWithDiagnostics($classes, $caseFlows);

            self::assertNull($result->definition);
            self::assertSame($stage, $result->graphContext?->stage);
            self::assertContains($code, array_map(
                static fn (RegistryDiagnostic $diagnostic): string => $diagnostic->code,
                $result->diagnostics,
            ));
        }
    }

    public function testRuntimeLoaderCanRequireCompiledArtifactLikeV6(): void
    {
        $missing = sys_get_temp_dir() . '/missing-message-bus-registry-' . bin2hex(random_bytes(6)) . '.php';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Compiled message registry file is required');

        (new RegistryRuntimeLoader())->load(
            [ParityLocalQueryHandler::class],
            compiledFile: $missing,
            requireCompiled: true,
        );
    }

    public function testProjectValidationRulesPreserveWarningPolicyAndGraphContext(): void
    {
        $compiler = new MessageRegistryCompiler(validationRules: [new ParityWarningRule()]);
        $result = $compiler->compileWithDiagnostics([ParitySingleCommandHandler::class]);

        self::assertTrue($result->hasDefinition());
        self::assertTrue($result->hasWarnings());
        self::assertFalse($result->isFailure(new MessageRegistryCompilerOptions()));
        self::assertTrue($result->isFailure(new MessageRegistryCompilerOptions(failOnWarning: true)));
        self::assertNotNull($result->graphContext);
        self::assertSame(RegistryCompilationStage::DefinitionBuilt, $result->graphContext->stage);
        self::assertCount(1, $result->graphContext->bindings);

        try {
            $compiler->compile(
                [ParitySingleCommandHandler::class],
                options: new MessageRegistryCompilerOptions(failOnWarning: true),
            );
            self::fail('Expected warning policy to fail strict compilation.');
        } catch (RegistryCompilationException $exception) {
            self::assertTrue($exception->hasWarnings());
            self::assertFalse($exception->hasErrors());
        }

        $failed = (new MessageRegistryCompiler(validationRules: [new ParityErrorRule()]))
            ->compileWithDiagnostics([ParitySingleCommandHandler::class]);
        self::assertFalse($failed->hasDefinition());
        self::assertSame(RegistryCompilationStage::ProjectRulesValidated, $failed->graphContext?->stage);
        self::assertSame('project.error', $failed->diagnostics[0]->code);
    }

    public function testCompilerStopsAtActualV6BoundariesWithPartialGraph(): void
    {
        $compiler = new MessageRegistryCompiler();

        $discovery = $compiler->compileWithDiagnostics([
            ParityAbstractHandler::class,
        ]);
        self::assertNotNull($discovery->graphContext);
        self::assertSame(RegistryCompilationStage::HandlersDiscovered, $discovery->graphContext->stage);
        self::assertCount(1, $discovery->graphContext->bindings);

        $aliases = $compiler->compileWithDiagnostics([
            ParityAliasOwnerCommand::class,
            ParityHydratedAliasHandler::class,
        ]);
        self::assertNotNull($aliases->graphContext);
        self::assertSame(RegistryCompilationStage::AliasesHydrated, $aliases->graphContext->stage);
        self::assertCount(1, $aliases->graphContext->bindings);
        self::assertContains(RegistryDiagnosticCodes::ALIAS_DUPLICATE, array_map(
            static fn (RegistryDiagnostic $diagnostic): string => $diagnostic->code,
            $aliases->diagnostics,
        ));

        $normalization = $compiler->compileWithDiagnostics(
            [ParityMissingBindingIdHandler::class],
            self::flows(),
        );
        self::assertNotNull($normalization->graphContext);
        self::assertSame(RegistryCompilationStage::BindingsNormalized, $normalization->graphContext->stage);

        $core = $compiler->compileWithDiagnostics([
            ParityInvalidSignatureHandler::class,
        ]);
        self::assertNotNull($core->graphContext);
        self::assertSame(RegistryCompilationStage::CoreValidated, $core->graphContext->stage);
        self::assertCount(1, $core->graphContext->bindings);
        self::assertContains(RegistryDiagnosticCodes::HANDLER_INVALID_SIGNATURE, array_map(
            static fn (RegistryDiagnostic $diagnostic): string => $diagnostic->code,
            $core->diagnostics,
        ));
    }

    public function testValidationRulesAreRuntimeCheckedLikeV6(): void
    {
        $this->expectException(RegistryCompilationException::class);
        $this->expectExceptionMessage('must return only');

        (new MessageRegistryCompiler(validationRules: [new ParityInvalidRuleResult()]))
            ->compileWithDiagnostics([ParitySingleCommandHandler::class]);
    }

    public function testRegistrationMetadataSurvivesBindingRoundTrip(): void
    {
        $definition = (new MessageRegistryCompiler())->compile([ParitySingleCommandHandler::class]);
        $binding = array_values($definition->bindings)[0];
        $context = new BindingRegistrationContext(
            RegistryOwner::feature('address-registry'),
            RegistrySource::provider('AddressRegistryProvider', 'geo-registry'),
        );

        $stamped = $context->stamp($binding);
        $restored = HandlerBindingDefinition::fromArray($stamped->toArray());

        self::assertNotNull($restored->owner);
        self::assertNotNull($restored->source);
        self::assertSame('feature', $restored->owner->kind);
        self::assertSame('address-registry', $restored->owner->id);
        self::assertSame('geo-registry', $restored->source->package);
    }

    public function testDiagnosticFactoriesRetainInfoSeverity(): void
    {
        self::assertSame(
            RegistryDiagnosticSeverity::Info,
            RegistryDiagnostic::info('project.info', 'Informational diagnostic.')->severity,
        );
    }

    private static function flows(): FlowRegistry
    {
        return new FlowRegistry(
            FlowDefinition::sync('default'),
            FlowDefinition::async('durable')
                ->middleware(ParityTraceInterceptor::class)
                ->transport('postgres', 'messages')
                ->delivery(new QueueDeliveryOptions(priority: 3)),
        );
    }
}

/** @implements Query<string> */
final readonly class ParityLocalQuery implements Query
{
}

#[QueryHandler(ParityLocalQuery::class, contextAware: false)]
final class ParityLocalQueryHandler
{
    public function __invoke(ParityLocalQuery $query): string
    {
        return 'ok';
    }
}

#[MessageAlias('event.durable')]
final readonly class ParityDurableEvent implements Event
{
}

final class ParityTraceInterceptor implements InterceptorInterface
{
    public function __invoke(MessageContextInterface $context, PipelineInterface $pipeline): mixed
    {
        return $pipeline->continue();
    }
}

#[EventSubscriber(
    ParityDurableEvent::class,
    flow: 'durable',
    priority: 7,
    bindingId: 'event.durable.audit',
    middleware: [ParityTraceInterceptor::class],
    delaySeconds: 15,
    retryPolicy: 'durable',
    contextAware: false,
)]
final class ParityDurableEventHandler
{
    public function __invoke(ParityDurableEvent $event): void
    {
    }
}

final readonly class ParityMissingAliasEvent implements Event
{
}

#[EventSubscriber(
    ParityMissingAliasEvent::class,
    flow: 'durable',
    bindingId: 'event.missing_alias',
    contextAware: false,
)]
final class ParityMissingAliasHandler
{
    public function __invoke(ParityMissingAliasEvent $event): void
    {
    }
}

#[EventSubscriber(ParityDurableEvent::class, flow: 'durable', contextAware: false)]
final class ParityMissingBindingIdHandler
{
    public function __invoke(ParityDurableEvent $event): void
    {
    }
}

/** @implements Query<string> */
#[MessageAlias('query.async')]
final readonly class ParityAsyncQuery implements Query
{
}

#[QueryHandler(ParityAsyncQuery::class, flow: 'durable', bindingId: 'query.async', contextAware: false)]
final class ParityAsyncQueryHandler
{
    public function __invoke(ParityAsyncQuery $query): string
    {
        return 'never';
    }
}

final readonly class ParityMultiCommand implements Command
{
}

#[CommandHandler(ParityMultiCommand::class, method: 'first', contextAware: false)]
#[CommandHandler(ParityMultiCommand::class, method: 'second', contextAware: false)]
final class ParityMultiCommandHandler
{
    public function first(ParityMultiCommand $command): void
    {
    }

    public function second(ParityMultiCommand $command): void
    {
    }
}

final readonly class ParitySingleCommand implements Command
{
}

#[CommandHandler(ParitySingleCommand::class, contextAware: false)]
final class ParitySingleCommandHandler
{
    public function __invoke(ParitySingleCommand $command): void
    {
    }
}

final readonly class ParityAbstractCommand implements Command
{
}

#[CommandHandler(ParityAbstractCommand::class, contextAware: false)]
abstract class ParityAbstractHandler
{
    public function __invoke(ParityAbstractCommand $command): void
    {
    }
}

#[MessageAlias('parity.duplicate')]
final readonly class ParityAliasOwnerCommand implements Command
{
}

#[MessageAlias('parity.duplicate')]
final readonly class ParityHydratedAliasCommand implements Command
{
}

#[CommandHandler(ParityHydratedAliasCommand::class, contextAware: false)]
final class ParityHydratedAliasHandler
{
    public function __invoke(ParityHydratedAliasCommand $command): void
    {
    }
}

final readonly class ParityInvalidSignatureCommand implements Command
{
}

final readonly class ParityWrongCommand implements Command
{
}

#[CommandHandler(ParityInvalidSignatureCommand::class, contextAware: false)]
final class ParityInvalidSignatureHandler
{
    public function __invoke(ParityWrongCommand $command): void
    {
    }
}

final class ParityWarningRule implements RegistryValidationRuleInterface
{
    public function validate(RegistryCompilationGraphContext $context): iterable
    {
        yield RegistryDiagnostic::warning('project.warning', 'Project validation warning.');
    }
}

final class ParityErrorRule implements RegistryValidationRuleInterface
{
    public function validate(RegistryCompilationGraphContext $context): iterable
    {
        yield RegistryDiagnostic::error('project.error', 'Project validation error.');
    }
}

final class ParityInvalidRuleResult implements RegistryValidationRuleInterface
{
    public function validate(RegistryCompilationGraphContext $context): iterable
    {
        yield new stdClass();
    }
}
