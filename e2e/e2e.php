<?php

// E2E of the PHP SDK against a real API (sdks/e2e/run.sh, fresh php container, autoloader from composer install
// --no-dev). Key from SWITCHFILE_API_KEY (via --env-file), base URL from SWITCHFILE_API_URL. Never prints the key or
// signed URLs.
//   php e2e.php <input directory with photo.png and letter.docx> <output directory>

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Switchfile\Exceptions\SwitchfileException;
use Switchfile\Models\Job;
use Switchfile\Models\Task;
use Switchfile\Switchfile;

[, $in, $out] = $argv;
$e2eUrl = getenv('E2E_URL') ?: 'https://www.w3.org/Icons/w3c_home.png';
$check = static function (bool $ok, string $what): void {
    if (!$ok) {
        fwrite(STDERR, "ERROR: {$what}\n");
        exit(1);
    }
};
$step = static fn (string $s) => print("-- php: {$s}\n");
$head = static fn (string $data, int $n) => substr($data, 0, $n);

$sf = new Switchfile(['sandbox' => true, 'timeout' => 120]);

$step('users()->me(), formats');
$check($sf->users()->me()->isSandbox(), 'sandbox key');
$check(in_array('jpg', array_column($sf->formats('png')['data'][0]['targets'], 'format'), true), 'png → jpg in formats');

$step('v2: import/upload → convert jpg → export/url, tasks()->upload with fopen');
$job = (new Job())
    ->setTag('sdk-e2e-php')
    ->addTask(new Task('import/upload', 'upload-my-file'))
    ->addTask((new Task('convert', 'convert-my-file'))->set('input', 'upload-my-file')->set('output_format', 'jpg')->set('quality', 80))
    ->addTask((new Task('export/url', 'export-my-file'))->set('input', 'convert-my-file'));
$sf->jobs()->create($job);
$uploadTask = $job->getTasks()->whereName('upload-my-file')[0];
$sf->tasks()->upload($uploadTask, fopen("{$in}/photo.png", 'r'), 'photo.png');
$check($uploadTask->getStatus() === 'finished', 'upload finished');
$sf->jobs()->wait($job, 300);
$check($job->getStatus() === 'finished', 'job finished: ' . json_encode(array_map(static fn (Task $t) => [$t->getName(), $t->getStatus(), $t->getCode()], $job->getTasks()->getArrayCopy())));
$file = $job->getExportUrls()[0];
$check($file->filename === 'photo.jpg', 'file name photo.jpg');
$jpg = stream_get_contents($sf->getHttpTransport()->download($file->url)->detach());
$check($head($jpg, 3) === "\xff\xd8\xff", 'JPEG');
$check(in_array($job->getId(), array_map(static fn (Job $j) => $j->getId(), $sf->jobs()->all(['filter[tag]' => 'sdk-e2e-php'])), true), 'jobs()->all with tag');

$step('v2: import/url → convert jpg → export/url, delete');
$urlJob = (new Job())
    ->addTask((new Task('import/url', 'i'))->set('url', $e2eUrl))
    ->addTask((new Task('convert', 'c'))->set('input', 'i')->set('output_format', 'jpg'))
    ->addTask((new Task('export/url', 'e'))->set('input', 'c'));
$sf->jobs()->create($urlJob);
$sf->jobs()->wait($urlJob, 300);
$check($urlJob->getStatus() === 'finished', 'URL job finished');
$check($head($sf->download($urlJob->getExportUrls()[0]), 3) === "\xff\xd8\xff", 'JPEG from URL');
$sf->jobs()->delete($urlJob);

$step('v1: convert path docx → pdf, content png → jpg, URL → jpg, from → webp');
$pdf = $sf->convert("{$in}/letter.docx", 'pdf');
$check($head((string) file_get_contents($sf->download($pdf, "{$out}/php-letter.pdf")), 4) === '%PDF', 'PDF');
$jpg = $sf->convert(['content' => (string) file_get_contents("{$in}/photo.png"), 'filename' => 'photo.png'], 'jpg', ['quality' => 70]);
$check($head($sf->download($jpg), 3) === "\xff\xd8\xff", 'JPEG from content');
$check($head($sf->download($sf->convert($e2eUrl, 'jpg')), 3) === "\xff\xd8\xff", 'JPEG from URL (v1)');
$webp = $sf->download($sf->convert(['from' => $jpg['id']], 'webp'));
$check(substr($webp, 8, 4) === 'WEBP', 'WEBP from from');

