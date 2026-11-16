<?php

declare(strict_types=1);

namespace Switchfile\Tests;

use Switchfile\Exceptions\SwitchfileException;
use Switchfile\Switchfile;

/**
 * New methods against the mock, the same cases as sdks/node/test/resources.test.ts: batches, archives, captures,
 * accessibility checks, uploads, lookup by idempotency key, cost estimate, /v2 formats, /health, webhooks.
 */
final class ResourcesTest extends MockTestCase
{
    private function lastPath(): string
    {
        $all = $this->requests();
        return $all[count($all) - 1]['path'];
    }

    private function expectError(callable $fn): SwitchfileException
    {
        try {
            $fn();
        } catch (SwitchfileException $e) {
            return $e;
        }
        self::fail('SwitchfileException expected');
    }

    public function testBatchWithZipAndWaitGetArchiveDelete(): void
    {
        $sf = $this->client();
        $b = $sf->batches()->create(['conversions' => [['url' => 'https://example.com/a.png', 'to' => 'jpg'], ['url' => 'https://example.com/b.png', 'to' => 'webp']], 'archive' => 'zip'], ['wait' => true]);
        self::assertSame('finished', $b['status']);
        self::assertSame(['total' => 2, 'finished' => 2, 'failed' => 0], $b['counts']);
        foreach ($b['conversions'] as $c) {
            self::assertSame($b['id'], $c['batch_id']);
        }
        $post = $this->requestsTo('/v1/batches', 'POST')[0];
        self::assertSame('/v1/batches?wait=30', $post['path']);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $post['headers']['idempotency-key']);
        self::assertNotEmpty($this->requestsTo("/v1/batches/{$b['id']}?wait=30"));

        self::assertSame('ready', $b['archive']['status']);
        self::assertSame('zip: a.jpg, b.webp', $sf->download($b['archive']));
        self::assertArrayNotHasKey('authorization', $this->requestsTo('/v1/archives/')[0]['headers']);

