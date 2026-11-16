<?php

declare(strict_types=1);

namespace Switchfile;

use Switchfile\Exceptions\SwitchfileException;
use Switchfile\Resources\AccessibilityChecksResource;
use Switchfile\Resources\AccountResource;
use Switchfile\Resources\ArchivesResource;
use Switchfile\Resources\BatchesResource;
use Switchfile\Resources\CapturesResource;
use Switchfile\Resources\ConversionsResource;
use Switchfile\Resources\Ids;
use Switchfile\Resources\JobsResource;
use Switchfile\Resources\TasksResource;
use Switchfile\Resources\UploadsResource;
use Switchfile\Resources\UsersResource;
use Switchfile\Resources\WebhooksResource;
use Switchfile\Transport\FileSource;
use Switchfile\Transport\HttpTransport;
use Switchfile\Transport\Stream;

/**
 * Entry point, like new \CloudConvert\CloudConvert(['api_key' => …, 'sandbox' => …]).
 *
 * Options: api_key (default SWITCHFILE_API_KEY), sandbox, base_url (default SWITCHFILE_API_URL or
 * https://api.switchfile.io), sandbox_base_url, timeout (seconds per request, 60), max_retries (3), poll_interval
 * (seconds, 1.0), user_agent (suffix), region (compatibility only, no effect).
 */
class Switchfile
{
    public const VERSION = '0.3.0';
    public const DEFAULT_BASE_URL = 'https://api.switchfile.io';
    /** Files up to this size are sent in the request (multipart), larger ones via POST /v1/uploads. */
    public const MULTIPART_LIMIT = 50 * 1024 * 1024;
    /** Largest file without storage (the API rejects larger ones with FILE_TOO_LARGE). */
    public const NO_STORE_LIMIT = 50 * 1024 * 1024;
    /**
     * Timeout of a request without storage (seconds): as long as the API allows it (upload 120 s, conversion
     * 90 s, sending the response 180 s). A longer client timeout takes precedence.
     */
    public const NO_STORE_TIMEOUT = 390;

    private HttpTransport $http;
    private JobsResource $jobs;
    private TasksResource $tasks;
    private UsersResource $users;
    private ConversionsResource $conversions;
    private AccountResource $account;
    private BatchesResource $batches;
    private ArchivesResource $archives;
    private CapturesResource $captures;
    private AccessibilityChecksResource $accessibilityChecks;
    private UploadsResource $uploads;
    private WebhooksResource $webhooks;
    private WebhookHandler $webhookHandler;
    private bool $sandbox;

    /** @param array<string, mixed> $options */
    public function __construct(array $options = [])
    {
        $apiKey = trim((string) ($options['api_key'] ?? (getenv('SWITCHFILE_API_KEY') ?: '')));
        $this->sandbox = (bool) ($options['sandbox'] ?? false);
        $base = (string) ($options['base_url'] ?? (getenv('SWITCHFILE_API_URL') ?: self::DEFAULT_BASE_URL));
        if ($this->sandbox && isset($options['sandbox_base_url'])) {
            $base = (string) $options['sandbox_base_url'];
        }
        if (!preg_match('#^https?://[^/\s]+#i', $base)) {
            throw new SwitchfileException("Invalid base URL: {$base}", 'INVALID_BASE_URL');
        }
        if ($this->sandbox && preg_match('/^(sf_live_|ac_live_)/', $apiKey)) {
            throw new SwitchfileException('Sandbox mode needs a sandbox key (sf_test_...). Create one in the dashboard under API keys.', 'SANDBOX_KEY_REQUIRED');
        }
        $ua = 'switchfile-php/' . self::VERSION . (isset($options['user_agent']) ? ' ' . $options['user_agent'] : '');
        $this->http = new HttpTransport($apiKey, rtrim($base, '/'), (float) ($options['timeout'] ?? 60), (int) ($options['max_retries'] ?? 3), $ua);
        $poll = (float) ($options['poll_interval'] ?? 1.0);
        $this->jobs = new JobsResource($this->http, $poll);
        $this->tasks = new TasksResource($this->http, $poll);
        $this->users = new UsersResource($this->http);
        $this->conversions = new ConversionsResource($this->http, $poll);
        $this->account = new AccountResource($this->http);
        $this->webhookHandler = new WebhookHandler();
        $this->batches = new BatchesResource($this->http, $poll);
        $this->archives = new ArchivesResource($this->http);
        $this->captures = new CapturesResource($this->http, $poll);
        $this->accessibilityChecks = new AccessibilityChecksResource($this->http, $poll);
        $this->uploads = new UploadsResource($this->http);
        $this->webhooks = new WebhooksResource($this->http, $this->webhookHandler);
    }

    public function jobs(): JobsResource
    {
        return $this->jobs;
    }

