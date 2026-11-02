<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Registry\Compiler;

use Fedor\MessageBus\Attribute\CommandHandler;
use Fedor\MessageBus\Attribute\EventSubscriber;
use Fedor\MessageBus\Attribute\MessageAlias;
use Fedor\MessageBus\Attribute\MessageHandlerAttributeInterface;
use Fedor\MessageBus\Attribute\QueryHandler;
use Fedor\MessageBus\Context\MessageContextInterface;
use Fedor\MessageBus\Flow\FlowDefinition;
use Fedor\MessageBus\Flow\FlowRegistry;
use Fedor\MessageBus\Interceptor\InterceptorInterface;
use Fedor\MessageBus\Message\Command;
use Fedor\MessageBus\Message\Event;
use Fedor\MessageBus\Message\Query;
use Fedor\MessageBus\Registry\CompiledRegistry;
use Fedor\MessageBus\Registry\HandlerBinding;
use Fedor\MessageBus\Registry\HandlerInvocationMode;
use Fedor\MessageBus\Registry\HandlerKind;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Throwable;

final class RegistryCompiler
{
    /**
     * @param list<string> $classes Explicit message and handler class names.
     */
    public function compile(array $classes, ?FlowRegistry $flows = null): CompiledRegistry
    {
        return $this->compileWithDiagnostics($classes, $flows)->requireRegistry();
    }

    /**
     * @param list<string> $classes Explicit message and handler class names.
     */
    public function compileWithDiagnostics(array $classes, ?FlowRegistry $flows = null): RegistryCompilationResult
    {
        $flows ??= new FlowRegistry();
        $diagnostics = [];
        $reflections = $this->reflectClasses($classes, $diagnostics);
        [$messageKinds, $aliases] = $this->discoverMessages($reflections, $diagnostics);
        $this->validateFlows($flows, $diagnostics);
        $pendingBindings = $this->discoverBindings($reflections, $messageKinds, $flows, $aliases, $diagnostics);
        $primaryBindings = $this->validateCardinality($pendingBindings, $flows, $diagnostics);

        $this->sortDiagnostics($diagnostics);
        if ($this->hasErrors($diagnostics)) {
            return new RegistryCompilationResult(null, $diagnostics);
        }

        $bindings = array_map(
            static fn (PendingBinding $binding): HandlerBinding => $binding->compile(
                $primaryBindings[$binding->id] ?? false,
            ),
            $pendingBindings,
        );

        return new RegistryCompilationResult(new CompiledRegistry($bindings, $aliases, $flows), $diagnostics);
    }

    /**
     * @param list<string> $classes
     * @param list<RegistryDiagnostic> $diagnostics
     * @return array<class-string, ReflectionClass<object>>
     */
    private function reflectClasses(array $classes, array &$diagnostics): array
    {
        $counts = array_count_values($classes);
        $classNames = array_keys($counts);
        sort($classNames, SORT_STRING);

        $reflections = [];
        foreach ($classNames as $class) {
            if (($counts[$class] ?? 0) > 1) {
                $diagnostics[] = $this->error(
                    RegistryDiagnosticCode::ClassDuplicate,
                    sprintf('Class "%s" appears more than once in the explicit registry class list.', $class),
                    sourceClass: $class,
                    suggestion: 'Keep every message and handler class exactly once.',
                );
            }

            if (!class_exists($class)) {
                $diagnostics[] = $this->error(
                    RegistryDiagnosticCode::ClassMissing,
                    sprintf('Registry class "%s" does not exist.', $class),
                    sourceClass: $class,
                    suggestion: 'Remove the stale entry or make the class autoloadable before compilation.',
                );

                continue;
            }

            /** @var class-string $class */
            $reflections[$class] = new ReflectionClass($class);
        }

        return $reflections;
    }

