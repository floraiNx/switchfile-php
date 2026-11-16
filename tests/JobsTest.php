<?php

declare(strict_types=1);

namespace Switchfile\Tests;

use Switchfile\Exceptions\HttpClientException;
use Switchfile\Exceptions\HttpServerException;
use Switchfile\Exceptions\SwitchfileException;
use Switchfile\Models\Job;
use Switchfile\Models\Task;
use Switchfile\Resources\JobsResource;
use Switchfile\Switchfile;
use Switchfile\Transport\HttpTransport;

/** /v2 as in the CloudConvert README: build, create, upload, wait, export URLs, download. */
final class JobsTest extends MockTestCase
{
    private function uploadJob(): Job
    {
        return (new Job())
            ->setTag('order-42')
            ->addTask(new Task('import/upload', 'upload-my-file'))
            ->addTask((new Task('convert', 'convert-my-file'))->set('input', 'upload-my-file')->set('output_format', 'pdf')->set('some_option', 'x'))
            ->addTask((new Task('export/url', 'export-my-file'))->set('input', 'convert-my-file'));
    }

    public function testCloudConvertFlowWithForm(): void
    {
        $sf = $this->client();
        $job = $this->uploadJob();
        $sf->jobs()->create($job);
        self::assertNotNull($job->getId());
        self::assertSame('order-42', $job->getTag());

        $uploadTask = $job->getTasks()->whereName('upload-my-file')[0];
        self::assertStringContainsString('/upload/', $uploadTask->getResult()->form->url);
        $path = $this->tempFile('letter.docx', 'hello world');
        $sf->tasks()->upload($uploadTask, fopen($path, 'r'), 'letter.docx');
        self::assertSame(Task::STATUS_FINISHED, $uploadTask->getStatus());

        $sf->jobs()->wait($job);
        self::assertSame(Job::STATUS_FINISHED, $job->getStatus());
        $files = $job->getExportUrls();
        self::assertCount(1, $files);
        self::assertSame('letter.pdf', $files[0]->filename);

        // as in the CloudConvert README: getHttpTransport()->download($url)->detach()
        $source = $sf->getHttpTransport()->download($files[0]->url)->detach();
        self::assertSame(self::resultFor('hello world', 'pdf'), stream_get_contents($source));

        $up = $this->requestsTo('/upload/')[0];
        self::assertArrayNotHasKey('authorization', $up['headers']);
        self::assertArrayNotHasKey('authorization', $this->requestsTo('/files/')[0]['headers']);
        $create = $this->requestsTo('/v2/jobs', 'POST')[0];
        self::assertSame('Bearer ' . self::TEST_KEY, $create['headers']['authorization']);
        self::assertStringStartsWith('switchfile-php/', $create['headers']['user-agent']);
        self::assertSame('x', json_decode($create['body'], true)['tasks']['convert-my-file']['some_option']);
    }

    public function testDirectUploadWithHeadersAndUploadComplete(): void
    {
        $this->resetMock(['direct_upload' => true]);
        $sf = $this->client();
        $job = $sf->jobs()->create($this->uploadJob());
        $task = $job->getTasks()->whereOperation('import/upload')[0];
        self::assertSame('PUT', $task->getResult()->upload->method);
        $sf->tasks()->upload($task, new \SplFileInfo($this->tempFile('photo.png', str_repeat("\x07", 4096))));
        self::assertSame('finished', $task->getStatus());

        $put = $this->requestsTo('/direct/', 'PUT')[0];
        self::assertSame('1', $put['headers']['x-amz-test']);
        self::assertArrayNotHasKey('authorization', $put['headers']);
        self::assertSame(4096, $put['body_size']);
        $complete = $this->requestsTo('/v2/tasks/')[0];
        self::assertStringEndsWith('/upload-complete', $complete['path']);
        self::assertSame(['filename' => 'photo.png'], json_decode($complete['body'], true));

        $done = $sf->jobs()->wait($job->getId());
        $out = sys_get_temp_dir() . '/sf-php-out-' . bin2hex(random_bytes(4)) . '.pdf';
        self::assertSame($out, $sf->download($done->getExportUrls()[0], $out));
        self::assertSame(self::resultFor(str_repeat("\x07", 4096), 'pdf'), file_get_contents($out));
    }

