<?php

declare(strict_types=1);

namespace Switchfile\Resources;

use Switchfile\Transport\FileSource;
use Switchfile\Transport\HttpTransport;

/** /v1/uploads: upload a file; upload_id works for convert(['upload_id' => …]), batches and checks. */
class UploadsResource
{
    public function __construct(private readonly HttpTransport $http)
    {
    }

    /**
     * POST /v1/uploads, then PUT to the signed URL. $file as for convert(). $params: filename, idempotency_key.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed> the upload object with upload_id
     */
    public function create(mixed $file, array $params = []): array
    {
        $source = FileSource::fromConvertArgument($file, isset($params['filename']) ? (string) $params['filename'] : null);
        try {
            $name = $source->filename();
            $up = $this->http->request('POST', '/v1/uploads', json: ($name !== null ? ['filename' => $name] : []) + ['size' => $source->size()], idempotencyKey: (string) ($params['idempotency_key'] ?? Ids::uuid()));
            $this->http->sendSigned('PUT', $up['url'], $source->openStream(), FileSource::putHeaders((array) ($up['headers'] ?? [])), $source->size());
            return $up;
        } finally {
            $source->cleanup();
        }
    }

    /** Delete the uploaded file immediately; conversions already created remain. */
    public function delete(string $id): void
    {
        $this->http->request('DELETE', '/v1/uploads/' . rawurlencode($id));
    }
}