        self::assertSame($b['id'], $sf->batches()->get($b['id'], 5)['id']);
        self::assertSame("/v1/batches/{$b['id']}?wait=5", $this->lastPath());
        $sf->batches()->delete($b['id']);
        $e = $this->expectError(fn () => $sf->batches()->get($b['id']));
        self::assertSame(['NOT_FOUND', 404], [$e->getErrorCode(), $e->getStatus()]);
    }

    public function testBatchWithoutWaitThenWait(): void
    {
        $sf = $this->client();
        $b = $sf->batches()->create(['conversions' => [['url' => 'https://example.com/a.png', 'to' => 'jpg']]], ['idempotency_key' => 'batch-1']);
        self::assertSame('processing', $b['status']);
        self::assertSame('batch-1', $this->requestsTo('/v1/batches', 'POST')[0]['headers']['idempotency-key']);
        self::assertSame('finished', $sf->batches()->wait($b['id'])['status']);
    }

    public function testArchiveFromFinishedConversionsAnd409WithIndex(): void
    {
        $sf = $this->client();
        $a = $sf->convert(['content' => 'a', 'filename' => 'a.png'], 'jpg');
        $b = $sf->convert(['content' => 'b', 'filename' => 'b.docx'], 'pdf');
        $archive = $sf->archives()->create([$a['id'], $b['id']]);
        self::assertSame(['archive', 2], [$archive['object'], $archive['files']]);
        self::assertSame(['conversions' => [$a['id'], $b['id']]], json_decode($this->requestsTo('/v1/archives', 'POST')[0]['body'], true));
        self::assertSame('zip: a.jpg, b.pdf', $sf->download($archive));

        $running = $sf->convert(['content' => 'c', 'filename' => 'c.png'], 'jpg', [], ['wait' => false]);
        $e = $this->expectError(fn () => $sf->archives()->create([$a['id'], $running['id']]));
        self::assertSame(['CONVERSION_NOT_FINISHED', 409, 1], [$e->getErrorCode(), $e->getStatus(), $e->getIndex()]);
    }

    public function testCaptureWaitFindIdempotencyDownloadDelete(): void
    {
        $sf = $this->client();
        $c = $sf->captures()->create(['url' => 'https://example.com/', 'output_format' => 'png', 'options' => ['device' => 'mobile']], ['wait' => true, 'idempotency_key' => 'cap-1']);
        self::assertSame('finished', $c['status']);
        self::assertSame('capture.png', $c['result']['file']['filename']);
        self::assertSame(['url' => 'https://example.com/', 'output_format' => 'png', 'options' => ['device' => 'mobile']], json_decode($this->requestsTo('/v1/captures', 'POST')[0]['body'], true));
        self::assertSame('capture of https://example.com/', $sf->download($c['result']['file']));
        self::assertSame('capture of https://example.com/', $sf->download($c));
        self::assertSame('NO_FILE', $this->expectError(fn () => $sf->download(['status' => 'processing', 'result' => null] + $c))->getErrorCode());

        self::assertSame($c['id'], $sf->captures()->findByIdempotencyKey('cap-1')['id']);
        self::assertNull($sf->captures()->findByIdempotencyKey('unknown'));
        self::assertSame('/v1/captures?idempotency_key=unknown', $this->lastPath());
        self::assertSame('finished', $sf->captures()->get($c['id'])['status']);
        $sf->captures()->delete($c['id']);
        self::assertSame('NOT_FOUND', $this->expectError(fn () => $sf->captures()->get($c['id']))->getErrorCode());
    }

    public function testFailedCapture(): void
    {
        $this->resetMock(['fail' => ['code' => 'PAGE_LOAD_FAILED', 'message' => 'The page did not load.']]);
        $sf = $this->client();
        $c = $sf->captures()->create(['url' => 'https://example.com/', 'output_format' => 'pdf']);
        $done = $sf->captures()->wait($c['id']);
        self::assertSame('failed', $done['status']);
        self::assertSame(['code' => 'PAGE_LOAD_FAILED', 'message' => 'The page did not load.'], $done['error']);
    }

    public function testCheckFileMultipartWaitDelete(): void
    {
        $sf = $this->client();
        $c = $sf->accessibilityChecks()->create(['content' => '%PDF-1.7', 'filename' => 'report.pdf'], ['profile' => 'ua2', 'report_pdf' => 'en', 'wait' => true]);
        self::assertSame('finished', $c['status']);
        self::assertSame(['filename' => 'report.pdf', 'size' => 8], $c['input']);
        self::assertSame(['applied' => 10, 'met' => 8, 'failed' => 2], $c['result']['criteria']);
        self::assertNotNull($c['result']['report_pdf_url']);
        $post = $this->requestsTo('/v1/accessibility/checks', 'POST')[0];
        self::assertSame('/v1/accessibility/checks?wait=30', $post['path']);
        self::assertStringStartsWith('multipart/form-data', $post['headers']['content-type']);
        self::assertSame(['summary' => '8 of 10 machine-checkable criteria met'], json_decode($sf->download($c['result']['report_json_url']), true));
        $sf->accessibilityChecks()->delete($c['id']);
        self::assertSame('NOT_FOUND', $this->expectError(fn () => $sf->accessibilityChecks()->get($c['id']))->getErrorCode());
    }

    public function testCheckUrlGetWaitIdempotency(): void
    {
        $sf = $this->client();
        $c = $sf->accessibilityChecks()->create(['url' => 'https://example.com/a.pdf'], ['idempotency_key' => 'chk-1']);
        self::assertSame(['url' => 'https://example.com/a.pdf'], json_decode($this->requestsTo('/v1/accessibility/checks', 'POST')[0]['body'], true));
        self::assertSame('finished', $sf->accessibilityChecks()->get($c['id'], 30)['status']);
        self::assertSame("/v1/accessibility/checks/{$c['id']}?wait=30", $this->lastPath());
        self::assertSame($c['id'], $sf->accessibilityChecks()->findByIdempotencyKey('chk-1')['id']);
        self::assertNull($sf->accessibilityChecks()->findByIdempotencyKey('unknown'));
    }

    public function testUploadPutWithoutKeyConvertDelete(): void
    {
        $sf = $this->client();
        $up = $sf->uploads()->create(['content' => 'movie', 'filename' => 'clip.mov']);
        self::assertSame(['filename' => 'clip.mov', 'size' => 5], json_decode($this->requestsTo('/v1/uploads', 'POST')[0]['body'], true));
        $put = array_values(array_filter($this->requests(), static fn ($r) => $r['method'] === 'PUT'))[0];
        self::assertSame(5, $put['body_size']);
        self::assertArrayNotHasKey('authorization', $put['headers']);
        $c = $sf->convert(['upload_id' => $up['upload_id']], 'mp4');
        self::assertSame('clip.mov', $c['input']['filename']);
        $sf->uploads()->delete($up['upload_id']);
        self::assertCount(1, $this->requestsTo("/v1/uploads/{$up['upload_id']}", 'DELETE'));
        self::assertSame('NOT_FOUND', $this->expectError(fn () => $sf->uploads()->delete($up['upload_id']))->getErrorCode());
    }

    public function testConversionFindByIdempotencyKey(): void
    {
        $sf = $this->client();
        $c = $sf->convert(['content' => 'x', 'filename' => 'a.png'], 'jpg', [], ['idempotency_key' => 'conv-key-1']);
        self::assertSame($c['id'], $sf->conversions()->findByIdempotencyKey('conv-key-1')['id']);
        self::assertNull($sf->conversions()->findByIdempotencyKey('unknown'));
        self::assertSame('/v1/conversions?idempotency_key=unknown', $this->lastPath());
    }

    public function testEstimateDryRun(): void
    {
        $sf = $this->client();
        $e = $sf->estimate('https://example.com/a.png', 'jpg');
        self::assertSame(['estimate', 'jpg', ['min' => 1, 'max' => 3], true], [$e['object'], $e['to'], $e['credits'], $e['free']]);
        self::assertSame('docx', $sf->estimate(['content' => 'x', 'filename' => 'a.docx'], 'pdf')['input_format']);
        $posts = $this->requestsTo('/v1/convert', 'POST');
        self::assertSame(['/v1/convert?dry_run=1', '/v1/convert?dry_run=1'], array_column($posts, 'path'));
        foreach ($posts as $p) {
            self::assertArrayNotHasKey('idempotency-key', $p['headers']);
        }
    }

    public function testEstimateSendsAtMostOneByte(): void
    {
        $sf = $this->client();
        $e = $sf->estimate($this->tempFile('talk.mkv', str_repeat("\x01", 1024 * 1024)), 'mp4');
        self::assertSame(['estimate', 'mkv', 'mp4'], [$e['object'], $e['input_format'], $e['to']]);
        $sf->estimate(['content' => str_repeat('x', 4096), 'filename' => 'clip.mov'], 'mp4');
        [$a, $b] = $this->requestsTo('/v1/convert', 'POST');
        self::assertSame(['filename' => 'talk.mkv', 'size' => 1], $a['file']);
        self::assertLessThan(1024, $a['body_size']);
        self::assertSame(['filename' => 'clip.mov', 'size' => 1], $b['file']);
        self::assertSame([], $this->requestsTo('/v1/uploads'));
    }

    public function testEstimateThenConvertWithSameStream(): void
    {
        $sf = $this->client();
        $fh = fopen($this->tempFile('data.csv', "a,b\n1,2\n"), 'rb');
        $sf->estimate($fh, 'pdf');
        self::assertSame(8, $sf->convert($fh, 'pdf')['input']['size']);
        fclose($fh);
        $mem = fopen('php://temp', 'w+b');
        fwrite($mem, "--a,b\n1,2\n");
        fseek($mem, 2);
        $sf->estimate($mem, 'pdf', [], ['filename' => 'data.csv']);
        self::assertSame(2, ftell($mem));
        self::assertSame(8, $sf->convert($mem, 'pdf', [], ['filename' => 'data.csv'])['input']['size']);
        fclose($mem);
        $estimates = $this->requestsTo('/v1/convert?dry_run=1');
        self::assertSame([['filename' => 'data.csv', 'size' => 1], ['filename' => 'data.csv', 'size' => 1]], array_column($estimates, 'file'));
    }

    public function testConvertFormatsAndHealthWithoutKey(): void
    {
        $sf = new Switchfile(['api_key' => '', 'base_url' => $this->url]);
        self::assertCount(3, $sf->convertFormats());
        $png = $sf->convertFormats(['filter[input_format]' => 'png', 'include' => 'options']);
        self::assertSame(['jpg', 'webp'], array_column($png, 'output_format'));
        self::assertSame('quality', $png[0]['options'][0]['name']);
        self::assertSame('/v2/convert/formats?filter%5Binput_format%5D=png&include=options', $this->lastPath());
        foreach ($this->requests() as $r) {
            self::assertArrayNotHasKey('authorization', $r['headers']);
        }
        self::assertSame(['status' => 'ok'], $sf->health());
    }

    public function testWebhooksCreate409ListDeleteVerify(): void
    {
        $sf = $this->client();
        $w = $sf->webhooks()->create(['url' => 'https://example.com/hooks', 'events' => ['job.finished', 'job.failed']]);
        self::assertSame(['https://example.com/hooks', ['job.finished', 'job.failed'], false, self::WEBHOOK_SECRET], [$w['url'], $w['events'], $w['disabled'], $w['signing_secret']]);
        $e = $this->expectError(fn () => $sf->webhooks()->create(['url' => 'https://example.com/other', 'events' => ['job.finished']]));
        self::assertSame(['WEBHOOK_EXISTS', 409], [$e->getErrorCode(), $e->getStatus()]);
        self::assertCount(2, $this->requestsTo('/v2/webhooks', 'POST'));

        $list = $sf->webhooks()->list(['filter[url]' => 'https://example.com/hooks']);
        self::assertSame([$w['id']], array_column($list, 'id'));
        self::assertArrayNotHasKey('signing_secret', $list[0]);
        $sf->webhooks()->delete($w['id']);
        self::assertSame([], $sf->webhooks()->list());
        self::assertSame('NOT_FOUND', $this->expectError(fn () => $sf->webhooks()->delete($w['id']))->getErrorCode());

        $body = json_encode(['event' => 'job.finished', 'job' => ['id' => 'j']]);
        $sig = hash_hmac('sha256', $body, $w['signing_secret']);
        self::assertTrue($sf->webhooks()->verify($body, $sig, $w['signing_secret']));
        self::assertFalse($sf->webhooks()->verify($body, $sig, 'other'));
        self::assertSame('job.finished', $sf->webhooks()->constructEvent($body, $sig, $w['signing_secret'])->getEvent());
    }
}
