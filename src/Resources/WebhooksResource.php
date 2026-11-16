<?php

declare(strict_types=1);

namespace Switchfile\Resources;

use Switchfile\Models\WebhookEvent;
use Switchfile\Transport\HttpTransport;
use Switchfile\WebhookHandler;

/** The account's webhook (/v2/webhooks) and signatures. An account has exactly one webhook. */
class WebhooksResource
{
    public function __construct(private readonly HttpTransport $http, private readonly WebhookHandler $handler)
    {
    }

    /**
     * Create the webhook: ['url' => …, 'events' => [...]]. If one exists already, the API answers with 409
     * WEBHOOK_EXISTS. signing_secret is only in this response.
     *
     * @param array{url: string, events: list<string>} $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        // No retry without an idempotency key: a second attempt after a network error would hit 409.
        return $this->http->request('POST', '/v2/webhooks', json: $data, retry: false)['data'];
    }

    /**
     * The account's webhook (at most one), without signing_secret.
     *
     * @param array<string, scalar> $query e.g. ['filter[url]' => 'https://…']
     * @return list<array<string, mixed>>
     */
    public function list(array $query = []): array
    {
        return $this->http->request('GET', '/v2/users/me/webhooks', $query)['data'];
    }

    public function delete(string $id): void
    {
        $this->http->request('DELETE', '/v2/webhooks/' . rawurlencode($id));
    }

    public function verify(string $payload, ?string $signature, string $signingSecret): bool
    {
        return $this->handler->verify($payload, $signature, $signingSecret);
    }

    public function constructEvent(string $payload, ?string $signature, string $signingSecret): WebhookEvent
    {
        return $this->handler->constructEvent($payload, $signature, $signingSecret);
    }
}
