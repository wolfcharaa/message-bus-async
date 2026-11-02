<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Registry;

use DateTimeImmutable;
use Fedor\MessageBus\Flow\FlowRegistry;
use Fedor\MessageBus\Registry\Compiler\RegistryCompiler as InternalRegistryCompiler;
use Fedor\MessageBus\Registry\Compiler\RegistryDiagnostic as InternalRegistryDiagnostic;
use Fedor\MessageBus\Registry\Compiler\RegistryDiagnosticCode as InternalRegistryDiagnosticCode;
use Fedor\MessageBus\Registry\Compiler\RegistryDiagnosticSeverity as InternalRegistryDiagnosticSeverity;

final class MessageRegistryCompiler
{
    public const SCHEMA_VERSION = 6;
    public const LIBRARY_VERSION = '0.1.0';

    private const SOURCE_COMPILER = 'message_bus.registry.compiler';

    /** @param iterable<mixed> $validationRules */
    public function __construct(
        private readonly InternalRegistryCompiler $compiler = new InternalRegistryCompiler(),
        private readonly iterable $validationRules = [],
    ) {
    }

    /** @param list<class-string> $classes */
    public function compile(
        array $classes,
        ?FlowRegistry $flows = null,
        string $libraryVersion = self::LIBRARY_VERSION,
        string $sourceHash = '',
        ?MessageRegistryCompilerOptions $options = null,
    ): MessageRegistryDefinition {
        $options ??= new MessageRegistryCompilerOptions();
        $result = $this->compileWithDiagnostics($classes, $flows, $libraryVersion, $sourceHash, $options);

        if ($result->isFailure($options)) {
            throw RegistryCompilationException::fromDiagnostics($result->diagnostics);
        }

        $definition = $result->definition;
        if (!$definition instanceof MessageRegistryDefinition) {
            throw new RegistryCompilationException('Registry compilation failed: definition was not built.');
        }

        return $definition;
    }

    /** @param list<class-string> $classes */
    public function compileWithDiagnostics(
        array $classes,
        ?FlowRegistry $flows = null,
        string $libraryVersion = self::LIBRARY_VERSION,
        string $sourceHash = '',
        ?MessageRegistryCompilerOptions $options = null,
    ): RegistryCompilationResult {
        $options ??= new MessageRegistryCompilerOptions();
        $flows ??= new FlowRegistry();

        $compiled = $this->compiler->compileWithDiagnostics($classes, $flows);
        $graphContext = $compiled->graphContext;
        $diagnostics = array_map(
            fn (InternalRegistryDiagnostic $diagnostic): RegistryDiagnostic => $this->translateDiagnostic(
                $diagnostic,
                $graphContext,
            ),
            $compiled->diagnostics,
        );

        if ($compiled->registry === null || $this->hasErrorDiagnostics($diagnostics)) {
            return new RegistryCompilationResult(null, $diagnostics, $graphContext);
        }

        $bindings = $graphContext->bindings;
        $aliases = $graphContext->aliases;
        $messageNames = $graphContext->messageNames;

        foreach ($this->validationRules as $rule) {
            if (!$rule instanceof RegistryValidationRuleInterface) {
                throw new RegistryCompilationException(sprintf(
                    'Registry validation rule `%s` must implement `%s`.',
                    is_object($rule) ? $rule::class : get_debug_type($rule),
                    RegistryValidationRuleInterface::class,
                ));
            }

            foreach ($rule->validate($graphContext) as $diagnostic) {
                if (!$diagnostic instanceof RegistryDiagnostic) {
                    throw new RegistryCompilationException(sprintf(
                        'Registry validation rule `%s` must return only `%s` instances.',
                        $rule::class,
                        RegistryDiagnostic::class,
                    ));
                }

                $diagnostics[] = $diagnostic;
            }
        }

        $graphContext = new RegistryCompilationGraphContext(
            RegistryCompilationStage::ProjectRulesValidated,
            $bindings,
            $aliases,
            $messageNames,
            $flows,
        );
        if ($this->hasErrorDiagnostics($diagnostics)) {
            return new RegistryCompilationResult(null, $diagnostics, $graphContext);
        }

        $definition = $this->definition($graphContext, $libraryVersion, $sourceHash);

        return new RegistryCompilationResult(
            $definition,
            $diagnostics,
            new RegistryCompilationGraphContext(
                RegistryCompilationStage::DefinitionBuilt,
                $bindings,
                $aliases,
                $messageNames,
                $flows,
            ),
        );
    }

    private function translateDiagnostic(
        InternalRegistryDiagnostic $diagnostic,
        RegistryCompilationGraphContext $graphContext,
    ): RegistryDiagnostic {
        $originKind = $diagnostic->attribute !== null
            ? RegistryDiagnosticOriginKind::Attribute
            : RegistryDiagnosticOriginKind::ReflectionClass;
        $origin = new RegistryDiagnosticOrigin(
            $originKind,
            $diagnostic->sourceClass,
            $diagnostic->attribute,
            file: $diagnostic->sourceFile,
            line: $diagnostic->sourceLine,
            sourceRule: self::SOURCE_COMPILER,
        );

        return new RegistryDiagnostic(
            $diagnostic->severity === InternalRegistryDiagnosticSeverity::Error
                ? RegistryDiagnosticSeverity::Error
                : RegistryDiagnosticSeverity::Warning,
            $this->diagnosticCode($diagnostic->code),
            $diagnostic->message,
            $origin,
            $this->diagnosticTarget($diagnostic, $graphContext),
            $diagnostic->suggestion,
        );
    }

