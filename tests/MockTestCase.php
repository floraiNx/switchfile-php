<?php

declare(strict_types=1);

namespace Switchfile\Tests;

use PHPUnit\Framework\TestCase;
use Switchfile\Switchfile;

/**
 * Tests against the shared mock (sdks/testing/mock_api.py), address from SWITCHFILE_MOCK_URL. Every test resets
 * the mock; behaviour as in mock_api.DEFAULT_BEHAVIOR.
 */
abstract class MockTestCase extends TestCase
{
    public const TEST_KEY = 'sf_test_MOCKKEY_0123456789abcdef';
    public const WEBHOOK_SECRET = 'whsec_mock_0123456789';

    protected string $url;

    protected function setUp(): void
    {
        $url = getenv('SWITCHFILE_MOCK_URL');
        if ($url === false || $url === '') {
            self::fail('SWITCHFILE_MOCK_URL is missing (mock: python3 sdks/testing/mock_api.py)');
        }
        $this->url = rtrim($url, '/');
        $this->resetMock();
    }

    /** @param array<string, mixed> $behavior */
    protected function resetMock(array $behavior = []): void
    {
        $this->admin('POST', '/__mock/reset', $behavior === [] ? new \stdClass() : $behavior);
    }

    /** @return list<array<string, mixed>> */
    protected function requests(): array
    {
        return $this->admin('GET', '/__mock/requests');
    }

    /** @return list<array<string, mixed>> */
    protected function requestsTo(string $prefix, ?string $method = null): array
    {
        return array_values(array_filter($this->requests(), static fn ($r) => str_starts_with($r['path'], $prefix) && ($method === null || $r['method'] === $method)));
    }

    protected function client(array $options = []): Switchfile
    {
        return new Switchfile($options + ['api_key' => self::TEST_KEY, 'base_url' => $this->url, 'poll_interval' => 0.01]);
    }

    protected static function resultFor(string $data, string $to): string
    {
        return "converted to {$to}: " . strlen($data) . " bytes\n";
    }

    protected function tempFile(string $name, string $content): string
    {
        $dir = sys_get_temp_dir() . '/sf-php-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("{$dir}/{$name}", $content);
        return "{$dir}/{$name}";
    }

    private function admin(string $method, string $path, mixed $body = null): mixed
    {
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => "Content-Type: application/json\r\n",
            'content' => $body === null ? '' : json_encode($body),
            'ignore_errors' => true,
        ]]);
        $raw = file_get_contents($this->url . $path, false, $ctx);
        return json_decode((string) $raw, true);
    }
}
