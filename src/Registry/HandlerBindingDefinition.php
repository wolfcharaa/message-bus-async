<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Registry;

use Fedor\MessageBus\Interceptor\InterceptorInterface;
use Fedor\MessageBus\Queue\QueueDeliveryOptions;
use Fedor\MessageBus\Registry\Metadata\RegistryOwner;
use Fedor\MessageBus\Registry\Metadata\RegistrySource;
use Fedor\MessageBus\Service\ServiceReference;
use InvalidArgumentException;

final readonly class HandlerBindingDefinition
{
    /**
     * @param class-string $message
     * @param class-string $action
     * @param list<class-string> $middleware Validated by the registry compiler.
     */
    public function __construct(
        public ?string $bindingId,
        public string $message,
        public string $action,
        public string $method,
        public string $flow,
        public HandlerKind $kind,
        public ?bool $primary,
        public int $priority,
        public array $middleware = [],
        public ?QueueDeliveryOptions $delivery = null,
        public HandlerInvocationMode $invocationMode = HandlerInvocationMode::ContextAware,
        public ?RegistryOwner $owner = null,
        public ?RegistrySource $source = null,
    ) {
    }

    public function withBindingId(string $bindingId): self
    {
        return new self(
            $bindingId,
            $this->message,
            $this->action,
            $this->method,
            $this->flow,
            $this->kind,
            $this->primary,
            $this->priority,
            $this->middleware,
            $this->delivery,
            $this->invocationMode,
            $this->owner,
            $this->source,
        );
    }

    public function withPrimary(bool $primary): self
    {
        return new self(
            $this->bindingId,
            $this->message,
            $this->action,
            $this->method,
            $this->flow,
            $this->kind,
            $primary,
            $this->priority,
            $this->middleware,
            $this->delivery,
            $this->invocationMode,
            $this->owner,
            $this->source,
        );
    }

    public function withRegistrationMetadata(?RegistryOwner $owner, ?RegistrySource $source): self
    {
        return new self(
            $this->bindingId,
            $this->message,
            $this->action,
            $this->method,
            $this->flow,
            $this->kind,
            $this->primary,
            $this->priority,
            $this->middleware,
            $this->delivery,
            $this->invocationMode,
            $owner,
            $source,
        );
    }

    public function compile(): HandlerBinding
    {
        if ($this->bindingId === null || trim($this->bindingId) === '') {
            throw new InvalidArgumentException('A normalized binding definition must have a binding ID.');
        }

        $interceptors = array_map(
            self::interceptorReference(...),
            $this->middleware,
        );

        return new HandlerBinding(
            $this->bindingId,
            $this->message,
            $this->kind,
            ServiceReference::forClass($this->action),
            $this->method,
            $this->flow,
            $this->priority,
            $this->primary ?? false,
            $this->invocationMode,
            $interceptors,
            $this->delivery,
            $this->owner,
            $this->source,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = $this->compile()->toArray();
        $data['bindingId'] = $data['id'];
        unset($data['id']);

        return $data;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (!array_key_exists('id', $data) && array_key_exists('bindingId', $data)) {
            $data['id'] = $data['bindingId'];
        }

        $binding = HandlerBinding::fromArray($data);

        return new self(
            $binding->id,
            $binding->message,
            $binding->handler->type,
            $binding->method,
            $binding->flow,
            $binding->kind,
            $binding->primary,
            $binding->priority,
            array_map(
                static fn (ServiceReference $reference): string => $reference->type,
                $binding->interceptors,
            ),
            $binding->delivery,
            $binding->invocationMode,
            $binding->owner,
            $binding->source,
        );
    }

    /** @return ServiceReference<InterceptorInterface> */
    private static function interceptorReference(string $class): ServiceReference
    {
        if (!is_a($class, InterceptorInterface::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'Interceptor `%s` must implement `%s`.',
                $class,
                InterceptorInterface::class,
            ));
        }

        return ServiceReference::forClass($class);
    }

}