    /**
     * @param array<class-string, ReflectionClass<object>> $reflections
     * @param list<RegistryDiagnostic> $diagnostics
     * @return array{array<class-string, HandlerKind>, array<string, class-string>}
     */
    private function discoverMessages(array $reflections, array &$diagnostics): array
    {
        $messageKinds = [];
        $aliases = [];
        $aliasOwners = [];

        foreach ($reflections as $class => $reflection) {
            $kinds = $this->messageKinds($class);
            $aliasAttributes = $reflection->getAttributes(MessageAlias::class);

            if (count($kinds) > 1) {
                $diagnostics[] = $this->errorForClass(
                    RegistryDiagnosticCode::MessageKindAmbiguous,
                    sprintf('Message "%s" implements more than one message marker.', $class),
                    $reflection,
                    suggestion: 'Implement exactly one of Query, Command, or Event.',
                );

                continue;
            }

            if ($kinds === []) {
                if ($aliasAttributes !== []) {
                    $diagnostics[] = $this->errorForClass(
                        RegistryDiagnosticCode::MessageAliasInvalidTarget,
                        sprintf('Class "%s" declares MessageAlias but is not a message.', $class),
                        $reflection,
                        MessageAlias::class,
                        suggestion: 'Implement one message marker or remove MessageAlias.',
                    );
                }

                continue;
            }

            $messageKinds[$class] = $kinds[0];
            if ($aliasAttributes === []) {
                continue;
            }

            if (count($aliasAttributes) > 1) {
                $diagnostics[] = $this->errorForClass(
                    RegistryDiagnosticCode::MessageAliasMultiple,
                    sprintf('Message "%s" declares more than one MessageAlias.', $class),
                    $reflection,
                    MessageAlias::class,
                    suggestion: 'Keep exactly one stable alias.',
                );

                continue;
            }

            try {
                /** @var MessageAlias $attribute */
                $attribute = $aliasAttributes[0]->newInstance();
            } catch (Throwable $exception) {
                $diagnostics[] = $this->errorForClass(
                    RegistryDiagnosticCode::MessageAliasEmpty,
                    sprintf('MessageAlias on "%s" cannot be instantiated: %s', $class, $exception->getMessage()),
                    $reflection,
                    MessageAlias::class,
                    suggestion: 'Use a non-empty string or string-backed enum alias.',
                );

                continue;
            }

            $alias = trim($attribute->name);
            if ($alias === '') {
                $diagnostics[] = $this->errorForClass(
                    RegistryDiagnosticCode::MessageAliasEmpty,
                    sprintf('Message "%s" declares an empty MessageAlias.', $class),
                    $reflection,
                    MessageAlias::class,
                    suggestion: 'Use a non-empty stable alias.',
                );

                continue;
            }

            if (isset($aliasOwners[$alias]) && $aliasOwners[$alias] !== $class) {
                $diagnostics[] = $this->errorForClass(
                    RegistryDiagnosticCode::MessageAliasDuplicate,
                    sprintf(
                        'MessageAlias "%s" is used by both "%s" and "%s".',
                        $alias,
                        $aliasOwners[$alias],
                        $class,
                    ),
                    $reflection,
                    MessageAlias::class,
                    alias: $alias,
                    suggestion: 'Assign a unique stable alias to every message.',
                );

                continue;
            }

            $aliasOwners[$alias] = $class;
            $aliases[$alias] = $class;
        }

        return [$messageKinds, $aliases];
    }

