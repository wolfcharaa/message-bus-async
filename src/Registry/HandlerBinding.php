<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Registry;

use Fedor\MessageBus\Interceptor\InterceptorInterface;
use Fedor\MessageBus\Message\Command;
use Fedor\MessageBus\Message\Event;
use Fedor\MessageBus\Message\Query;
use Fedor\MessageBus\Queue\QueueDeliveryOptions;
use Fedor\MessageBus\Registry\Metadata\RegistryOwner;
use Fedor\MessageBus\Registry\Metadata\RegistrySource;
use Fedor\MessageBus\Service\ServiceReference;
use InvalidArgumentException;

final readonly class HandlerBinding
{
    /**
     * @param class-string $message
     * @param ServiceReference<object> $handler
     * @param list<ServiceReference<InterceptorInterface>> $interceptors
     */
    public function __construct(
        public string $id,
        public string $message,
        public HandlerKind $kind,
        public ServiceReference $handler,
        public string $method,
        public string $flow,
        public int $priority,
        public bool $primary,
        public HandlerInvocationMode $invocationMode,
        public array $interceptors = [],
        public ?QueueDeliveryOptions $delivery = null,
        public ?RegistryOwner $owner = null,
        public ?RegistrySource $source = null,
    ) {
        self::requireNonEmpty($this->id, 'Binding ID');
        self::requireNonEmpty($this->method, 'Handler method');
        self::requireNonEmpty($this->flow, 'Flow name');

        $expectedMessageType = match ($this->kind) {
            HandlerKind::Query => Query::class,
            HandlerKind::Command => Command::class,
            HandlerKind::Event => Event::class,
        };

        if (!is_a($this->message, $expectedMessageType, true)) {
            throw new InvalidArgumentException(sprintf(
                'Message class "%s" must implement %s for a %s binding.',
                $this->message,
                $expectedMessageType,
                $this->kind->value,
            ));
        }

    }

    public function withRegistrationMetadata(?RegistryOwner $owner, ?RegistrySource $source): self
    {
        return new self(
            $this->id,
            $this->message,
            $this->kind,
            $this->handler,
            $this->method,
            $this->flow,
            $this->priority,
            $this->primary,
            $this->invocationMode,
            $this->interceptors,
            $this->delivery,
            $owner,
            $source,
        );
    }

    /**
     * @return array{
     *     id: string,
     *     message: class-string,
     *     kind: string,
     *     handler: array{id: string, type: class-string},
     *     method: string,
     *     flow: string,
     *     priority: int,
     *     primary: bool,
     *     invocationMode: string,
     *     interceptors: list<array{id: string, type: class-string<InterceptorInterface>}>,
     *     delivery: array{priority: ?int, delaySeconds: ?int, retryPolicy: ?string}|null
     * }
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'message' => $this->message,
            'kind' => $this->kind->value,
            'handler' => self::referenceToArray($this->handler),
            'method' => $this->method,
            'flow' => $this->flow,
            'priority' => $this->priority,
            'primary' => $this->primary,
            'invocationMode' => $this->invocationMode->value,
            'interceptors' => array_map(
                static fn (ServiceReference $reference): array => self::referenceToArray($reference),
                $this->interceptors,
            ),
            'delivery' => $this->delivery?->toArray(),
        ];

        if ($this->owner !== null) {
            $data['owner'] = $this->owner->toArray();
        }

        if ($this->source !== null) {
            $data['source'] = $this->source->toArray();
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $message = self::string($data, 'message');
        if (!class_exists($message) && !interface_exists($message)) {
            throw new InvalidRegistryArtifact(sprintf('Binding message class "%s" does not exist.', $message));
        }

        /** @var class-string $message */
        $kindValue = self::string($data, 'kind');
        $kind = HandlerKind::tryFrom($kindValue) ?? throw new InvalidRegistryArtifact(sprintf(
            'Unsupported handler kind "%s".',
            $kindValue,
        ));

        $invocationModeValue = self::string($data, 'invocationMode');
        $invocationMode = HandlerInvocationMode::tryFrom($invocationModeValue)
            ?? throw new InvalidRegistryArtifact(sprintf(
                'Unsupported handler invocation mode "%s".',
                $invocationModeValue,
            ));

        $priority = $data['priority'] ?? null;
        if (!is_int($priority)) {
            throw new InvalidRegistryArtifact('Binding field "priority" must be an integer.');
        }

        $primary = $data['primary'] ?? null;
        if (!is_bool($primary)) {
            throw new InvalidRegistryArtifact('Binding field "primary" must be a boolean.');
        }

        $interceptorData = $data['interceptors'] ?? null;
        if (!is_array($interceptorData) || !array_is_list($interceptorData)) {
            throw new InvalidRegistryArtifact('Binding field "interceptors" must be a list.');
        }

        $interceptors = [];
        foreach ($interceptorData as $index => $referenceData) {
            $reference = self::reference($referenceData, sprintf('interceptors[%d]', $index));
            if (!is_a($reference->type, InterceptorInterface::class, true)) {
                throw new InvalidRegistryArtifact(sprintf(
                    'Binding interceptor type "%s" must implement %s.',
                    $reference->type,
                    InterceptorInterface::class,
                ));
            }

            /** @var ServiceReference<InterceptorInterface> $reference */
            $interceptors[] = $reference;
        }

        try {
            return new self(
                self::string($data, 'id'),
                $message,
                $kind,
                self::reference($data['handler'] ?? null, 'handler'),
                self::string($data, 'method'),
                self::string($data, 'flow'),
                $priority,
                $primary,
                $invocationMode,
                $interceptors,
                self::delivery($data['delivery'] ?? null),
                self::owner($data['owner'] ?? null),
                self::source($data['source'] ?? null),
            );
        } catch (InvalidArgumentException $exception) {
            throw new InvalidRegistryArtifact($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @template TService of object
     * @param ServiceReference<TService> $reference
     * @return array{id: string, type: class-string<TService>}
     */
    private static function referenceToArray(ServiceReference $reference): array
    {
        return ['id' => $reference->id, 'type' => $reference->type];
    }

    /** @return ServiceReference<object> */
    private static function reference(mixed $data, string $field): ServiceReference
    {
        if (!is_array($data)) {
            throw new InvalidRegistryArtifact(sprintf('Binding field "%s" must be a service reference.', $field));
        }

        $id = $data['id'] ?? null;
        $type = $data['type'] ?? null;
        if (!is_string($id) || !is_string($type)) {
            throw new InvalidRegistryArtifact(sprintf(
                'Binding field "%s" must contain string id and type values.',
                $field,
            ));
        }

        if (!class_exists($type) && !interface_exists($type)) {
            throw new InvalidRegistryArtifact(sprintf('Service reference type "%s" does not exist.', $type));
        }

        /** @var class-string<object> $type */
        try {
            return new ServiceReference($id, $type);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidRegistryArtifact($exception->getMessage(), previous: $exception);
        }
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $field): string
    {
        $value = $data[$field] ?? null;
        if (!is_string($value)) {
            throw new InvalidRegistryArtifact(sprintf('Binding field "%s" must be a string.', $field));
        }

        return $value;
    }

    private static function requireNonEmpty(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('%s must be a non-empty string.', $field));
        }
    }

    private static function delivery(mixed $data): ?QueueDeliveryOptions
    {
        if ($data === null) {
            return null;
        }

        if (!is_array($data)) {
            throw new InvalidRegistryArtifact('Binding field "delivery" must be an array or null.');
        }

        $priority = $data['priority'] ?? null;
        $delaySeconds = $data['delaySeconds'] ?? null;
        $retryPolicy = $data['retryPolicy'] ?? null;
        if (
            ($priority !== null && !is_int($priority))
            || ($delaySeconds !== null && !is_int($delaySeconds))
            || ($retryPolicy !== null && !is_string($retryPolicy))
        ) {
            throw new InvalidRegistryArtifact('Binding delivery contains invalid values.');
        }

        return new QueueDeliveryOptions($priority, $delaySeconds, $retryPolicy);
    }

    private static function owner(mixed $data): ?RegistryOwner
    {
        if ($data === null) {
            return null;
        }

        if (!is_array($data) || !isset($data['kind'], $data['id']) || !is_string($data['kind']) || !is_string($data['id'])) {
            throw new InvalidRegistryArtifact('Binding owner metadata must contain string kind and id values.');
        }

        return RegistryOwner::fromArray(['kind' => $data['kind'], 'id' => $data['id']]);
    }

    private static function source(mixed $data): ?RegistrySource
    {
        if ($data === null) {
            return null;
        }

        if (!is_array($data)) {
            throw new InvalidRegistryArtifact('Binding source metadata must be an array or null.');
        }

        $normalized = [];
        foreach (['type', 'name', 'providerClass', 'package', 'location'] as $key) {
            $value = $data[$key] ?? null;
            if ($value !== null && !is_string($value)) {
                throw new InvalidRegistryArtifact(sprintf('Binding source field `%s` must be a string or null.', $key));
            }
            $normalized[$key] = $value;
        }

        if (!is_string($normalized['type']) || !is_string($normalized['name'])) {
            throw new InvalidRegistryArtifact('Binding source metadata must contain string type and name values.');
        }

        /** @var array<string, string|null> $normalized */
        return RegistrySource::fromArray($normalized);
    }
}
