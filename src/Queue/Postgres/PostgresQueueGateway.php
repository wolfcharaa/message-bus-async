<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Queue\Postgres;

use Amp\Cancellation;
use Amp\Postgres\PostgresExecutor;
use Amp\Postgres\PostgresLink;
use Amp\Sql\SqlResult;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Fedor\MessageBus\Envelope\SerializedEnvelope;
use Fedor\MessageBus\Queue\BatchQueueProviderInterface;
use Fedor\MessageBus\Queue\QueueBatchEnqueueResult;
use Fedor\MessageBus\Queue\QueueEnqueueResult;
use Fedor\MessageBus\Queue\QueueJobControlInterface;
use Fedor\MessageBus\Queue\QueueJobState;
use Fedor\MessageBus\Queue\QueueJobStatus;
use Fedor\MessageBus\Queue\QueueMessage;
use Fedor\MessageBus\Queue\QueueStatusRepositoryInterface;
use Fedor\MessageBus\Queue\QueueTableDefinition;
use JsonException;
use RuntimeException;

final class PostgresQueueGateway implements
    BatchQueueProviderInterface,
    QueueStatusRepositoryInterface,
    QueueJobControlInterface
{
    private readonly string $table;
    private readonly string $tableName;

    public function __construct(
        private readonly PostgresLink $postgres,
        QueueTableDefinition $definition = new QueueTableDefinition(),
    ) {
        $this->tableName = $definition->tableName;
        $this->table = $this->quoteIdentifier($definition->tableName);
    }

    #[\Override]
    public function enqueue(QueueMessage $message, Cancellation $cancellation): QueueEnqueueResult
    {
        $cancellation->throwIfRequested();

        return $this->insert($this->postgres, $message);
    }

    #[\Override]
    public function enqueueMany(iterable $messages, Cancellation $cancellation): QueueBatchEnqueueResult
    {
        $cancellation->throwIfRequested();
        $transaction = $this->postgres->beginTransaction();

        try {
            $results = [];
            foreach ($messages as $message) {
                $cancellation->throwIfRequested();
                $results[] = $this->insert($transaction, $message);
            }
            $cancellation->throwIfRequested();
            $transaction->commit();

            return new QueueBatchEnqueueResult(...$results);
        } catch (\Throwable $error) {
            if ($transaction->isActive()) {
                $transaction->rollback();
            }

            throw $error;
        }
    }

    #[\Override]
    public function get(string $queueMessageId, Cancellation $cancellation): ?QueueJobStatus
    {
        $cancellation->throwIfRequested();
        $result = $this->postgres->execute(
            $this->statusSelect() . ' WHERE id = :id',
            ['id' => $queueMessageId],
        );
        $row = $result->fetchRow();

        return $row === null ? null : $this->status($row);
    }

    #[\Override]
    public function listByMessageId(string $messageId, Cancellation $cancellation): array
    {
        return $this->listBy('message_id', $messageId, $cancellation);
    }

    #[\Override]
    public function listByCorrelationId(string $correlationId, Cancellation $cancellation): array
    {
        return $this->listBy('correlation_id', $correlationId, $cancellation);
    }

    #[\Override]
    public function cancel(string $queueMessageId, Cancellation $cancellation): void
    {
        $cancellation->throwIfRequested();
        $this->postgres->execute(
            'UPDATE ' . $this->table . '
             SET status = :cancelled, finished_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = :pending',
            [
                'cancelled' => QueueJobState::Cancelled->value,
                'id' => $queueMessageId,
                'pending' => QueueJobState::Pending->value,
            ],
        );
    }

    #[\Override]
    public function requestCancellation(string $queueMessageId, Cancellation $cancellation): void
    {
        $cancellation->throwIfRequested();
        $this->postgres->execute(
            'UPDATE ' . $this->table . '
             SET cancellation_requested = TRUE, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = :running',
            [
                'id' => $queueMessageId,
                'running' => QueueJobState::Running->value,
            ],
        );
    }

    private function insert(PostgresExecutor $executor, QueueMessage $message): QueueEnqueueResult
    {
        $result = $executor->execute(
            'INSERT INTO ' . $this->table . ' (
                transport, queue, status, message_name, message_id, correlation_id, flow, binding_id,
                priority, attempts, max_attempts, retry_policy_key, retry_policy_snapshot,
                available_at, serialized_envelope, created_at, updated_at
             ) VALUES (
                :transport, :queue, :status, :message_name, :message_id, :correlation_id, :flow, :binding_id,
                :priority, 0, :max_attempts, :retry_policy_key, :retry_policy_snapshot,
                :available_at, :serialized_envelope, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             ) RETURNING id, created_at',
            [
                'transport' => $message->transport,
                'queue' => $message->queue,
                'status' => QueueJobState::Pending->value,
                'message_name' => $message->envelope->messageName,
                'message_id' => $message->messageId,
                'correlation_id' => $message->correlationId,
                'flow' => $message->flow,
                'binding_id' => $message->bindingId,
                'priority' => $message->priority,
                'max_attempts' => $message->retryPolicySnapshot->maxAttempts,
                'retry_policy_key' => $message->retryPolicyKey,
                'retry_policy_snapshot' => $this->json($message->retryPolicySnapshot->toArray()),
                'available_at' => $message->availableAt->format(DATE_ATOM),
                'serialized_envelope' => $this->serializedEnvelope($message->envelope),
            ],
        );
        $row = $result->fetchRow();
        if ($row === null) {
            throw new RuntimeException('PostgreSQL queue insert did not return the job identity.');
        }

        return new QueueEnqueueResult(
            $this->identifier($row, 'id'),
            status: QueueJobState::Pending,
            createdAt: $this->date($this->field($row, 'created_at')),
            metadata: ['table' => $this->tableName],
        );
    }

    /** @return list<QueueJobStatus> */
    private function listBy(string $column, string $value, Cancellation $cancellation): array
    {
        $cancellation->throwIfRequested();
        $result = $this->postgres->execute(
            $this->statusSelect() . ' WHERE ' . $column . ' = :value ORDER BY id ASC',
            ['value' => $value],
        );
        $statuses = [];
        foreach ($result as $row) {
            $statuses[] = $this->status($row);
        }

        return $statuses;
    }

    private function statusSelect(): string
    {
        return 'SELECT id, status, message_id, correlation_id, flow, binding_id, transport, queue,
                       attempts, max_attempts, available_at, started_at, finished_at,
                       created_at, updated_at, last_error
                FROM ' . $this->table;
    }

    /** @param array<string, mixed> $row */
    private function status(array $row): QueueJobStatus
    {
        return new QueueJobStatus(
            $this->identifier($row, 'id'),
            QueueJobState::from($this->string($row, 'status')),
            $this->string($row, 'message_id'),
            $this->string($row, 'correlation_id'),
            $this->string($row, 'flow'),
            $this->string($row, 'binding_id'),
            $this->string($row, 'transport'),
            $this->string($row, 'queue'),
            $this->integer($row, 'attempts'),
            $this->integer($row, 'max_attempts'),
            $this->date($this->field($row, 'available_at')),
            $this->nullableDate($this->field($row, 'started_at')),
            $this->nullableDate($this->field($row, 'finished_at')),
            $this->date($this->field($row, 'created_at')),
            $this->date($this->field($row, 'updated_at')),
            $this->nullableString($row, 'last_error'),
        );
    }

    /** @param array<string, mixed> $row */
    private function field(array $row, string $field): mixed
    {
        if (!\array_key_exists($field, $row)) {
            throw new RuntimeException(\sprintf('PostgreSQL queue row is missing field `%s`.', $field));
        }

        return $row[$field];
    }

    /** @param array<string, mixed> $row */
    private function identifier(array $row, string $field): string
    {
        $value = $this->field($row, $field);
        if (!\is_string($value) && !\is_int($value)) {
            throw new RuntimeException(\sprintf('PostgreSQL queue field `%s` must be an identifier.', $field));
        }

        return (string) $value;
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $field): string
    {
        $value = $this->field($row, $field);
        if (!\is_string($value)) {
            throw new RuntimeException(\sprintf('PostgreSQL queue field `%s` must be a string.', $field));
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function nullableString(array $row, string $field): ?string
    {
        $value = $this->field($row, $field);
        if ($value !== null && !\is_string($value)) {
            throw new RuntimeException(\sprintf('PostgreSQL queue field `%s` must be a nullable string.', $field));
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $field): int
    {
        $value = $this->field($row, $field);
        if (!\is_int($value)) {
            throw new RuntimeException(\sprintf('PostgreSQL queue field `%s` must be an integer.', $field));
        }

        return $value;
    }

    private function serializedEnvelope(SerializedEnvelope $envelope): string
    {
        return $this->json([
            'messageName' => $envelope->messageName,
            'schemaVersion' => $envelope->schemaVersion,
            'payload' => $envelope->payload,
        ]);
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        try {
            return \json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $error) {
            throw new RuntimeException('PostgreSQL queue payload cannot be encoded as JSON.', previous: $error);
        }
    }

    private function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (!\is_string($value)) {
            throw new RuntimeException('PostgreSQL queue timestamp has an invalid type.');
        }

        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function nullableDate(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : $this->date($value);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . \str_replace('"', '""', $identifier) . '"';
    }
}
