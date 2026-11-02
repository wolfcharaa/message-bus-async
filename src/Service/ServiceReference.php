<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Service;

use InvalidArgumentException;

/** @template-covariant TService of object */
final readonly class ServiceReference
{
    /** @param class-string<TService> $type */
    public function __construct(
        public string $id,
        public string $type,
    ) {
        if (trim($this->id) === '') {
            throw new InvalidArgumentException('Service reference ID must be a non-empty string.');
        }

        if (!class_exists($this->type) && !interface_exists($this->type)) {
            throw new InvalidArgumentException(sprintf(
                'Service reference type "%s" must be an existing class or interface.',
                $this->type,
            ));
        }
    }

    /**
     * @template TObject of object
     * @param class-string<TObject> $type
     * @return self<TObject>
     */
    public static function forClass(string $type): self
    {
        return new self($type, $type);
    }
}
