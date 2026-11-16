<?php

declare(strict_types=1);

namespace Switchfile\Models;

/** Own account (/v2/users/me). */
class User
{
    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    public function getId(): ?string
    {
        return $this->data['id'] ?? null;
    }

    public function getEmail(): ?string
    {
        return $this->data['email'] ?? null;
    }

    public function getCredits(): int
    {
        return (int) ($this->data['credits'] ?? 0);
    }

    public function isSandbox(): bool
    {
        return (bool) ($this->data['sandbox'] ?? false);
    }

    /** @return array<string, mixed> */
    public function getFreeQuota(): array
    {
        return $this->data['free_quota'] ?? [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
