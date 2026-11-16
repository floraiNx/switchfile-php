<?php

declare(strict_types=1);

namespace Switchfile\Exceptions;

/**
 * Base of all SDK errors. getErrorCode() returns the stable code of the API (e.g. INVALID_DATA,
 * UNAUTHENTICATED, NOT_FOUND, RATE_LIMIT_EXCEEDED) or of the client (NETWORK_ERROR, TIMEOUT).
 */
class SwitchfileException extends \Exception
{
    /** @param array<string, list<string>>|null $errors */
    public function __construct(
        string $message,
        private readonly string $errorCode = 'ERROR',
        private readonly ?int $status = null,
        private readonly ?array $errors = null,
        private readonly ?float $retryAfter = null,
        private readonly mixed $body = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** HTTP status of the response or null (network error). */
    public function getStatus(): ?int
    {
        return $this->status;
    }

    /** @return array<string, list<string>>|null errors per field for INVALID_DATA */
    public function getErrors(): ?array
    {
        return $this->errors;
    }

    public function getRetryAfter(): ?float
    {
        return $this->retryAfter;
    }

    public function getBody(): mixed
    {
        return $this->body;
    }

    /** For batches and archives: index of the entry in conversions that fails. */
    public function getIndex(): ?int
    {
        return is_array($this->body) && is_int($this->body['index'] ?? null) ? $this->body['index'] : null;
    }
}
