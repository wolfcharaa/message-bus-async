<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Queue;

use InvalidArgumentException;

final readonly class QueueTableDefinition
{
    public function __construct(
        public string $tableName = 'message_bus__queue_jobs',
    ) {
        if (\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $this->tableName) !== 1) {
            throw new InvalidArgumentException('Queue table name must be a PostgreSQL identifier without a schema prefix.');
        }
    }
}
