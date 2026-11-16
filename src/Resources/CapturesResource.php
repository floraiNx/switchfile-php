<?php

declare(strict_types=1);

namespace Switchfile\Resources;

use Switchfile\Transport\HttpTransport;

/** /v1/captures: public web pages as PDF, PNG or JPG. If a capture fails, status and error say so. */
class CapturesResource
{
    public function __construct(private readonly HttpTransport $http, private readonly float $pollInterval)
    {
    }

    /**
     * $data: url, output_format (pdf, png, jpg), options (e.g. ['device' => 'mobile']), filename.
     * $params: wait (false), timeout (seconds, 1800), idempotency_key.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function create(array $data, array $params = []): array
    {
        $wait = ($params['wait'] ?? false) === true;
        $c = $this->http->request(
            'POST',
            '/v1/captures',
            ['wait' => $wait ? ConversionsResource::WAIT_SECONDS : null],
            json: $data,
            idempotencyKey: (string) ($params['idempotency_key'] ?? Ids::uuid()),
            timeout: $this->http->getTimeout() + ($wait ? ConversionsResource::WAIT_SECONDS : 0)
        );
        if (!$wait || in_array($c['status'] ?? null, ['finished', 'failed'], true)) {
            return $c;
        }
        return $this->wait($c['id'], (float) ($params['timeout'] ?? 1800));
    }

    /** @return array<string, mixed> */
    public function get(string $id, ?int $wait = null): array
    {
        $w = Ids::waitSeconds($wait);
        return $this->http->request('GET', '/v1/captures/' . rawurlencode($id), ['wait' => $w], timeout: $this->http->getTimeout() + ($w ?? 0));
    }

    /**
     * Waits until the capture is finished or failed. Does not throw on failed.
     *
     * @return array<string, mixed>
     */
    public function wait(string $id, ?float $timeout = null): array
    {
        return Poll::until(
            fn () => $this->get($id, ConversionsResource::WAIT_SECONDS),
            static fn (array $c) => in_array($c['status'] ?? null, ['finished', 'failed'], true),
            $timeout,
            $this->pollInterval
        );
    }

    public function delete(string $id): void
    {
        $this->http->request('DELETE', '/v1/captures/' . rawurlencode($id));
    }

    /**
     * The capture that POST /v1/captures created with this idempotency key, or null.
     *
     * @return array<string, mixed>|null
     */
    public function findByIdempotencyKey(string $key): ?array
    {
        return $this->http->request('GET', '/v1/captures', ['idempotency_key' => $key])['data'][0] ?? null;
    }
}
