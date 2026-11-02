<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Tests;

use DateTimeImmutable;
use Fedor\MessageBus\Queue\Postgres\PostgresQueueSchemaGenerator;
use Fedor\MessageBus\Queue\QueueJobState;
use Fedor\MessageBus\Queue\QueueJobStatus;
use Fedor\MessageBus\Queue\QueueTableDefinition;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueuePostgresContractsTest extends TestCase
{
    /**
     * @return iterable<string, array{QueueJobState, QueueJobState, bool}>
     */
    public static function transitions(): iterable
    {
        foreach (QueueJobState::cases() as $source) {
            foreach (QueueJobState::cases() as $target) {
                $allowed = match ($source) {
                    QueueJobState::Pending => \in_array($target, [
                        QueueJobState::Running,
                        QueueJobState::Cancelled,
                    ], true),
                    QueueJobState::Running => \in_array($target, [
                        QueueJobState::Pending,
                        QueueJobState::Succeeded,
                        QueueJobState::Failed,
                        QueueJobState::Cancelled,
                        QueueJobState::Interrupted,
                    ], true),
                    default => false,
                };

                yield $source->value . ' -> ' . $target->value => [$source, $target, $allowed];
            }
        }
    }

    #[DataProvider('transitions')]
    public function testItDefinesTheV6QueueStateMachine(
        QueueJobState $source,
        QueueJobState $target,
        bool $allowed,
    ): void {
        self::assertSame($allowed, $source->canTransitionTo($target));
    }

    public function testItMarksOnlyCompletedJobsAsTerminal(): void
    {
        self::assertFalse(QueueJobState::Pending->isTerminal());
        self::assertFalse(QueueJobState::Running->isTerminal());
        self::assertFalse(QueueJobState::Interrupted->isTerminal());
        self::assertTrue(QueueJobState::Succeeded->isTerminal());
        self::assertTrue(QueueJobState::Failed->isTerminal());
        self::assertTrue(QueueJobState::Cancelled->isTerminal());
    }

    public function testItGeneratesTheWorkerCompatibleQueueSchema(): void
    {
        $sql = (new PostgresQueueSchemaGenerator())->generate();

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "message_bus__queue_jobs"', $sql);
        self::assertStringContainsString('id BIGSERIAL PRIMARY KEY', $sql);
        self::assertStringContainsString("status IN ('pending', 'running', 'succeeded', 'failed', 'cancelled', 'interrupted')", $sql);
        self::assertStringContainsString('retry_policy_snapshot JSONB NOT NULL', $sql);
        self::assertStringContainsString('locked_by TEXT NULL', $sql);
        self::assertStringContainsString('heartbeat_at TIMESTAMPTZ NULL', $sql);
        self::assertStringContainsString('cancellation_requested BOOLEAN NOT NULL DEFAULT FALSE', $sql);
        self::assertStringContainsString('serialized_envelope JSONB NOT NULL', $sql);
        self::assertStringContainsString('"message_bus__queue_jobs_pending_idx"', $sql);
        self::assertStringContainsString('"message_bus__queue_jobs_running_heartbeat_idx"', $sql);
        self::assertStringContainsString('"message_bus__queue_jobs_interrupted_idx"', $sql);
    }

    public function testItUsesAnExplicitSafeTableName(): void
    {
        $sql = (new PostgresQueueSchemaGenerator())->generate(new QueueTableDefinition('async_jobs'));

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "async_jobs"', $sql);
        self::assertStringContainsString('"async_jobs_binding_id_idx"', $sql);

        $this->expectException(InvalidArgumentException::class);
        new QueueTableDefinition('public.async_jobs');
    }

    public function testStatusPreservesPollingFields(): void
    {
        $now = new DateTimeImmutable('2026-11-02T20:00:00+00:00');
        $status = new QueueJobStatus(
            '42',
            QueueJobState::Pending,
            'message-1',
            'correlation-1',
            'async',
            'binding.id',
            'postgres',
            'default',
            1,
            3,
            $now,
            null,
            null,
            $now,
            $now,
            'last error',
        );

        self::assertSame('42', $status->queueMessageId);
        self::assertSame(QueueJobState::Pending, $status->status);
        self::assertSame('correlation-1', $status->correlationId);
        self::assertSame('last error', $status->lastError);
    }
}
