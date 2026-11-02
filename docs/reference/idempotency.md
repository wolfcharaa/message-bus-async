# Idempotency and replay

The package preserves the handler-idempotency model from
`romanfedorskij/message-bus` v6. It is an interceptor and policy contract, not a
second middleware system and not an implicit `ON CONFLICT` rule for queue
enqueue.

## Runtime decisions

`RequiresIdempotencyKey` resolves the current binding from the execution
envelope and builds an `IdempotencyExecution` containing stable binding, flow,
message identity, correlation, causation, alias, and original creation time. A
resolved policy supplies four ports:

- `IdempotencyKeyExtractorInterface` returns a normalized `IdempotencyKey`;
- `IntentFingerprintFactoryInterface` identifies the semantic message intent;
- `IdempotencyStoreInterface` atomically claims and completes work;
- the optional `IdempotencyEffectReferenceFactoryInterface` records the durable
  effect created by a successful handler.

The store returns one decision:

| Decision | Runtime result |
| --- | --- |
| `IdempotencyClaimed` | Continue the interceptor pipeline, then call `complete()` |
| `IdempotencyCompletedSame` | Return a void no-op without invoking the handler |
| `IdempotencyInProgress` | Throw retryable `IdempotencyInProgressException` |
| `IdempotencyConflict` | Throw non-retryable `IdempotencyConflictDetected` |

If the handler or an inner interceptor fails, completion is not recorded. The
interceptor does not open a transaction. When the store and handler effect must
commit atomically, a transaction interceptor must wrap the idempotency
interceptor.

## Policy registration

A provider declares policy descriptors with typed service references:

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

`IdempotencyPolicyResolver` resolves those references through the neutral
`ServiceResolverInterface` and rejects duplicate binding policies. The resolved
registry retains `RegistryOwner` and `RegistrySource`, so compile-time rules can
verify policy ownership.

## Flow contract

The handler needs an explicit binding ID and owner metadata:

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

Register the interceptor roles and validate the flow during registry
compilation:

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

The corresponding flow must place the transaction boundary outside the guard:

```php
FlowDefinition::sync('idempotent')->middleware(
    TransactionInterceptor::class,
    RequiresIdempotencyKey::class,
);
```

The rule rejects missing roles, reversed ordering, query handlers, generated
`auto.*` binding IDs, missing binding ownership, absent policies, and owner
mismatches.

## Transport boundary

This contract protects handler effects during at-least-once delivery. It does
not make producer enqueue idempotent. The core queue gateway therefore does not
accept a deterministic enqueue key and does not automatically retry an
ambiguous non-idempotent enqueue. A future transport-level deduplication feature
must define its own storage key, retention, conflict, and observability contract
explicitly.
