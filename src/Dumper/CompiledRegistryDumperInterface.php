<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Dumper;

use Fedor\MessageBus\Registry\MessageRegistryDefinition;

interface CompiledRegistryDumperInterface
{
    public function dump(MessageRegistryDefinition $definition): string;
}
