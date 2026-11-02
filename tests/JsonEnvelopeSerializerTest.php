<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Tests;

use DateTimeImmutable;
use Fedor\MessageBus\Attribute\EventSubscriber;
use Fedor\MessageBus\Attribute\MessageAlias;
use Fedor\MessageBus\Codec\InvalidMessagePayload;
use Fedor\MessageBus\Codec\JsonValue;
use Fedor\MessageBus\Codec\MessageCodecInterface;
use Fedor\MessageBus\Codec\MessageCodecNotFound;
use Fedor\MessageBus\Codec\MessageCodecRegistry;
use Fedor\MessageBus\Codec\MessageCodecRegistryValidationRule;
use Fedor\MessageBus\Codec\MessageUpcasterInterface;
use Fedor\MessageBus\Codec\UnsupportedMessageVersion;
use Fedor\MessageBus\Envelope\Envelope;
use Fedor\MessageBus\Envelope\Headers;
use Fedor\MessageBus\Envelope\InvalidSerializedEnvelope;
use Fedor\MessageBus\Envelope\JsonEnvelopeSerializer;
use Fedor\MessageBus\Envelope\SerializedEnvelope;
use Fedor\MessageBus\Flow\FlowDefinition;
use Fedor\MessageBus\Flow\FlowRegistry;
use Fedor\MessageBus\Message\Command;
use Fedor\MessageBus\Message\Event;
use Fedor\MessageBus\Message\Query;
use Fedor\MessageBus\Registry\MessageRegistryCompiler;
use Fedor\MessageBus\Registry\RegistryCompilationStage;
use Fedor\MessageBus\Registry\RegistryDiagnosticCodes;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

final class JsonEnvelopeSerializerTest extends TestCase
{
    public function testEnvelopeRoundTripUsesExplicitCodecAndIndependentVersions(): void
    {
        $serializer = new JsonEnvelopeSerializer(new MessageCodecRegistry([
            new CodecAddressRegisteredV1(),
        ]));
        $envelope = new Envelope(
            new CodecAddressRegistered('Москва', '101000', 'RU'),
            'message-1',
            'correlation-1',
            'causation-1',
            new Headers(['trace' => 'trace-1', 'attempt' => 2]),
            new DateTimeImmutable('2026-11-02T20:00:00+00:00'),
        );

        $serialized = $serializer->serialize($envelope);

        self::assertSame(SerializedEnvelope::SCHEMA_VERSION, $serialized->schemaVersion);
        self::assertSame('address.registered', $serialized->messageName);
        self::assertSame(
            '{"message":{"alias":"address.registered","version":1,"payload":{"city":"Москва","postalCode":"101000","country":"RU"}},"metadata":{"messageId":"message-1","correlationId":"correlation-1","causationId":"causation-1","createdAt":"2026-11-02T20:00:00+00:00","headers":{"trace":"trace-1","attempt":2}}}',
            $serialized->payload,
        );

        $restored = $serializer->deserialize($serialized);

        self::assertEquals($envelope->message, $restored->message);
        self::assertSame('message-1', $restored->messageId);
        self::assertSame('correlation-1', $restored->correlationId);
        self::assertSame('causation-1', $restored->causationId);
        self::assertEquals($envelope->createdAt, $restored->createdAt);
        self::assertSame(['trace' => 'trace-1', 'attempt' => 2], $restored->headers->all());
    }

    public function testOlderMessageVersionTraversesContinuousUpcasterChain(): void
    {
        $serializer = new JsonEnvelopeSerializer(new MessageCodecRegistry(
            [new CodecAddressRegisteredV3()],
            [new CodecAddressV1ToV2(), new CodecAddressV2ToV3()],
        ));
        $fixture = new SerializedEnvelope('address.registered', <<<'JSON'
            {"message":{"alias":"address.registered","version":1,"payload":{"city":"Москва","zip":"101000"}},"metadata":{"messageId":"old-1","correlationId":"old-correlation","causationId":null,"headers":{}}}
            JSON);

        $restored = $serializer->deserialize($fixture);

        self::assertEquals(
            new CodecAddressRegistered('Москва', '101000', 'RU'),
            $restored->message,
        );
    }

