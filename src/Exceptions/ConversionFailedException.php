<?php

declare(strict_types=1);

namespace Switchfile\Exceptions;

/** A conversion (/v1) failed. getConversion() returns the whole object. */
class ConversionFailedException extends SwitchfileException
{
    /** @param array<string, mixed> $conversion */
    public function __construct(string $message, string $errorCode, private readonly array $conversion)
    {
        parent::__construct($message, $errorCode, null, null, null, $conversion);
    }

    /** @return array<string, mixed> */
    public function getConversion(): array
    {
        return $this->conversion;
    }
}
