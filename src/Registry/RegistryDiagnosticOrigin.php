<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Registry;

use ReflectionClass;
use ReflectionMethod;

final readonly class RegistryDiagnosticOrigin
{
    public function __construct(
        public RegistryDiagnosticOriginKind $kind,
        public ?string $name = null,
        public ?string $attributeClass = null,
        public ?string $configKey = null,
        public ?string $file = null,
        public ?int $line = null,
        public ?string $sourceRule = null,
    ) {
    }

    /** @param ReflectionClass<object> $reflection */
    public static function fromReflectionClass(
        ReflectionClass $reflection,
        RegistryDiagnosticOriginKind $kind = RegistryDiagnosticOriginKind::ReflectionClass,
        ?string $attributeClass = null,
        ?string $sourceRule = null,
    ): self {
        $file = $reflection->getFileName();
        $line = $reflection->getStartLine();

        return new self(
            $kind,
            $reflection->getName(),
            $attributeClass,
            null,
            is_string($file) ? $file : null,
            $line !== false ? $line : null,
            $sourceRule,
        );
    }

    public static function fromReflectionMethod(ReflectionMethod $method, ?string $sourceRule = null): self
    {
        $file = $method->getFileName();
        $line = $method->getStartLine();

        return new self(
            RegistryDiagnosticOriginKind::ReflectionMethod,
            $method->getDeclaringClass()->getName() . '::' . $method->getName(),
            file: is_string($file) ? $file : null,
            line: $line !== false ? $line : null,
            sourceRule: $sourceRule,
        );
    }

    public static function flow(string $key, ?string $sourceRule = null): self
    {
        return new self(
            RegistryDiagnosticOriginKind::Flow,
            $key,
            configKey: 'flows.' . $key,
            sourceRule: $sourceRule,
        );
    }

    public static function compiler(?string $name = null, ?string $sourceRule = null): self
    {
        return new self(RegistryDiagnosticOriginKind::Compiler, $name, sourceRule: $sourceRule);
    }
}
