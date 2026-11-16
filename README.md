# switchfile for PHP

The official PHP SDK for the [switchfile](https://switchfile.io) file conversion API.

- The same classes and methods as the CloudConvert PHP SDK (`jobs()->create()`, `jobs()->wait()`,
  `tasks()->upload()`, `webhookHandler()->constructEvent()` and more), so existing code keeps working after you
  change the namespace and the constructor.
- `convert()` for the simple API: one call with a file and a target format, and you get the finished conversion back.
- No dependencies besides `ext-curl` and `ext-json`. PHP 8.1 or newer.

```bash
composer require switchfile/switchfile-php
```

## Moving from CloudConvert

Change the namespace and the constructor. Everything else stays the same.

```diff
- use \CloudConvert\CloudConvert;
- use \CloudConvert\Models\Job;
- use \CloudConvert\Models\Task;
+ use \Switchfile\Switchfile;
+ use \Switchfile\Models\Job;
+ use \Switchfile\Models\Task;

- $cloudconvert = new CloudConvert(['api_key' => getenv('CLOUDCONVERT_API_KEY'), 'sandbox' => false]);
+ $cloudconvert = new Switchfile(['api_key' => getenv('SWITCHFILE_API_KEY'), 'sandbox' => false]);
```

Exceptions move from `\CloudConvert\Exceptions\…` to `\Switchfile\Exceptions\…` with the same names
(`HttpClientException`, `SignatureVerificationException`, `UnexpectedDataException`).

Create an API key in the [dashboard](https://switchfile.io/dashboard/keys). Keys starting with `sf_test_` are
sandbox keys: they never use credits.

What is different:

| CloudConvert | switchfile |
|---|---|
| `'sandbox' => true` uses a separate sandbox server | The key decides: `sf_test_` keys are sandbox keys. `'sandbox' => true` refuses live keys, so tests cannot use credits by mistake |
| `'region' => 'us-east'` | Accepted and ignored; there is one region |
| PSR-18 HTTP client (Guzzle) required | No HTTP client needed; uses `ext-curl` |
| `getHttpTransport()->download()` returns a PSR-7 stream | Returns a stream with `detach()`, `getContents()` and `getSize()` |
| Operations such as `optimize`, `merge`, `capture-website`, `import/s3` | Tasks: `import/url`, `import/upload`, `import/base64`, `convert`, `export/url` |
| `signedUrlBuilder()` | Not available |
| `tasks()->create()`, `tasks()->delete()` | Not available; create tasks inside a job with `jobs()->create()` |

## Convert a file in one call

```php
use Switchfile\Switchfile;

$switchfile = new Switchfile(['api_key' => getenv('SWITCHFILE_API_KEY')]);

$conversion = $switchfile->convert('talk.mkv', 'mp4');
$switchfile->download($conversion, 'talk.mp4');
```

`convert($file, $to, $options, $params)` takes a file path, a stream (`fopen`), an `SplFileInfo`, a public URL as a
string (`'https://...'`) or `['url' => ...]`, `['upload_id' => ...]` for a file sent through `/v1/uploads`,
`['from' => $conversionId]` to convert an earlier input again, or `['content' => $bytes, 'filename' => 'a.png']`.

Files up to 50 MB are sent with the request, larger files (up to your plan's limit) go through an upload URL and are
read from disk while they upload. The call waits until the conversion is done and throws
`ConversionFailedException` if it fails. Conversions are arrays, as in the [API documentation](https://switchfile.io/developers).

```php
$conversion = $switchfile->convert(
    fopen('photo.heic', 'r'),
    'jpg',
    ['quality' => 80],                                   // settings for the conversion
    [
        'webhook_url' => 'https://example.com/hooks/switchfile',
        'wait' => true,                                  // false: return right away with status queued
        'timeout' => 600,                                // stop waiting after 10 minutes
    ]
);

foreach ($conversion['result']['files'] as $file) {     // e.g. one image per page
    $switchfile->download($file['url'], $file['filename']);
}
$bytes = $switchfile->download($conversion);             // contents as a string without a destination
```

A preset from your dashboard sets the target format and its settings. Pass `null` as `$to` and the ID or name in
`$params`; settings in `$options` override single values of the preset:

```php
$switchfile->convert('photo.heic', null, [], ['preset' => 'Web images']);
$switchfile->convert('photo.heic', null, ['quality' => 90], ['preset' => 'Web images']);
```

### Without storage

`convertWithoutStorage` sends the file with the request and gets the result back in the response. switchfile keeps
neither of them, only an entry in the usage history without the file name. Files can be up to 50 MB, several result
files come back as one ZIP.

```php
$result = $switchfile->convertWithoutStorage('invoice.docx', 'pdf');
file_put_contents($result['filename'] ?? 'invoice.pdf', $result['data']);
$result['conversion_id'];   // the entry in the usage history
$result['credits'];         // credits used
$result['notes'];           // notes on the result, an empty array without notes
```

For jobs, call `setStore(false)` on a job with three tasks, one of each: `import/base64`, `convert` and
`export/url`. The job comes back finished from `jobs()->create()`, and each file of the export task has its bytes as base64 in
`content`. `$switchfile->account()->setNoStore(true)` makes the account refuse every request that would store a file.
Keep using `convertWithoutStorage()` and `setStore(false)` after that: the other calls expect a stored conversion and
are rejected. Details are in the [guide](https://switchfile.io/docs/no-store).

### Notes and incomplete results

A conversion can finish with notes, for example when single pages of a long PDF could not be converted. A stored
conversion has them in `$conversion['result']['notes']`, and `$conversion['result']['incomplete']` is true when pages
are missing or were added as images without text. `convertWithoutStorage()` returns only the file, so the SDK reads
both from the response headers into `$result['notes']` and `$result['incomplete']`:

```php
$result = $switchfile->convertWithoutStorage('report.pdf', 'docx');
if ($result['incomplete']) {
    echo implode("\n", $result['notes']);
}

$conversion = $switchfile->convert('report.pdf', 'docx');
if ($conversion['result']['incomplete']) {
    echo implode("\n", $conversion['result']['notes']);
}
```

The notes name the pages, and the last one says whether the result was free. In a job, the file in `result.files` of
the `convert` task has `incomplete` and `notes`. See
[incomplete results](https://switchfile.io/docs/errors#incomplete).

More calls for the simple API:

```php
$switchfile->conversions()->get($id);           // status and result
$switchfile->conversions()->wait($id);          // until finished
$switchfile->conversions()->list([$id1, $id2]); // up to 50 at once
$switchfile->conversions()->delete($id);        // delete input and result now
$switchfile->formats('heic');                   // targets for one input format (no key needed)
$switchfile->account()->get();                  // credits, free quota, webhook signing secret
$switchfile->account()->update('https://example.com/hooks/switchfile');
$switchfile->estimate('talk.mkv', 'mp4');       // credits and price, nothing is created
```

The estimate only needs the file name, so the SDK sends just the first byte of the file, whatever its size.
A stream that cannot seek, such as `php://stdin`, is still read to the end first, even though only one byte is sent.
It does not check the upload size limit: a file that is too large is only rejected by `convert()`.

If a response got lost, look up what the request with your `idempotency_key` created. Nothing new is created:

```php
$conversion = $switchfile->conversions()->findByIdempotencyKey('order-1234'); // null if nothing
```

### Batches and ZIP files

```php
$batch = $switchfile->batches()->create([
    'conversions' => [
        ['url' => 'https://example.com/a.heic', 'to' => 'jpg'],
        ['from' => $earlierConversionId, 'to' => 'webp'],
    ],
    'archive' => 'zip',              // also offer all results as one ZIP file
], ['wait' => true]);                // default: return right away
$batch['counts'];                    // ['total' => …, 'finished' => …, 'failed' => …]
$switchfile->download($batch['archive'], 'results.zip');

$archive = $switchfile->archives()->create([$id1, $id2]); // ZIP of finished conversions
$switchfile->download($archive, 'results.zip');

$switchfile->batches()->get($batch['id'], 30);
$switchfile->batches()->wait($batch['id']);
$switchfile->batches()->delete($batch['id']); // with all its conversions
```

A batch creates all conversions or none. An error for one entry has its position in `$e->getIndex()`.

### Website captures

```php
$capture = $switchfile->captures()->create(
    ['url' => 'https://example.com/', 'output_format' => 'pdf', 'options' => ['page_format' => 'a4']],
    ['wait' => true]
);
$switchfile->download($capture['result']['file'], 'example.pdf');
```

`captures()->get($id, $wait)`, `wait($id)`, `delete($id)` and `findByIdempotencyKey($key)` work like their
counterparts for conversions. A failed capture comes back with `'status' => 'failed'` and `error`.
Once the capture is finished, `download($capture, 'example.pdf')` loads the same file.

### PDF accessibility checks

```php
$check = $switchfile->accessibilityChecks()->create('report.pdf', ['profile' => 'ua1', 'report_pdf' => 'en', 'wait' => true]);
$check['result']['summary'];         // how many machine-checkable criteria are met
$check['result']['topics'];          // problems with steps to fix them in Word, InDesign and Acrobat
$switchfile->download($check['result']['report_pdf_url'], 'report-accessibility.pdf');
```

The input is a file (up to 25 MB), a URL, `['url' => …]` or `['upload_id' => …]`. The result is not a statement that
the file meets legal requirements: a person who knows the content still has to review it. `get`, `wait`, `delete` and
`findByIdempotencyKey` work as for captures.

### Uploads

```php
$upload = $switchfile->uploads()->create('big-video.mov');
$switchfile->convert(['upload_id' => $upload['upload_id']], 'mp4');
$switchfile->uploads()->delete($upload['upload_id']);
```

`convert()` uploads large files by itself. Use `uploads()->create()` to send a file once and use it for several
conversions, a batch or a check.

## Jobs and tasks

```php
use Switchfile\Models\Job;
use Switchfile\Models\Task;

$job = (new Job())
    ->setTag('order-1234')
    ->addTask((new Task('import/url', 'import-my-file'))->set('url', 'https://example.com/report.docx'))
    ->addTask((new Task('convert', 'convert-my-file'))
        ->set('input', 'import-my-file')
        ->set('output_format', 'pdf'))
    ->addTask((new Task('export/url', 'export-my-file'))->set('input', 'convert-my-file'));

$switchfile->jobs()->create($job);
$switchfile->jobs()->wait($job);

foreach ($job->getExportUrls() as $file) {
    $source = $switchfile->getHttpTransport()->download($file->url)->detach();
    $dest = fopen('output/' . $file->filename, 'w');
    stream_copy_to_stream($source, $dest);
}
```

`jobs()->wait()` fills the job when it is `finished` or `error`; it does not throw for a failed job. Check
`$job->getStatus()` and `getCode()` and `getMessage()` of each task.

### Uploading files

```php
$job = (new Job())
    ->addTask(new Task('import/upload', 'upload-my-file'))
    ->addTask((new Task('convert', 'convert-my-file'))->set('input', 'upload-my-file')->set('output_format', 'pdf'))
    ->addTask((new Task('export/url', 'export-my-file'))->set('input', 'convert-my-file'));

$switchfile->jobs()->create($job);

$uploadTask = $job->getTasks()->whereName('upload-my-file')[0];
$switchfile->tasks()->upload($uploadTask, fopen('./file.pdf', 'r'), 'file.pdf');
```

As with CloudConvert, `upload()` takes a stream, a string with the file contents, or an `SplFileInfo` for a path.
Browsers can also upload directly with the form in `$uploadTask->getResult()->form` (`url` and `parameters`).

### All methods

| Method | Returns |
|---|---|
| `jobs()->create(Job $job)` | the same `Job`, filled |
| `jobs()->get($id)` | `Job` |
| `jobs()->wait(Job\|string $job, ?float $timeout)` | `Job` once it is `finished` or `error` |
| `jobs()->all(['filter[status]' => ..., 'filter[tag]' => ...])` | `Job[]` |
| `jobs()->delete(Job\|string $job)` | `void` |
| `$job->getExportUrls()` | files (`filename`, `size`, `url`) of all finished `export/url` tasks |
| `$job->getTasks()->whereName()`, `whereStatus()`, `whereOperation()` | `TaskCollection` |
| `tasks()->get($id)` | `Task` |
| `tasks()->wait(Task\|string $task)` | `Task` |
| `tasks()->all(['filter[job_id]' => ...])` | `Task[]` |
| `tasks()->cancel(Task\|string $task)` | `Task` |
| `tasks()->retry(Task\|string $task)` | the new `Task` |
| `tasks()->upload(Task $task, $file, $fileName)` | `Task`, filled |
| `users()->me()` | `User` with credits and today's free quota |
| `webhooks()->create(['url' => …, 'events' => [...]])` | webhook array with `signing_secret` |
| `webhooks()->list(['filter[url]' => …])` | list of webhooks (an account has at most one) |
| `webhooks()->delete($id)` | `void` |
| `webhooks()->verify($payload, $signature, $secret)` | `bool` |
| `webhookHandler()->constructEvent($payload, $signature, $secret)` | `WebhookEvent` (also `webhooks()->constructEvent()`) |
| `convertFormats(['filter[input_format]' => …, 'include' => 'options'])` | list of supported pairs (no key needed) |
| `health()` | `['status' => 'ok']` (no key needed) |
| `getHttpTransport()->download($url)` | stream with `detach()` |

`jobs()->wait()` and `tasks()->wait()` ask `GET /v2/jobs/{id}` again and again instead of calling
`/v2/jobs/{id}/wait`, which can hold a request for minutes.

## Webhooks

An account has one webhook, the same one as in the dashboard. Create it over the API like at CloudConvert:

```php
$webhook = $switchfile->webhooks()->create([
    'url' => 'https://example.com/hooks/switchfile',
    'events' => ['job.finished', 'job.failed', 'conversion.finished', 'conversion.failed'],
]);
$webhook['signing_secret'];          // only in this response
$switchfile->webhooks()->list();
$switchfile->webhooks()->delete($webhook['id']);
```

While the account has a webhook, `webhooks()->create()` throws `WEBHOOK_EXISTS`. Check incoming requests like this:

```php
$signingSecret = getenv('SWITCHFILE_WEBHOOK_SECRET');
$payload = @file_get_contents('php://input');
$signature = $_SERVER['HTTP_CLOUDCONVERT_SIGNATURE'] ?? $_SERVER['HTTP_SWITCHFILE_SIGNATURE'] ?? '';

try {
    $event = $switchfile->webhookHandler()->constructEvent($payload, $signature, $signingSecret);
} catch (\Switchfile\Exceptions\UnexpectedDataException | \Switchfile\Exceptions\SignatureVerificationException $e) {
    http_response_code(400);
    exit();
}

if ($job = $event->getJob()) {            // job.finished, job.failed
    $exportTask = $job->getTasks()->whereStatus(Task::STATUS_FINISHED)->whereName('export-it')[0];
} else {                                  // conversion.finished, conversion.failed
    $conversion = $event->getConversion();
}
```

Jobs send `job.finished` and `job.failed` with the header `CloudConvert-Signature`. Conversions from `convert()` send
`conversion.finished` and `conversion.failed` with `Switchfile-Signature`. Both are signed the same way. The signing
secret is `webhook_signing_secret` from `$switchfile->account()->get()` and in the dashboard.
`constructEventFromRequest($request, $secret)` takes a PSR-7 request.

## Settings

```php
$switchfile = new Switchfile([
    'api_key' => getenv('SWITCHFILE_API_KEY'),   // default: SWITCHFILE_API_KEY
    'base_url' => 'https://api.switchfile.io',   // default: SWITCHFILE_API_URL or https://api.switchfile.io
    'sandbox' => true,                           // only accept sandbox keys (sf_test_)
    'timeout' => 60,                             // per request, in seconds
    'max_retries' => 3,                          // network errors, 429 and 5xx
    'user_agent' => 'my-app/1.0',                // added to the User-Agent header
]);
```

Requests are repeated after network errors, `429 RATE_LIMIT_EXCEEDED` (after `Retry-After`) and server errors.
`convert()` sends an `Idempotency-Key`, so a repeated request never creates a second conversion.

## Errors

Every error is a `Switchfile\Exceptions\SwitchfileException` with a stable code from `getErrorCode()` (for example
`UNAUTHENTICATED`, `INVALID_DATA`, `UNSUPPORTED_PAIR`, `CREDITS_EXCEEDED`, `RATE_LIMIT_EXCEEDED`), the HTTP status
from `getStatus()` and, for `INVALID_DATA`, `getErrors()` per field. API errors are `HttpClientException` (4xx and
network) or `HttpServerException` (5xx). A failed conversion from `convert()` throws `ConversionFailedException` with
the conversion in `getConversion()`.

```php
use Switchfile\Exceptions\ConversionFailedException;
use Switchfile\Exceptions\SwitchfileException;

try {
    $switchfile->convert('notes.txt', 'mp4');
} catch (ConversionFailedException $e) {
    echo 'failed: ', $e->getErrorCode(), ' ', $e->getConversion()['id'];
} catch (SwitchfileException $e) {
    echo $e->getStatus(), ' ', $e->getErrorCode(), ' ', $e->getMessage();
}
```

## Links

- [API documentation](https://switchfile.io/developers)
- [Supported formats](https://switchfile.io/convert)

License: MIT, Aura UG (haftungsbeschränkt).
