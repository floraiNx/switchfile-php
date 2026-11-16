<?php

declare(strict_types=1);

namespace Switchfile\Resources;

use Switchfile\Transport\HttpTransport;

/** /v1/archives: ZIP with the results of finished conversions. */
class ArchivesResource
{
    public function __construct(private readonly HttpTransport $http)
    {
    }

    /**
     * Up to 50 finished conversions. url is signed; download with $switchfile->download($archive).
     * $params: idempotency_key.
     *
     * @param list<string> $conversionIds
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function create(array $conversionIds, array $params = []): array
    {
        return $this->http->request('POST', '/v1/archives', json: ['conversions' => array_values($conversionIds)], idempotencyKey: (string) ($params['idempotency_key'] ?? Ids::uuid()));
    }
}