    /**
     * @param array<class-string, ReflectionClass<object>> $reflections
     * @param array<class-string, HandlerKind> $messageKinds
     * @param array<string, class-string> $aliases
     * @param list<RegistryDiagnostic> $diagnostics
     * @return list<PendingBinding>
     */
    private function discoverBindings(
        array $reflections,
        array &$messageKinds,
        FlowRegistry $flows,
        array &$aliases,
        array &$diagnostics,
    ): array {
        $bindings = [];
        $bindingOwners = [];

        foreach ($reflections as $handlerClass => $reflection) {
            $attributes = $reflection->getAttributes(
                MessageHandlerAttributeInterface::class,
                ReflectionAttribute::IS_INSTANCEOF,
            );
            if ($attributes === []) {
                continue;
            }

            $instantiable = $reflection->isInstantiable();
            if (!$instantiable) {
                $diagnostics[] = $this->errorForClass(
                    RegistryDiagnosticCode::HandlerClassNotInstantiable,
                    sprintf('Handler class "%s" must be instantiable.', $handlerClass),
                    $reflection,
                    suggestion: 'Use a concrete handler class.',
                );
            }

            foreach ($attributes as $reflectionAttribute) {
                $before = count($diagnostics);

                try {
                    /** @var MessageHandlerAttributeInterface $attribute */
                    $attribute = $reflectionAttribute->newInstance();
                    $kind = $this->attributeKind($attribute);
                    if ($kind === null) {
                        $diagnostics[] = $this->errorForClass(
                            RegistryDiagnosticCode::HandlerAttributeUnsupported,
                            sprintf('Handler attribute "%s" is not supported.', $reflectionAttribute->getName()),
                            $reflection,
                            $reflectionAttribute->getName(),
                            suggestion: 'Use QueryHandler, CommandHandler, or EventSubscriber.',
                        );

                        continue;
                    }

                    $flowKey = trim($attribute->flow());
                    $flow = $flows->all()[$flowKey] ?? null;
                    if ($flow === null) {
                        $diagnostics[] = $this->errorForClass(
                            RegistryDiagnosticCode::FlowMissing,
                            sprintf('Message flow "%s" is not registered.', $flowKey),
                            $reflection,
                            $reflectionAttribute->getName(),
                            suggestion: 'Register the flow or update the handler attribute.',
                        );
                    }

                    $declaredBindingId = $attribute->bindingId();
                    $bindingId = $declaredBindingId === null || trim($declaredBindingId) === ''
                        ? null
                        : $declaredBindingId;
                    if ($flow?->isAsync() === true && $bindingId === null) {
                        $diagnostics[] = $this->errorForClass(
                            RegistryDiagnosticCode::BindingIdMissing,
                            sprintf('Async binding on "%s" must declare a stable bindingId.', $handlerClass),
                            $reflection,
                            $reflectionAttribute->getName(),
                            suggestion: 'Set bindingId explicitly on the async handler attribute.',
                        );
                    }

                    $message = $attribute->message();
                    if (!class_exists($message)) {
                        $diagnostics[] = $this->errorForClass(
                            RegistryDiagnosticCode::BindingMessageMissing,
                            sprintf('Binding message class "%s" does not exist.', $message),
                            $reflection,
                            $reflectionAttribute->getName(),
                            bindingId: $bindingId,
                            suggestion: 'Reference an autoloadable message class.',
                        );
                    } else {
                        /** @var class-string $message */
                        $messageKind = $this->messageKind($message, $diagnostics);
                        if ($messageKind !== null) {
                            $messageKinds[$message] = $messageKind;
                            if ($messageKind !== $kind) {
                                $diagnostics[] = $this->errorForClass(
                                    RegistryDiagnosticCode::BindingMessageKindMismatch,
                                    sprintf('Binding kind "%s" does not match message "%s".', $kind->value, $message),
                                    $reflection,
                                    $reflectionAttribute->getName(),
                                    bindingId: $bindingId,
                                    suggestion: 'Use the handler attribute matching the message marker.',
                                );
                            }
                        }

                        $alias = $this->hydrateMessageAlias($message, $aliases, $diagnostics);
                        if ($flow?->isAsync() === true && $alias === null) {
                            $diagnostics[] = $this->errorForClass(
                                RegistryDiagnosticCode::MessageAliasRequired,
                                sprintf('Async message "%s" must declare MessageAlias.', $message),
                                $reflection,
                                $reflectionAttribute->getName(),
                                bindingId: $bindingId,
                                suggestion: 'Add a stable MessageAlias to every durable message.',
                            );
                        }
                    }

                    if ($flow?->isAsync() === true && $kind === HandlerKind::Query) {
                        $diagnostics[] = $this->errorForClass(
                            RegistryDiagnosticCode::QueryAsyncFlow,
                            sprintf('Query "%s" must be bound to a sync flow.', $message),
                            $reflection,
                            $reflectionAttribute->getName(),
                            bindingId: $bindingId,
                            suggestion: 'Use a command or event for asynchronous work.',
                        );
                    }

                    $resolvedBindingId = $bindingId ?? $this->autoBindingId(
                        $kind,
                        $message,
                        $handlerClass,
                        $attribute->method(),
                        $flowKey,
                    );
                    if (isset($bindingOwners[$resolvedBindingId])) {
                        $diagnostics[] = $this->errorForClass(
                            RegistryDiagnosticCode::BindingIdDuplicate,
                            sprintf(
                                'Binding ID "%s" is used by both "%s" and "%s".',
                                $resolvedBindingId,
                                $bindingOwners[$resolvedBindingId],
                                $handlerClass,
                            ),
                            $reflection,
                            $reflectionAttribute->getName(),
                            bindingId: $resolvedBindingId,
                            suggestion: 'Assign a unique stable ID to every handler binding.',
                        );
                    } else {
                        $bindingOwners[$resolvedBindingId] = $handlerClass;
                    }

                    $this->validateHandlerSignature(
                        $reflection,
                        $attribute,
                        $kind,
                        $resolvedBindingId,
                        $diagnostics,
                    );
                    $this->validateInterceptors(
                        $reflection,
                        $reflectionAttribute->getName(),
                        $attribute->middleware(),
                        $resolvedBindingId,
                        $diagnostics,
                    );

                    if ($instantiable && $flow !== null && count($diagnostics) === $before) {
                        /** @var class-string $message */
                        /** @var list<class-string<InterceptorInterface>> $middleware */
                        $middleware = $attribute->middleware();
                        $delivery = match (true) {
                            $attribute instanceof CommandHandler => $attribute->delivery(),
                            $attribute instanceof EventSubscriber => $attribute->delivery(),
                            default => null,
                        };
                        $bindings[] = new PendingBinding(
                            $resolvedBindingId,
                            $message,
                            $kind,
                            $handlerClass,
                            $attribute->method(),
                            $flowKey,
                            $attribute->priority(),
                            $attribute instanceof CommandHandler ? $attribute->primary : null,
                            $attribute->contextAware()
                                ? HandlerInvocationMode::ContextAware
                                : HandlerInvocationMode::Contextless,
                            $middleware,
                            $delivery,
                        );
                    }
                } catch (Throwable $exception) {
                    $diagnostics[] = $this->errorForClass(
                        RegistryDiagnosticCode::HandlerAttributeInvalid,
                        sprintf(
                            'Handler attribute "%s" on "%s" is invalid: %s',
                            $reflectionAttribute->getName(),
                            $handlerClass,
                            $exception->getMessage(),
                        ),
                        $reflection,
                        $reflectionAttribute->getName(),
                        suggestion: 'Correct the attribute arguments and referenced classes.',
                    );
                }
            }
        }

        return $bindings;
    }

