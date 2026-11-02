<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Registry;

use Fedor\MessageBus\Flow\FlowRegistry;
use Fedor\MessageBus\Message\Command;
use Fedor\MessageBus\Message\Event;
use Fedor\MessageBus\Message\Query;
use InvalidArgumentException;

final readonly class CompiledRegistry
{
    /** @var array<string, HandlerBinding> */
    private array $bindings;

    /** @var array<class-string, list<HandlerBinding>> */
    private array $messageBindings;

    /** @var array<string, class-string> */
    private array $aliases;

    /** @var array<class-string, string> */
    private array $messageAliases;

    /**
     * @param list<HandlerBinding> $bindings
     * @param array<string, class-string> $aliases
     */
    public function __construct(
        array $bindings,
        array $aliases,
        public FlowRegistry $flows = new FlowRegistry(),
    ) {
        $bindingsById = [];
        $messageBindings = [];

        foreach ($bindings as $binding) {
            if (isset($bindingsById[$binding->id])) {
                throw new InvalidArgumentException(sprintf('Duplicate binding ID "%s".', $binding->id));
            }

            $bindingsById[$binding->id] = $binding;
            $messageBindings[$binding->message][] = $binding;
        }

        ksort($bindingsById, SORT_STRING);
        ksort($messageBindings, SORT_STRING);
        foreach ($messageBindings as &$messageBindingList) {
            usort($messageBindingList, self::compareBindings(...));
        }
        unset($messageBindingList);

        $messageAliases = [];
        foreach ($aliases as $alias => $message) {
            if (trim($alias) === '') {
                throw new InvalidArgumentException('Every message alias must be a non-empty string.');
            }

            if (!self::isMessageClass($message)) {
                throw new InvalidArgumentException(sprintf(
                    'Alias "%s" must reference an existing query, command, or event class.',
                    $alias,
                ));
            }

            if (isset($messageAliases[$message]) && $messageAliases[$message] !== $alias) {
                throw new InvalidArgumentException(sprintf(
                    'Message class "%s" has more than one alias.',
                    $message,
                ));
            }

            $messageAliases[$message] = $alias;
        }

        ksort($aliases, SORT_STRING);
        ksort($messageAliases, SORT_STRING);

        $this->bindings = $bindingsById;
        $this->messageBindings = $messageBindings;
        $this->aliases = $aliases;
        $this->messageAliases = $messageAliases;
    }

    /** @return list<HandlerBinding> */
    public function bindingsForMessage(object|string $message): array
    {
        $messageClass = is_object($message) ? $message::class : $message;

        return $this->messageBindings[$messageClass] ?? [];
    }

    public function binding(string $bindingId): HandlerBinding
    {
        return $this->bindings[$bindingId] ?? throw new BindingNotFound(sprintf(
            'Message binding "%s" was not found.',
            $bindingId,
        ));
    }

    public function messageAlias(object|string $message): ?string
    {
        $messageClass = is_object($message) ? $message::class : $message;

        return $this->messageAliases[$messageClass] ?? null;
    }

    /** @return class-string|null */
    public function messageClass(string $alias): ?string
    {
        return $this->aliases[$alias] ?? null;
    }

    /** @return list<HandlerBinding> */
    public function bindings(): array
    {
        return array_values($this->bindings);
    }

    /** @return array<string, class-string> */
    public function aliases(): array
    {
        return $this->aliases;
    }

    /**
     * @return array{
     *     aliases: array<string, class-string>,
     *     bindings: array<string, array<string, mixed>>,
     *     flows: array<string, array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        $bindings = [];
        foreach ($this->bindings as $bindingId => $binding) {
            $bindings[$bindingId] = $binding->toArray();
        }

        return ['aliases' => $this->aliases, 'bindings' => $bindings, 'flows' => $this->flows->toArray()];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $aliasData = $data['aliases'] ?? null;
        if (!is_array($aliasData)) {
            throw new InvalidRegistryArtifact('Registry field "aliases" must be a map.');
        }

        $aliases = [];
        foreach ($aliasData as $alias => $message) {
            if (!is_string($alias) || !is_string($message)) {
                throw new InvalidRegistryArtifact('Registry aliases must map string names to message classes.');
            }

            if (!class_exists($message) && !interface_exists($message)) {
                throw new InvalidRegistryArtifact(sprintf('Aliased message class "%s" does not exist.', $message));
            }

            /** @var class-string $message */
            $aliases[$alias] = $message;
        }

        $bindingData = $data['bindings'] ?? null;
        if (!is_array($bindingData)) {
            throw new InvalidRegistryArtifact('Registry field "bindings" must be a map.');
        }

        $bindings = [];
        foreach ($bindingData as $bindingId => $definition) {
            if (!is_string($bindingId) || !is_array($definition)) {
                throw new InvalidRegistryArtifact('Registry bindings must map string IDs to binding definitions.');
            }

            $binding = HandlerBinding::fromArray(self::stringMap(
                $definition,
                sprintf('Registry binding "%s"', $bindingId),
            ));
            if ($binding->id !== $bindingId) {
                throw new InvalidRegistryArtifact(sprintf(
                    'Registry binding key "%s" does not match definition ID "%s".',
                    $bindingId,
                    $binding->id,
                ));
            }

            $bindings[] = $binding;
        }

        try {
            $flowData = $data['flows'] ?? null;
            if (!is_array($flowData)) {
                throw new InvalidRegistryArtifact('Registry field "flows" must be a map.');
            }

            return new self($bindings, $aliases, FlowRegistry::fromArray(self::nestedStringMaps($flowData, 'flows')));
        } catch (InvalidArgumentException $exception) {
            throw new InvalidRegistryArtifact($exception->getMessage(), previous: $exception);
        }
    }

    private static function compareBindings(HandlerBinding $left, HandlerBinding $right): int
    {
        return $right->priority <=> $left->priority ?: $left->id <=> $right->id;
    }

    private static function isMessageClass(string $class): bool
    {
        return is_a($class, Query::class, true)
            || is_a($class, Command::class, true)
            || is_a($class, Event::class, true);
    }

    /**
     * @param array<mixed, mixed> $data
     * @return array<string, mixed>
     */
    private static function stringMap(array $data, string $field): array
    {
        $normalized = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidRegistryArtifact(sprintf('%s must use string keys.', $field));
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<mixed, mixed> $data
     * @return array<string, array<string, mixed>>
     */
    private static function nestedStringMaps(array $data, string $field): array
    {
        $normalized = [];
        foreach ($data as $key => $value) {
            if (!is_string($key) || !is_array($value)) {
                throw new InvalidRegistryArtifact(sprintf('Registry field "%s" must map strings to arrays.', $field));
            }

            $normalized[$key] = self::stringMap($value, $field . '.' . $key);
        }

        return $normalized;
    }
}
