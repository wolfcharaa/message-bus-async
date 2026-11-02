<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Codec;

interface MessageUpcasterInterface
{
    public function alias(): string;

    public function fromVersion(): int;

    public function upcast(JsonValue $payload): JsonValue;
}