    public function testRegistryRejectsDuplicateCodecsAndBrokenUpcasterChain(): void
    {
        try {
            new MessageCodecRegistry([
                new CodecAddressRegisteredV1(),
                new CodecDuplicateAlias(),
            ]);
            self::fail('Expected duplicate codec alias to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('alias "address.registered" is duplicated', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing version 2 -> 3');

        new MessageCodecRegistry(
            [new CodecAddressRegisteredV3()],
            [new CodecAddressV1ToV2()],
        );
    }

    public function testRegistryRejectsUnknownAliasAndFutureVersionWithoutConstructingClasses(): void
    {
        $registry = new MessageCodecRegistry([new CodecAddressRegisteredV1()]);

        try {
            $registry->decode('unknown.message', 1, JsonValue::from([]));
            self::fail('Expected unknown alias to be rejected.');
        } catch (MessageCodecNotFound $exception) {
            self::assertStringContainsString('unknown.message', $exception->getMessage());
        }

        $this->expectException(UnsupportedMessageVersion::class);
        $this->expectExceptionMessage('version 2 cannot be read by codec version 1');

        $registry->decode('address.registered', 2, JsonValue::from([]));
    }

    public function testJsonPayloadRejectsObjectsAndNonFiniteNumbers(): void
    {
        try {
            JsonValue::from(['object' => new stdClass()]);
            self::fail('Expected object payload to be rejected.');
        } catch (InvalidMessagePayload $exception) {
            self::assertStringContainsString('stdClass given', $exception->getMessage());
        }

        $this->expectException(InvalidMessagePayload::class);
        $this->expectExceptionMessage('non-finite float');

        JsonValue::from(INF);
    }

    public function testDeserializerRejectsUnknownEnvelopeSchemaAndMalformedMetadata(): void
    {
        $serializer = new JsonEnvelopeSerializer(new MessageCodecRegistry([
            new CodecAddressRegisteredV1(),
        ]));

        try {
            $serializer->deserialize(new SerializedEnvelope('address.registered', '{}', 2));
            self::fail('Expected unsupported envelope schema to be rejected.');
        } catch (InvalidSerializedEnvelope $exception) {
            self::assertStringContainsString('schema version 2 is not supported', $exception->getMessage());
        }

        try {
            $serializer->deserialize(new SerializedEnvelope('other.message', <<<'JSON'
                {"message":{"alias":"address.registered","version":1,"payload":{}},"metadata":{"messageId":"m","correlationId":"c","causationId":null,"headers":{}}}
                JSON));
            self::fail('Expected the durable message name mismatch to be rejected.');
        } catch (InvalidSerializedEnvelope $exception) {
            self::assertStringContainsString('does not match its payload alias', $exception->getMessage());
        }

        $this->expectException(InvalidSerializedEnvelope::class);
        $this->expectExceptionMessage('metadata has invalid field types');

        $serializer->deserialize(new SerializedEnvelope('address.registered', <<<'JSON'
            {"message":{"alias":"address.registered","version":1,"payload":{}},"metadata":{"messageId":12,"correlationId":"c","causationId":null,"headers":{}}}
            JSON));
    }

    public function testRegistryValidationRequiresCodecForEveryAsyncAliasAndMatchingClass(): void
    {
        $flows = new FlowRegistry(FlowDefinition::async('async')->transport('postgres', 'messages'));

        $missing = (new MessageRegistryCompiler(validationRules: [
            new MessageCodecRegistryValidationRule(new MessageCodecRegistry([])),
        ]))->compileWithDiagnostics([CodecAddressRegisteredHandler::class], $flows);

        self::assertFalse($missing->hasDefinition());
        self::assertSame(RegistryCompilationStage::ProjectRulesValidated, $missing->graphContext?->stage);
        self::assertSame(RegistryDiagnosticCodes::CODEC_MISSING, $missing->diagnostics[0]->code);

        $mismatched = (new MessageRegistryCompiler(validationRules: [
            new MessageCodecRegistryValidationRule(new MessageCodecRegistry([new CodecDuplicateAlias()])),
        ]))->compileWithDiagnostics([CodecAddressRegisteredHandler::class], $flows);

        self::assertFalse($mismatched->hasDefinition());
        self::assertSame(RegistryDiagnosticCodes::CODEC_MESSAGE_MISMATCH, $mismatched->diagnostics[0]->code);

        $valid = (new MessageRegistryCompiler(validationRules: [
            new MessageCodecRegistryValidationRule(new MessageCodecRegistry([new CodecAddressRegisteredV1()])),
        ]))->compileWithDiagnostics([CodecAddressRegisteredHandler::class], $flows);

        self::assertTrue($valid->hasDefinition());
        self::assertFalse($valid->hasErrors());
    }
}

#[MessageAlias('address.registered')]
final readonly class CodecAddressRegistered implements Event
{
    public function __construct(
        public string $city,
        public string $postalCode,
        public string $country,
    ) {
    }
}

#[EventSubscriber(
    CodecAddressRegistered::class,
    flow: 'async',
    bindingId: 'address.registered.projection',
    contextAware: false,
)]
final class CodecAddressRegisteredHandler
{
    public function __invoke(CodecAddressRegistered $event): void
    {
    }
}

