<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Registry\Compiler;

use Fedor\MessageBus\Registry\CompiledRegistry;
use Fedor\MessageBus\Registry\RegistryCompilationGraphContext;
use LogicException;

final readonly class RegistryCompilationResult
{
    /** @param list<RegistryDiagnostic> $diagnostics */
    public function __construct(
        public ?CompiledRegistry $registry,
        public array $diagnostics,
        public RegistryCompilationGraphContext $graphContext,
    ) {
        if ($this->registry !== null && $this->hasErrors()) {
            throw new LogicException('A compiled registry cannot contain error diagnostics.');
        }
    }

    public function hasErrors(): bool
    {
        foreach ($this->diagnostics as $diagnostic) {
            if ($diagnostic->severity === RegistryDiagnosticSeverity::Error) {
                return true;
            }
        }

        return false;
    }

    public function requireRegistry(): CompiledRegistry
    {
        if ($this->registry !== null && !$this->hasErrors()) {
            return $this->registry;
        }

        $errors = array_values(array_filter(
            $this->diagnostics,
            static fn (RegistryDiagnostic $diagnostic): bool => $diagnostic->severity === RegistryDiagnosticSeverity::Error,
        ));

        if ($errors === []) {
            throw new LogicException('Registry compilation did not produce a registry or error diagnostics.');
        }

        throw new RegistryCompilationFailed($errors);
    }
}
