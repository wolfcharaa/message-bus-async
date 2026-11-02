<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Identity;

final class RandomMessageIdGenerator implements MessageIdGeneratorInterface
{
    #[\Override]
    public function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