final readonly class CodecAddressRemoved implements Event
{
    public function __construct(public string $city)
    {
    }
}

final class CodecAddressRegisteredV1 implements MessageCodecInterface
{
    public function messageClass(): string
    {
        return CodecAddressRegistered::class;
    }

    public function alias(): string
    {
        return 'address.registered';
    }

    public function version(): int
    {
        return 1;
    }

    /** @param Query<mixed>|Command|Event $message */
    public function toPayload(Query|Command|Event $message): JsonValue
    {
        if (!$message instanceof CodecAddressRegistered) {
            throw new InvalidArgumentException('Unexpected message type.');
        }

        return JsonValue::from([
            'city' => $message->city,
            'postalCode' => $message->postalCode,
            'country' => $message->country,
        ]);
    }

    public function fromPayload(JsonValue $payload): CodecAddressRegistered
    {
        return CodecAddressPayload::restore($payload);
    }
}

final class CodecAddressRegisteredV3 implements MessageCodecInterface
{
    public function messageClass(): string
    {
        return CodecAddressRegistered::class;
    }

    public function alias(): string
    {
        return 'address.registered';
    }

    public function version(): int
    {
        return 3;
    }

    /** @param Query<mixed>|Command|Event $message */
    public function toPayload(Query|Command|Event $message): JsonValue
    {
        if (!$message instanceof CodecAddressRegistered) {
            throw new InvalidArgumentException('Unexpected message type.');
        }

        return JsonValue::from([
            'city' => $message->city,
            'postalCode' => $message->postalCode,
            'country' => $message->country,
        ]);
    }

    public function fromPayload(JsonValue $payload): CodecAddressRegistered
    {
        return CodecAddressPayload::restore($payload);
    }
}

final class CodecDuplicateAlias implements MessageCodecInterface
{
    public function messageClass(): string
    {
        return CodecAddressRemoved::class;
    }

    public function alias(): string
    {
        return 'address.registered';
    }

    public function version(): int
    {
        return 1;
    }

    /** @param Query<mixed>|Command|Event $message */
    public function toPayload(Query|Command|Event $message): JsonValue
    {
        if (!$message instanceof CodecAddressRemoved) {
            throw new InvalidArgumentException('Unexpected message type.');
        }

        return JsonValue::from(['city' => $message->city]);
    }

    public function fromPayload(JsonValue $payload): CodecAddressRemoved
    {
        $data = $payload->value();
        if (!is_array($data) || !is_string($data['city'] ?? null)) {
            throw new InvalidMessagePayload('Address removed payload is invalid.');
        }

        return new CodecAddressRemoved($data['city']);
    }
}

final class CodecAddressV1ToV2 implements MessageUpcasterInterface
{
    public function alias(): string
    {
        return 'address.registered';
    }

    public function fromVersion(): int
    {
        return 1;
    }

    public function upcast(JsonValue $payload): JsonValue
    {
        $data = $payload->value();
        if (!is_array($data) || !is_string($data['zip'] ?? null)) {
            throw new InvalidMessagePayload('Address v1 payload is invalid.');
        }

        $data['postalCode'] = $data['zip'];
        unset($data['zip']);

        return JsonValue::from($data);
    }
}

final class CodecAddressV2ToV3 implements MessageUpcasterInterface
{
    public function alias(): string
    {
        return 'address.registered';
    }

    public function fromVersion(): int
    {
        return 2;
    }

    public function upcast(JsonValue $payload): JsonValue
    {
        $data = $payload->value();
        if (!is_array($data)) {
            throw new InvalidMessagePayload('Address v2 payload is invalid.');
        }

        $data['country'] = 'RU';

        return JsonValue::from($data);
    }
}

final class CodecAddressPayload
{
    public static function restore(JsonValue $payload): CodecAddressRegistered
    {
        $data = $payload->value();
        if (
            !is_array($data)
            || !is_string($data['city'] ?? null)
            || !is_string($data['postalCode'] ?? null)
            || !is_string($data['country'] ?? null)
        ) {
            throw new InvalidMessagePayload('Address registered payload is invalid.');
        }

        return new CodecAddressRegistered(
            $data['city'],
            $data['postalCode'],
            $data['country'],
        );
    }

    private function __construct()
    {
    }
}
