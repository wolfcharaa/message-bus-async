<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Codec;

use RuntimeException;

final class UnsupportedMessageVersion extends RuntimeException
{
    public function __construct(string $alias, int $version, int $currentVersion)
    {
        parent::__construct(sprintf(
            'Message "%s" version %d cannot be read by codec version %d.',
            $alias,
            $version,
            $currentVersion,
        ));
    }
}
