<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Identity;

interface MessageIdGeneratorInterface
{
    public function generate(): string;
}
