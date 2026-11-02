<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Envelope;

interface EnvelopeSerializerInterface
{
    public function serialize(Envelope $envelope): SerializedEnvelope;

    public function deserialize(SerializedEnvelope $envelope): Envelope;
}
