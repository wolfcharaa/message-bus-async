<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Tests;

use Fedor\MessageBus\Attribute\QueryHandler;
use Fedor\MessageBus\Integration\Thesis\MessageBusModule;
use Fedor\MessageBus\Message\Query;
use Fedor\MessageBus\MessageBus;
use Fedor\MessageBus\Registry\CompiledMessageRegistry;
use Fedor\MessageBus\Registry\MessageRegistryCompiler;
use PHPUnit\Framework\TestCase;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;
use Throwable;

final class ThesisMessageBusModuleTest extends TestCase
{
    public function testItBuildsTheBusAndDisposesHandlerScope(): void
    {
        $trace = new ThesisModuleTrace();

        $result = Dic::run(
            new ThesisMessageBusTestModule($trace),
            static fn (MessageBus $bus): string => $bus->ask(new ThesisModuleQuery('ready')),
        );

        self::assertSame('handled:ready', $result);
        self::assertSame(1, $trace->created);
        self::assertSame([null], $trace->disposedErrors);
    }
}

/** @implements Query<string> */
final readonly class ThesisModuleQuery implements Query
{
    public function __construct(public string $value)
    {
    }
}

#[QueryHandler(ThesisModuleQuery::class, contextAware: false)]
final readonly class ThesisModuleQueryHandler
{
    public function __construct(private ThesisModuleTrace $trace)
    {
        ++$this->trace->created;
    }

    public function __invoke(ThesisModuleQuery $query): string
    {
        return 'handled:' . $query->value;
    }

    public function dispose(?Throwable $error): void
    {
        $this->trace->disposedErrors[] = $error;
    }
}

final class ThesisModuleTrace
{
    public int $created = 0;

    /** @var list<?Throwable> */
    public array $disposedErrors = [];
}

/** @implements Module<Ref<MessageBus>> */
final readonly class ThesisMessageBusTestModule implements Module
{
    public function __construct(private ThesisModuleTrace $trace)
    {
    }

    /** @return Ref<MessageBus> */
    public function configure(Dic $dic): Ref
    {
        $registry = new CompiledMessageRegistry(
            (new MessageRegistryCompiler())->compile([ThesisModuleQueryHandler::class]),
        );
        $trace = $dic->value($this->trace);
        $handler = $dic
            ->object(ThesisModuleQueryHandler::class)
            ->arg('trace', $trace)
            ->scoped()
            ->disposer(static function (ThesisModuleQueryHandler $handler, ?Throwable $error): void {
                $handler->dispose($error);
            });

        return $dic->import(new MessageBusModule(
            registry: $dic->value($registry),
            services: [ThesisModuleQueryHandler::class => $handler],
        ));
    }
}
