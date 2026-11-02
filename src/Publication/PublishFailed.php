<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Publication;

use RuntimeException;
use Throwable;

final class PublishFailed extends RuntimeException
{
    public function __construct(
        private readonly PublishResult $result,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Message publish failed for %d binding(s).', count($result->failures())),
            previous: $previous,
        );
    }

    public function result(): PublishResult
    {
        return $this->result;
    }
}
