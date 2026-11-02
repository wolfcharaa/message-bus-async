<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Envelope;

use InvalidArgumentException;
use JsonSerializable;

final readonly class Headers implements JsonSerializable
{
    /** @var array<non-empty-string, bool|float|int|string|null> */
    private array $values;

    /** @param array<array-key, mixed> $values */
    public function __construct(array $values = [])
    {
        $validated = [];

        foreach ($values as $name => $value) {
            if (!is_string($name) || trim($name) === '') {
                throw new InvalidArgumentException('Header names must be non-empty strings.');
            }

            if (!is_bool($value) && !is_float($value) && !is_int($value) && !is_string($value) && $value !== null) {
                throw new InvalidArgumentException(sprintf(
                    'Header "%s" must be a scalar value or null.',
                    $name,
                ));
            }

            $validated[$name] = $value;
        }

        $this->values = $validated;
    }

    public static function empty(): self
    {
        return new self();
    }

    public function with(string $name, bool|float|int|string|null $value): self
    {
        return new self([...$this->values, $name => $value]);
    }

    public function merge(self $overrides): self
    {
        return new self([...$this->values, ...$overrides->values]);
    }

    public function get(string $name): bool|float|int|string|null
    {
        return $this->values[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    /** @return array<non-empty-string, bool|float|int|string|null> */
    public function all(): array
    {
        return $this->values;
    }

    /** @return array<non-empty-string, bool|float|int|string|null> */
    public function jsonSerialize(): array
    {
        return $this->values;
    }
}
