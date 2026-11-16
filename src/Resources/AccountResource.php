<?php

declare(strict_types=1);

namespace Switchfile\Resources;

use Switchfile\Transport\HttpTransport;

/** /v1/account: credits, free allowance, webhook URL and signing secret. */
class AccountResource
{
    public function __construct(private readonly HttpTransport $http)
    {
    }

    /** @return array<string, mixed> */
    public function get(): array
    {
        return $this->http->request('GET', '/v1/account');
    }

    /**
     * Set the account's webhook URL (null removes it).
     *
     * @return array<string, mixed>
     */
    public function update(?string $webhookUrl): array
    {
        return $this->http->request('PATCH', '/v1/account', json: ['webhook_url' => $webhookUrl], retry: true);
    }

    /**
     * Turn "always without storage" on or off: with true every conversion of the account runs without storage,
     * requests that store end with STORAGE_DISABLED.
     *
     * @return array<string, mixed>
     */
    public function setNoStore(bool $noStore): array
    {
        return $this->http->request('PATCH', '/v1/account', json: ['no_store' => $noStore], retry: true);
    }

    /** @return array<string, mixed> balance by credit pool with expiry */
    public function credits(): array
    {
        return $this->http->request('GET', '/v1/account/credits');
    }

    /** @return array<string, mixed> transactions and usage per day */
    public function transactions(): array
    {
        return $this->http->request('GET', '/v1/account/transactions');
    }

    /** @return array<string, mixed> monthly plan from the website (plan is null without one) */
    public function plan(): array
    {
        return $this->http->request('GET', '/v1/account/plan');
    }
}
