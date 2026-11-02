<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Execution;

use Closure;
use Fedor\MessageBus\Context\MessageContextInterface;
use Fedor\MessageBus\Flow\FlowDefinition;
use Fedor\MessageBus\Invoker\CallableInvokerInterface;
use Fedor\MessageBus\Registry\HandlerBinding;
use Fedor\MessageBus\Service\ServiceResolverInterface;

final readonly class ExecutionRequest
{
    /** @var Closure(HandlerBinding): MessageContextInterface */
    private Closure $contextForBinding;

    /**
     * @param non-empty-list<HandlerBinding> $bindings
     * @param null|callable(HandlerBinding): MessageContextInterface $contextForBinding
     */
    public function __construct(
        public array $bindings,
        public MessageContextInterface $context,
        public FlowDefinition $flow,
        public ExecutionOptions $options,
        public ServiceResolverInterface $services,
        public CallableInvokerInterface $invoker,
        ?callable $contextForBinding = null,
    ) {
        $this->contextForBinding = $contextForBinding !== null
            ? Closure::fromCallable($contextForBinding)
            : static fn (HandlerBinding $binding): MessageContextInterface => $context;
    }

    public function contextForBinding(HandlerBinding $binding): MessageContextInterface
    {
        return ($this->contextForBinding)($binding);
    }
}
