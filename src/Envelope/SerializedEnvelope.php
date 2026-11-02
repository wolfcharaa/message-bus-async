<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Envelope;

use InvalidArgumentException;

final readonly class SerializedEnvelope
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        public string $messageName,
        public string $payload,
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {
        if (trim($this->messageName) === '') {
            throw new InvalidArgumentException('Serialized envelope message name must not be empty.');
        }

        if (trim($this->payload) === '') {
            throw new InvalidArgumentException('Serialized envelope payload must not be empty.');
        }

        if ($this->schemaVersion < 1) {
            throw new InvalidArgumentException('Serialized envelope schema version must be positive.');
        }
    }
}