$step('v1: conversions()->list, delete, error UNSUPPORTED_PAIR');
$check(array_column($sf->conversions()->list([$pdf['id'], $jpg['id']]), 'id') === [$pdf['id'], $jpg['id']], 'list');
$sf->conversions()->delete($pdf['id']);
try {
    $sf->convert(['content' => 'plain text', 'filename' => 'notes.txt'], 'mp4');
    $check(false, 'UNSUPPORTED_PAIR expected');
} catch (SwitchfileException $e) {
    $check($e->getErrorCode() === 'UNSUPPORTED_PAIR', 'code ' . $e->getErrorCode());
}

$step('webhookHandler()->verify with the account signing secret');
$secret = $sf->account()->get()['webhook_signing_secret'];
$body = json_encode(['event' => 'conversion.finished', 'data' => ['id' => $jpg['id']]]);
$check($sf->webhookHandler()->verify($body, hash_hmac('sha256', $body, $secret), $secret), 'signature matches');
$check(!$sf->webhookHandler()->verify($body, hash_hmac('sha256', $body, $secret), 'wrong'), 'wrong secret');
$step('v1: estimate, findByIdempotencyKey, uploads, batches with ZIP, archives');
$check($sf->estimate($e2eUrl, 'jpg')['object'] === 'estimate', 'estimate');
$idemKey = 'sdk-e2e-php-' . (string) (int) (microtime(true) * 1000);
$keyed = $sf->convert($e2eUrl, 'webp', [], ['idempotency_key' => $idemKey]);
$check(($sf->conversions()->findByIdempotencyKey($idemKey)['id'] ?? null) === $keyed['id'], 'findByIdempotencyKey');
$upload = $sf->uploads()->create("{$in}/photo.png");
$batch = $sf->batches()->create(['conversions' => [['upload_id' => $upload['upload_id'], 'to' => 'jpg'], ['from' => $jpg['id'], 'to' => 'webp']], 'archive' => 'zip'], ['wait' => true, 'timeout' => 300]);
$check($batch['counts'] === ['total' => 2, 'finished' => 2, 'failed' => 0], 'batch finished');
$check($head((string) file_get_contents($sf->download($batch['archive'], "{$out}/batch.zip")), 2) === 'PK', 'ZIP of the batch');
$archive = $sf->archives()->create([$jpg['id'], $keyed['id']]);
$check($archive['files'] === 2, 'archive with 2 files');
$check($head($sf->download($archive), 2) === 'PK', 'ZIP of the archive');
$sf->batches()->delete($batch['id']);
$sf->uploads()->delete($upload['upload_id']);

$step('v1: captures() and accessibilityChecks() (503 counts as skipped)');
$optional = static function (callable $fn): ?array {
    try {
        return $fn();
    } catch (SwitchfileException $e) {
        if ($e->getStatus() !== 503) {
            throw $e;
        }
        echo "   skipped: {$e->getErrorCode()}\n";
        return null;
    }
};
$capture = $optional(fn () => $sf->captures()->create(['url' => $e2eUrl, 'output_format' => 'pdf'], ['wait' => true, 'timeout' => 300]));
if ($capture !== null) {
    $check($capture['status'] === 'finished', 'capture finished');
    $check($head($sf->download($capture['result']['file']), 4) === '%PDF', 'PDF of the capture');
    $sf->captures()->delete($capture['id']);
}
$a11y = $optional(fn () => $sf->accessibilityChecks()->create("{$out}/php-letter.pdf", ['wait' => true, 'timeout' => 300]));
if ($a11y !== null) {
    $check($a11y['status'] === 'finished' && $a11y['result']['manual_review_needed'] === true, 'check finished');
    $sf->accessibilityChecks()->delete($a11y['id']);
}

$step('v2: convertFormats, health, webhooks()->list');
$check(in_array('jpg', array_column($sf->convertFormats(['filter[input_format]' => 'png']), 'output_format'), true), 'convertFormats');
$check($sf->health() === ['status' => 'ok'], 'health');
$check(is_array($sf->webhooks()->list()), 'webhooks()->list');
echo "== PHP SDK E2E passed\n";
