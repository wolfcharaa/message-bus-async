<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Idempotency;

interface IntentFingerprintFactoryInterface
{
    public function fingerprint(object $message): IntentFingerprint;
}
