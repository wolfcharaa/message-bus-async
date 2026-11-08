# История Изменений

Здесь фиксируются значимые изменения проекта.

## [0.1.1] - 2026-11-09

- Interceptor continuation стал one-shot и больше не позволяет повторно
  продолжить цепочку после downstream short-circuit.
- `ServiceScopeFailure` сохраняет первичную ошибку выполнения вместе с ошибкой
  очистки scope.

## [0.1.0] - 2026-11-07

Первый публичный release.

- Добавлены типизированные contracts query, command и event bus.
- Добавлены явная compilation registry, diagnostics и доверенные compiled
  registry artifacts.
- Добавлены nested correlation, cancellation, interceptor pipelines и scoped
  service resolution.
- Добавлены версионированные JSON codecs и детерминированные upcaster chains.
- Добавлены idempotency policy и flow-validation contracts.
- Добавлены AMPHP PostgreSQL producer, batch enqueue, status и cancellation
  gateway со schema at-least-once queue.
- Добавлены optional integrations PSR-11 и Thesis DIC.
- Добавлена граница exact-binding execution для worker package.

[0.1.1]: https://github.com/wolfcharaa/message-bus-async/releases/tag/v0.1.1
[0.1.0]: https://github.com/wolfcharaa/message-bus-async/releases/tag/v0.1.0