    /**
     * @param ReflectionClass<object> $handler
     * @param list<RegistryDiagnostic> $diagnostics
     */
    private function validateHandlerSignature(
        ReflectionClass $handler,
        MessageHandlerAttributeInterface $attribute,
        HandlerKind $kind,
        ?string $bindingId,
        array &$diagnostics,
    ): void {
        $methodName = trim($attribute->method());
        if ($methodName === '' || !$handler->hasMethod($methodName)) {
            $diagnostics[] = $this->errorForClass(
                RegistryDiagnosticCode::HandlerMethodMissing,
                sprintf('Handler method "%s::%s" does not exist.', $handler->getName(), $methodName),
                $handler,
                bindingId: $bindingId,
                suggestion: 'Declare the configured method or update the handler attribute.',
            );

            return;
        }

        $method = $handler->getMethod($methodName);
        if (!$method->isPublic() || $method->isStatic()) {
            $diagnostics[] = $this->errorForMethod(
                RegistryDiagnosticCode::HandlerMethodNotCallable,
                sprintf('Handler method "%s::%s" must be public and non-static.', $handler->getName(), $methodName),
                $method,
                bindingId: $bindingId,
                suggestion: 'Expose a public instance handler method.',
            );
        }

        $parameters = $method->getParameters();
        $expectedCount = $attribute->contextAware() ? 2 : 1;
        if (count($parameters) !== $expectedCount) {
            $diagnostics[] = $this->errorForMethod(
                RegistryDiagnosticCode::HandlerSignatureInvalid,
                sprintf(
                    'Handler "%s::%s" must accept exactly %d argument(s); %d declared.',
                    $handler->getName(),
                    $methodName,
                    $expectedCount,
                    count($parameters),
                ),
                $method,
                bindingId: $bindingId,
                suggestion: $attribute->contextAware()
                    ? 'Use (Message $message, MessageContextInterface $context).'
                    : 'Use (Message $message) or enable contextAware.',
            );
        }

        $message = $attribute->message();
        if (isset($parameters[0]) && !$this->parameterAccepts($parameters[0]->getType(), $message)) {
            $diagnostics[] = $this->errorForMethod(
                RegistryDiagnosticCode::HandlerSignatureInvalid,
                sprintf('First argument of "%s::%s" must accept "%s".', $handler->getName(), $methodName, $message),
                $method,
                bindingId: $bindingId,
                suggestion: 'Type the first argument with the declared message class or its interface.',
            );
        }

        if (
            $attribute->contextAware()
            && isset($parameters[1])
            && !$this->parameterAccepts($parameters[1]->getType(), MessageContextInterface::class)
        ) {
            $diagnostics[] = $this->errorForMethod(
                RegistryDiagnosticCode::HandlerSignatureInvalid,
                sprintf(
                    'Second argument of "%s::%s" must accept %s.',
                    $handler->getName(),
                    $methodName,
                    MessageContextInterface::class,
                ),
                $method,
                bindingId: $bindingId,
                suggestion: 'Type the second argument with MessageContextInterface.',
            );
        }

        $returnType = $method->getReturnType();
        if ($kind === HandlerKind::Query && ($returnType === null || $this->isVoidLike($returnType))) {
            $diagnostics[] = $this->errorForMethod(
                RegistryDiagnosticCode::HandlerSignatureInvalid,
                sprintf('Query handler "%s::%s" must declare a non-void return type.', $handler->getName(), $methodName),
                $method,
                bindingId: $bindingId,
                suggestion: 'Declare the query result type explicitly.',
            );
        }

        if ($kind === HandlerKind::Command && !$this->isNamedType($returnType, 'void')) {
            $diagnostics[] = $this->errorForMethod(
                RegistryDiagnosticCode::HandlerSignatureInvalid,
                sprintf('Command handler "%s::%s" must return void.', $handler->getName(), $methodName),
                $method,
                bindingId: $bindingId,
                suggestion: 'Use QueryHandler when the caller needs a result.',
            );
        }

        if (
            $kind === HandlerKind::Event
            && !$this->isNamedType($returnType, 'void')
            && !$this->isNamedType($returnType, 'null')
        ) {
            $diagnostics[] = $this->errorForMethod(
                RegistryDiagnosticCode::HandlerSignatureInvalid,
                sprintf('Event subscriber "%s::%s" must return void or null.', $handler->getName(), $methodName),
                $method,
                bindingId: $bindingId,
                suggestion: 'Event subscribers must not return business results.',
            );
        }
    }

