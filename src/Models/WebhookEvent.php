<?php

declare(strict_types=1);

namespace Switchfile\Models;

/**
 * Verified webhook event. /v2: job.finished, job.failed with getJob(). /v1: conversion.finished,
 * conversion.failed with getConversion().
 */
class WebhookEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(private readonly array $payload)
    {
    }

    public function getEvent(): string
    {
        return (string) $this->payload['event'];
    }

    public function getJob(): ?Job
    {
        return isset($this->payload['job']) && is_array($this->payload['job']) ? Job::fromArray($this->payload['job']) : null;
    }

    /** @return array<string, mixed>|null */
    public function getConversion(): ?array
    {
        return isset($this->payload['data']) && is_array($this->payload['data']) ? $this->payload['data'] : null;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }
}