    public function tasks(): TasksResource
    {
        return $this->tasks;
    }

    public function users(): UsersResource
    {
        return $this->users;
    }

    public function webhookHandler(): WebhookHandler
    {
        return $this->webhookHandler;
    }

    /** /v2/webhooks: create, list, delete and verify as in the other SDKs. */
    public function webhooks(): WebhooksResource
    {
        return $this->webhooks;
    }

    /** For downloads: getHttpTransport()->download($file->url)->detach() as in the CloudConvert SDK. */
    public function getHttpTransport(): HttpTransport
    {
        return $this->http;
    }

    /** /v1: read, wait for and delete conversions. */
    public function conversions(): ConversionsResource
    {
        return $this->conversions;
    }

    /** /v1: account and webhook URL. */
    public function account(): AccountResource
    {
        return $this->account;
    }

    /** /v1: up to 50 conversions in one request. */
    public function batches(): BatchesResource
    {
        return $this->batches;
    }

    /** /v1: ZIP with the results of finished conversions. */
    public function archives(): ArchivesResource
    {
        return $this->archives;
    }

    /** /v1: web pages as PDF, PNG or JPG. */
    public function captures(): CapturesResource
    {
        return $this->captures;
    }

    /** /v1: check PDFs against PDF/UA. */
    public function accessibilityChecks(): AccessibilityChecksResource
    {
        return $this->accessibilityChecks;
    }