    /**
     * @param ReflectionClass<object> $handler
     * @param list<class-string<InterceptorInterface>> $interceptors
     * @param list<RegistryDiagnostic> $diagnostics
     */
    private function validateInterceptors(
        ReflectionClass $handler,
        string $attribute,
        array $interceptors,
        ?string $bindingId,
        array &$diagnostics,
    ): void {
        foreach ($interceptors as $interceptor) {
            if (!class_exists($interceptor)) {
                $diagnostics[] = $this->errorForClass(
                    RegistryDiagnosticCode::InterceptorMissing,
                    sprintf('Interceptor class "%s" does not exist.', $interceptor),
                    $handler,
                    $attribute,
                    bindingId: $bindingId,
                    suggestion: 'Reference an autoloadable interceptor class.',
                );

                continue;
            }

            $reflection = new ReflectionClass($interceptor);
            if (!$reflection->isInstantiable()) {
                $diagnostics[] = $this->errorForClass(
                    RegistryDiagnosticCode::InterceptorInvalid,
                    sprintf('Interceptor "%s" must be an instantiable %s.', $interceptor, InterceptorInterface::class),
                    $handler,
                    $attribute,
                    bindingId: $bindingId,
                    suggestion: 'Use a concrete class implementing InterceptorInterface.',
                );
            }
        }
    }

