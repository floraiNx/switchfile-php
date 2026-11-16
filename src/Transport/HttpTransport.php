<?php

declare(strict_types=1);

namespace Switchfile\Transport;

use Switchfile\Exceptions\HttpClientException;
use Switchfile\Exceptions\HttpServerException;
use Switchfile\Exceptions\SwitchfileException;

/**
 * HTTP with ext-curl, no other dependencies. The API key is only sent to the API base URL, never to signed
 * upload and download URLs. Files are read from disk while sending, not loaded into memory first.
 */
class HttpTransport
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly float $timeout,
        private readonly int $maxRetries,
        private readonly string $userAgent
    ) {
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getTimeout(): float
    {
        return $this->timeout;
    }

    /**
     * JSON request to the API. Returns the decoded body (arrays, null on 204).
     *
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null $json
     * @param (callable(): array<string, mixed>)|null $multipart returns the fields (CURLFile for files) per attempt
     * @param list<string> $headers additional headers, e.g. 'Switchfile-Store: false'
     * @param bool $binary success response as ['body' => bytes, 'headers' => [...]] instead of JSON, e.g. the file of a
     *   conversion without storage
     * @return mixed
     */
    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $json = null,
        ?callable $multipart = null,
        bool $auth = true,
        ?string $idempotencyKey = null,
        ?float $timeout = null,
        ?bool $retry = null,
        array $headers = [],
        bool $binary = false
    ): mixed {
        $url = $this->baseUrl . $path;
        $query = array_filter($query, static fn ($v) => $v !== null);
        if ($query) {
            $url .= '?' . http_build_query(array_map(static fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : $v, $query));
        }
        $headers = array_merge(['Accept: application/json', 'User-Agent: ' . $this->userAgent], $headers);
        if ($auth) {
            if ($this->apiKey === '') {
                throw new SwitchfileException('No API key. Pass api_key or set SWITCHFILE_API_KEY.', 'MISSING_API_KEY');
            }
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        $retryServer = $retry ?? (in_array($method, ['GET', 'DELETE'], true) || $idempotencyKey !== null);

        for ($attempt = 0; ; $attempt++) {
            $h = $headers;
            $body = null;
            if ($json !== null) {
                $h[] = 'Content-Type: application/json';
                $body = json_encode($json === [] ? new \stdClass() : $json, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } elseif ($multipart !== null) {
                $body = $multipart();
            }
            try {
                [$status, $raw, $respHeaders] = $this->exec($method, $url, $h, $body, $timeout ?? $this->timeout, false);
            } catch (SwitchfileException $e) {
                if ($retryServer && $attempt < $this->maxRetries) {
                    usleep(self::backoff($attempt));
                    continue;
                }
                throw $e;
            }
            if ($status >= 300 && $status < 400) {
                // Never follow redirects and never treat them as success: usually the base URL is wrong.
                throw self::redirectError($status, $respHeaders['location'] ?? null, $url);
            }
            if ($binary && $status >= 200 && $status < 300) {
                return ['body' => $raw, 'headers' => $respHeaders];
            }
            $data = self::decode($raw);
            if ($status >= 200 && $status < 300) {
                return $status === 204 ? null : $data;
            }
            $err = self::errorFrom($status, $data, $respHeaders['retry-after'] ?? null);
            $again = ($status === 429 && $err->getErrorCode() === 'RATE_LIMIT_EXCEEDED')
                || ($status === 409 && $err->getErrorCode() === 'IDEMPOTENCY_KEY_IN_USE')
                // Without storage: busy or occupied, the API has converted nothing and charged nothing.
                || ($status === 429 && $err->getErrorCode() === 'CONCURRENCY_LIMIT')
                || ($status === 503 && in_array($err->getErrorCode(), ['SERVER_BUSY', 'NO_STORE_UNAVAILABLE'], true))
                || ($retryServer && in_array($status, [500, 502, 503, 504], true));
            if ($again && $attempt < $this->maxRetries) {
                $wait = $err->getRetryAfter() !== null ? (int) ($err->getRetryAfter() * 1_000_000) : self::backoff($attempt);
                if ($wait <= 60_000_000) {
                    usleep($wait);
                    continue;
                }
            }
            throw $err;
        }
    }

    /**
     * Upload to a signed URL (no key). $body: fields for multipart (POST) or a stream resource for PUT.
     *
     * @param array<string, mixed>|resource $body
     * @param list<string> $headers
     * @return mixed decoded JSON body or null
     */
    public function sendSigned(string $method, string $url, mixed $body, array $headers = [], ?int $size = null): mixed
    {
        $target = $this->resolveSigned($url);
        $h = array_merge(['User-Agent: ' . $this->userAgent], $headers);
        [$status, $raw, $respHeaders] = $this->exec($method, $target, $h, $body, max($this->timeout, 3600), false, $size);
        $data = self::decode($raw);
        if ($status >= 200 && $status < 300) {
            return $data;
        }
        throw self::errorFrom($status, $data, $respHeaders['retry-after'] ?? null);
    }

    /**
     * Download a signed URL (no key). With $destination (path) write to that file and return the path,
     * otherwise return a stream in memory or in a temp file.
     */
    public function download(string $url, ?string $destination = null): Stream|string
    {
        $target = $this->resolveSigned($url);
        for ($attempt = 0; ; $attempt++) {
            if ($destination !== null) {
                $tmp = $destination . '.part-' . getmypid() . '-' . bin2hex(random_bytes(4));
                $fh = fopen($tmp, 'wb');
                if ($fh === false) {
                    throw new SwitchfileException("Could not write {$destination}.", 'WRITE_FAILED');
                }
            } else {
                $fh = fopen('php://temp/maxmemory:' . (16 * 1024 * 1024), 'w+b');
            }
            try {
                [$status, $raw, $respHeaders] = $this->exec('GET', $target, ['User-Agent: ' . $this->userAgent], null, max($this->timeout, 3600), true, null, $fh);
            } catch (SwitchfileException $e) {
                fclose($fh);
                if (isset($tmp)) {
                    @unlink($tmp);
                }
                if ($attempt < $this->maxRetries) {
                    usleep(self::backoff($attempt));
                    continue;
                }
                throw $e;
            }
            if ($status >= 200 && $status < 300) {
                if ($destination !== null) {
                    fclose($fh);
                    if (!rename($tmp, $destination)) {
                        @unlink($tmp);
                        throw new SwitchfileException("Could not write {$destination}.", 'WRITE_FAILED');
                    }
                    return $destination;
                }
                rewind($fh);
                return new Stream($fh);
            }
            rewind($fh);
            $raw = (string) stream_get_contents($fh);
            fclose($fh);
            if (isset($tmp)) {
                @unlink($tmp);
            }
            if ($status >= 500 && $attempt < $this->maxRetries) {
                usleep(self::backoff($attempt));
                continue;
            }
            throw self::errorFrom($status, self::decode($raw), $respHeaders['retry-after'] ?? null);
        }
    }

    public function resolveSigned(string $url): string
    {
        if (!preg_match('#^https?://#i', $url)) {
            $url = rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/');
        }
        $p = parse_url($url);
        if ($p === false || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || isset($p['user']) || isset($p['pass'])) {
            throw new SwitchfileException('The switchfile API returned a file URL that is not allowed.', 'BAD_RESPONSE');
        }
        return $url;
    }

    /**
     * @param list<string> $headers
     * @param array<string, mixed>|string|resource|null $body
     * @param resource|null $sink
     * @return array{0: int, 1: string, 2: array<string, string>}
     */
    private function exec(string $method, string $url, array $headers, mixed $body, float $timeout, bool $follow, ?int $size = null, $sink = null): array
    {
        $ch = curl_init($url);
        $respHeaders = [];
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ];
        if (defined('CURLOPT_REDIR_PROTOCOLS')) {
            $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if ($sink !== null) {
            $opts[CURLOPT_FILE] = $sink;
        } else {
            $opts[CURLOPT_RETURNTRANSFER] = true;
        }
        if (is_resource($body)) {
            // PUT with stream: curl reads the file while sending.
            $opts[CURLOPT_UPLOAD] = true;
            $opts[CURLOPT_INFILE] = $body;
            $opts[CURLOPT_INFILESIZE] = $size ?? -1;
            $opts[CURLOPT_HTTPHEADER][] = 'Expect:';
        } elseif (is_array($body)) {
            $opts[CURLOPT_POSTFIELDS] = $body;
            $opts[CURLOPT_HTTPHEADER][] = 'Expect:';
        } elseif (is_string($body)) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno !== 0 || $raw === false) {
            $host = (string) parse_url($url, PHP_URL_HOST);
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new HttpClientException("The request to {$host} did not finish in time.", 'TIMEOUT');
            }
            throw new HttpClientException("Could not reach {$host}.", 'NETWORK_ERROR');
        }
        return [$status, $sink !== null ? '' : (string) $raw, $respHeaders];
    }

    /** Error for a 3xx response from the API. */
    public static function redirectError(int $status, ?string $location, string $url): SwitchfileException
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        $host .= $port !== null && $port !== false ? ":{$port}" : '';
        $target = $location !== null && $location !== '' ? " to {$location}" : '';
        return new SwitchfileException(
            "The switchfile API at {$host} answered with a redirect (HTTP {$status}{$target}). Check the base URL; it should be https://api.switchfile.io.",
            'UNEXPECTED_REDIRECT',
            $status,
        );
    }

    public static function errorFrom(int $status, mixed $data, ?string $retryAfter): SwitchfileException
    {
        $b = is_array($data) ? $data : [];
        $fallback = [401 => 'UNAUTHENTICATED', 403 => 'FORBIDDEN', 404 => 'NOT_FOUND', 413 => 'FILE_TOO_LARGE', 422 => 'INVALID_DATA', 429 => 'RATE_LIMIT_EXCEEDED'];
        $code = is_string($b['code'] ?? null) && $b['code'] !== '' ? $b['code'] : ($status >= 500 ? 'INTERNAL_ERROR' : ($fallback[$status] ?? 'HTTP_ERROR'));
        $message = "The switchfile API answered with HTTP {$status}.";
        foreach (['detail', 'message', 'title'] as $k) {
            if (is_string($b[$k] ?? null) && $b[$k] !== '') {
                $message = $b[$k];
                break;
            }
        }
        $errors = is_array($b['errors'] ?? null) ? $b['errors'] : null;
        $ra = $retryAfter !== null && is_numeric($retryAfter) ? (float) $retryAfter : null;
        $class = $status >= 500 ? HttpServerException::class : HttpClientException::class;
        return new $class($message, $code, $status, $errors, $ra, $data);
    }

    private static function decode(string $raw): mixed
    {
        if ($raw === '') {
            return null;
        }
        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    private static function backoff(int $attempt): int
    {
        return (int) (min(8.0, 0.5 * 2 ** $attempt) * 1_000_000) + random_int(0, 250_000);
    }
}
