# Идемпотентность И Повторное Выполнение

Пакет сохраняет модель handler idempotency из
`romanfedorskij/message-bus` v6. Это interceptor и policy contract, а не
вторая middleware system и не неявный `ON CONFLICT` для enqueue.

## Решения Runtime

`RequiresIdempotencyKey` определяет текущий binding из execution envelope и
создаёт `IdempotencyExecution` со стабильными binding, flow, message identity,
correlation, causation, alias и исходным временем создания. Policy предоставляет
четыре порта:

- `IdempotencyKeyExtractorInterface` возвращает нормализованный key;
- `IntentFingerprintFactoryInterface` определяет смысловое намерение message;
- `IdempotencyStoreInterface` атомарно claims и завершает работу;
- optional `IdempotencyEffectReferenceFactoryInterface` записывает durable
  effect успешного handler.

| Решение store | Результат runtime |
| --- | --- |
| `IdempotencyClaimed` | продолжить pipeline, затем вызвать `complete()` |
| `IdempotencyCompletedSame` | вернуть void no-op без handler |
| `IdempotencyInProgress` | выбросить retryable `IdempotencyInProgressException` |
| `IdempotencyConflict` | выбросить non-retryable `IdempotencyConflictDetected` |

Если handler или внутренний interceptor падает, completion не записывается.
Interceptor не открывает транзакцию. Если store и эффект handler должны
зафиксироваться атомарно, transaction interceptor обязан оборачивать
idempotency interceptor.

## Регистрация Policy

```php
use Fedor\MessageBus\Idempotency\IdempotencyPolicyDescriptor;
use Fedor\MessageBus\Service\ServiceReference;

yield IdempotencyPolicyDescriptor::forBinding(
    'address.import',
    new ServiceReference('address.idempotency_key', AddressKeyExtractor::class),
    new ServiceReference('address.intent_fingerprint', AddressFingerprintFactory::class),
    new ServiceReference('address.idempotency_store', AddressIdempotencyStore::class),
    retentionSeconds: 86_400,
);
```

`IdempotencyPolicyResolver` разрешает references через нейтральный
`ServiceResolverInterface` и отклоняет duplicate policies одного binding.
Resolved registry сохраняет `RegistryOwner` и `RegistrySource`, чтобы
compile-time rules могли проверить ownership.

## Flow Contract

Handler требует явные binding ID и owner metadata:

```php
#[CommandHandler(
    ImportAddress::class,
    flow: 'idempotent',
    bindingId: 'address.import',
    ownerKind: 'feature',
    ownerId: 'address',
)]
final class ImportAddressHandler
{
    public function __invoke(ImportAddress $command): void
    {
        // effect
    }
}
```

Зарегистрируйте роли interceptors и проверяйте flow при compilation:

```php
$roles = (new InterceptorRoleRegistry())
    ->register(TransactionInterceptor::class, 'transaction_boundary')
    ->register(RequiresIdempotencyKey::class, IdempotencyInterceptorRole::Guard);

$contracts = new FlowContractRegistry(
    IdempotencyFlowContract::requiresIdempotencyKey('idempotent')
        ->requiresOrder('transaction_boundary', IdempotencyInterceptorRole::Guard),
);

$rule = new FlowContractValidationRule($contracts, $roles, [$policies]);
```

Соответствующий flow ставит transaction boundary снаружи guard:

```php
FlowDefinition::sync('idempotent')->middleware(
    TransactionInterceptor::class,
    RequiresIdempotencyKey::class,
);
```

Rule отклоняет отсутствующие роли, обратный порядок, query handlers,
сгенерированные `auto.*` binding IDs, отсутствующий ownership/policy и
несовпадение owners.

## Граница Transport

Контракт защищает эффект handler при at-least-once delivery, но не делает
producer enqueue идемпотентным. Core gateway не принимает deterministic enqueue
key и не повторяет автоматически неоднозначный non-idempotent enqueue. Будущий
transport-level deduplication должен отдельно определить storage key, retention,
conflict и observability.
