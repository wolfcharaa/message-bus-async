<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Registry;

final readonly class CompiledMessageRegistry
{
    private CompiledRegistry $compiled;

    public function __construct(private MessageRegistryDefinition $definition)
    {
        $this->compiled = $definition->compiledRegistry();
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(MessageRegistryDefinition::fromArray($data));
    }

    public static function fromFile(string $file): self
    {
        $data = require $file;
        if (!is_array($data)) {
            throw new RegistryCompilationException('Compiled message registry file must return array.');
        }

        /** @var array<string, mixed> $data */
        return self::fromArray($data);
    }

    public function definition(): MessageRegistryDefinition
    {
        return $this->definition;
    }

    public function compiled(): CompiledRegistry
    {
        return $this->compiled;
    }

    /** @return list<HandlerBinding> */
    public function bindingsForMessage(object|string $message): array
    {
        return $this->compiled->bindingsForMessage($message);
    }

    public function binding(string $bindingId): HandlerBinding
    {
        return $this->compiled->binding($bindingId);
    }

    public function messageName(object|string $message): ?string
    {
        return $this->compiled->messageAlias($message);
    }

    /** @return class-string|null */
    public function messageClassForName(string $messageName): ?string
    {
        return $this->compiled->messageClass($messageName);
    }

    public function nameOf(object|string $message): string
    {
        $messageClass = is_object($message) ? $message::class : $message;

        return $this->messageName($messageClass) ?? $messageClass;
    }

    /** @return class-string */
    public function classOf(string $name): string
    {
        $class = $this->messageClassForName($name);
        if ($class !== null) {
            return $class;
        }

        if (class_exists($name)) {
            /** @var class-string $name */
            return $name;
        }

        throw new BindingNotFound(sprintf('Message class for name `%s` was not found.', $name));
    }
}
