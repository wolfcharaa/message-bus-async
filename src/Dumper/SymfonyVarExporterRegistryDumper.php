<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Dumper;

use Fedor\MessageBus\Registry\MessageRegistryDefinition;
use Symfony\Component\VarExporter\VarExporter;

final class SymfonyVarExporterRegistryDumper implements CompiledRegistryDumperInterface
{
    public function dump(MessageRegistryDefinition $definition): string
    {
        return "<?php\n\nreturn " . VarExporter::export($definition->toArray()) . ";\n";
    }
}
