<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Queue;

enum QueueJobState: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Interrupted = 'interrupted';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Cancelled => true,
            self::Pending, self::Running, self::Interrupted => false,
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Pending => $target === self::Running || $target === self::Cancelled,
            self::Running => \in_array($target, [
                self::Pending,
                self::Succeeded,
                self::Failed,
                self::Cancelled,
                self::Interrupted,
            ], true),
            self::Succeeded, self::Failed, self::Cancelled, self::Interrupted => false,
        };
    }
}
