<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Idempotency;

enum IdempotencyInterceptorRole: string
{
    case Guard = 'idempotency_guard';
}
