<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Flow;

use BackedEnum;

final readonly class TransportDefinition
{
    public function __construct(
        public string $transport,
        public string $queue,
    ) {
    }

    public static function from(string|BackedEnum $transport, string|BackedEnum $queue): self
    {
        return new self(self::normalize($transport), self::normalize($queue));
    }

    /** @return array{transport: string, queue: string} */
    public function toArray(): array
    {
        return ['transport' => $this->transport, 'queue' => $this->queue];
    }

    /** @param array{transport: string, queue: string}|null $data */
    public static function fromArray(?array $data): ?self
    {
        return $data === null ? null : new self($data['transport'], $data['queue']);
    }

    private static function normalize(string|BackedEnum $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : $value;
    }
}
