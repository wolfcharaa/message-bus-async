# Первый Запрос

Это руководство собирает минимальный рабочий query-flow с Thesis DIC.
Обработчик живёт в scope одного корневого вызова и не извлекается из контейнера
прикладным кодом.

## 1. Установить Пакет

```shell
composer config repositories.message-bus-async vcs \
  https://github.com/wolfcharaa/message-bus-async.git
composer require romanfedorskij/message-bus-async:^0.1 thesis/dic:^0.5
```

VCS repository нужен до регистрации пакета в Packagist.

## 2. Создать `example.php`

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
        return "Привет, {$query->name}!";
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
    static fn (MessageBus $bus): string => $bus->ask(new Greeting('Ада')),
);

echo $result, PHP_EOL;
```

## 3. Запустить

```shell
php example.php
```

Ожидаемый вывод:

```text
Привет, Ада!
```

Registry компилируется до dispatch. Корневой `ask()` открывает Thesis scope,
разрешает в нём `GreetingHandler` и закрывает scope после результата или
исключения.

## Что Делать Дальше

- Добавляйте commands и events в тот же явный список классов.
- Перед вложенными вызовами, interceptors, custom flows или сохранением
  compiled registry прочитайте [runtime contract](../reference/runtime.md).
- Перед durable bindings прочитайте
  [контракт очереди PostgreSQL](../reference/postgres-queue.md).
- Подключайте [worker package](https://github.com/wolfcharaa/message-bus-async-worker),
  только если приложению нужны consumption и process control.