    /**
     * @param list<PendingBinding> $bindings
     * @param list<RegistryDiagnostic> $diagnostics
     * @return array<string, bool>
     */
    private function validateCardinality(
        array $bindings,
        FlowRegistry $flows,
        array &$diagnostics,
    ): array
    {
        $groups = [];
        foreach ($bindings as $binding) {
            $groups[$binding->kind->value][$binding->message][] = $binding;
        }

        $primaryBindings = [];
        foreach ($groups[HandlerKind::Query->value] ?? [] as $message => $messageBindings) {
            if (count($messageBindings) !== 1) {
                $diagnostics[] = $this->error(
                    RegistryDiagnosticCode::QueryHandlerCount,
                    sprintf('Query "%s" must have exactly one handler; %d found.', $message, count($messageBindings)),
                    sourceClass: $messageBindings[0]->handler,
                    bindingId: $messageBindings[0]->id,
                    suggestion: 'Keep query dispatch single-handler.',
                );
            }

            foreach ($messageBindings as $binding) {
                $primaryBindings[$binding->id] = true;
            }
        }

        foreach ($groups[HandlerKind::Command->value] ?? [] as $message => $messageBindings) {
            $syncBindings = array_values(array_filter(
                $messageBindings,
                static fn (PendingBinding $binding): bool => $flows->get($binding->flow)->isSync(),
            ));
            if ($syncBindings === []) {
                continue;
            }

            $explicitPrimary = array_values(array_filter(
                $syncBindings,
                static fn (PendingBinding $binding): bool => $binding->primary === true,
            ));

            if (count($syncBindings) === 1 && $syncBindings[0]->primary === null) {
                $primaryBindings[$syncBindings[0]->id] = true;
                continue;
            }

            if (count($syncBindings) > 1 && $explicitPrimary === []) {
                $diagnostics[] = $this->error(
                    RegistryDiagnosticCode::CommandPrimaryMissing,
                    sprintf('Command "%s" must have exactly one primary handler.', $message),
                    sourceClass: $syncBindings[0]->handler,
                    bindingId: $syncBindings[0]->id,
                    suggestion: 'Set primary: true on exactly one CommandHandler.',
                );

                continue;
            }

            if (count($explicitPrimary) > 1) {
                $diagnostics[] = $this->error(
                    RegistryDiagnosticCode::CommandPrimaryDuplicate,
                    sprintf('Command "%s" has more than one primary handler.', $message),
                    sourceClass: $explicitPrimary[0]->handler,
                    bindingId: $explicitPrimary[0]->id,
                    suggestion: 'Keep primary: true on one CommandHandler only.',
                );

                continue;
            }

            if ($explicitPrimary !== []) {
                $primaryBindings[$explicitPrimary[0]->id] = true;
            }
        }

        return $primaryBindings;
    }

