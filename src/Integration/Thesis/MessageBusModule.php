<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Integration\Thesis;

use Fedor\MessageBus\Envelope\EnvelopeFactory;
use Fedor\MessageBus\Envelope\EnvelopeSerializerInterface;
use Fedor\MessageBus\Invoker\CallableInvokerInterface;
use Fedor\MessageBus\MessageBus;
use Fedor\MessageBus\Queue\QueueProviderInterface;
use Fedor\MessageBus\Queue\RetryPolicyRegistryInterface;
use Fedor\MessageBus\Registry\CompiledMessageRegistry;
use Fedor\MessageBus\Service\ServiceScopeFactoryInterface;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;

/** @implements Module<Ref<MessageBus>> */
final readonly class MessageBusModule implements Module
{
    /**
     * @param Ref<CompiledMessageRegistry> $registry
     * @param array<string, Ref<object>> $services
     * @param Ref<ServiceScopeFactoryInterface>|null $scopeFactory
     * @param Ref<EnvelopeFactory>|null $envelopeFactory
     * @param Ref<CallableInvokerInterface>|null $invoker
     * @param Ref<EnvelopeSerializerInterface>|null $envelopeSerializer
     * @param Ref<ClockInterface>|null $clock
     * @param Ref<QueueProviderInterface>|null $queueProvider
     * @param Ref<RetryPolicyRegistryInterface>|null $retryPolicyRegistry
     */
    public function __construct(
        private Ref $registry,
        private array $services = [],
        private ?Ref $scopeFactory = null,
        private ?Ref $envelopeFactory = null,
        private ?Ref $invoker = null,
        private ?Ref $envelopeSerializer = null,
        private ?Ref $clock = null,
        private ?Ref $queueProvider = null,
        private ?Ref $retryPolicyRegistry = null,
    ) {
        if ($this->scopeFactory !== null && $this->services !== []) {
            throw new InvalidArgumentException(
                'MessageBusModule services cannot be combined with an explicit scope factory.',
            );
        }
    }

    /** @return Ref<MessageBus> */
    public function configure(Dic $dic): Ref
    {
        $arguments = [
            'registry' => $this->registry,
            'scopeFactory' => $this->scopeFactory ?? $this->defaultScopeFactory($dic),
        ];
        foreach ([
            'envelopeFactory' => $this->envelopeFactory,
            'invoker' => $this->invoker,
            'envelopeSerializer' => $this->envelopeSerializer,
            'clock' => $this->clock,
            'queueProvider' => $this->queueProvider,
            'retryPolicyRegistry' => $this->retryPolicyRegistry,
        ] as $name => $service) {
            if ($service !== null) {
                $arguments[$name] = $service;
            }
        }

        return $dic
            ->object(MessageBus::class)
            ->args($arguments);
    }

    /** @return Ref<ThesisServiceScopeFactory> */
    private function defaultScopeFactory(Dic $dic): Ref
    {
        $providers = [];
        foreach ($this->services as $id => $service) {
            $providers[$id] = $dic->provider($service);
        }

        $resolver = $dic
            ->object(ThesisServiceResolver::class)
            ->arg('providers', $dic->value($providers))
            ->scoped();

        return $dic
            ->object(ThesisServiceScopeFactory::class)
            ->arg('resolver', $dic->scoped($resolver));
    }
}
