<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Registry\Metadata;

use InvalidArgumentException;

final readonly class RegistryOwner
{
    public string $kind;
    public string $id;

    public function __construct(string $kind, string $id)
    {
        $this->kind = $this->normalizeSlug($kind, 'kind');
        $this->id = $this->normalizeSlug($id, 'id');
    }

    public static function core(string $id): self
    {
        return new self('core', $id);
    }

    public static function feature(string $id): self
    {
        return new self('feature', $id);
    }

    public static function app(string $id): self
    {
        return new self('app', $id);
    }

    /** @param array{kind: string, id: string} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['kind'], $data['id']);
    }

    /** @return array{kind: string, id: string} */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'id' => $this->id];
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind && $this->id === $other->id;
    }

    private function normalizeSlug(string $value, string $field): string
    {
        $value = strtolower(trim($value));
        if ($value === '' || preg_match('/^[a-z0-9][a-z0-9._-]*$/', $value) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Registry owner %s must be a non-empty lowercase ASCII slug.',
                $field,
            ));
        }

        return $value;
    }
}
