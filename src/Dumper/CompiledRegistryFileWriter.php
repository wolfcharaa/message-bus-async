<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Dumper;

use Fedor\MessageBus\Registry\MessageRegistryDefinition;
use RuntimeException;

final readonly class CompiledRegistryFileWriter
{
    public function __construct(
        private CompiledRegistryDumperInterface $dumper = new SymfonyVarExporterRegistryDumper(),
    ) {
    }

    public function write(MessageRegistryDefinition $definition, string $targetFile): string
    {
        $directory = dirname($targetFile);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create compiled registry directory `%s`.', $directory));
        }

        $temporaryFile = tempnam($directory, basename($targetFile) . '.tmp.');
        if ($temporaryFile === false) {
            throw new RuntimeException(sprintf('Unable to create temporary compiled registry file in `%s`.', $directory));
        }

        try {
            if (file_put_contents($temporaryFile, $this->dumper->dump($definition), LOCK_EX) === false) {
                throw new RuntimeException(sprintf('Unable to write temporary compiled registry file `%s`.', $temporaryFile));
            }

            if (!rename($temporaryFile, $targetFile)) {
                throw new RuntimeException(sprintf('Unable to move compiled registry file to `%s`.', $targetFile));
            }
        } finally {
            if (is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }

        return $targetFile;
    }
}
