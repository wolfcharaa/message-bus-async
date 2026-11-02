<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Tests;

use Fedor\MessageBus\Attribute\CommandHandler;
use Fedor\MessageBus\Attribute\EventSubscriber;
use Fedor\MessageBus\Attribute\MessageAlias;
use Fedor\MessageBus\Attribute\QueryHandler;
use Fedor\MessageBus\Context\MessageContextInterface;
use Fedor\MessageBus\Flow\FlowDefinition;
use Fedor\MessageBus\Flow\FlowRegistry;
use Fedor\MessageBus\Interceptor\InterceptorInterface;
use Fedor\MessageBus\Interceptor\PipelineInterface;
use Fedor\MessageBus\Message\Command;
use Fedor\MessageBus\Message\Event;
use Fedor\MessageBus\Message\Query;
use Fedor\MessageBus\Registry\MessageRegistryCompiler;
use Fedor\MessageBus\Registry\RegistryCompilationException;
use Fedor\MessageBus\Registry\RegistryCompilationResult;
use Fedor\MessageBus\Registry\RegistryCompilationStage;
use Fedor\MessageBus\Registry\RegistryDiagnostic;
use Fedor\MessageBus\Registry\RegistryDiagnosticCodes;
use PHPUnit\Framework\TestCase;

final class RegistryDiagnosticsParityTest extends TestCase
{
    public function testAliasDiagnosticsPreserveV6Behavior(): void
    {
        $compiler = new MessageRegistryCompiler();

        $duplicate = $compiler->compileWithDiagnostics([
            DiagnosticsDuplicateAliasFirst::class,
            DiagnosticsDuplicateAliasSecond::class,
        ]);
        self::assertDiagnostic($duplicate, RegistryDiagnosticCodes::ALIAS_DUPLICATE);
        self::assertSame(RegistryCompilationStage::HandlersDiscovered, $duplicate->graphContext?->stage);

        $multiple = $compiler->compileWithDiagnostics([self::multipleAliasFixture()]);
        self::assertDiagnostic($multiple, RegistryDiagnosticCodes::ALIAS_MULTIPLE_FOR_MESSAGE);
        self::assertSame([], $multiple->graphContext?->aliases);
    }

    public function testBindingCardinalityAndKindDiagnosticsPreserveV6Behavior(): void
    {
        $compiler = new MessageRegistryCompiler();
        $async = new FlowRegistry(
            FlowDefinition::sync('default'),
            FlowDefinition::async('async')->transport('postgres', 'messages'),
        );

        $cases = [
            [
                [DiagnosticsDuplicateBindingHandlerA::class, DiagnosticsDuplicateBindingHandlerB::class],
                null,
                RegistryDiagnosticCodes::BINDING_DUPLICATE_ID,
            ],
            [
                [DiagnosticsMissingFlowHandler::class],
                null,
                RegistryDiagnosticCodes::FLOW_MISSING,
            ],
            [
                [DiagnosticsDuplicateQueryHandlerA::class, DiagnosticsDuplicateQueryHandlerB::class],
                null,
                RegistryDiagnosticCodes::QUERY_HANDLER_COUNT,
            ],
            [
                [DiagnosticsPrimaryMissingHandlerA::class, DiagnosticsPrimaryMissingHandlerB::class],
                null,
                RegistryDiagnosticCodes::COMMAND_PRIMARY_MISSING,
            ],
            [
                [DiagnosticsPrimaryDuplicateHandlerA::class, DiagnosticsPrimaryDuplicateHandlerB::class],
                null,
                RegistryDiagnosticCodes::COMMAND_PRIMARY_DUPLICATE,
            ],
            [
                [DiagnosticsAsyncQueryHandler::class],
                $async,
                RegistryDiagnosticCodes::QUERY_ASYNC_FLOW,
            ],
            [
                [DiagnosticsAmbiguousMessage::class, DiagnosticsAmbiguousMessageHandler::class],
                null,
                RegistryDiagnosticCodes::MESSAGE_KIND_CONFLICT,
            ],
        ];

        foreach ($cases as [$classes, $flows, $code]) {
            $result = $compiler->compileWithDiagnostics($classes, $flows);

            self::assertFalse($result->hasDefinition());
            self::assertDiagnostic($result, $code);
        }
    }