    /** /v1: upload and delete files. */
    public function uploads(): UploadsResource
    {
        return $this->uploads;
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    /**
     * Convert a file (/v1) and (by default) wait for the result.
     *
     * $file: path, stream resource, \SplFileInfo, an http(s) URL as string, ['url' => …], ['upload_id' => …],
     * ['from' => conversion_id] or ['content' => bytes, 'filename' => …].
     * $params: filename, input_format, webhook_url, preset (ID or name of a preset from the dashboard; sets the
     * target format and settings, $to may then be empty), wait (true), timeout (seconds, 1800), idempotency_key,
     * multipart_limit.
     *
     * @param array<string, scalar> $options conversion settings, e.g. ['quality' => 80]
     * @param array<string, mixed> $params
     * @return array<string, mixed> the conversion
     * @throws Exceptions\ConversionFailedException if the conversion fails
     */
    public function convert(mixed $file, ?string $to, array $options = [], array $params = []): array
    {
        $to = (string) $to;
        $preset = isset($params['preset']) ? (string) $params['preset'] : '';
        if ($to === '' && $preset === '') {
            throw new SwitchfileException('The target format (to) is missing. Pass a format or a preset.', 'INVALID_DATA');
        }
        $key = (string) ($params['idempotency_key'] ?? self::uuid());
        $wait = ($params['wait'] ?? true) !== false;
        $query = ['wait' => $wait ? ConversionsResource::WAIT_SECONDS : null];
        $timeout = $this->http->getTimeout() + ($wait ? ConversionsResource::WAIT_SECONDS : 0);
        $filename = isset($params['filename']) ? (string) $params['filename'] : null;
        $fields = $to !== '' ? ['to' => $to] : [];
        if ($options) {
            $fields['options'] = $options;
        }
        foreach (['input_format', 'webhook_url', 'preset'] as $k) {
            if (isset($params[$k])) {
                $fields[$k] = (string) $params[$k];
            }
        }

        $json = self::jsonSource($file);
        if ($json !== null) {
            $body = $json + $fields + ($filename !== null ? ['filename' => $filename] : []);
            $conv = $this->http->request('POST', '/v1/convert', $query, json: $body, idempotencyKey: $key, timeout: $timeout);
        } else {
            $source = FileSource::fromConvertArgument($file, $filename);
            try {
                $name = $source->filename();
                if ($source->size() <= (int) ($params['multipart_limit'] ?? self::MULTIPART_LIMIT)) {
                    $form = $to !== '' ? ['to' => $to] : [];
                    if ($options) {
                        $form['options'] = json_encode($options, JSON_THROW_ON_ERROR);
                    }
                    foreach (['input_format', 'webhook_url', 'preset'] as $k) {
                        if (isset($fields[$k])) {
                            $form[$k] = $fields[$k];
                        }
                    }
                    if ($name !== null) {
                        $form['filename'] = $name;
                    }
                    $conv = $this->http->request('POST', '/v1/convert', $query, multipart: static fn () => $form + ['file' => $source->curlFile()], idempotencyKey: $key, timeout: max($timeout, 1800));
                } else {
                    $up = $this->http->request('POST', '/v1/uploads', json: ($name !== null ? ['filename' => $name] : []) + ['size' => $source->size()], idempotencyKey: $key . ':upload');
                    $this->http->sendSigned('PUT', $up['url'], $source->openStream(), FileSource::putHeaders((array) ($up['headers'] ?? [])), $source->size());
                    $body = ['upload_id' => $up['upload_id']] + $fields + ($name !== null ? ['filename' => $name] : []);
                    $conv = $this->http->request('POST', '/v1/convert', $query, json: $body, idempotencyKey: $key, timeout: $timeout);
                }
            } finally {
                $source->cleanup();
            }
        }
        if (!$wait) {
            return $conv;
        }
        if (in_array($conv['status'] ?? null, ['finished', 'failed'], true)) {
            return ConversionsResource::assertFinished($conv);
        }
        return $this->conversions->wait($conv['id'], (float) ($params['timeout'] ?? 1800));
    }

    /**
     * Convert a file without storage (POST /v1/convert?store=false): file in the request, result in the response,
     * neither input nor result stays with switchfile. No idempotency key, because the API does not keep the result;
     * a retry only happens if it has converted nothing.
     *
     * $file: path, stream resource, \SplFileInfo or ['content' => bytes, 'filename' => …], up to 50 MB.
     * $params: filename, input_format, preset, timeout (seconds; default NO_STORE_TIMEOUT or the client's, if longer).
     *
     * @param array<string, scalar> $options conversion settings, e.g. ['quality' => 80]
     * @param array<string, mixed> $params
     * @return array{data: string, filename: ?string, content_type: string, conversion_id: ?string, credits: ?int, notes: list<string>, incomplete: bool}
     *   data is the file; multiple results come as one ZIP. conversion_id is the entry in the usage history.
     *   notes are the hints from the X-Notes header (e.g. which pages are missing), incomplete says whether it is a
     *   partial result (X-Incomplete header).
     */
    public function convertWithoutStorage(mixed $file, ?string $to, array $options = [], array $params = []): array
    {
        $to = (string) $to;
        $preset = isset($params['preset']) ? (string) $params['preset'] : '';
        if ($to === '' && $preset === '') {
            throw new SwitchfileException('The target format (to) is missing. Pass a format or a preset.', 'INVALID_DATA');
        }
        if ((is_string($file) && preg_match('#^https?://#i', $file)) || (is_array($file) && !array_key_exists('content', $file))) {
            throw new SwitchfileException('Without storage, send the file itself. A URL or an ID needs storage.', 'INVALID_DATA');
        }
        $filename = isset($params['filename']) ? (string) $params['filename'] : null;
        $source = FileSource::fromConvertArgument($file, $filename);
        try {
            if ($source->size() > self::NO_STORE_LIMIT) {
                throw new SwitchfileException('Without storage, files can be at most ' . (self::NO_STORE_LIMIT / 1024 / 1024) . ' MB.', 'FILE_TOO_LARGE');
            }
            $form = $to !== '' ? ['to' => $to] : [];
            if ($preset !== '') {
                $form['preset'] = $preset;
            }
            if ($options) {
                $form['options'] = json_encode($options, JSON_THROW_ON_ERROR);
            }
            if (isset($params['input_format'])) {
                $form['input_format'] = (string) $params['input_format'];
            }
            $name = $source->filename();
            if ($name !== null) {
                $form['filename'] = $name;
            }
            $res = $this->http->request(
                'POST',
                '/v1/convert',
                ['store' => 'false'],
                multipart: static fn () => $form + ['file' => $source->curlFile()],
                timeout: (float) ($params['timeout'] ?? max($this->http->getTimeout(), self::NO_STORE_TIMEOUT)),
                headers: ['Switchfile-Store: false'],
                binary: true
            );
        } finally {
            $source->cleanup();
        }
        $h = $res['headers'];
        return [
            'data' => $res['body'],
            'filename' => self::filenameOf($h['content-disposition'] ?? null),
            'content_type' => $h['content-type'] ?? 'application/octet-stream',
            'conversion_id' => $h['x-conversion-id'] ?? null,
            'credits' => isset($h['x-credits']) && ctype_digit($h['x-credits']) ? (int) $h['x-credits'] : null,
            'notes' => self::notesOf($h['x-notes'] ?? null),
            'incomplete' => ($h['x-incomplete'] ?? null) === '1',
        ];
    }

    /**
     * X-Notes header (JSON list, ASCII only) as a list; empty if missing or unreadable.
     *
     * @return list<string>
     */
    private static function notesOf(?string $header): array
    {
        $notes = $header === null ? null : json_decode($header, true);
        return is_array($notes) && array_is_list($notes) ? array_values(array_filter($notes, 'is_string')) : [];
    }

    /** File name from Content-Disposition (filename* before filename). */
    private static function filenameOf(?string $header): ?string
    {
        if ($header === null || $header === '') {
            return null;
        }
        if (preg_match("/filename\\*=UTF-8''([^;]+)/i", $header, $m)) {
            $name = rawurldecode(trim($m[1]));
            if (preg_match('//u', $name) === 1) {
                return $name;
            }
        }
        if (preg_match('/filename="([^"]*)"/i', $header, $m) || preg_match('/filename=([^;]+)/i', $header, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * Cost of a conversion (POST /v1/convert?dry_run=1): credits, price and whether it fits the free allowance.
     * Creates no conversion. $file as for convert(); $params: filename, input_format, preset. The estimate only reads
     * the file name or input_format, so only the first byte of a file is sent.
     *
     * @param array<string, scalar> $options
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function estimate(mixed $file, ?string $to, array $options = [], array $params = []): array
    {
        $to = (string) $to;
        if ($to === '' && !isset($params['preset'])) {
            throw new SwitchfileException('The target format (to) is missing. Pass a format or a preset.', 'INVALID_DATA');
        }
        $fields = $to !== '' ? ['to' => $to] : [];
        if ($options) {
            $fields['options'] = $options;
        }
        foreach (['input_format', 'preset'] as $k) {
            if (isset($params[$k])) {
                $fields[$k] = (string) $params[$k];
            }
        }
        $filename = isset($params['filename']) ? (string) $params['filename'] : null;
        $json = self::jsonSource($file);
        if ($json !== null) {
            return $this->http->request('POST', '/v1/convert', ['dry_run' => 1], json: $json + $fields + ($filename !== null ? ['filename' => $filename] : []), retry: true);
        }
        [$head, $name] = FileSource::head($file, $filename);
        $form = $fields;
        if ($options) {
            $form['options'] = json_encode($options, JSON_THROW_ON_ERROR);
        }
        if ($name !== null) {
            $form['filename'] = $name;
        }
        $form['file'] = new \CURLStringFile($head, $name ?? 'file', 'application/octet-stream');
        return $this->http->request('POST', '/v1/convert', ['dry_run' => 1], multipart: static fn () => $form, retry: true);
    }

    /**
     * All pairs of input and output format with minimum credits (GET /v2/convert/formats, no key).
     *
     * @param array<string, scalar> $query e.g. ['filter[input_format]' => 'png', 'include' => 'options']
     * @return list<array<string, mixed>>
     */
    public function convertFormats(array $query = []): array
    {
        return $this->http->request('GET', '/v2/convert/formats', $query, auth: false)['data'];
    }

    /**
     * API status (no key): ['status' => 'ok'] as long as API and database are reachable.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return $this->http->request('GET', '/health', auth: false);
    }

    /**
     * All formats with targets and suggestions (no key).
     *
     * @return array<string, mixed>
     */
    public function formats(?string $input = null): array
    {
        return $this->http->request('GET', '/v1/formats', ['input' => $input], auth: false);
    }

    /**
     * Download a result: URL, file from result.files (array or object), finished conversion (first file) or finished
     * website capture. With $destination the content is written to that file (returns the path), otherwise it is
     * returned as a string.
     *
     * @param string|array<string, mixed>|object $source
     */
    public function download(mixed $source, ?string $destination = null): string
    {
        $url = is_string($source) ? $source : null;
        $isCapture = is_array($source) ? ($source['object'] ?? null) === 'website_capture' : is_object($source) && ($source->object ?? null) === 'website_capture';
        if ($isCapture) {
            // url is the captured web page here, not the result.
            $url = is_array($source) ? ($source['result']['file']['url'] ?? null) : ($source->result->file->url ?? null);
            if (!is_string($url) || $url === '') {
                throw new SwitchfileException('Nothing to download: the capture has no result file yet.', 'NO_FILE');
            }
        } elseif (is_array($source)) {
            $url = $source['download_url'] ?? $source['url'] ?? null;
        } elseif (is_object($source)) {
            $url = $source->url ?? null;
        }
        if (!is_string($url) || $url === '') {
            throw new SwitchfileException('Nothing to download: the conversion has no result file yet.', 'NO_FILE');
        }
        $res = $this->http->download($url, $destination);
        if ($res instanceof Stream) {
            $data = $res->getContents();
            $res->close();
            return $data;
        }
        return $res;
    }

    /** @return array<string, string>|null */
    private static function jsonSource(mixed $file): ?array
    {
        if (is_string($file) && preg_match('#^https?://#i', $file)) {
            return ['url' => $file];
        }
        if (is_array($file) && !array_key_exists('content', $file)) {
            foreach (['url', 'upload_id', 'from'] as $k) {
                if (isset($file[$k]) && is_string($file[$k])) {
                    return [$k => $file[$k]];
                }
            }
            throw new SwitchfileException("Pass ['url' => ...], ['upload_id' => ...], ['from' => ...] or ['content' => ...].", 'INVALID_SOURCE');
        }
        return null;
    }

    private static function uuid(): string
    {
        return Ids::uuid();
    }
}
