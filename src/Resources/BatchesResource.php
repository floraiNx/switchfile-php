<?php

declare(strict_types=1);

namespace Switchfile\Resources;

use Switchfile\Transport\HttpTransport;

/** /v1/batches: up to 50 conversions in one request, all or none. Batches are associative arrays. */
class BatchesResource
{
    public function __construct(private readonly HttpTransport $http, private readonly float $pollInterval)
    {
    }

    /**
     * $data: conversions (list as for convert, each with url, upload_id or from), archive ('zip'), webhook_url.
     * $params: wait (false; true waits until the end), timeout (seconds, 1800), idempotency_key.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function create(array $data, array $params = []): array
    {
        $wait = ($params['wait'] ?? false) === true;
        $b = $this->http->request(
            'POST',
            '/v1/batches',
            ['wait' => $wait ? ConversionsResource::WAIT_SECONDS : null],
            json: $data,
            idempotencyKey: (string) ($params['idempotency_key'] ?? Ids::uuid()),
            timeout: $this->http->getTimeout() + ($wait ? ConversionsResource::WAIT_SECONDS : 0)
        );
        if (!$wait || ($b['status'] ?? null) === 'finished') {
            return $b;
        }
        return $this->wait($b['id'], (float) ($params['timeout'] ?? 1800));
    }

    /**
     * Read a batch. $wait: wait up to this many seconds for the end (at most 30).
     *
     * @return array<string, mixed>
     */
    public function get(string $id, ?int $wait = null): array
    {
        $w = Ids::waitSeconds($wait);
        return $this->http->request('GET', '/v1/batches/' . rawurlencode($id), ['wait' => $w], timeout: $this->http->getTimeout() + ($w ?? 0));
    }

    /**
     * Waits until all conversions are done (some may have failed, see counts).
     *
     * @return array<string, mixed>
     */
    public function wait(string $id, ?float $timeout = null): array
    {
        return Poll::until(
            fn () => $this->get($id, ConversionsResource::WAIT_SECONDS),
            static fn (array $b) => ($b['status'] ?? null) === 'finished',
            $timeout,
            $this->pollInterval
        );
    }

    /** Delete the batch with all its conversions immediately. */
    public function delete(string $id): void
    {
        $this->http->request('DELETE', '/v1/batches/' . rawurlencode($id));
    }
}
