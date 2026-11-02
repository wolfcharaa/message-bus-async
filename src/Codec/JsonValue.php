<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Codec;

use JsonSerializable;

final readonly class JsonValue implements JsonSerializable
{
    private function __construct(private mixed $value)
    {
    }

    public static function from(mixed $value): self
    {
        self::assertValid($value);

        return new self($value);
    }

    public function value(): mixed
    {
        return $this->value;
    }

    public function jsonSerialize(): mixed
    {
        return $this->value;
    }

    private static function assertValid(mixed $value, string $path = '$'): void
    {
        if (
            $value === null
            || is_bool($value)
            || is_int($value)
            || is_string($value)
        ) {
            return;
        }

        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidMessagePayload(sprintf('JSON payload at "%s" contains a non-finite float.', $path));
            }

            return;
        }

        if (!is_array($value)) {
            throw new InvalidMessagePayload(sprintf(
                'JSON payload at "%s" must contain only scalar values, arrays, or null; %s given.',
                $path,
                get_debug_type($value),
            ));
        }

        $list = array_is_list($value);
        foreach ($value as $key => $item) {
            if (!$list && (!is_string($key) || trim($key) === '')) {
                throw new InvalidMessagePayload(sprintf(
                    'JSON object at "%s" must use non-empty string keys.',
                    $path,
                ));
            }

            self::assertValid($item, $path . '[' . (is_int($key) ? $key : json_encode($key, JSON_THROW_ON_ERROR)) . ']');
        }
    }
}