    public function testHandlerSignatureDiagnosticsPreserveV6Variants(): void
    {
        $compiler = new MessageRegistryCompiler();
        $handlers = [
            DiagnosticsNoArgumentsHandler::class,
            DiagnosticsContextlessWithContextHandler::class,
            DiagnosticsQueryWithoutReturnHandler::class,
            DiagnosticsCommandWithReturnHandler::class,
            DiagnosticsEventWithReturnHandler::class,
        ];

        foreach ($handlers as $handler) {
            $result = $compiler->compileWithDiagnostics([$handler]);

            self::assertFalse($result->hasDefinition());
            self::assertDiagnostic($result, RegistryDiagnosticCodes::HANDLER_INVALID_SIGNATURE);
            self::assertSame(RegistryCompilationStage::CoreValidated, $result->graphContext?->stage);
        }
    }

    public function testMismatchedHandlerKeepsTypedDiagnosticTarget(): void
    {
        $result = (new MessageRegistryCompiler())->compileWithDiagnostics([
            DiagnosticsMismatchedHandler::class,
        ]);

        $diagnostic = self::diagnostic($result, RegistryDiagnosticCodes::HANDLER_INVALID_SIGNATURE);

        self::assertNotNull($diagnostic);
        self::assertNotNull($diagnostic->target);
        self::assertSame(DiagnosticsExpectedCommand::class, $diagnostic->target->messageClass);
        self::assertSame(DiagnosticsMismatchedHandler::class, $diagnostic->target->handlerClass);
        self::assertSame('__invoke', $diagnostic->target->method);
        self::assertSame('default', $diagnostic->target->flow);
    }

    public function testInterceptorContractDiagnosticsPreserveV6Boundary(): void
    {
        $invalid = (new MessageRegistryCompiler())->compileWithDiagnostics([
            DiagnosticsInvalidInterceptorHandler::class,
        ]);

        self::assertFalse($invalid->hasDefinition());
        self::assertDiagnostic($invalid, RegistryDiagnosticCodes::INTERCEPTOR_INVALID_SIGNATURE);

        $valid = (new MessageRegistryCompiler())->compileWithDiagnostics([
            DiagnosticsValidInterceptorHandler::class,
        ]);

        self::assertTrue($valid->hasDefinition());
        self::assertFalse($valid->hasErrors());
    }

    public function testFlowDiagnosticsPreserveV6VariantsAndAsyncRequirements(): void
    {
        $compiler = new MessageRegistryCompiler();
        $classes = [DiagnosticsFlowHandler::class];
        $cases = [
            new FlowRegistry(FlowDefinition::sync('default')->context('MissingDiagnosticsContext')),
            new FlowRegistry(FlowDefinition::sync('default')->context(DiagnosticsNotAContext::class)),
            new FlowRegistry(FlowDefinition::sync('default')->context(DiagnosticsCustomContext::class)),
            new FlowRegistry(FlowDefinition::sync('default')->context(
                DiagnosticsCustomContext::class,
                DiagnosticsNotAFactory::class,
            )),
            new FlowRegistry(FlowDefinition::sync('default')->strategy('MissingDiagnosticsStrategy')),
        ];

        foreach ($cases as $flows) {
            $result = $compiler->compileWithDiagnostics($classes, $flows);

            self::assertFalse($result->hasDefinition());
            self::assertDiagnostic($result, RegistryDiagnosticCodes::FLOW_INVALID);
        }

        $async = $compiler->compileWithDiagnostics(
            [DiagnosticsAsyncFlowHandler::class],
            new FlowRegistry(FlowDefinition::async('async')),
        );

        self::assertFalse($async->hasDefinition());
        self::assertDiagnostic($async, RegistryDiagnosticCodes::FLOW_INVALID);
    }

    public function testCompileThrowsTypedDiagnosticsLikeV6(): void
    {
        try {
            (new MessageRegistryCompiler())->compile([
                DiagnosticsDuplicateAliasFirst::class,
                DiagnosticsDuplicateAliasSecond::class,
            ]);
        } catch (RegistryCompilationException $exception) {
            self::assertTrue($exception->hasErrors());
            self::assertContains(
                RegistryDiagnosticCodes::ALIAS_DUPLICATE,
                array_map(
                    static fn (RegistryDiagnostic $diagnostic): string => $diagnostic->code,
                    $exception->diagnostics(),
                ),
            );

            return;
        }

        self::fail('Expected RegistryCompilationException.');
    }

    private static function assertDiagnostic(RegistryCompilationResult $result, string $code): void
    {
        self::assertNotNull(self::diagnostic($result, $code), 'Diagnostic code not found: ' . $code);
    }

    private static function diagnostic(RegistryCompilationResult $result, string $code): ?RegistryDiagnostic
    {
        foreach ($result->diagnostics as $diagnostic) {
            if ($diagnostic->code === $code) {
                return $diagnostic;
            }
        }

        return null;
    }

