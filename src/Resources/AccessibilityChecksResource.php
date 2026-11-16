<?php

declare(strict_types=1);

namespace Switchfile\Resources;

use Switchfile\Exceptions\SwitchfileException;
use Switchfile\Transport\FileSource;
use Switchfile\Transport\HttpTransport;

/** /v1/accessibility/checks: check PDFs against the machine-checkable criteria of PDF/UA. */
class AccessibilityChecksResource
{
    public function __construct(private readonly HttpTransport $http, private readonly float $pollInterval)
    {
    }

    /**
     * $file: path, stream resource, \SplFileInfo, ['content' => bytes, 'filename' => …] (up to 25 MB), an http(s) URL
     * as string, ['url' => …] or ['upload_id' => …].
     * $params: profile (auto, ua1, ua2), report_pdf ('en'), filename, wait (false), timeout (seconds, 1800),
     * idempotency_key.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function create(mixed $file, array $params = []): array
    {
        $wait = ($params['wait'] ?? false) === true;
        $query = ['wait' => $wait ? ConversionsResource::WAIT_SECONDS : null];
        $key = (string) ($params['idempotency_key'] ?? Ids::uuid());
        $timeout = $this->http->getTimeout() + ($wait ? ConversionsResource::WAIT_SECONDS : 0);
        $fields = [];
        foreach (['profile', 'report_pdf'] as $k) {
            if (isset($params[$k])) {
                $fields[$k] = (string) $params[$k];
            }
        }
        $filename = isset($params['filename']) ? (string) $params['filename'] : null;

        if (is_string($file) && preg_match('#^https?://#i', $file)) {
            $file = ['url' => $file];
        }
        if (is_array($file) && !array_key_exists('content', $file)) {
            $src = isset($file['url']) ? ['url' => (string) $file['url']] : (isset($file['upload_id']) ? ['upload_id' => (string) $file['upload_id']] : null);
            if ($src === null) {
                throw new SwitchfileException("Pass a file, ['url' => ...] or ['upload_id' => ...].", 'INVALID_DATA');
            }
            $body = $src + $fields + ($filename !== null ? ['filename' => $filename] : []);
            $c = $this->http->request('POST', '/v1/accessibility/checks', $query, json: $body, idempotencyKey: $key, timeout: $timeout);
        } else {
            $source = FileSource::fromConvertArgument($file, $filename);
            try {
                $form = $fields;
                if ($source->filename() !== null) {
                    $form['filename'] = $source->filename();
                }
                $c = $this->http->request('POST', '/v1/accessibility/checks', $query, multipart: static fn () => $form + ['file' => $source->curlFile()], idempotencyKey: $key, timeout: max($timeout, 1800));
            } finally {
                $source->cleanup();
            }
        }
        if (!$wait || in_array($c['status'] ?? null, ['finished', 'failed'], true)) {
            return $c;
        }
        return $this->wait($c['id'], (float) ($params['timeout'] ?? 1800));
    }

    /** @return array<string, mixed> */
    public function get(string $id, ?int $wait = null): array
    {
        $w = Ids::waitSeconds($wait);
        return $this->http->request('GET', '/v1/accessibility/checks/' . rawurlencode($id), ['wait' => $w], timeout: $this->http->getTimeout() + ($w ?? 0));
    }

    /**
     * Waits until the check is finished or failed. Does not throw on failed.
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

    /** Delete PDF and report immediately (a running check stops). */
    public function delete(string $id): void
    {
        $this->http->request('DELETE', '/v1/accessibility/checks/' . rawurlencode($id));
    }

    /** @return array<string, mixed>|null */
    public function findByIdempotencyKey(string $key): ?array
    {
        return $this->http->request('GET', '/v1/accessibility/checks', ['idempotency_key' => $key])['data'][0] ?? null;
    }
}
