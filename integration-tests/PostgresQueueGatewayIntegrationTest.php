<?php

declare(strict_types=1);

namespace Fedor\MessageBus\IntegrationTests;

use Amp\NullCancellation;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use DateTimeImmutable;
use Fedor\MessageBus\Envelope\SerializedEnvelope;
use Fedor\MessageBus\Queue\Postgres\PostgresQueueGateway;
use Fedor\MessageBus\Queue\Postgres\PostgresQueueSchemaGenerator;
use Fedor\MessageBus\Queue\QueueJobState;
use Fedor\MessageBus\Queue\QueueMessage;
use Fedor\MessageBus\Queue\QueueTableDefinition;
use PHPUnit\Framework\TestCase;

final class PostgresQueueGatewayIntegrationTest extends TestCase
{
    private PostgresConnectionPool $postgres;
    private QueueTableDefinition $table;
    private PostgresQueueGateway $gateway;
    private bool $databaseInitialized = false;

    protected function setUp(): void
    {
        $config = getenv('MESSAGE_BUS_TEST_POSTGRES_CONFIG');
        if (!is_string($config) || trim($config) === '') {
            self::markTestSkipped(
                'MESSAGE_BUS_TEST_POSTGRES_CONFIG must contain a libpq connection string.',
            );
        }

        $this->postgres = new PostgresConnectionPool(PostgresConfig::fromString($config));
        $this->table = new QueueTableDefinition('message_bus_test_queue_jobs_' . getmypid());
        $this->databaseInitialized = true;
        $this->postgres->query('DROP TABLE IF EXISTS "' . $this->table->tableName . '"');
        $this->applySchema((new PostgresQueueSchemaGenerator())->generate($this->table));
        $this->gateway = new PostgresQueueGateway($this->postgres, $this->table);
    }

    protected function tearDown(): void
    {
        if (!$this->databaseInitialized) {
            return;
        }

        $this->postgres->query('DROP TABLE IF EXISTS "' . $this->table->tableName . '"');
        $this->postgres->close();
    }

    public function testProducerStatusAndCancellationUseTheSharedSchema(): void
    {
        $cancellation = new NullCancellation();
        $first = $this->gateway->enqueue($this->message('message-1'), $cancellation);

        self::assertSame(QueueJobState::Pending, $first->status);
        self::assertSame('message_bus_test_queue_jobs_' . getmypid(), $first->metadata['table']);
        self::assertSame(QueueJobState::Pending, $this->gateway->get($first->queueMessageId, $cancellation)?->status);
        self::assertCount(1, $this->gateway->listByMessageId('message-1', $cancellation));
        self::assertCount(1, $this->gateway->listByCorrelationId('correlation-1', $cancellation));

        $this->gateway->cancel($first->queueMessageId, $cancellation);
        self::assertSame(QueueJobState::Cancelled, $this->gateway->get($first->queueMessageId, $cancellation)?->status);

        $second = $this->gateway->enqueue($this->message('message-2'), $cancellation);
        $this->postgres->execute(
            'UPDATE "' . $this->table->tableName . '" SET status = :running WHERE id = :id',
            ['running' => QueueJobState::Running->value, 'id' => $second->queueMessageId],
        );
        $this->gateway->requestCancellation($second->queueMessageId, $cancellation);
        $row = $this->postgres->execute(
            'SELECT cancellation_requested FROM "' . $this->table->tableName . '" WHERE id = :id',
            ['id' => $second->queueMessageId],
        )->fetchRow();

        self::assertSame(true, $row['cancellation_requested'] ?? null);

        $batch = $this->gateway->enqueueMany([
            $this->message('message-3'),
            $this->message('message-4'),
        ], $cancellation);
        self::assertCount(2, $batch->all());
    }

    private function applySchema(string $schema): void
    {
        foreach (explode(";\n", $schema) as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $this->postgres->query($statement);
            }
        }
    }

    private function message(string $messageId): QueueMessage
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
        );
    }
}