    /** @return class-string */
    private static function multipleAliasFixture(): string
    {
        $shortName = 'DiagnosticsMultipleAliasMessage' . bin2hex(random_bytes(6));
        $class = __NAMESPACE__ . '\\' . $shortName;
        eval(sprintf(
            <<<'PHP'
                namespace Fedor\MessageBus\Tests;

                #[\Fedor\MessageBus\Attribute\MessageAlias('diagnostics.multiple.one')]
                #[\Fedor\MessageBus\Attribute\MessageAlias('diagnostics.multiple.two')]
                final readonly class %s implements \Fedor\MessageBus\Message\Command
                {
                }
                PHP,
            $shortName,
        ));

        foreach (get_declared_classes() as $declaredClass) {
            if ($declaredClass === $class) {
                return $declaredClass;
            }
        }

        throw new \RuntimeException('Multiple-alias fixture was not declared.');
    }
}

#[MessageAlias('diagnostics.duplicate')]
final readonly class DiagnosticsDuplicateAliasFirst implements Command
{
}

#[MessageAlias('diagnostics.duplicate')]
final readonly class DiagnosticsDuplicateAliasSecond implements Command
{
}

final readonly class DiagnosticsDuplicateBindingEvent implements Event
{
}

#[EventSubscriber(
    DiagnosticsDuplicateBindingEvent::class,
    bindingId: 'diagnostics.duplicate_binding',
    contextAware: false,
)]
final class DiagnosticsDuplicateBindingHandlerA
{
    public function __invoke(DiagnosticsDuplicateBindingEvent $event): void
    {
    }
}

#[EventSubscriber(
    DiagnosticsDuplicateBindingEvent::class,
    bindingId: 'diagnostics.duplicate_binding',
    contextAware: false,
)]
final class DiagnosticsDuplicateBindingHandlerB
{
    public function __invoke(DiagnosticsDuplicateBindingEvent $event): void
    {
    }
}

final readonly class DiagnosticsMissingFlowCommand implements Command
{
}

#[CommandHandler(DiagnosticsMissingFlowCommand::class, flow: 'missing', contextAware: false)]
final class DiagnosticsMissingFlowHandler
{
    public function __invoke(DiagnosticsMissingFlowCommand $command): void
    {
    }
}

/** @implements Query<string> */
final readonly class DiagnosticsDuplicateQuery implements Query
{
}

#[QueryHandler(DiagnosticsDuplicateQuery::class, contextAware: false)]
final class DiagnosticsDuplicateQueryHandlerA
{
    public function __invoke(DiagnosticsDuplicateQuery $query): string
    {
        return 'a';
    }
}

#[QueryHandler(DiagnosticsDuplicateQuery::class, contextAware: false)]
final class DiagnosticsDuplicateQueryHandlerB
{
    public function __invoke(DiagnosticsDuplicateQuery $query): string
    {
        return 'b';
    }
}

final readonly class DiagnosticsPrimaryMissingCommand implements Command
{
}

#[CommandHandler(DiagnosticsPrimaryMissingCommand::class, contextAware: false)]
final class DiagnosticsPrimaryMissingHandlerA
{
    public function __invoke(DiagnosticsPrimaryMissingCommand $command): void
    {
    }
}

#[CommandHandler(DiagnosticsPrimaryMissingCommand::class, contextAware: false)]
final class DiagnosticsPrimaryMissingHandlerB
{
    public function __invoke(DiagnosticsPrimaryMissingCommand $command): void
    {
    }
}

final readonly class DiagnosticsPrimaryDuplicateCommand implements Command
{
}

#[CommandHandler(DiagnosticsPrimaryDuplicateCommand::class, primary: true, contextAware: false)]
final class DiagnosticsPrimaryDuplicateHandlerA
{
    public function __invoke(DiagnosticsPrimaryDuplicateCommand $command): void
    {
    }
}

#[CommandHandler(DiagnosticsPrimaryDuplicateCommand::class, primary: true, contextAware: false)]
final class DiagnosticsPrimaryDuplicateHandlerB
{
    public function __invoke(DiagnosticsPrimaryDuplicateCommand $command): void
    {
    }
}

/** @implements Query<string> */
#[MessageAlias('diagnostics.async_query')]
final readonly class DiagnosticsAsyncQuery implements Query
{
}

#[QueryHandler(
    DiagnosticsAsyncQuery::class,
    flow: 'async',
    bindingId: 'diagnostics.async_query',
    contextAware: false,
)]
final class DiagnosticsAsyncQueryHandler
{
    public function __invoke(DiagnosticsAsyncQuery $query): string
    {
        return 'never';
    }
}

