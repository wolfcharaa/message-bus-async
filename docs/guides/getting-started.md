# Getting Started

This guide builds the smallest complete local query flow with Thesis DIC. The
handler is scoped to one root bus execution and is never resolved through a
service locator inside application code.

## 1. Install

```shell
composer config repositories.message-bus-async vcs \
  https://github.com/wolfcharaa/message-bus-async.git
composer require romanfedorskij/message-bus-async:^0.1 thesis/dic:^0.5
```

## 2. Create `example.php`

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Fedor\MessageBus\Attribute\QueryHandler;
use Fedor\MessageBus\Integration\Thesis\MessageBusModule;
use Fedor\MessageBus\Message\Query;
use Fedor\MessageBus\MessageBus;
use Fedor\MessageBus\Registry\CompiledMessageRegistry;
use Fedor\MessageBus\Registry\MessageRegistryCompiler;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;

/** @implements Query<string> */
final readonly class Greeting implements Query
{
    public function __construct(public string $name) {}
}

#[QueryHandler(Greeting::class, contextAware: false)]
final class GreetingHandler
{
    public function __invoke(Greeting $query): string
    {
        return "Hello, {$query->name}!";
    }
}

/** @implements Module<Ref<MessageBus>> */
final class ApplicationModule implements Module
{
    /** @return Ref<MessageBus> */
    public function configure(Dic $dic): Ref
    {
        $definition = (new MessageRegistryCompiler())->compile([
            GreetingHandler::class,
        ]);

        $handler = $dic->object(GreetingHandler::class)->scoped();

        return $dic->import(new MessageBusModule(
            registry: $dic->value(new CompiledMessageRegistry($definition)),
            services: [GreetingHandler::class => $handler],
        ));
    }
}

$result = Dic::run(
    new ApplicationModule(),
    static fn (MessageBus $bus): string => $bus->ask(new Greeting('Ada')),
);

echo $result, PHP_EOL;
```

## 3. Run

```shell
php example.php
```

Expected output:

```text
Hello, Ada!
```

The registry is compiled before dispatch. The root `ask()` opens a Thesis
scope, resolves `GreetingHandler` in that scope, and disposes the scope after
the result or exception.

## Next Steps

- Add commands and events using the same explicit class-list model.
- Read the [runtime contract](../reference/runtime.md) before adding nested
  calls, interceptors, custom flows, or compiled registry artifacts.
- Read the [PostgreSQL queue reference](../reference/postgres-queue.md) before
  enabling durable bindings.
- Add the [worker package](https://github.com/wolfcharaa/message-bus-async-worker)
  only when the application needs queue consumption and process control.
