<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Codec;

use InvalidArgumentException;

final readonly class EncodedMessage
{
    public function __construct(
        public string $alias,
        public int $version,
        public JsonValue $payload,
    ) {
        if (trim($this->alias) === '') {
            throw new InvalidArgumentException('Encoded message alias must not be empty.');
        }

        if ($this->version < 1) {
            throw new InvalidArgumentException('Encoded message version must be positive.');
        }
    }
}