/** @implements Query<string> */
final readonly class DiagnosticsAmbiguousMessage implements Query, Command
{
}

#[QueryHandler(DiagnosticsAmbiguousMessage::class, contextAware: false)]
final class DiagnosticsAmbiguousMessageHandler
{
    public function __invoke(DiagnosticsAmbiguousMessage $message): string
    {
        return 'never';
    }
}

final readonly class DiagnosticsNoArgumentsCommand implements Command
{
}

#[CommandHandler(DiagnosticsNoArgumentsCommand::class, contextAware: false)]
final class DiagnosticsNoArgumentsHandler
{
    public function __invoke(): void
    {
    }
}

final readonly class DiagnosticsContextlessCommand implements Command
{
}

#[CommandHandler(DiagnosticsContextlessCommand::class, contextAware: false)]
final class DiagnosticsContextlessWithContextHandler
{
    public function __invoke(
        DiagnosticsContextlessCommand $command,
        MessageContextInterface $context,
    ): void {
    }
}

/** @implements Query<string> */
final readonly class DiagnosticsQueryWithoutReturn implements Query
{
}

#[QueryHandler(DiagnosticsQueryWithoutReturn::class, contextAware: false)]
final class DiagnosticsQueryWithoutReturnHandler
{
    public function __invoke(DiagnosticsQueryWithoutReturn $query): void
    {
    }
}

final readonly class DiagnosticsCommandWithReturn implements Command
{
}

#[CommandHandler(DiagnosticsCommandWithReturn::class, contextAware: false)]
final class DiagnosticsCommandWithReturnHandler
{
    public function __invoke(DiagnosticsCommandWithReturn $command): string
    {
        return 'invalid';
    }
}

final readonly class DiagnosticsEventWithReturn implements Event
{
}

#[EventSubscriber(DiagnosticsEventWithReturn::class, contextAware: false)]
final class DiagnosticsEventWithReturnHandler
{
    public function __invoke(DiagnosticsEventWithReturn $event): string
    {
        return 'invalid';
    }
}

final readonly class DiagnosticsExpectedCommand implements Command
{
}

final readonly class DiagnosticsWrongCommand implements Command
{
}

#[CommandHandler(DiagnosticsExpectedCommand::class, contextAware: false)]
final class DiagnosticsMismatchedHandler
{
    public function __invoke(DiagnosticsWrongCommand $command): void
    {
    }
}

final readonly class DiagnosticsInterceptorCommand implements Command
{
}

abstract class DiagnosticsAbstractInterceptor implements InterceptorInterface
{
    public function __invoke(MessageContextInterface $context, PipelineInterface $pipeline): mixed
    {
        return $pipeline->continue();
    }
}

#[CommandHandler(
    DiagnosticsInterceptorCommand::class,
    middleware: [DiagnosticsAbstractInterceptor::class],
    contextAware: false,
)]
final class DiagnosticsInvalidInterceptorHandler
{
    public function __invoke(DiagnosticsInterceptorCommand $command): void
    {
    }
}

final class DiagnosticsValidInterceptor implements InterceptorInterface
{
    public function __invoke(MessageContextInterface $context, PipelineInterface $pipeline): mixed
    {
        return $pipeline->continue();
    }
}

#[CommandHandler(
    DiagnosticsInterceptorCommand::class,
    middleware: [DiagnosticsValidInterceptor::class],
    contextAware: false,
)]
final class DiagnosticsValidInterceptorHandler
{
    public function __invoke(DiagnosticsInterceptorCommand $command): void
    {
    }
}

final readonly class DiagnosticsFlowCommand implements Command
{
}

#[CommandHandler(DiagnosticsFlowCommand::class, contextAware: false)]
final class DiagnosticsFlowHandler
{
    public function __invoke(DiagnosticsFlowCommand $command): void
    {
    }
}

final class DiagnosticsNotAContext
{
}

interface DiagnosticsCustomContext extends MessageContextInterface
{
}

final class DiagnosticsNotAFactory
{
}

#[MessageAlias('diagnostics.flow.async')]
final readonly class DiagnosticsAsyncFlowEvent implements Event
{
}

#[EventSubscriber(
    DiagnosticsAsyncFlowEvent::class,
    flow: 'async',
    bindingId: 'diagnostics.flow.async',
    contextAware: false,
)]
final class DiagnosticsAsyncFlowHandler
{
    public function __invoke(DiagnosticsAsyncFlowEvent $event): void
    {
    }
}
