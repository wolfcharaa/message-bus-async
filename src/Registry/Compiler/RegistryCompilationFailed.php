<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Registry\Compiler;

use RuntimeException;

final class RegistryCompilationFailed extends RuntimeException
{
    /**
     * @param non-empty-list<RegistryDiagnostic> $diagnostics
     */
    public function __construct(public readonly array $diagnostics)
    {
        parent::__construct(sprintf(
            "Registry compilation failed with %d diagnostic(s):\n%s",
            count($this->diagnostics),
            implode("\n", array_map(
                static fn (RegistryDiagnostic $diagnostic): string => $diagnostic->format(),
                $this->diagnostics,
            )),
        ));
    }
}
