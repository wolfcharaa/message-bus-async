<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Registry;

enum RegistryDiagnosticSeverity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';
}
