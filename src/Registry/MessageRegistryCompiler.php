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
        $diagnostics = array_map($this->translateDiagnostic(...), $compiled->diagnostics);

        if ($compiled->registry === null || $this->hasErrorDiagnostics($diagnostics)) {
            return new RegistryCompilationResult(
                null,
                $diagnostics,
                new RegistryCompilationGraphContext(
                    $this->failureStage($compiled->diagnostics),
                    flows: $flows,
                ),
            );
        }

        $registry = $compiled->registry;
        $bindings = $registry->bindings();
        $aliases = $registry->aliases();
        $messageNames = $this->messageNames($aliases);
        $graphContext = new RegistryCompilationGraphContext(
            RegistryCompilationStage::CoreValidated,
            $bindings,
            $aliases,
            $messageNames,
            $flows,
        );

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

        $definition = $this->definition($registry, $libraryVersion, $sourceHash);

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

    private function translateDiagnostic(InternalRegistryDiagnostic $diagnostic): RegistryDiagnostic
    {
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
            new RegistryDiagnosticTarget(
                bindingId: $diagnostic->bindingId,
                handlerClass: $diagnostic->sourceClass,
                alias: $diagnostic->alias,
            ),
            $diagnostic->suggestion,
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

    /** @param list<InternalRegistryDiagnostic> $diagnostics */
    private function failureStage(array $diagnostics): RegistryCompilationStage
    {
        foreach ($diagnostics as $diagnostic) {
            if (in_array($diagnostic->code, [
                InternalRegistryDiagnosticCode::ClassMissing,
                InternalRegistryDiagnosticCode::ClassDuplicate,
                InternalRegistryDiagnosticCode::MessageAliasInvalidTarget,
                InternalRegistryDiagnosticCode::MessageAliasMultiple,
                InternalRegistryDiagnosticCode::MessageAliasEmpty,
                InternalRegistryDiagnosticCode::MessageAliasDuplicate,
                InternalRegistryDiagnosticCode::HandlerClassNotInstantiable,
                InternalRegistryDiagnosticCode::HandlerAttributeInvalid,
                InternalRegistryDiagnosticCode::HandlerAttributeUnsupported,
            ], true)) {
                return RegistryCompilationStage::HandlersDiscovered;
            }
        }

        foreach ($diagnostics as $diagnostic) {
            if (in_array($diagnostic->code, [
                InternalRegistryDiagnosticCode::BindingIdMissing,
                InternalRegistryDiagnosticCode::FlowMissing,
                InternalRegistryDiagnosticCode::MessageAliasRequired,
                InternalRegistryDiagnosticCode::QueryHandlerCount,
                InternalRegistryDiagnosticCode::CommandPrimaryMissing,
                InternalRegistryDiagnosticCode::CommandPrimaryDuplicate,
            ], true)) {
                return RegistryCompilationStage::BindingsNormalized;
            }
        }

        return RegistryCompilationStage::CoreValidated;
    }

    private function definition(
        CompiledRegistry $registry,
        string $libraryVersion,
        string $sourceHash,
    ): MessageRegistryDefinition {
        $messages = [];
        $bindings = [];
        foreach ($registry->bindings() as $binding) {
            $messages[$binding->message][] = $binding->id;
            $bindings[$binding->id] = $binding;
        }

        ksort($messages, SORT_STRING);
        foreach ($messages as &$bindingIds) {
            sort($bindingIds, SORT_STRING);
        }
        unset($bindingIds);
        ksort($bindings, SORT_STRING);

        $aliases = $registry->aliases();

        return new MessageRegistryDefinition(
            self::SCHEMA_VERSION,
            $libraryVersion,
            (new DateTimeImmutable())->format(DATE_ATOM),
            $sourceHash,
            $registry->flows,
            $messages,
            $bindings,
            $aliases,
            $this->messageNames($aliases),
        );
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
