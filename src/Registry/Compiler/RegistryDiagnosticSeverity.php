<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Registry\Compiler;

enum RegistryDiagnosticSeverity: string
{
    case Error = 'error';
    case Warning = 'warning';
}