    /** @param list<RegistryDiagnostic> $diagnostics */
    private function validateFlows(FlowRegistry $flows, array &$diagnostics): void
    {
        foreach ($flows->all() as $flow) {
            if ($flow->isAsync() && $flow->transport === null) {
                $diagnostics[] = $this->error(
                    RegistryDiagnosticCode::FlowInvalid,
                    sprintf('Async flow "%s" must declare transport and queue.', $flow->key),
                    sourceClass: $flow->key,
                    suggestion: 'Call transport() when defining an async flow.',
                );
            }

            foreach ($flow->middleware as $middleware) {
                if (!class_exists($middleware)) {
                    $diagnostics[] = $this->error(
                        RegistryDiagnosticCode::InterceptorMissing,
                        sprintf('Flow interceptor class "%s" does not exist.', $middleware),
                        sourceClass: $flow->key,
                        suggestion: 'Reference an autoloadable interceptor class.',
                    );

                    continue;
                }

                $reflection = new ReflectionClass($middleware);
                if (!$reflection->isInstantiable()) {
                    $diagnostics[] = $this->error(
                        RegistryDiagnosticCode::InterceptorInvalid,
                        sprintf('Flow interceptor "%s" must be instantiable.', $middleware),
                        sourceClass: $flow->key,
                        suggestion: 'Use a concrete InterceptorInterface implementation.',
                    );
                }
            }
        }
    }

    /**
     * @param class-string $message
     * @param list<RegistryDiagnostic> $diagnostics
     */
    private function messageKind(string $message, array &$diagnostics): ?HandlerKind
    {
        $kinds = $this->messageKinds($message);
        if (count($kinds) === 1) {
            return $kinds[0];
        }

        $reflection = new ReflectionClass($message);
        $diagnostics[] = $this->errorForClass(
            RegistryDiagnosticCode::BindingMessageKindMismatch,
            $kinds === []
                ? sprintf('Binding message "%s" does not implement a message marker.', $message)
                : sprintf('Binding message "%s" implements more than one message marker.', $message),
            $reflection,
            suggestion: 'Implement exactly one of Query, Command, or Event.',
        );

        return null;
    }

    /**
     * @param class-string $message
     * @param array<string, class-string> $aliases
     * @param list<RegistryDiagnostic> $diagnostics
     */
    private function hydrateMessageAlias(string $message, array &$aliases, array &$diagnostics): ?string
    {
        $knownAlias = array_search($message, $aliases, true);
        if (is_string($knownAlias)) {
            return $knownAlias;
        }

        $reflection = new ReflectionClass($message);
        $attributes = $reflection->getAttributes(MessageAlias::class);
        if ($attributes === []) {
            return null;
        }

        if (count($attributes) > 1) {
            $diagnostics[] = $this->errorForClass(
                RegistryDiagnosticCode::MessageAliasMultiple,
                sprintf('Message "%s" declares more than one MessageAlias.', $message),
                $reflection,
                MessageAlias::class,
                suggestion: 'Keep exactly one stable alias.',
            );

            return null;
        }

        /** @var MessageAlias $attribute */
        $attribute = $attributes[0]->newInstance();
        $alias = trim($attribute->name);
        if ($alias === '') {
            $diagnostics[] = $this->errorForClass(
                RegistryDiagnosticCode::MessageAliasEmpty,
                sprintf('Message "%s" declares an empty MessageAlias.', $message),
                $reflection,
                MessageAlias::class,
                suggestion: 'Use a non-empty stable alias.',
            );

            return null;
        }

        if (isset($aliases[$alias]) && $aliases[$alias] !== $message) {
            $diagnostics[] = $this->errorForClass(
                RegistryDiagnosticCode::MessageAliasDuplicate,
                sprintf(
                    'MessageAlias "%s" is used by both "%s" and "%s".',
                    $alias,
                    $aliases[$alias],
                    $message,
                ),
                $reflection,
                MessageAlias::class,
                alias: $alias,
                suggestion: 'Assign a unique stable alias to every durable message.',
            );

            return null;
        }

        $aliases[$alias] = $message;

        return $alias;
    }

    private function autoBindingId(
        HandlerKind $kind,
        string $message,
        string $handler,
        string $method,
        string $flow,
    ): string {
        return 'auto.' . sha1(implode('|', [$kind->value, $message, $handler, $method, $flow]));
    }

