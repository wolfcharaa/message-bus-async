<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Envelope;

use Fedor\MessageBus\Codec\JsonValue;
use Fedor\MessageBus\Codec\MessageCodecRegistry;
use JsonException;

final readonly class JsonEnvelopeSerializer implements EnvelopeSerializerInterface
{
    public function __construct(private MessageCodecRegistry $codecs)
    {
    }

    public function serialize(Envelope $envelope): SerializedEnvelope
    {
        $message = $this->codecs->encode($envelope->message);
        $payload = [
            'message' => [
                'alias' => $message->alias,
                'version' => $message->version,
                'payload' => $message->payload,
            ],
            'metadata' => [
                'messageId' => $envelope->messageId,
                'correlationId' => $envelope->correlationId,
                'causationId' => $envelope->causationId,
                'headers' => $envelope->headers->all(),
            ],
        ];

        try {
            return new SerializedEnvelope(
                $message->alias,
                json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
            );
        } catch (JsonException $exception) {
            throw new InvalidSerializedEnvelope('Envelope cannot be encoded as JSON.', previous: $exception);
        }
    }

    public function deserialize(SerializedEnvelope $envelope): Envelope
    {
        if ($envelope->schemaVersion !== SerializedEnvelope::SCHEMA_VERSION) {
            throw new InvalidSerializedEnvelope(sprintf(
                'Envelope schema version %d is not supported; expected %d.',
                $envelope->schemaVersion,
                SerializedEnvelope::SCHEMA_VERSION,
            ));
        }

        try {
            $data = json_decode($envelope->payload, true, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $exception) {
            throw new InvalidSerializedEnvelope('Envelope contains invalid JSON.', previous: $exception);
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidSerializedEnvelope('Envelope JSON root must be an object.');
        }

        $message = $data['message'] ?? null;
        $metadata = $data['metadata'] ?? null;
        if (!is_array($message) || array_is_list($message)) {
            throw new InvalidSerializedEnvelope('Envelope message must be an object.');
        }
        if (!is_array($metadata) || array_is_list($metadata)) {
            throw new InvalidSerializedEnvelope('Envelope metadata must be an object.');
        }

        $alias = $message['alias'] ?? null;
        $version = $message['version'] ?? null;
        if (!is_string($alias) || trim($alias) === '' || !is_int($version) || !array_key_exists('payload', $message)) {
            throw new InvalidSerializedEnvelope('Envelope message alias, version, and payload are required.');
        }
        if ($alias !== $envelope->messageName) {
            throw new InvalidSerializedEnvelope('Envelope message name does not match its payload alias.');
        }
        $payload = JsonValue::from($message['payload']);

        $messageId = $metadata['messageId'] ?? null;
        $correlationId = $metadata['correlationId'] ?? null;
        $causationId = $metadata['causationId'] ?? null;
        $headers = $metadata['headers'] ?? null;
        if (
            !is_string($messageId)
            || !is_string($correlationId)
            || ($causationId !== null && !is_string($causationId))
            || !is_array($headers)
            || array_is_list($headers) && $headers !== []
        ) {
            throw new InvalidSerializedEnvelope('Envelope metadata has invalid field types.');
        }

        try {
            return new Envelope(
                $this->codecs->decode($alias, $version, $payload),
                $messageId,
                $correlationId,
                $causationId,
                new Headers($headers),
            );
        } catch (InvalidSerializedEnvelope $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new InvalidSerializedEnvelope('Envelope cannot be restored.', previous: $exception);
        }
    }
}
