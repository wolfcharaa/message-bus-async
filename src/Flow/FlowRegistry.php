<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Flow;

use BackedEnum;
use InvalidArgumentException;

final readonly class FlowRegistry
{
    /** @var array<string, FlowDefinition> */
    private array $flows;

    public function __construct(FlowDefinition ...$flows)
    {
        if ($flows === []) {
            $flows = [FlowDefinition::sync('default')];
        }

        $indexed = [];
        foreach ($flows as $flow) {
            $indexed[$flow->key] = $flow;
        }
        ksort($indexed, SORT_STRING);

        $this->flows = $indexed;
    }

    public function get(string|BackedEnum $key): FlowDefinition
    {
        $normalized = $key instanceof BackedEnum ? (string) $key->value : $key;

        return $this->flows[$normalized] ?? throw new InvalidArgumentException(sprintf(
            'Message flow "%s" is not registered.',
            $normalized,
        ));
    }

    /** @return array<string, FlowDefinition> */
    public function all(): array
    {
        return $this->flows;
    }

    /** @return array<string, array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(static fn (FlowDefinition $flow): array => $flow->toArray(), $this->flows);
    }

    /** @param array<string, array<string, mixed>> $data */
    public static function fromArray(array $data): self
    {
        $flows = [];
        foreach ($data as $flow) {
            $flows[] = FlowDefinition::fromArray($flow);
        }

        return new self(...$flows);
    }
}
