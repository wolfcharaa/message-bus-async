<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Registry;

interface RegistryValidationRuleInterface
{
    /** @return iterable<mixed> */
    public function validate(RegistryCompilationGraphContext $context): iterable;
}
