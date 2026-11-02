<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Registry;

use Fedor\MessageBus\Flow\FlowRegistry;

final readonly class MessageRegistryDefinition
{
    /**
     * @param array<class-string, list<string>> $messages
     * @param array<string, HandlerBinding> $bindings
     * @param array<string, class-string> $aliases
     * @param array<class-string, string> $messageNames
     */
    public function __construct(
        public int $schemaVersion,
        public string $libraryVersion,
        public string $generatedAt,
        public string $sourceHash,
        public FlowRegistry $flows,
        public array $messages,
        public array $bindings,
        public array $aliases,
        public array $messageNames,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schemaVersion' => $this->schemaVersion,
            'libraryVersion' => $this->libraryVersion,
            'generatedAt' => $this->generatedAt,
            'sourceHash' => $this->sourceHash,
            'flows' => $this->flows->toArray(),
            'messages' => $this->messages,
            'bindings' => array_map(
                static fn (HandlerBinding $binding): array => $binding->toArray(),
                $this->bindings,
            ),
            'aliases' => $this->aliases,
            'messageNames' => $this->messageNames,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $schemaVersion = $data['schemaVersion'] ?? null;
        if ($schemaVersion !== MessageRegistryCompiler::SCHEMA_VERSION) {
            throw new RegistryCompilationException('Unsupported message registry schema version.');
        }

        $rawBindings = self::array($data, 'bindings');
        $bindings = [];
        foreach ($rawBindings as $bindingId => $binding) {
            if (!is_string($bindingId) || !is_array($binding)) {
                throw new RegistryCompilationException('Registry bindings must map string IDs to arrays.');
            }

            /** @var array<string, mixed> $binding */
            $definition = HandlerBinding::fromArray($binding);
            if ($definition->id !== $bindingId) {
                throw new RegistryCompilationException(sprintf(
                    'Registry binding key `%s` does not match definition ID `%s`.',
                    $bindingId,
                    $definition->id,
                ));
            }
            $bindings[$bindingId] = $definition;
        }

        return new self(
            $schemaVersion,
            self::string($data, 'libraryVersion'),
            self::string($data, 'generatedAt'),
            self::string($data, 'sourceHash'),
            FlowRegistry::fromArray(self::nestedArrays($data, 'flows')),
            self::messageMap($data),
            $bindings,
            self::classMap($data, 'aliases'),
            self::stringMap($data, 'messageNames'),
        );
    }

    public function compiledRegistry(): CompiledRegistry
    {
        return new CompiledRegistry(array_values($this->bindings), $this->aliases, $this->flows);
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new RegistryCompilationException(sprintf('Registry field `%s` must be a string.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<mixed, mixed>
     */
    private static function array(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            throw new RegistryCompilationException(sprintf('Registry field `%s` must be an array.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, array<string, mixed>>
     */
    private static function nestedArrays(array $data, string $key): array
    {
        $result = [];
        foreach (self::array($data, $key) as $name => $value) {
            if (!is_string($name) || !is_array($value)) {
                throw new RegistryCompilationException(sprintf(
                    'Registry field `%s` must map strings to arrays.',
                    $key,
                ));
            }

            /** @var array<string, mixed> $value */
            $result[$name] = $value;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<class-string, list<string>>
     */
    private static function messageMap(array $data): array
    {
        $result = [];
        foreach (self::array($data, 'messages') as $message => $bindingIds) {
            if (!is_string($message) || !is_array($bindingIds) || !array_is_list($bindingIds)) {
                throw new RegistryCompilationException('Registry messages must map message classes to binding ID lists.');
            }

            $ids = [];
            foreach ($bindingIds as $bindingId) {
                if (!is_string($bindingId)) {
                    throw new RegistryCompilationException('Registry message binding IDs must be strings.');
                }
                $ids[] = $bindingId;
            }

            /** @var class-string $message */
            $result[$message] = $ids;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, class-string>
     */
    private static function classMap(array $data, string $key): array
    {
        $result = [];
        foreach (self::array($data, $key) as $name => $class) {
            if (!is_string($name) || !is_string($class)) {
                throw new RegistryCompilationException(sprintf('Registry field `%s` must map strings to classes.', $key));
            }

            /** @var class-string $class */
            $result[$name] = $class;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<class-string, string>
     */
    private static function stringMap(array $data, string $key): array
    {
        $result = [];
        foreach (self::array($data, $key) as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new RegistryCompilationException(sprintf('Registry field `%s` must map strings to strings.', $key));
            }

            /** @var class-string $name */
            $result[$name] = $value;
        }

        return $result;
    }
}
