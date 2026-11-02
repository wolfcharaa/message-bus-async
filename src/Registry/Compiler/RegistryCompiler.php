<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Registry\Compiler;

use Fedor\MessageBus\Attribute\CommandHandler;
use Fedor\MessageBus\Attribute\EventSubscriber;
use Fedor\MessageBus\Attribute\MessageAlias;
use Fedor\MessageBus\Attribute\MessageHandlerAttributeInterface;
use Fedor\MessageBus\Attribute\QueryHandler;
use Fedor\MessageBus\Context\DefaultMessageContext;
use Fedor\MessageBus\Context\DefaultMessageContextFactory;
use Fedor\MessageBus\Context\MessageContextFactoryInterface;
use Fedor\MessageBus\Context\MessageContextInterface;
use Fedor\MessageBus\Execution\HandlerExecutionStrategyInterface;
use Fedor\MessageBus\Flow\FlowDefinition;
use Fedor\MessageBus\Flow\FlowRegistry;
use Fedor\MessageBus\Interceptor\InterceptorInterface;
use Fedor\MessageBus\Message\Command;
use Fedor\MessageBus\Message\Event;
use Fedor\MessageBus\Message\Query;
use Fedor\MessageBus\Registry\CompiledRegistry;
use Fedor\MessageBus\Registry\HandlerBinding;
use Fedor\MessageBus\Registry\HandlerBindingDefinition;
use Fedor\MessageBus\Registry\HandlerInvocationMode;
use Fedor\MessageBus\Registry\HandlerKind;
use Fedor\MessageBus\Registry\RegistryCompilationGraphContext;
use Fedor\MessageBus\Registry\RegistryCompilationStage;
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
        [, $aliases] = $this->discoverMessages($reflections, $diagnostics);
        $bindings = $this->discoverBindings($reflections, $diagnostics);
        $messageNames = $this->messageNames($aliases);
        $graphContext = $this->graphContext(
            RegistryCompilationStage::HandlersDiscovered,
            $bindings,
            $aliases,
            $messageNames,
            $flows,
        );
        if ($this->hasErrors($diagnostics)) {
            return $this->result(null, $diagnostics, $graphContext);
        }

        [$aliases, $messageNames] = $this->hydrateMessageAliases(
            $bindings,
            $aliases,
            $messageNames,
            $diagnostics,
        );
        $graphContext = $this->graphContext(
            RegistryCompilationStage::AliasesHydrated,
            $bindings,
            $aliases,
            $messageNames,
            $flows,
        );
        if ($this->hasErrors($diagnostics)) {
            return $this->result(null, $diagnostics, $graphContext);
        }

        $bindings = $this->normalizeBindings($bindings, $flows, $messageNames, $diagnostics);
        $graphContext = $this->graphContext(
            RegistryCompilationStage::BindingsNormalized,
            $bindings,
            $aliases,
            $messageNames,
            $flows,
        );
        if ($this->hasErrors($diagnostics)) {
            return $this->result(null, $diagnostics, $graphContext);
        }

        $this->validateFlows($flows, $diagnostics);
        $this->validateBindings($bindings, $flows, $diagnostics);
        $graphContext = $this->graphContext(
            RegistryCompilationStage::CoreValidated,
            $bindings,
            $aliases,
            $messageNames,
            $flows,
        );
        if ($this->hasErrors($diagnostics)) {
            return $this->result(null, $diagnostics, $graphContext);
        }

        $compiledBindings = array_map(
            static fn (HandlerBindingDefinition $binding): HandlerBinding => $binding->compile(),
            $bindings,
        );

        return $this->result(
            new CompiledRegistry($compiledBindings, $aliases, $flows),
            $diagnostics,
            $graphContext,
        );
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
     * @param list<RegistryDiagnostic> $diagnostics
     * @return list<HandlerBindingDefinition>
     */
    private function discoverBindings(
        array $reflections,
        array &$diagnostics,
    ): array {
        $bindings = [];

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
                    $declaredBindingId = $attribute->bindingId();
                    $bindingId = $declaredBindingId === null || trim($declaredBindingId) === ''
                        ? null
                        : $declaredBindingId;
                    $message = $attribute->message();
                    /** @var class-string $message */
                    /** @var list<class-string<InterceptorInterface>> $middleware */
                    $middleware = $attribute->middleware();
                    $delivery = match (true) {
                        $attribute instanceof CommandHandler => $attribute->delivery(),
                        $attribute instanceof EventSubscriber => $attribute->delivery(),
                        default => null,
                    };
                    $bindings[] = new HandlerBindingDefinition(
                        $bindingId,
                        $message,
                        $handlerClass,
                        $attribute->method(),
                        $flowKey,
                        $kind,
                        $attribute instanceof CommandHandler ? $attribute->primary : null,
                        $attribute->priority(),
                        $middleware,
                        $delivery,
                        $attribute->contextAware()
                            ? HandlerInvocationMode::ContextAware
                            : HandlerInvocationMode::Contextless,
                        owner: $attribute->registrationOwner(),
                        source: $attribute->registrationSource($handlerClass),
                    );
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
     * @param list<HandlerBindingDefinition> $bindings
     * @param array<string, class-string> $aliases
     * @param array<class-string, string> $messageNames
     * @param list<RegistryDiagnostic> $diagnostics
     * @return array{array<string, class-string>, array<class-string, string>}
     */
    private function hydrateMessageAliases(
        array $bindings,
        array $aliases,
        array $messageNames,
        array &$diagnostics,
    ): array {
        foreach ($bindings as $binding) {
            if (!class_exists($binding->message)) {
                continue;
            }

            $this->hydrateMessageAlias($binding->message, $aliases, $diagnostics);
        }

        return [$aliases, $this->messageNames($aliases) + $messageNames];
    }

    /**
     * @param list<HandlerBindingDefinition> $bindings
     * @param array<class-string, string> $messageNames
     * @param list<RegistryDiagnostic> $diagnostics
     * @return list<HandlerBindingDefinition>
     */
    private function normalizeBindings(
        array $bindings,
        FlowRegistry $flows,
        array $messageNames,
        array &$diagnostics,
    ): array {
        $normalized = [];

        foreach ($bindings as $binding) {
            $flow = $flows->all()[$binding->flow] ?? null;
            if ($flow === null) {
                $diagnostics[] = $this->error(
                    RegistryDiagnosticCode::FlowMissing,
                    sprintf('Message flow "%s" is not registered.', $binding->flow),
                    sourceClass: $binding->action,
                    bindingId: $binding->bindingId,
                    suggestion: 'Register the flow or update the handler attribute.',
                );

                continue;
            }

            if ($flow->isAsync() && $binding->bindingId === null) {
                $diagnostics[] = $this->error(
                    RegistryDiagnosticCode::BindingIdMissing,
                    sprintf('Async binding on "%s" must declare a stable bindingId.', $binding->action),
                    sourceClass: $binding->action,
                    suggestion: 'Set bindingId explicitly on the async handler attribute.',
                );

                continue;
            }

            if ($binding->bindingId === null) {
                $binding = $binding->withBindingId($this->autoBindingId(
                    $binding->kind,
                    $binding->message,
                    $binding->action,
                    $binding->method,
                    $binding->flow,
                ));
            }

            if ($flow->isAsync() && !isset($messageNames[$binding->message])) {
                $diagnostics[] = $this->error(
                    RegistryDiagnosticCode::MessageAliasRequired,
                    sprintf('Async message "%s" must declare MessageAlias.', $binding->message),
                    sourceClass: $binding->action,
                    bindingId: $binding->bindingId,
                    suggestion: 'Add a stable MessageAlias to every durable message.',
                );

                continue;
            }

            $normalized[] = $binding;
        }

        $primaryBindings = $this->validateCardinality($normalized, $flows, $diagnostics);

        return array_map(
            static fn (HandlerBindingDefinition $binding): HandlerBindingDefinition => $binding->withPrimary(
                $primaryBindings[$binding->bindingId ?? ''] ?? false,
            ),
            $normalized,
        );
    }

    /**
     * @param list<HandlerBindingDefinition> $bindings
     * @param list<RegistryDiagnostic> $diagnostics
     */
    private function validateBindings(array $bindings, FlowRegistry $flows, array &$diagnostics): void
    {
        $bindingOwners = [];

        foreach ($bindings as $binding) {
            if ($binding->bindingId === null) {
                $diagnostics[] = $this->error(
                    RegistryDiagnosticCode::BindingIdMissing,
                    'Binding ID was not normalized.',
                    sourceClass: $binding->action,
                    suggestion: 'Report this as a compiler normalization bug.',
                );

                continue;
            }

            if (isset($bindingOwners[$binding->bindingId])) {
                $diagnostics[] = $this->error(
                    RegistryDiagnosticCode::BindingIdDuplicate,
                    sprintf(
                        'Binding ID "%s" is used by both "%s" and "%s".',
                        $binding->bindingId,
                        $bindingOwners[$binding->bindingId],
                        $binding->action,
                    ),
                    sourceClass: $binding->action,
                    bindingId: $binding->bindingId,
                    suggestion: 'Assign a unique stable ID to every handler binding.',
                );
            } else {
                $bindingOwners[$binding->bindingId] = $binding->action;
            }

            $flow = $flows->all()[$binding->flow] ?? null;
            if ($flow === null) {
                $diagnostics[] = $this->error(
                    RegistryDiagnosticCode::FlowMissing,
                    sprintf('Message flow "%s" is not registered.', $binding->flow),
                    sourceClass: $binding->action,
                    bindingId: $binding->bindingId,
                    suggestion: 'Register the flow or update the handler attribute.',
                );

                continue;
            }

            if (!class_exists($binding->message)) {
                $diagnostics[] = $this->error(
                    RegistryDiagnosticCode::BindingMessageMissing,
                    sprintf('Binding message class "%s" does not exist.', $binding->message),
                    sourceClass: $binding->action,
                    bindingId: $binding->bindingId,
                    suggestion: 'Reference an autoloadable message class.',
                );

                continue;
            }

            $messageKind = $this->messageKind($binding->message, $diagnostics);
            if ($messageKind !== null && $messageKind !== $binding->kind) {
                $diagnostics[] = $this->error(
                    RegistryDiagnosticCode::BindingMessageKindMismatch,
                    sprintf(
                        'Binding kind "%s" does not match message "%s".',
                        $binding->kind->value,
                        $binding->message,
                    ),
                    sourceClass: $binding->action,
                    bindingId: $binding->bindingId,
                    suggestion: 'Use the handler attribute matching the message marker.',
                );
            }

            if ($flow->isAsync() && $binding->kind === HandlerKind::Query) {
                $diagnostics[] = $this->error(
                    RegistryDiagnosticCode::QueryAsyncFlow,
                    sprintf('Query "%s" must be bound to a sync flow.', $binding->message),
                    sourceClass: $binding->action,
                    bindingId: $binding->bindingId,
                    suggestion: 'Use a command or event for asynchronous work.',
                );
            }

            $this->validateHandlerSignature($binding, $flow, $diagnostics);
            $this->validateInterceptors($binding, $diagnostics);
        }
    }

    /**
     * @param list<RegistryDiagnostic> $diagnostics
     */
    private function validateHandlerSignature(
        HandlerBindingDefinition $binding,
        FlowDefinition $flow,
        array &$diagnostics,
    ): void {
        if (!class_exists($binding->action)) {
            $diagnostics[] = $this->error(
                RegistryDiagnosticCode::HandlerClassNotInstantiable,
                sprintf('Handler class "%s" does not exist.', $binding->action),
                sourceClass: $binding->action,
                bindingId: $binding->bindingId,
                suggestion: 'Reference an autoloadable handler class.',
            );

            return;
        }

        $handler = new ReflectionClass($binding->action);
        $methodName = trim($binding->method);
        if ($methodName === '' || !$handler->hasMethod($methodName)) {
            $diagnostics[] = $this->errorForClass(
                RegistryDiagnosticCode::HandlerMethodMissing,
                sprintf('Handler method "%s::%s" does not exist.', $handler->getName(), $methodName),
                $handler,
                bindingId: $binding->bindingId,
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
                bindingId: $binding->bindingId,
                suggestion: 'Expose a public instance handler method.',
            );
        }

        $parameters = $method->getParameters();
        $contextAware = $binding->invocationMode === HandlerInvocationMode::ContextAware;
        $expectedCount = $contextAware ? 2 : 1;
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
                bindingId: $binding->bindingId,
                suggestion: $contextAware
                    ? 'Use (Message $message, MessageContextInterface $context).'
                    : 'Use (Message $message) or enable contextAware.',
            );
        }

        $message = $binding->message;
        if (isset($parameters[0]) && !$this->parameterAccepts($parameters[0]->getType(), $message)) {
            $diagnostics[] = $this->errorForMethod(
                RegistryDiagnosticCode::HandlerSignatureInvalid,
                sprintf('First argument of "%s::%s" must accept "%s".', $handler->getName(), $methodName, $message),
                $method,
                bindingId: $binding->bindingId,
                suggestion: 'Type the first argument with the declared message class or its interface.',
            );
        }

        if (
            $contextAware
            && isset($parameters[1])
            && !$this->contextParameterAccepts($parameters[1]->getType(), $flow)
        ) {
            $diagnostics[] = $this->errorForMethod(
                RegistryDiagnosticCode::HandlerSignatureInvalid,
                sprintf(
                    'Second argument of "%s::%s" must accept %s.',
                    $handler->getName(),
                    $methodName,
                    $flow->contextInterface,
                ),
                $method,
                bindingId: $binding->bindingId,
                suggestion: 'Type the second argument with the configured flow context.',
            );
        }

        $returnType = $method->getReturnType();
        if ($binding->kind === HandlerKind::Query && ($returnType === null || $this->isVoidLike($returnType))) {
            $diagnostics[] = $this->errorForMethod(
                RegistryDiagnosticCode::HandlerSignatureInvalid,
                sprintf('Query handler "%s::%s" must declare a non-void return type.', $handler->getName(), $methodName),
                $method,
                bindingId: $binding->bindingId,
                suggestion: 'Declare the query result type explicitly.',
            );
        }

        if ($binding->kind === HandlerKind::Command && !$this->isNamedType($returnType, 'void')) {
            $diagnostics[] = $this->errorForMethod(
                RegistryDiagnosticCode::HandlerSignatureInvalid,
                sprintf('Command handler "%s::%s" must return void.', $handler->getName(), $methodName),
                $method,
                bindingId: $binding->bindingId,
                suggestion: 'Use QueryHandler when the caller needs a result.',
            );
        }

        if (
            $binding->kind === HandlerKind::Event
            && !$this->isNamedType($returnType, 'void')
            && !$this->isNamedType($returnType, 'null')
        ) {
            $diagnostics[] = $this->errorForMethod(
                RegistryDiagnosticCode::HandlerSignatureInvalid,
                sprintf('Event subscriber "%s::%s" must return void or null.', $handler->getName(), $methodName),
                $method,
                bindingId: $binding->bindingId,
                suggestion: 'Event subscribers must not return business results.',
            );
        }
    }

    /**
     * @param list<RegistryDiagnostic> $diagnostics
     */
    private function validateInterceptors(
        HandlerBindingDefinition $binding,
        array &$diagnostics,
    ): void {
        $handler = new ReflectionClass($binding->action);
        foreach ($binding->middleware as $interceptor) {
            if (!class_exists($interceptor)) {
                $diagnostics[] = $this->errorForClass(
                    RegistryDiagnosticCode::InterceptorMissing,
                    sprintf('Interceptor class "%s" does not exist.', $interceptor),
                    $handler,
                    bindingId: $binding->bindingId,
                    suggestion: 'Reference an autoloadable interceptor class.',
                );

                continue;
            }

            $reflection = new ReflectionClass($interceptor);
            if (!$reflection->isInstantiable() || !is_a($interceptor, InterceptorInterface::class, true)) {
                $diagnostics[] = $this->errorForClass(
                    RegistryDiagnosticCode::InterceptorInvalid,
                    sprintf('Interceptor "%s" must be an instantiable %s.', $interceptor, InterceptorInterface::class),
                    $handler,
                    bindingId: $binding->bindingId,
                    suggestion: 'Use a concrete class implementing InterceptorInterface.',
                );
            }
        }
    }

    /**
     * @param list<HandlerBindingDefinition> $bindings
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
                    sourceClass: $messageBindings[0]->action,
                    bindingId: $messageBindings[0]->bindingId,
                    suggestion: 'Keep query dispatch single-handler.',
                );
            }

            foreach ($messageBindings as $binding) {
                if ($binding->bindingId !== null) {
                    $primaryBindings[$binding->bindingId] = true;
                }
            }
        }

        foreach ($groups[HandlerKind::Command->value] ?? [] as $message => $messageBindings) {
            $syncBindings = array_values(array_filter(
                $messageBindings,
                static fn (HandlerBindingDefinition $binding): bool => ($flows->all()[$binding->flow] ?? null)?->isSync() ?? false,
            ));
            if ($syncBindings === []) {
                continue;
            }

            $explicitPrimary = array_values(array_filter(
                $syncBindings,
                static fn (HandlerBindingDefinition $binding): bool => $binding->primary === true,
            ));

            if (count($syncBindings) === 1 && $syncBindings[0]->primary === null) {
                if ($syncBindings[0]->bindingId !== null) {
                    $primaryBindings[$syncBindings[0]->bindingId] = true;
                }
                continue;
            }

            if (count($syncBindings) > 1 && $explicitPrimary === []) {
                $diagnostics[] = $this->error(
                    RegistryDiagnosticCode::CommandPrimaryMissing,
                    sprintf('Command "%s" must have exactly one primary handler.', $message),
                    sourceClass: $syncBindings[0]->action,
                    bindingId: $syncBindings[0]->bindingId,
                    suggestion: 'Set primary: true on exactly one CommandHandler.',
                );

                continue;
            }

            if (count($explicitPrimary) > 1) {
                $diagnostics[] = $this->error(
                    RegistryDiagnosticCode::CommandPrimaryDuplicate,
                    sprintf('Command "%s" has more than one primary handler.', $message),
                    sourceClass: $explicitPrimary[0]->action,
                    bindingId: $explicitPrimary[0]->bindingId,
                    suggestion: 'Keep primary: true on one CommandHandler only.',
                );

                continue;
            }

            if ($explicitPrimary !== [] && $explicitPrimary[0]->bindingId !== null) {
                $primaryBindings[$explicitPrimary[0]->bindingId] = true;
            }
        }

        return $primaryBindings;
    }

    /** @param list<RegistryDiagnostic> $diagnostics */
    private function validateFlows(FlowRegistry $flows, array &$diagnostics): void
    {
        foreach ($flows->all() as $flow) {
            if (!interface_exists($flow->contextInterface) && !class_exists($flow->contextInterface)) {
                $diagnostics[] = $this->error(
                    RegistryDiagnosticCode::FlowInvalid,
                    sprintf('Flow "%s" context "%s" does not exist.', $flow->key, $flow->contextInterface),
                    sourceClass: $flow->key,
                    suggestion: 'Reference an existing MessageContextInterface implementation.',
                );

                continue;
            }

            if (!is_a($flow->contextInterface, MessageContextInterface::class, true)) {
                $diagnostics[] = $this->error(
                    RegistryDiagnosticCode::FlowInvalid,
                    sprintf(
                        'Flow "%s" context "%s" must extend %s.',
                        $flow->key,
                        $flow->contextInterface,
                        MessageContextInterface::class,
                    ),
                    sourceClass: $flow->key,
                    suggestion: 'Use a context implementing MessageContextInterface.',
                );
            }

            if ($flow->contextFactory === null) {
                $diagnostics[] = $this->error(
                    RegistryDiagnosticCode::FlowInvalid,
                    sprintf('Flow "%s" must declare a context factory.', $flow->key),
                    sourceClass: $flow->key,
                    suggestion: 'Set a context factory for custom flow contexts.',
                );
            } elseif (!class_exists($flow->contextFactory)) {
                $diagnostics[] = $this->error(
                    RegistryDiagnosticCode::FlowInvalid,
                    sprintf('Flow "%s" context factory "%s" does not exist.', $flow->key, $flow->contextFactory),
                    sourceClass: $flow->key,
                    suggestion: 'Reference an existing MessageContextFactoryInterface implementation.',
                );
            } elseif (!is_a($flow->contextFactory, MessageContextFactoryInterface::class, true)) {
                $diagnostics[] = $this->error(
                    RegistryDiagnosticCode::FlowInvalid,
                    sprintf(
                        'Flow "%s" context factory "%s" must implement %s.',
                        $flow->key,
                        $flow->contextFactory,
                        MessageContextFactoryInterface::class,
                    ),
                    sourceClass: $flow->key,
                    suggestion: 'Use a MessageContextFactoryInterface implementation.',
                );
            }

            if (!class_exists($flow->strategy)) {
                $diagnostics[] = $this->error(
                    RegistryDiagnosticCode::FlowInvalid,
                    sprintf('Flow "%s" strategy "%s" does not exist.', $flow->key, $flow->strategy),
                    sourceClass: $flow->key,
                    suggestion: 'Reference an existing execution strategy.',
                );
            } else {
                $strategy = new ReflectionClass($flow->strategy);
                if (!$strategy->isInstantiable() || !is_a($flow->strategy, HandlerExecutionStrategyInterface::class, true)) {
                    $diagnostics[] = $this->error(
                        RegistryDiagnosticCode::FlowInvalid,
                        sprintf(
                            'Flow "%s" strategy "%s" must implement %s.',
                            $flow->key,
                            $flow->strategy,
                            HandlerExecutionStrategyInterface::class,
                        ),
                        sourceClass: $flow->key,
                        suggestion: 'Use an instantiable HandlerExecutionStrategyInterface implementation.',
                    );
                }
            }

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
                if (!$reflection->isInstantiable() || !is_a($middleware, InterceptorInterface::class, true)) {
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

    private function contextParameterAccepts(?ReflectionType $type, FlowDefinition $flow): bool
    {
        if ($this->parameterAccepts($type, $flow->contextInterface)) {
            return true;
        }

        return $flow->contextFactory === DefaultMessageContextFactory::class
            && $this->parameterAccepts($type, DefaultMessageContext::class);
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
     * @param array<string, class-string> $aliases
     * @return array<class-string, string>
     */
    private function messageNames(array $aliases): array
    {
        $messageNames = [];
        foreach ($aliases as $alias => $message) {
            $messageNames[$message] = $alias;
        }
        ksort($messageNames, SORT_STRING);

        return $messageNames;
    }

    /**
     * @param list<HandlerBindingDefinition> $bindings
     * @param array<string, class-string> $aliases
     * @param array<class-string, string> $messageNames
     */
    private function graphContext(
        RegistryCompilationStage $stage,
        array $bindings,
        array $aliases,
        array $messageNames,
        FlowRegistry $flows,
    ): RegistryCompilationGraphContext {
        return new RegistryCompilationGraphContext($stage, $bindings, $aliases, $messageNames, $flows);
    }

    /** @param list<RegistryDiagnostic> $diagnostics */
    private function result(
        ?CompiledRegistry $registry,
        array $diagnostics,
        RegistryCompilationGraphContext $graphContext,
    ): RegistryCompilationResult {
        $this->sortDiagnostics($diagnostics);

        return new RegistryCompilationResult($registry, $diagnostics, $graphContext);
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
