<?php

declare(strict_types=1);

namespace Switchfile\Tests;

use Switchfile\Exceptions\ConversionFailedException;
use Switchfile\Exceptions\HttpClientException;
use Switchfile\Exceptions\SwitchfileException;
use Switchfile\Switchfile;

/** /v1: convert() with path, content, URL, earlier conversion, large file; errors; account; formats. */
final class ConvertTest extends MockTestCase
{
    public function testPathMultipartWithOptions(): void
    {
        $sf = $this->client();
        $path = $this->tempFile('report.docx', 'word document');
        $c = $sf->convert($path, 'pdf', ['quality' => 80, 'grayscale' => true]);
        self::assertSame('finished', $c['status']);
        self::assertSame('report.docx', $c['input']['filename']);
        self::assertSame(['quality' => 80, 'grayscale' => true], $c['options']);
        $post = $this->requestsTo('/v1/convert')[0];
        self::assertSame('/v1/convert?wait=30', $post['path']);
        self::assertStringStartsWith('multipart/form-data', $post['headers']['content-type']);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $post['headers']['idempotency-key']);
        self::assertNotEmpty($this->requestsTo("/v1/conversions/{$c['id']}?wait=30"));
        $out = dirname($path) . '/report.pdf';
        self::assertSame($out, $sf->download($c, $out));
        self::assertSame(self::resultFor('word document', 'pdf'), file_get_contents($out));
        self::assertArrayNotHasKey('authorization', $this->requestsTo('/files/')[0]['headers']);
    }

    public function testContentFinishedImmediatelyAndWaitFalse(): void
    {
        $this->resetMock(['conversion_polls' => 0]);
        $c = $this->client()->convert(['content' => 'png bytes', 'filename' => 'a.png'], 'jpg');
        self::assertSame('finished', $c['status']);
        self::assertSame([], $this->requestsTo('/v1/conversions/'));

        $this->resetMock();
        $c = $this->client()->convert(fopen($this->tempFile('a.png', 'x'), 'r'), 'jpg', [], ['wait' => false]);
        self::assertContains($c['status'], ['queued', 'processing']);
        self::assertSame('/v1/convert', $this->requestsTo('/v1/convert')[0]['path']);
    }

    public function testUrlAndFrom(): void
    {
        $sf = $this->client();
        $sf->convert('https://example.com/photo.heic', 'jpg', [], ['webhook_url' => 'https://hooks.example.com/x']);
        $sf->convert(['url' => 'https://example.com/b.heic'], 'png');
        $bodies = array_map(static fn ($r) => json_decode($r['body'], true), $this->requestsTo('/v1/convert'));
        self::assertSame(['url' => 'https://example.com/photo.heic', 'to' => 'jpg', 'webhook_url' => 'https://hooks.example.com/x'], $bodies[0]);
        self::assertSame(['url' => 'https://example.com/b.heic', 'to' => 'png'], $bodies[1]);

        $first = $sf->convert(['content' => 'abc', 'filename' => 'a.png'], 'jpg');
        $again = $sf->convert(['from' => $first['id']], 'webp');
        self::assertSame(['webp', 'a.png'], [$again['to'], $again['input']['filename']]);
    }

    public function testPresetWithoutTarget(): void
    {
        $sf = $this->client();
        $a = $sf->convert(['content' => 'png bytes', 'filename' => 'a.png'], null, ['quality' => 90], ['preset' => 'Web images']);
        self::assertSame('jpg', $a['to']);
        self::assertEquals(['quality' => 90, 'width' => 1600], $a['options']);
        $b = $sf->convert('https://example.com/b.png', '', [], ['preset' => 'Web images']);
        self::assertSame('jpg', $b['to']);
        self::assertSame(['url' => 'https://example.com/b.png', 'preset' => 'Web images'], json_decode($this->requestsTo('/v1/convert')[1]['body'], true));
        try {
            $sf->convert('https://example.com/c.png', null, [], ['preset' => 'Nope']);
            self::fail('PRESET_NOT_FOUND expected');
        } catch (\Switchfile\Exceptions\SwitchfileException $e) {
            self::assertSame('PRESET_NOT_FOUND', $e->getErrorCode());
        }
    }

    public function testLargeFileViaUploads(): void
    {
        $c = $this->client()->convert($this->tempFile('big.mov', str_repeat("\x01", 2048)), 'mp4', [], ['multipart_limit' => 1024]);
        self::assertSame('finished', $c['status']);
        self::assertSame(['filename' => 'big.mov', 'size' => 2048], json_decode($this->requestsTo('/v1/uploads')[0]['body'], true));
        $put = $this->requestsTo('/v1put/', 'PUT')[0];
        self::assertSame(2048, $put['body_size']);
        self::assertArrayNotHasKey('authorization', $put['headers']);
        self::assertArrayHasKey('upload_id', json_decode($this->requestsTo('/v1/convert')[0]['body'], true));
    }

    public function testErrors(): void
    {
        $this->resetMock(['fail' => ['code' => 'INVALID_INPUT', 'message' => 'The input file could not be read.']]);
        try {
            $this->client()->convert(['content' => 'x', 'filename' => 'a.docx'], 'pdf');
            self::fail('ConversionFailedException expected');
        } catch (ConversionFailedException $e) {
            self::assertSame('INVALID_INPUT', $e->getErrorCode());
            self::assertSame('failed', $e->getConversion()['status']);
        }

        $this->resetMock();
        try {
            $this->client()->convert(['content' => 'x', 'filename' => 'a.txt'], 'mp4');
            self::fail('UNSUPPORTED_PAIR expected');
        } catch (HttpClientException $e) {
            self::assertSame(['UNSUPPORTED_PAIR', 422], [$e->getErrorCode(), $e->getStatus()]);
            self::assertSame('Converting txt to mp4 is not supported.', $e->getMessage());
        }

        $this->resetMock(['server_error_once' => '^/v1/convert$']);
        self::assertSame('finished', $this->client()->convert(['content' => 'x', 'filename' => 'a.png'], 'jpg')['status']);
        $posts = $this->requestsTo('/v1/convert');
        self::assertCount(2, $posts);
        self::assertSame($posts[0]['headers']['idempotency-key'], $posts[1]['headers']['idempotency-key']);

        $this->resetMock();
        foreach ([[['content' => 'x'], ''], ['/does/not/exist.png', 'jpg']] as [$file, $to]) {
            try {
                $this->client()->convert($file, $to);
                self::fail('error expected');
            } catch (SwitchfileException $e) {
                self::assertContains($e->getErrorCode(), ['INVALID_DATA', 'READ_FAILED']);
            }
        }
        self::assertSame([], $this->requests());
    }

    public function testWithoutStorage(): void
    {
        $path = $this->tempFile('Bericht ä.docx', 'word document'); // public-english: allow (non-ASCII file name)
        $r = $this->client()->convertWithoutStorage($path, 'pdf', ['quality' => 80]);
        self::assertSame(self::resultFor('word document', 'pdf'), $r['data']);
        self::assertSame('Bericht ä.pdf', $r['filename']); // public-english: allow
        self::assertSame('application/octet-stream', $r['content_type']);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $r['conversion_id']);
        self::assertSame(1, $r['credits']);
        $post = $this->requestsTo('/v1/convert')[0];
        self::assertSame('/v1/convert?store=false', $post['path']);
        self::assertSame('false', $post['headers']['switchfile-store']);
        self::assertArrayNotHasKey('idempotency-key', $post['headers']);
        self::assertStringStartsWith('multipart/form-data', $post['headers']['content-type']);
        // The mock keeps nothing: the ID cannot be fetched.
        try {
            $this->client()->conversions()->get((string) $r['conversion_id']);
            self::fail('NOT_FOUND expected');
        } catch (HttpClientException $e) {
            self::assertSame('NOT_FOUND', $e->getErrorCode());
        }
    }

    public function testWithoutStorageNotesAndPartialResult(): void
    {
        $file = ['content' => 'x', 'filename' => 'a.pdf'];
        $plain = $this->client()->convertWithoutStorage($file, 'xlsx');
        self::assertSame([[], false], [$plain['notes'], $plain['incomplete']]);
        // Note without a partial result, with characters outside ASCII
        $sheets = ['The workbook „Übersicht“ has 3 worksheets; only the first was converted.']; // public-english: allow (non-ASCII)
        $this->resetMock(['notes' => $sheets]);
        $noted = $this->client()->convertWithoutStorage($file, 'xlsx');
        self::assertSame([$sheets, false], [$noted['notes'], $noted['incomplete']]);
        // Partial result: the flag comes from the header, not from the wording of the notes.
        $partial = ['Pages 3-4 are missing from the result: they could not be read.', 'The result is incomplete, so this conversion is free.'];
        $this->resetMock(['notes' => $partial]);
        self::assertFalse($this->client()->convertWithoutStorage($file, 'xlsx')['incomplete']);
        $this->resetMock(['notes' => $partial, 'incomplete' => true]);
        $r = $this->client()->convertWithoutStorage($file, 'xlsx');
        self::assertSame([$partial, true], [$r['notes'], $r['incomplete']]);
        $stored = $this->client()->convert($file, 'xlsx');
        self::assertSame([$partial, true], [$stored['result']['notes'], $stored['result']['incomplete']]);
    }

    public function testWithoutStorageRejectedOrRetried(): void
    {
        foreach ([['https://example.com/a.png', 'jpg'], [['upload_id' => 'u'], 'jpg'], [['content' => 'x'], '']] as [$file, $to]) {
            try {
                $this->client()->convertWithoutStorage($file, $to);
                self::fail('INVALID_DATA expected');
            } catch (SwitchfileException $e) {
                self::assertSame('INVALID_DATA', $e->getErrorCode());
            }
        }
        try {
            $this->client()->convertWithoutStorage(['content' => str_repeat('x', Switchfile::NO_STORE_LIMIT + 1)], 'jpg');
            self::fail('FILE_TOO_LARGE expected');
        } catch (SwitchfileException $e) {
            self::assertSame('FILE_TOO_LARGE', $e->getErrorCode());
        }
        self::assertSame([], $this->requests());

        $this->resetMock(['fail' => ['code' => 'INVALID_INPUT', 'message' => 'The file is damaged.']]);
        try {
            $this->client()->convertWithoutStorage(['content' => 'x', 'filename' => 'a.png'], 'jpg');
            self::fail('INVALID_INPUT expected');
        } catch (HttpClientException $e) {
            self::assertSame(['INVALID_INPUT', 422], [$e->getErrorCode(), $e->getStatus()]);
        }
        self::assertCount(1, $this->requestsTo('/v1/convert'));

        $this->resetMock(['busy_once' => '^/v1/convert$']);
        self::assertSame('a.jpg', $this->client()->convertWithoutStorage(['content' => 'x', 'filename' => 'a.png'], 'jpg')['filename']);
        self::assertCount(2, $this->requestsTo('/v1/convert'));

        $this->resetMock(['server_error_once' => '^/v1/convert$']);
        try {
            $this->client()->convertWithoutStorage(['content' => 'x', 'filename' => 'a.png'], 'jpg');
            self::fail('503 expected');
        } catch (SwitchfileException $e) {
            self::assertSame(503, $e->getStatus());
        }
        self::assertCount(1, $this->requestsTo('/v1/convert'));
    }

    public function testAccountWithoutStorage(): void
    {
        $sf = $this->client();
        self::assertFalse($sf->account()->get()['no_store']);
        self::assertTrue($sf->account()->setNoStore(true)['no_store']);
        $patch = $this->requestsTo('/v1/account', 'PATCH');
        self::assertSame(['no_store' => true], json_decode((string) end($patch)['body'], true));
        self::assertFalse($sf->account()->setNoStore(false)['no_store']);
    }

    public function testConversionsAccountFormats(): void
    {
        $sf = $this->client();
        $c = $sf->convert(['content' => 'x', 'filename' => 'a.png'], 'jpg', [], ['wait' => false]);
        self::assertSame($c['id'], $sf->conversions()->get($c['id'])['id']);
        self::assertSame([$c['id']], array_column($sf->conversions()->list([$c['id'], '00000000-0000-4000-8000-999999999999']), 'id'));
        self::assertSame('finished', $sf->conversions()->wait($c['id'])['status']);
        $sf->conversions()->delete($c['id']);
        try {
            $sf->conversions()->get($c['id']);
            self::fail('NOT_FOUND expected');
        } catch (HttpClientException $e) {
            self::assertSame('NOT_FOUND', $e->getErrorCode());
        }

        self::assertSame(self::WEBHOOK_SECRET, $sf->account()->get()['webhook_signing_secret']);
        self::assertSame('https://example.com/hook', $sf->account()->update('https://example.com/hook')['webhook_url']);
        self::assertNull($sf->account()->update(null)['webhook_url']);
        self::assertSame(100, $sf->account()->credits()['packs']);
        self::assertSame('web', $sf->account()->transactions()['data'][0]['source']);
        self::assertNull($sf->account()->plan()['plan']);

        $this->resetMock();
        $anon = new Switchfile(['api_key' => '', 'base_url' => $this->url]);
        self::assertContains('png', array_column($anon->formats()['data'], 'format'));
        self::assertCount(1, $anon->formats('docx')['data']);
        foreach ($this->requests() as $r) {
            self::assertArrayNotHasKey('authorization', $r['headers']);
        }
        try {
            $anon->account()->get();
            self::fail('MISSING_API_KEY expected');
        } catch (SwitchfileException $e) {
            self::assertSame('MISSING_API_KEY', $e->getErrorCode());
        }
    }
}
