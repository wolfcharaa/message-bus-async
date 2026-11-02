<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Flow;

use BackedEnum;
use Fedor\MessageBus\Interceptor\InterceptorInterface;
use Fedor\MessageBus\Queue\QueueDeliveryOptions;

final readonly class FlowDefinition
{
    /** @param list<class-string<InterceptorInterface>> $middleware */
    private function __construct(
        public string $key,
        public FlowMode $mode,
        public array $middleware,
        public ?TransportDefinition $transport,
        public ?QueueDeliveryOptions $delivery,
    ) {
    }

    public static function sync(string|BackedEnum $key): self
    {
        return new self(self::normalize($key), FlowMode::Sync, [], null, null);
    }

    public static function async(string|BackedEnum $key): self
    {
        return new self(self::normalize($key), FlowMode::Async, [], null, null);
    }

    /** @param class-string<InterceptorInterface> ...$middleware */
    public function middleware(string ...$middleware): self
    {
        return new self($this->key, $this->mode, array_values($middleware), $this->transport, $this->delivery);
    }

    public function transport(string|BackedEnum $transport, string|BackedEnum $queue): self
    {
        return new self(
            $this->key,
            $this->mode,
            $this->middleware,
            TransportDefinition::from($transport, $queue),
            $this->delivery,
        );
    }

    public function delivery(QueueDeliveryOptions $delivery): self
    {
        return new self($this->key, $this->mode, $this->middleware, $this->transport, $delivery);
    }

    public function isSync(): bool
    {
        return $this->mode === FlowMode::Sync;
    }

    public function isAsync(): bool
    {
        return $this->mode === FlowMode::Async;
    }

    /**
     * @return array{
     *     key: string,
     *     mode: string,
     *     middleware: list<class-string<InterceptorInterface>>,
     *     transport: array{transport: string, queue: string}|null,
     *     delivery: array{priority: ?int, delaySeconds: ?int, retryPolicy: ?string}|null
     * }
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'mode' => $this->mode->value,
            'middleware' => $this->middleware,
            'transport' => $this->transport?->toArray(),
            'delivery' => $this->delivery?->toArray(),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $key = $data['key'] ?? null;
        $mode = $data['mode'] ?? null;
        if (!is_string($key) || !is_string($mode)) {
            throw new \InvalidArgumentException('Flow key and mode must be strings.');
        }

        $rawMiddleware = $data['middleware'] ?? [];
        if (!is_array($rawMiddleware) || !array_is_list($rawMiddleware)) {
            throw new \InvalidArgumentException('Flow middleware must be a list.');
        }

        $middleware = [];
        foreach ($rawMiddleware as $class) {
            if (!is_string($class) || !class_exists($class) || !is_a($class, InterceptorInterface::class, true)) {
                throw new \InvalidArgumentException('Flow middleware must contain InterceptorInterface classes.');
            }

            $middleware[] = $class;
        }

        $transport = self::transportFromMixed($data['transport'] ?? null);
        $delivery = self::deliveryFromMixed($data['delivery'] ?? null);

        return new self(
            $key,
            FlowMode::from($mode),
            $middleware,
            $transport,
            $delivery,
        );
    }

    private static function transportFromMixed(mixed $data): ?TransportDefinition
    {
        if ($data === null) {
            return null;
        }

        if (!is_array($data)) {
            throw new \InvalidArgumentException('Flow transport must be an array or null.');
        }

        $transport = $data['transport'] ?? null;
        $queue = $data['queue'] ?? null;
        if (!is_string($transport) || !is_string($queue)) {
            throw new \InvalidArgumentException('Flow transport and queue must be strings.');
        }

        return new TransportDefinition($transport, $queue);
    }

    private static function deliveryFromMixed(mixed $data): ?QueueDeliveryOptions
    {
        if ($data === null) {
            return null;
        }

        if (!is_array($data)) {
            throw new \InvalidArgumentException('Flow delivery must be an array or null.');
        }

        $priority = $data['priority'] ?? null;
        $delaySeconds = $data['delaySeconds'] ?? null;
        $retryPolicy = $data['retryPolicy'] ?? null;
        if (
            ($priority !== null && !is_int($priority))
            || ($delaySeconds !== null && !is_int($delaySeconds))
            || ($retryPolicy !== null && !is_string($retryPolicy))
        ) {
            throw new \InvalidArgumentException('Flow delivery contains invalid values.');
        }

        return new QueueDeliveryOptions($priority, $delaySeconds, $retryPolicy);
    }

    private static function normalize(string|BackedEnum $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : $value;
    }
}
