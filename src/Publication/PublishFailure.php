<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Publication;

use InvalidArgumentException;
use Throwable;

final readonly class PublishFailure
{
    /**
     * @param class-string<Throwable> $errorClass
     */
    public function __construct(
        public string $bindingId,
        public PublishedExecutionMode $mode,
        public string $messageId,
        public string $correlationId,
        public string $errorClass,
        public string $errorMessage,
        public int $errorCode = 0,
    ) {
        foreach ([
            'Binding ID' => $this->bindingId,
            'Message ID' => $this->messageId,
            'Correlation ID' => $this->correlationId,
            'Error class' => $this->errorClass,
        ] as $name => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(sprintf('%s must be a non-empty string.', $name));
            }
        }
    }

    public static function fromThrowable(
        Throwable $error,
        string $bindingId,
        PublishedExecutionMode $mode,
        string $messageId,
        string $correlationId,
    ): self {
        return new self(
            bindingId: $bindingId,
            mode: $mode,
            messageId: $messageId,
            correlationId: $correlationId,
            errorClass: $error::class,
            errorMessage: $error->getMessage(),
            errorCode: $error->getCode(),
        );
    }
}
