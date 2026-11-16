<?php

declare(strict_types=1);

namespace Switchfile\Tests;

use PHPUnit\Framework\TestCase;
use Switchfile\Exceptions\SignatureVerificationException;
use Switchfile\Exceptions\SwitchfileException;
use Switchfile\Exceptions\UnexpectedDataException;
use Switchfile\Switchfile;

/** Constructor, sandbox, version, webhooks, mock checked against openapi.json. */
final class ClientTest extends MockTestCase
{
    public function testSandboxAndBaseUrl(): void
    {
        $sf = new Switchfile(['api_key' => 'sf_test_x', 'sandbox' => true, 'base_url' => 'https://api.example/', 'sandbox_base_url' => 'https://sb.example']);
        self::assertTrue($sf->isSandbox());
        self::assertSame('https://sb.example', $sf->getHttpTransport()->getBaseUrl());
        self::assertSame('http://localhost:3000', (new Switchfile(['api_key' => 'k', 'base_url' => 'http://localhost:3000/']))->getHttpTransport()->getBaseUrl());
        try {
            new Switchfile(['api_key' => 'sf_live_x', 'sandbox' => true]);
            self::fail('SANDBOX_KEY_REQUIRED expected');
        } catch (SwitchfileException $e) {
            self::assertSame('SANDBOX_KEY_REQUIRED', $e->getErrorCode());
        }
        new Switchfile(['api_key' => 'sf_live_x', 'sandbox' => false, 'region' => 'us-east']);
        $this->expectException(SwitchfileException::class);
        new Switchfile(['api_key' => 'k', 'base_url' => 'ftp://x']);
    }

    public function testVersionMatchesComposerAndConstant(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Switchfile::VERSION);
        $this->client()->users()->me();
        self::assertSame('switchfile-php/' . Switchfile::VERSION, $this->requests()[0]['headers']['user-agent']);
    }

    public function testWebhooks(): void
    {
        $h = $this->client()->webhookHandler();
        $secret = 'whsec_test';
        $body = json_encode(['event' => 'job.finished', 'job' => ['id' => 'j1', 'tag' => 'x', 'status' => 'finished', 'tasks' => []]]);
        $sig = hash_hmac('sha256', $body, $secret);
        self::assertTrue($h->verify($body, $sig, $secret));
        self::assertTrue($h->verify($body, strtoupper($sig), $secret));
        self::assertFalse($h->verify($body, $sig, 'other'));
        self::assertFalse($h->verify($body, '', $secret));
        self::assertFalse($h->verify($body, null, $secret));
        self::assertFalse($h->verify($body . ' ', $sig, $secret));

        $event = $h->constructEvent($body, $sig, $secret);
        self::assertSame('job.finished', $event->getEvent());
        self::assertSame('x', $event->getJob()->getTag());
        self::assertNull($event->getConversion());

        $v1 = json_encode(['event' => 'conversion.finished', 'data' => ['id' => 'c1']]);
        self::assertSame('c1', $h->constructEvent($v1, hash_hmac('sha256', $v1, $secret), $secret)->getConversion()['id']);

        $request = new class ($v1, hash_hmac('sha256', $v1, $secret)) {
            public function __construct(private string $body, private string $sig)
            {
            }

            public function getHeaderLine(string $name): string
            {
                return $name === 'Switchfile-Signature' ? $this->sig : '';
            }

            public function getBody(): string
            {
                return $this->body;
            }
        };
        self::assertSame('conversion.finished', $h->constructEventFromRequest($request, $secret)->getEvent());

        try {
            $h->constructEvent($body, '00', $secret);
            self::fail('SignatureVerificationException expected');
        } catch (SignatureVerificationException $e) {
            self::assertSame('INVALID_SIGNATURE', $e->getErrorCode());
        }
        $this->expectException(UnexpectedDataException::class);
        $h->constructEvent('{nope', hash_hmac('sha256', '{nope', $secret), $secret);
    }

    public function testMockHasRequiredFieldsOfOpenApi(): void
    {
        $spec = json_decode((string) file_get_contents(__DIR__ . '/../../../apps/api/openapi.json'), true);
        $schemas = $spec['components']['schemas'];
        $missing = static fn (array $obj, array $schema) => array_values(array_diff($schema['required'] ?? [], array_keys($obj)));

        $sf = $this->client();
        $job = $sf->jobs()->create((new \Switchfile\Models\Job())
            ->addTask((new \Switchfile\Models\Task('import/url', 'a'))->set('url', 'https://example.com/a.png'))
            ->addTask((new \Switchfile\Models\Task('convert', 'b'))->set('input', 'a')->set('output_format', 'jpg'))
            ->addTask((new \Switchfile\Models\Task('export/url', 'c'))->set('input', 'b')));
        $sf->jobs()->wait($job);
        self::assertSame([], $missing($job->toArray(), $schemas['Job']));
        foreach ($job->getTasks() as $t) {
            self::assertSame([], $missing($t->toArray(), $schemas['Task']));
        }
        self::assertSame([], $missing($sf->users()->me()->toArray(), $schemas['Me']['properties']['data']));
        self::assertSame([], $missing($sf->convert(['content' => 'x', 'filename' => 'a.png'], 'jpg'), $schemas['Conversion']));
        self::assertSame([], $missing($sf->account()->get(), $schemas['Account']));
    }

    public function testRedirectThrowsWithoutFollowing(): void
    {
        $sf = $this->client(['base_url' => $this->url . '/__redirect']);
        $calls = [
            fn () => $sf->conversions()->get('00000000-0000-4000-8000-000000000001'),
            fn () => $sf->convert('https://example.com/a.png', 'jpg'),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                self::fail('3xx must throw an exception');
            } catch (SwitchfileException $e) {
                self::assertSame('UNEXPECTED_REDIRECT', $e->getErrorCode());
                self::assertSame(301, $e->getStatus());
                self::assertStringContainsString($this->url . '/v1/', $e->getMessage());
            }
        }
        // Only the requests to /__redirect, none to the target (the key would otherwise stay in the header).
        $paths = array_map(static fn ($r) => substr($r['path'], 0, 12), $this->requests());
        self::assertSame(['/__redirect/', '/__redirect/'], $paths);
    }

    public function testUploadHeaderFilterDropsCrLfNul(): void
    {
        self::assertSame(
            ['x-amz-acl' => 'private', 'Content-Type' => 'image/png', 'x-amz-meta-n' => '12'],
            \Switchfile\Transport\FileSource::safeHeaders([
                'x-amz-acl' => 'private',
                'Content-Type' => 'image/png',
                'x-amz-meta-n' => 12,
                'Authorization' => 'Bearer k',
                'Host' => 'evil.example',
                'Content-Length' => '5',
                'bad name' => 'x',
                'x-crlf' => "a\r\nInjected: 1",
                'x-lf' => "a\nb",
                'x-nul' => "a\0b",
                'x-null' => null,
            ]),
        );
    }
}