    public function testUploadWithStringContentLikeCloudConvert(): void
    {
        $sf = $this->client();
        $job = $sf->jobs()->create($this->uploadJob());
        $sf->tasks()->upload($job->getTasks()->whereName('upload-my-file')[0], 'file contents', 'a.docx');
        $done = $sf->jobs()->wait($job);
        self::assertSame(self::resultFor('file contents', 'pdf'), $sf->download($done->getExportUrls()[0]));
    }

    public function testGetAllDelete(): void
    {
        $sf = $this->client();
        $job = (new Job())->setTag('t-1')
            ->addTask((new Task('import/url', 'i'))->set('url', 'https://example.com/a.docx'))
            ->addTask((new Task('convert', 'c'))->set('input', 'i')->set('output_format', 'pdf'))
            ->addTask((new Task('export/url', 'e'))->set('input', 'c'));
        $sf->jobs()->create($job);
        self::assertSame($job->getId(), $sf->jobs()->get($job->getId())->getId());
        $ids = array_map(static fn (Job $j) => $j->getId(), $sf->jobs()->all(['filter[tag]' => 't-1']));
        self::assertContains($job->getId(), $ids);
        $sf->jobs()->delete($job);
        try {
            $sf->jobs()->get($job->getId());
            self::fail('NOT_FOUND expected');
        } catch (HttpClientException $e) {
            self::assertSame('NOT_FOUND', $e->getErrorCode());
            self::assertSame(404, $e->getStatus());
        }
    }

    public function testTasksAllCancelRetryWait(): void
    {
        $sf = $this->client();
        $job = $sf->jobs()->create($this->uploadJob());
        $tasks = $sf->tasks()->all(['filter[job_id]' => $job->getId()]);
        self::assertCount(3, $tasks);
        $conv = $job->getTasks()->whereOperation('convert')[0];
        self::assertSame('convert-my-file', $sf->tasks()->get($conv->getId())->getName());
        self::assertSame('CANCELLED', $sf->tasks()->cancel($conv)->getCode());
        self::assertSame('error', $sf->tasks()->wait($conv->getId())->getStatus());
        self::assertSame($conv->getId(), $sf->tasks()->retry($conv)->getRetryOfTaskId());
    }