    /** @return list<HandlerKind> */
    private function messageKinds(string $class): array
    {
        $kinds = [];
        if (is_a($class, Query::class, true)) {
            $kinds[] = HandlerKind::Query;
        }
        if (is_a($class, Command::class, true)) {
            $kinds[] = HandlerKind::Command;
        }
        if (is_a($class, Event::class, true)) {
            $kinds[] = HandlerKind::Event;
        }

        return $kinds;
    }

    private function attributeKind(MessageHandlerAttributeInterface $attribute): ?HandlerKind
    {
        return match (true) {
            $attribute instanceof QueryHandler => HandlerKind::Query,
            $attribute instanceof CommandHandler => HandlerKind::Command,
            $attribute instanceof EventSubscriber => HandlerKind::Event,
            default => null,
        };
    }

    private function parameterAccepts(?ReflectionType $type, string $actualClass): bool
    {
        if ($type instanceof ReflectionNamedType) {
            return !$type->isBuiltin() && is_a($actualClass, $type->getName(), true);
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $inner) {
                if ($this->parameterAccepts($inner, $actualClass)) {
                    return true;
                }
            }

            return false;
        }

        if ($type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $inner) {
                if (!$this->parameterAccepts($inner, $actualClass)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private function isVoidLike(ReflectionType $type): bool
    {
        return $this->isNamedType($type, 'void') || $this->isNamedType($type, 'never');
    }

    private function isNamedType(?ReflectionType $type, string $name): bool
    {
        return $type instanceof ReflectionNamedType && $type->getName() === $name;
    }

    /**
     * @param list<RegistryDiagnostic> $diagnostics
     */
    private function hasErrors(array $diagnostics): bool
    {
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->severity === RegistryDiagnosticSeverity::Error) {
                return true;
            }
        }

        return false;
    }

    /** @param list<RegistryDiagnostic> $diagnostics */
    private function sortDiagnostics(array &$diagnostics): void
    {
        usort(
            $diagnostics,
            static fn (RegistryDiagnostic $left, RegistryDiagnostic $right): int => [
                $left->sourceClass ?? '',
                $left->sourceLine ?? 0,
                $left->code->value,
                $left->message,
            ] <=> [
                $right->sourceClass ?? '',
                $right->sourceLine ?? 0,
                $right->code->value,
                $right->message,
            ],
        );
    }

    private function error(
        RegistryDiagnosticCode $code,
        string $message,
        ?string $sourceClass = null,
        ?string $sourceFile = null,
        ?int $sourceLine = null,
        ?string $attribute = null,
        ?string $bindingId = null,
        ?string $alias = null,
        ?string $suggestion = null,
    ): RegistryDiagnostic {
        return new RegistryDiagnostic(
            RegistryDiagnosticSeverity::Error,
            $code,
            $message,
            $sourceClass,
            $sourceFile,
            $sourceLine,
            $attribute,
            $bindingId,
            $alias,
            $suggestion,
        );
    }

    /** @param ReflectionClass<object> $class */
    private function errorForClass(
        RegistryDiagnosticCode $code,
        string $message,
        ReflectionClass $class,
        ?string $attribute = null,
        ?string $bindingId = null,
        ?string $alias = null,
        ?string $suggestion = null,
    ): RegistryDiagnostic {
        $file = $class->getFileName();
        $line = $class->getStartLine();

        return $this->error(
            $code,
            $message,
            $class->getName(),
            $file === false ? null : $file,
            $line === false ? null : $line,
            $attribute,
            $bindingId,
            $alias,
            $suggestion,
        );
    }

    private function errorForMethod(
        RegistryDiagnosticCode $code,
        string $message,
        ReflectionMethod $method,
        ?string $bindingId = null,
        ?string $suggestion = null,
    ): RegistryDiagnostic {
        $file = $method->getFileName();
        $line = $method->getStartLine();

        return $this->error(
            $code,
            $message,
            $method->getDeclaringClass()->getName(),
            $file === false ? null : $file,
            $line === false ? null : $line,
            bindingId: $bindingId,
            suggestion: $suggestion,
        );
    }
}
