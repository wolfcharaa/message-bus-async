<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Codec;

use Fedor\MessageBus\Registry\RegistryCompilationGraphContext;
use Fedor\MessageBus\Registry\RegistryDiagnostic;
use Fedor\MessageBus\Registry\RegistryDiagnosticCodes;
use Fedor\MessageBus\Registry\RegistryDiagnosticOrigin;
use Fedor\MessageBus\Registry\RegistryDiagnosticTarget;
use Fedor\MessageBus\Registry\RegistryValidationRuleInterface;

final readonly class MessageCodecRegistryValidationRule implements RegistryValidationRuleInterface
{
    public function __construct(private MessageCodecRegistry $codecs)
    {
    }

    public function validate(RegistryCompilationGraphContext $context): iterable
    {
        $flows = $context->flows?->all() ?? [];
        foreach ($context->bindings as $binding) {
            if (!(($flows[$binding->flow] ?? null)?->isAsync() ?? false)) {
                continue;
            }

            $alias = $context->messageNames[$binding->message] ?? null;
            if ($alias === null) {
                continue;
            }

            $target = new RegistryDiagnosticTarget(
                bindingId: $binding->bindingId,
                messageName: $alias,
                messageClass: $binding->message,
                handlerClass: $binding->action,
                method: $binding->method,
                flow: $binding->flow,
                alias: $alias,
            );
            $origin = RegistryDiagnosticOrigin::compiler('message codecs', self::class);

            if (!$this->codecs->hasAlias($alias)) {
                yield RegistryDiagnostic::error(
                    RegistryDiagnosticCodes::CODEC_MISSING,
                    sprintf('Async message "%s" has no codec for alias "%s".', $binding->message, $alias),
                    $origin,
                    $target,
                    'Register one explicit versioned codec for every durable message alias.',
                );

                continue;
            }

            $codecMessage = $this->codecs->messageClassForAlias($alias);
            if ($codecMessage !== $binding->message) {
                yield RegistryDiagnostic::error(
                    RegistryDiagnosticCodes::CODEC_MESSAGE_MISMATCH,
                    sprintf(
                        'Codec alias "%s" maps to "%s" instead of "%s".',
                        $alias,
                        $codecMessage,
                        $binding->message,
                    ),
                    $origin,
                    $target,
                    'Make the registry alias and codec message class describe the same durable contract.',
                );
            }
        }
    }
}