    public function testTimeoutWithoutStorageMatchesApi(): void
    {
        // 120 s was shorter than the time the API allows a request without storage
        // (upload 120 s, conversion 90 s, sending the response 180 s).
        self::assertSame(120 + 90 + 180, Switchfile::NO_STORE_TIMEOUT);
        $seen = new \ArrayObject();
        $client = $this->client();
        $http = new class (self::TEST_KEY, $this->url, 60.0, 0, 'test', $seen) extends HttpTransport {
            public function __construct(string $key, string $base, float $timeout, int $retries, string $ua, private readonly \ArrayObject $seen)
            {
                parent::__construct($key, $base, $timeout, $retries, $ua);
            }

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
                $this->seen[] = $timeout;
                return parent::request($method, $path, $query, $json, $multipart, $auth, $idempotencyKey, $timeout, $retry, $headers, $binary);
            }
        };
        (new \ReflectionProperty(Switchfile::class, 'http'))->setValue($client, $http);
        (new \ReflectionProperty(Switchfile::class, 'jobs'))->setValue($client, new JobsResource($http, 0.01));
        $client->convertWithoutStorage(['content' => 'x', 'filename' => 'a.png'], 'jpg');
        $job = (new Job())
            ->setStore(false)
            ->addTask((new Task('import/base64', 'import-1'))->set('file', base64_encode('x'))->set('filename', 'a.docx'))
            ->addTask((new Task('convert', 'convert-1'))->set('input', 'import-1')->set('output_format', 'pdf'))
            ->addTask((new Task('export/url', 'export-1'))->set('input', 'convert-1'));
        $client->jobs()->create($job);
        $client->convertWithoutStorage(['content' => 'x', 'filename' => 'a.png'], 'jpg', [], ['timeout' => 30]);
        self::assertSame([390.0, 390.0, 30.0], $seen->getArrayCopy());
    }

    public function testJobWithoutStorage(): void
    {
        $job = (new Job())
            ->setStore(false)
            ->addTask((new Task('import/base64', 'import-1'))->set('file', base64_encode('word document'))->set('filename', 'report.docx'))
            ->addTask((new Task('convert', 'convert-1'))->set('input', 'import-1')->set('output_format', 'pdf'))
            ->addTask((new Task('export/url', 'export-1'))->set('input', 'convert-1'));
        $this->client()->jobs()->create($job);
        self::assertSame(Job::STATUS_FINISHED, $job->getStatus());
        $files = $job->getExportUrls();
        self::assertSame('report.pdf', $files[0]->filename);
        self::assertSame(self::resultFor('word document', 'pdf'), base64_decode($files[0]->content));
        $post = $this->requestsTo('/sync/v2/jobs', 'POST')[0];
        self::assertSame('false', $post['headers']['switchfile-store']);
        self::assertFalse(json_decode((string) $post['body'], true)['store']);
        self::assertSame([], $this->requestsTo('/v2/jobs'));
    }

    public function testFailedJobDoesNotThrow(): void
    {
        $this->resetMock(['fail' => ['code' => 'INVALID_INPUT', 'message' => 'Damaged file.']]);
        $sf = $this->client();
        $job = $sf->jobs()->create($this->uploadJob());
        $sf->tasks()->upload($job->getTasks()[0], 'x', 'x.docx');
        $sf->jobs()->wait($job);
        self::assertSame('error', $job->getStatus());
        self::assertSame('INVALID_INPUT', $job->getTasks()->whereStatus(Task::STATUS_ERROR)[0]->getCode());
        self::assertSame([], $job->getExportUrls());
    }

    public function testWaitTimeout(): void
    {
        $sf = $this->client();
        $job = $sf->jobs()->create($this->uploadJob());
        try {
            $sf->jobs()->wait($job, 0.05);
            self::fail('TIMEOUT expected');
        } catch (SwitchfileException $e) {
            self::assertSame('TIMEOUT', $e->getErrorCode());
        }
    }

    public function testUsersMe(): void
    {
        $me = $this->client()->users()->me();
        self::assertSame('dev@example.com', $me->getEmail());
        self::assertTrue($me->isSandbox());
    }

    public function testErrorsAndRetries(): void
    {
        $wrong = new Switchfile(['api_key' => 'sf_test_wrong', 'base_url' => $this->url]);
        try {
            $wrong->users()->me();
            self::fail('UNAUTHENTICATED expected');
        } catch (HttpClientException $e) {
            self::assertSame(['UNAUTHENTICATED', 401], [$e->getErrorCode(), $e->getStatus()]);
        }

        try {
            $this->client()->jobs()->create(new Job());
            self::fail('INVALID_DATA expected');
        } catch (HttpClientException $e) {
            self::assertSame('INVALID_DATA', $e->getErrorCode());
            self::assertSame(['tasks' => ['At least one task is required.']], $e->getErrors());
        }

        $this->resetMock(['rate_limit_once' => '^/v2/jobs$']);
        self::assertNotNull($this->client()->jobs()->create($this->uploadJob())->getId());
        self::assertCount(2, $this->requestsTo('/v2/jobs', 'POST'));

        $this->resetMock(['server_error_once' => '^/v2/users/me$']);
        self::assertSame('user-1', $this->client()->users()->me()->getId());

        $this->resetMock(['server_error_once' => '^/v2/jobs$']);
        try {
            $this->client()->jobs()->create($this->uploadJob());
            self::fail('503 expected');
        } catch (HttpServerException $e) {
            self::assertSame(503, $e->getStatus());
        }
        self::assertCount(1, $this->requestsTo('/v2/jobs', 'POST'));

        $offline = new Switchfile(['api_key' => self::TEST_KEY, 'base_url' => 'http://127.0.0.1:9', 'max_retries' => 0]);
        try {
            $offline->users()->me();
            self::fail('NETWORK_ERROR expected');
        } catch (HttpClientException $e) {
            self::assertSame('NETWORK_ERROR', $e->getErrorCode());
        }
    }
}
