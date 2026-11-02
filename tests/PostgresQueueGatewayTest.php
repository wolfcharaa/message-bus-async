<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Tests;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\NullCancellation;
use Amp\Postgres\PostgresLink;
use Amp\Postgres\PostgresResult;
use Amp\Postgres\PostgresTransaction;
use DateTimeImmutable;
use Fedor\MessageBus\Envelope\SerializedEnvelope;
use Fedor\MessageBus\Queue\Postgres\PostgresQueueGateway;
use Fedor\MessageBus\Queue\QueueJobState;
use Fedor\MessageBus\Queue\QueueMessage;
use Fedor\MessageBus\Queue\RetryPolicySnapshot;
use PHPUnit\Framework\TestCase;

final class PostgresQueueGatewayTest extends TestCase
{
    public function testItEnqueuesACompleteDurableRecord(): void
    {
        $result = $this->createMock(PostgresResult::class);
        $result->expects(self::once())
            ->method('fetchRow')
            ->willReturn(['id' => 42, 'created_at' => '2026-11-02T20:00:00+00:00']);
        $postgres = $this->createMock(PostgresLink::class);
        $postgres->expects(self::once())
            ->method('execute')
            ->with(
                self::callback(static fn (string $sql): bool => str_contains(
                    $sql,
                    'INSERT INTO "message_bus__queue_jobs"',
                )),
                self::callback(function (array $params): bool {
                    self::assertSame('test.event', $params['message_name']);
                    self::assertSame('message-1', $params['message_id']);
                    self::assertSame('binding.one', $params['binding_id']);
                    self::assertSame(3, $params['max_attempts']);
                    $serializedEnvelope = $params['serialized_envelope'] ?? null;
                    if (!is_string($serializedEnvelope)) {
                        self::fail('Serialized envelope SQL parameter must be a string.');
                    }
                    self::assertJsonStringEqualsJsonString(
                        '{"messageName":"test.event","schemaVersion":1,"payload":"{\\"value\\":1}"}',
                        $serializedEnvelope,
                    );

                    return true;
                }),
            )
            ->willReturn($result);

        $enqueue = (new PostgresQueueGateway($postgres))->enqueue(
            self::message(),
            new NullCancellation(),
        );

        self::assertSame('42', $enqueue->queueMessageId);
        self::assertSame(QueueJobState::Pending, $enqueue->status);
        self::assertSame('2026-11-02T20:00:00+00:00', $enqueue->createdAt?->format(DATE_ATOM));
        self::assertSame(['table' => 'message_bus__queue_jobs'], $enqueue->metadata);
    }

    public function testItReadsTheV6PollingStatusContract(): void
    {
        $result = $this->createMock(PostgresResult::class);
        $result->expects(self::once())->method('fetchRow')->willReturn([
            'id' => 42,
            'status' => 'running',
            'message_id' => 'message-1',
            'correlation_id' => 'correlation-1',
            'flow' => 'async',
            'binding_id' => 'binding.one',
            'transport' => 'postgres',
            'queue' => 'default',
            'attempts' => 2,
            'max_attempts' => 3,
            'available_at' => '2026-11-02T19:59:00+00:00',
            'started_at' => '2026-11-02T20:00:00+00:00',
            'finished_at' => null,
            'created_at' => '2026-11-02T19:58:00+00:00',
            'updated_at' => '2026-11-02T20:00:00+00:00',
            'last_error' => 'previous failure',
        ]);
        $postgres = $this->createMock(PostgresLink::class);
        $postgres->expects(self::once())
            ->method('execute')
            ->with(
                self::callback(static fn (string $sql): bool => str_contains($sql, 'WHERE id = :id')),
                ['id' => '42'],
            )
            ->willReturn($result);

        $status = (new PostgresQueueGateway($postgres))->get('42', new NullCancellation());

        self::assertNotNull($status);
        self::assertSame('42', $status->queueMessageId);
        self::assertSame(QueueJobState::Running, $status->status);
        self::assertSame(2, $status->attempts);
        self::assertSame('previous failure', $status->lastError);
    }

    public function testItPreservesAtomicBatchEnqueue(): void
    {
        $firstResult = $this->databaseResult('41');
        $secondResult = $this->databaseResult('42');
        $transaction = $this->createMock(PostgresTransaction::class);
        $transaction->expects(self::exactly(2))
            ->method('execute')
            ->willReturnOnConsecutiveCalls($firstResult, $secondResult);
        $transaction->expects(self::once())->method('commit');
        $transaction->expects(self::never())->method('rollback');
        $postgres = $this->createMock(PostgresLink::class);
        $postgres->expects(self::once())->method('beginTransaction')->willReturn($transaction);

        $result = (new PostgresQueueGateway($postgres))->enqueueMany(
            [self::message(), self::message('message-2')],
            new NullCancellation(),
        );

        self::assertSame(['41', '42'], array_map(
            static fn ($enqueue): string => $enqueue->queueMessageId,
            $result->all(),
        ));
    }

    public function testCancellationRollsBackAnOpenBatchBeforeTheNextWrite(): void
    {
        $deferred = new DeferredCancellation();
        $transaction = $this->createMock(PostgresTransaction::class);
        $transaction->expects(self::once())
            ->method('execute')
            ->willReturnCallback(function () use ($deferred): PostgresResult {
                $deferred->cancel();

                return $this->databaseResult('41');
            });
        $transaction->expects(self::once())->method('isActive')->willReturn(true);
        $transaction->expects(self::once())->method('rollback');
        $transaction->expects(self::never())->method('commit');
        $postgres = $this->createMock(PostgresLink::class);
        $postgres->expects(self::once())->method('beginTransaction')->willReturn($transaction);

        try {
            (new PostgresQueueGateway($postgres))->enqueueMany(
                [self::message(), self::message('message-2')],
                $deferred->getCancellation(),
            );
            self::fail('Cancelled batch must fail.');
        } catch (CancelledException) {
        }
    }

    public function testItUsesStateGuardedCancellationUpdates(): void
    {
        $result = $this->createStub(PostgresResult::class);
        $postgres = $this->createMock(PostgresLink::class);
        $postgres->expects(self::exactly(2))
            ->method('execute')
            ->willReturnCallback(static function (string $sql, array $params) use ($result): PostgresResult {
                if (str_contains($sql, 'cancellation_requested = TRUE')) {
                    self::assertSame(['id' => '42', 'running' => 'running'], $params);

                    return $result;
                }

                self::assertStringContainsString('finished_at = CURRENT_TIMESTAMP', $sql);
                self::assertSame(
                    ['cancelled' => 'cancelled', 'id' => '42', 'pending' => 'pending'],
                    $params,
                );

                return $result;
            });
        $gateway = new PostgresQueueGateway($postgres);

        $gateway->cancel('42', new NullCancellation());
        $gateway->requestCancellation('42', new NullCancellation());
    }

    private function databaseResult(string $id): PostgresResult
    {
        $result = $this->createStub(PostgresResult::class);
        $result->method('fetchRow')->willReturn([
            'id' => $id,
            'created_at' => '2026-11-02T20:00:00+00:00',
        ]);

        return $result;
    }

    private static function message(string $messageId = 'message-1'): QueueMessage
    {
        return new QueueMessage(
            'postgres',
            'default',
            new SerializedEnvelope('test.event', '{"value":1}'),
            $messageId,
            'correlation-1',
            'async',
            'binding.one',
            new DateTimeImmutable('2026-11-02T20:00:00+00:00'),
            5,
            'default',
            new RetryPolicySnapshot(),
        );
    }
}