    private function diagnosticTarget(
        InternalRegistryDiagnostic $diagnostic,
        RegistryCompilationGraphContext $graphContext,
    ): RegistryDiagnosticTarget {
        $binding = null;
        foreach ($graphContext->bindings as $candidate) {
            if (
                $candidate->action === $diagnostic->sourceClass
                && ($diagnostic->bindingId === null || $candidate->bindingId === $diagnostic->bindingId)
            ) {
                $binding = $candidate;
                break;
            }
        }

        if ($binding === null && $diagnostic->bindingId !== null) {
            foreach ($graphContext->bindings as $candidate) {
                if ($candidate->bindingId === $diagnostic->bindingId) {
                    $binding = $candidate;
                    break;
                }
            }
        }

        if ($binding !== null) {
            return new RegistryDiagnosticTarget(
                bindingId: $diagnostic->bindingId ?? $binding->bindingId,
                messageName: $graphContext->messageNames[$binding->message] ?? null,
                messageClass: $binding->message,
                handlerClass: $binding->action,
                method: $binding->method,
                flow: $binding->flow,
                alias: $diagnostic->alias,
            );
        }

        $messageClass = match ($diagnostic->code) {
            InternalRegistryDiagnosticCode::MessageAliasDuplicate,
            InternalRegistryDiagnosticCode::MessageAliasMultiple,
            InternalRegistryDiagnosticCode::MessageAliasEmpty,
            InternalRegistryDiagnosticCode::MessageAliasInvalidTarget,
            InternalRegistryDiagnosticCode::MessageKindAmbiguous => $diagnostic->sourceClass,
            default => null,
        };

        return new RegistryDiagnosticTarget(
            bindingId: $diagnostic->bindingId,
            messageName: $messageClass === null ? null : ($graphContext->messageNames[$messageClass] ?? null),
            messageClass: $messageClass,
            handlerClass: $messageClass === null ? $diagnostic->sourceClass : null,
            alias: $diagnostic->alias,
        );
    }

    private function diagnosticCode(InternalRegistryDiagnosticCode $code): string
    {
        return match ($code) {
            InternalRegistryDiagnosticCode::MessageAliasDuplicate => RegistryDiagnosticCodes::ALIAS_DUPLICATE,
            InternalRegistryDiagnosticCode::MessageAliasMultiple => RegistryDiagnosticCodes::ALIAS_MULTIPLE_FOR_MESSAGE,
            InternalRegistryDiagnosticCode::MessageAliasRequired,
            InternalRegistryDiagnosticCode::MessageAliasMissing => RegistryDiagnosticCodes::MESSAGE_ALIAS_REQUIRED,
            InternalRegistryDiagnosticCode::BindingIdMissing => RegistryDiagnosticCodes::BINDING_MISSING_ID,
            InternalRegistryDiagnosticCode::BindingIdDuplicate => RegistryDiagnosticCodes::BINDING_DUPLICATE_ID,
            InternalRegistryDiagnosticCode::FlowMissing => RegistryDiagnosticCodes::FLOW_MISSING,
            InternalRegistryDiagnosticCode::FlowInvalid => RegistryDiagnosticCodes::FLOW_INVALID,
            InternalRegistryDiagnosticCode::HandlerMethodMissing,
            InternalRegistryDiagnosticCode::HandlerMethodNotCallable,
            InternalRegistryDiagnosticCode::HandlerSignatureInvalid => RegistryDiagnosticCodes::HANDLER_INVALID_SIGNATURE,
            InternalRegistryDiagnosticCode::InterceptorMissing,
            InternalRegistryDiagnosticCode::InterceptorInvalid => RegistryDiagnosticCodes::INTERCEPTOR_INVALID_SIGNATURE,
            InternalRegistryDiagnosticCode::QueryHandlerCount => RegistryDiagnosticCodes::QUERY_HANDLER_COUNT,
            InternalRegistryDiagnosticCode::QueryAsyncFlow => RegistryDiagnosticCodes::QUERY_ASYNC_FLOW,
            InternalRegistryDiagnosticCode::BindingMessageKindMismatch,
            InternalRegistryDiagnosticCode::MessageKindAmbiguous => RegistryDiagnosticCodes::MESSAGE_KIND_CONFLICT,
            InternalRegistryDiagnosticCode::CommandPrimaryMissing => RegistryDiagnosticCodes::COMMAND_PRIMARY_MISSING,
            InternalRegistryDiagnosticCode::CommandPrimaryDuplicate => RegistryDiagnosticCodes::COMMAND_PRIMARY_DUPLICATE,
            default => 'registry.' . str_replace('_', '.', $code->value),
        };
    }

    private function definition(
        RegistryCompilationGraphContext $graphContext,
        string $libraryVersion,
        string $sourceHash,
    ): MessageRegistryDefinition {
        $messages = [];
        $bindings = [];
        foreach ($graphContext->bindings as $binding) {
            if ($binding->bindingId === null) {
                throw new RegistryCompilationException('Normalized binding has no binding ID.');
            }

            $messages[$binding->message][] = $binding->bindingId;
            $bindings[$binding->bindingId] = $binding;
        }

        ksort($messages, SORT_STRING);
        foreach ($messages as &$bindingIds) {
            sort($bindingIds, SORT_STRING);
        }
        unset($bindingIds);
        ksort($bindings, SORT_STRING);

        $flows = $graphContext->flows ?? new FlowRegistry();

        return new MessageRegistryDefinition(
            self::SCHEMA_VERSION,
            $libraryVersion,
            (new DateTimeImmutable())->format(DATE_ATOM),
            $sourceHash,
            $flows,
            $messages,
            $bindings,
            $graphContext->aliases,
            $graphContext->messageNames,
        );
    }

    /** @param list<RegistryDiagnostic> $diagnostics */
    private function hasErrorDiagnostics(array $diagnostics): bool
    {
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->isError()) {
                return true;
            }
        }

        return false;
    }
}
