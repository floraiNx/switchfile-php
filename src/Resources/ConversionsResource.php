<?php

declare(strict_types=1);

namespace Switchfile\Resources;

use Switchfile\Exceptions\ConversionFailedException;
use Switchfile\Transport\HttpTransport;

/** /v1/conversions. Conversions are associative arrays as in the API docs. */
class ConversionsResource
{
    public const WAIT_SECONDS = 30;

    public function __construct(private readonly HttpTransport $http, private readonly float $pollInterval)
    {
    }

    /**
     * Read a conversion. $wait: wait up to this many seconds for the end (at most 30).
     *
     * @return array<string, mixed>
     */
    public function get(string $id, ?int $wait = null): array
    {
        $w = $wait !== null && $wait > 0 ? min(self::WAIT_SECONDS, $wait) : null;
        return $this->http->request('GET', '/v1/conversions/' . rawurlencode($id), ['wait' => $w], timeout: $this->http->getTimeout() + ($w ?? 0));
    }

    /**
     * Waits until the conversion is done. Throws ConversionFailedException if it fails.
     *
     * @return array<string, mixed>
     */
    public function wait(string $id, ?float $timeout = null): array
    {
        $c = Poll::until(
            fn () => $this->get($id, self::WAIT_SECONDS),
            static fn (array $c) => in_array($c['status'] ?? null, ['finished', 'failed'], true),
            $timeout,
            $this->pollInterval
        );
        return self::assertFinished($c);
    }

    /**
     * Up to 50 conversions; unknown or expired IDs are missing from the response.
     *
     * @param list<string> $ids
     * @return list<array<string, mixed>>
     */
    public function list(array $ids): array
    {
        return $this->http->request('GET', '/v1/conversions', ['ids' => implode(',', $ids)])['data'];
    }

    /** @return array<string, mixed>|null the conversion that POST /v1/convert created with this idempotency key */
    public function findByIdempotencyKey(string $key): ?array
    {
        return $this->http->request('GET', '/v1/conversions', ['idempotency_key' => $key])['data'][0] ?? null;
    }

    /** Delete input and result immediately. */
    public function delete(string $id): void
    {
        $this->http->request('DELETE', '/v1/conversions/' . rawurlencode($id));
    }

    /**
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    public static function assertFinished(array $c): array
    {
        if (($c['status'] ?? null) === 'failed') {
            $err = is_array($c['error'] ?? null) ? $c['error'] : [];
            throw new ConversionFailedException($err['message'] ?? 'The conversion failed.', $err['code'] ?? 'CONVERSION_FAILED', $c);
        }
        return $c;
    }
}
