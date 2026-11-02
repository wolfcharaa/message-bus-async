<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Registry\Compiler;

final readonly class RegistryDiagnostic
{
    public function __construct(
        public RegistryDiagnosticSeverity $severity,
        public RegistryDiagnosticCode $code,
        public string $message,
        public ?string $sourceClass = null,
        public ?string $sourceFile = null,
        public ?int $sourceLine = null,
        public ?string $attribute = null,
        public ?string $bindingId = null,
        public ?string $alias = null,
        public ?string $suggestion = null,
    ) {
    }

    public function format(): string
    {
        $location = $this->sourceClass;
        if ($this->sourceFile !== null) {
            $location = $this->sourceFile . ($this->sourceLine !== null ? ':' . $this->sourceLine : '');
        }

        $formatted = sprintf('[%s] %s: %s', strtoupper($this->severity->value), $this->code->value, $this->message);
        if ($location !== null) {
            $formatted .= sprintf(' (%s)', $location);
        }

        if ($this->suggestion !== null) {
            $formatted .= ' ' . $this->suggestion;
        }

        return $formatted;
    }
}
