<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Flow\Contract;

use Fedor\MessageBus\Interceptor\InterceptorInterface;
use Fedor\MessageBus\Registry\HandlerBindingDefinition;
use Fedor\MessageBus\Registry\RegistryCompilationGraphContext;
use Fedor\MessageBus\Registry\RegistryDiagnostic;
use Fedor\MessageBus\Registry\RegistryDiagnosticCodes;
use Fedor\MessageBus\Registry\RegistryDiagnosticOrigin;
use Fedor\MessageBus\Registry\RegistryDiagnosticTarget;
use Fedor\MessageBus\Registry\RegistryValidationRuleInterface;

final readonly class FlowContractValidationRule implements RegistryValidationRuleInterface
{
    /** @param iterable<BindingPolicyRegistryInterface> $policyRegistries */
    public function __construct(
        private FlowContractRegistry $contracts,
        private InterceptorRoleRegistry $interceptorRoles,
        private iterable $policyRegistries = [],
    ) {
    }

    public function validate(RegistryCompilationGraphContext $context): iterable
    {
        $diagnostics = [];
        $policyRegistries = $this->policyRegistries();

        foreach ($context->bindings as $binding) {
            $contract = $this->contracts->get($binding->flow);
            if ($contract === null) {
                continue;
            }

            $target = $this->target($binding);
            $origin = RegistryDiagnosticOrigin::flow($binding->flow, 'message_bus.flow_contract');
            $flowInterceptors = $context->flows === null
                ? []
                : $context->flows->get($binding->flow)->middleware;
            $interceptors = $this->interceptors($binding, $flowInterceptors);
            $positions = $this->interceptorRoles->firstRolePositions($interceptors);

            if ($contract->allowedHandlerKinds !== [] && !in_array($binding->kind, $contract->allowedHandlerKinds, true)) {
                $diagnostics[] = $this->error(
                    sprintf('Flow `%s` does not allow `%s` binding `%s`.', $binding->flow, $binding->kind->value, $binding->bindingId),
                    $origin,
                    $target,
                    'Move this handler to a compatible flow or change the flow contract.',
                );
            }

            foreach ($contract->requiredRoles as $role) {
                if (!isset($positions[$role])) {
                    $diagnostics[] = $this->error(
                        sprintf('Flow `%s` requires interceptor role `%s` for binding `%s`.', $binding->flow, $role, $binding->bindingId),
                        $origin,
                        $target,
                        'Register an interceptor role mapping or add an interceptor with the required role.',
                    );
                }
            }

            foreach ($contract->requiredOrder as $order) {
                $before = $positions[$order['before']] ?? null;
                $after = $positions[$order['after']] ?? null;
                if ($before !== null && $after !== null && $before >= $after) {
                    $diagnostics[] = $this->error(
                        sprintf(
                            'Flow `%s` requires interceptor role `%s` before `%s` for binding `%s`.',
                            $binding->flow,
                            $order['before'],
                            $order['after'],
                            $binding->bindingId,
                        ),
                        $origin,
                        $target,
                        'Reorder flow or binding interceptors so outer roles appear before inner roles.',
                    );
                }
            }

            if ($contract->requiresStableBindingId && $this->isAutoBindingId($binding)) {
                $diagnostics[] = $this->error(
                    sprintf('Flow `%s` requires stable bindingId for `%s -> %s`.', $binding->flow, $binding->message, $binding->action),
                    $origin,
                    $target,
                    'Set an explicit bindingId on the handler attribute or manual binding.',
                );
            }

            if ($contract->requiresBindingOwner && $binding->owner === null) {
                $diagnostics[] = $this->error(
                    sprintf('Flow `%s` requires binding owner metadata for binding `%s`.', $binding->flow, $binding->bindingId),
                    $origin,
                    $target,
                    'Set owner metadata on the handler attribute or binding registration context.',
                );
            }

            foreach ($contract->requiredPolicies as $policyRegistryClass) {
                $policyRegistry = $policyRegistries[$policyRegistryClass] ?? null;
                if ($policyRegistry === null) {
                    $diagnostics[] = $this->error(
                        sprintf('Flow `%s` requires policy registry `%s`.', $binding->flow, $policyRegistryClass),
                        $origin,
                        $target,
                        'Pass the required policy registry to FlowContractValidationRule.',
                    );
                    continue;
                }

                $bindingId = $binding->bindingId ?? '';
                if (!$policyRegistry->hasForBinding($bindingId)) {
                    $diagnostics[] = $this->error(
                        sprintf('Flow `%s` requires `%s` policy for binding `%s`.', $binding->flow, $policyRegistryClass, $bindingId),
                        $origin,
                        $target,
                        'Register a policy for every binding in this flow contract.',
                    );
                    continue;
                }

                if ($policyRegistry instanceof OwnedBindingPolicyRegistryInterface && $binding->owner !== null) {
                    $policyOwner = $policyRegistry->ownerForBinding($bindingId);
                    if ($policyOwner !== null && !$binding->owner->equals($policyOwner)) {
                        $diagnostics[] = $this->error(
                            sprintf(
                                'Flow `%s` binding `%s` owner `%s:%s` does not match policy owner `%s:%s`.',
                                $binding->flow,
                                $bindingId,
                                $binding->owner->kind,
                                $binding->owner->id,
                                $policyOwner->kind,
                                $policyOwner->id,
                            ),
                            $origin,
                            $target,
                            'Register the policy from the same owner as the protected binding.',
                        );
                    }
                }
            }
        }

        return $diagnostics;
    }

    /** @return array<class-string<BindingPolicyRegistryInterface>, BindingPolicyRegistryInterface> */
    private function policyRegistries(): array
    {
        $indexed = [];
        foreach ($this->policyRegistries as $registry) {
            foreach (class_implements($registry) ?: [] as $interface) {
                if (is_a($interface, BindingPolicyRegistryInterface::class, true)) {
                    $indexed[$interface] = $registry;
                }
            }
            $indexed[$registry::class] = $registry;
        }

        return $indexed;
    }

    /**
     * @param list<class-string> $flowInterceptors
     * @return list<class-string<InterceptorInterface>>
     */
    private function interceptors(HandlerBindingDefinition $binding, array $flowInterceptors): array
    {
        /** @var list<class-string<InterceptorInterface>> */
        return [...$flowInterceptors, ...$binding->middleware];
    }

    private function isAutoBindingId(HandlerBindingDefinition $binding): bool
    {
        return $binding->bindingId === null || str_starts_with($binding->bindingId, 'auto.');
    }

    private function target(HandlerBindingDefinition $binding): RegistryDiagnosticTarget
    {
        return new RegistryDiagnosticTarget(
            bindingId: $binding->bindingId,
            messageClass: $binding->message,
            handlerClass: $binding->action,
            method: $binding->method,
            flow: $binding->flow,
        );
    }

    private function error(
        string $message,
        RegistryDiagnosticOrigin $origin,
        RegistryDiagnosticTarget $target,
        string $hint,
    ): RegistryDiagnostic {
        return RegistryDiagnostic::error(
            RegistryDiagnosticCodes::FLOW_CONTRACT_VIOLATION,
            $message,
            $origin,
            $target,
            $hint,
        );
    }
}
