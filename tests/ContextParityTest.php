<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Tests;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Fedor\MessageBus\Attribute\CommandHandler;
use Fedor\MessageBus\Context\DefaultMessageContext;
use Fedor\MessageBus\Context\DefaultMessageContextFactory;
use Fedor\MessageBus\Context\MessageContextFactoryInterface;
use Fedor\MessageBus\Context\MessageContextInterface;
use Fedor\MessageBus\Envelope\Envelope;
use Fedor\MessageBus\Envelope\Headers;
use Fedor\MessageBus\Execution\ExecutionOptions;
use Fedor\MessageBus\Flow\FlowDefinition;
use Fedor\MessageBus\Flow\FlowRegistry;
use Fedor\MessageBus\Message\Command;
use Fedor\MessageBus\Message\Event;
use Fedor\MessageBus\Message\Query;
use Fedor\MessageBus\MessageBusInterface;
use Fedor\MessageBus\Publication\PublishResult;
use Fedor\MessageBus\Registry\MessageRegistryCompiler;
use Fedor\MessageBus\Registry\RegistryCompilationStage;
use Fedor\MessageBus\Registry\RegistryDiagnosticCodes;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ContextParityTest extends TestCase
{
    public function testDefaultFactoryCreatesReadOnlyContextWithInheritedCancellation(): void
    {
        $bus = new ContextSpyBus();
        $deferred = new DeferredCancellation();
        $envelope = self::envelope();
        $context = (new DefaultMessageContextFactory())->create(
            $bus,
            $envelope,
            FlowDefinition::sync('default'),
            $deferred->getCancellation(),
        );

        self::assertInstanceOf(DefaultMessageContext::class, $context);
        self::assertSame($envelope, $context->envelope());
        self::assertSame($deferred->getCancellation(), $context->cancellation());
        try {
            $context->ask(new ContextQuery());
            self::fail('The spy bus must stop query execution after recording options.');
        } catch (ContextAskRecorded) {
        }
        self::assertNotNull($bus->lastOptions);
        self::assertSame($deferred->getCancellation(), $bus->lastOptions->cancellation);

        $context->dispatch(new ContextCommand(), new ExecutionOptions(headers: ['tenant' => 'alpha']));
        self::assertNotNull($bus->lastOptions);
        self::assertSame('alpha', $bus->lastOptions->headers->get('tenant'));

        $deferred->cancel();
        self::assertTrue($bus->lastOptions->cancellation->isRequested());
    }

    public function testFlowContextMetadataSurvivesArtifactRoundTrip(): void
    {
        $flow = FlowDefinition::sync('custom')->context(
            ContextCustomInterface::class,
            ContextCustomFactory::class,
        );

        $restored = FlowDefinition::fromArray($flow->toArray());

        self::assertSame(ContextCustomInterface::class, $restored->contextInterface);
        self::assertSame(ContextCustomFactory::class, $restored->contextFactory);
        self::assertNull(FlowDefinition::sync('custom')->context(ContextCustomInterface::class)->contextFactory);
        self::assertSame(
            DefaultMessageContextFactory::class,
            FlowDefinition::sync('default')->context(MessageContextInterface::class)->contextFactory,
        );
    }

    public function testCompilerValidatesCustomContextFactoryAtCoreStage(): void
    {
        $compiler = new MessageRegistryCompiler();
        $valid = $compiler->compileWithDiagnostics(
            [ContextCommand::class, ContextAwareHandler::class],
            new FlowRegistry(FlowDefinition::sync('custom')->context(
                ContextCustomInterface::class,
                ContextCustomFactory::class,
            )),
        );

        self::assertFalse($valid->hasErrors());
        self::assertSame(RegistryCompilationStage::DefinitionBuilt, $valid->graphContext?->stage);

        $missingFactory = $compiler->compileWithDiagnostics(
            [],
            new FlowRegistry(FlowDefinition::sync('custom')->context(ContextCustomInterface::class)),
        );
        self::assertSame(RegistryCompilationStage::CoreValidated, $missingFactory->graphContext?->stage);
        self::assertContains(
            RegistryDiagnosticCodes::FLOW_INVALID,
            array_map(static fn ($diagnostic): string => $diagnostic->code, $missingFactory->diagnostics),
        );

        $invalidFactory = $compiler->compileWithDiagnostics(
            [],
            new FlowRegistry(FlowDefinition::sync('custom')->context(
                ContextCustomInterface::class,
                ContextNotAFactory::class,
            )),
        );
        self::assertContains(
            RegistryDiagnosticCodes::FLOW_INVALID,
            array_map(static fn ($diagnostic): string => $diagnostic->code, $invalidFactory->diagnostics),
        );
    }

    private static function envelope(): Envelope
    {
        return new Envelope(
            new ContextQuery(),
            'message-1',
            'correlation-1',
            null,
            new Headers(['source' => 'test']),
        );
    }
}

/** @implements Query<string> */
final readonly class ContextQuery implements Query
{
}

final readonly class ContextCommand implements Command
{
}

final readonly class ContextEvent implements Event
{
}

interface ContextCustomInterface extends MessageContextInterface
{
}

final class ContextCustomFactory implements MessageContextFactoryInterface
{
    #[\Override]
    public function create(
        MessageBusInterface $messageBus,
        Envelope $envelope,
        FlowDefinition $flow,
        Cancellation $cancellation,
    ): MessageContextInterface {
        return new DefaultMessageContext($messageBus, $envelope, $cancellation);
    }
}

final class ContextNotAFactory
{
}

#[CommandHandler(ContextCommand::class, flow: 'custom')]
final class ContextAwareHandler
{
    public function __invoke(ContextCommand $command, ContextCustomInterface $context): void
    {
    }
}

final class ContextSpyBus implements MessageBusInterface
{
    public ?ExecutionOptions $lastOptions = null;

    #[\Override]
    public function ask(Query $query, ?ExecutionOptions $options = null): mixed
    {
        $this->lastOptions = $options;

        throw new ContextAskRecorded();
    }

    #[\Override]
    public function dispatch(Command $command, ?ExecutionOptions $options = null): void
    {
        $this->lastOptions = $options;
    }

    #[\Override]
    public function publish(Event $event, ?ExecutionOptions $options = null): PublishResult
    {
        $this->lastOptions = $options;

        return PublishResult::empty();
    }
}

final class ContextAskRecorded extends LogicException
{
}
