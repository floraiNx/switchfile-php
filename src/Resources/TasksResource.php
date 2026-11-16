<?php

declare(strict_types=1);

namespace Switchfile\Resources;

use Switchfile\Exceptions\SwitchfileException;
use Switchfile\Models\Task;
use Switchfile\Transport\FileSource;
use Switchfile\Transport\HttpTransport;

/** /v2/tasks, methods like $cloudconvert->tasks(). */
class TasksResource
{
    public function __construct(private readonly HttpTransport $http, private readonly float $pollInterval)
    {
    }

    /** @param array<string, scalar> $query e.g. ['include' => 'payload'] */
    public function get(string $id, array $query = []): Task
    {
        return Task::fromArray($this->http->request('GET', '/v2/tasks/' . rawurlencode($id), $query)['data']);
    }

    public function wait(Task|string $task, ?float $timeout = null): Task
    {
        $id = is_string($task) ? $task : (string) $task->getId();
        $data = Poll::until(
            fn () => $this->http->request('GET', '/v2/tasks/' . rawurlencode($id))['data'],
            static fn (array $d) => in_array($d['status'] ?? null, ['finished', 'error'], true),
            $timeout,
            $this->pollInterval
        );
        return is_string($task) ? Task::fromArray($data) : $task->populate($data);
    }

    /**
     * @param array<string, scalar> $query e.g. ['filter[job_id]' => $id, 'filter[status]' => 'error']
     * @return list<Task>
     */
    public function all(array $query = []): array
    {
        return array_map(static fn (array $t) => Task::fromArray($t), $this->http->request('GET', '/v2/tasks', $query)['data']);
    }

    public function cancel(Task|string $task): Task
    {
        $id = is_string($task) ? $task : (string) $task->getId();
        $data = $this->http->request('POST', '/v2/tasks/' . rawurlencode($id) . '/cancel', retry: true)['data'];
        return is_string($task) ? Task::fromArray($data) : $task->populate($data);
    }

    /** Retry a task; returns the new task. */
    public function retry(Task|string $task): Task
    {
        $id = is_string($task) ? $task : (string) $task->getId();
        return Task::fromArray($this->http->request('POST', '/v2/tasks/' . rawurlencode($id) . '/retry', retry: false)['data']);
    }

    /**
     * Upload a file for an import/upload task, as in the CloudConvert SDK: $file is a string (contents), a
     * stream resource (fopen) or an \SplFileInfo (path). Uses the direct upload (result->upload: PUT, then
     * upload-complete), otherwise the form (result->form). Fills the task from the response.
     *
     * @param string|resource|\SplFileInfo $file
     */
    public function upload(Task $task, mixed $file, ?string $fileName = null): Task
    {
        if ($task->getOperation() !== 'import/upload') {
            throw new SwitchfileException("Task {$task->getName()} is not an import/upload task.", 'INVALID_TASK');
        }
        $source = FileSource::fromUploadArgument($file, $fileName);
        $result = $task->getResult();
        try {
            if (isset($result->upload->url)) {
                $headers = FileSource::putHeaders((array) ($result->upload->headers ?? []));
                $this->http->sendSigned('PUT', $result->upload->url, $source->openStream(), $headers, $source->size());
                $body = $source->filename() !== null ? ['filename' => $source->filename()] : [];
                $data = $this->http->request('POST', '/v2/tasks/' . rawurlencode((string) $task->getId()) . '/upload-complete', json: $body, retry: true)['data'];
                return $task->populate($data);
            }
            if (isset($result->form->url)) {
                $fields = [];
                foreach ((array) ($result->form->parameters ?? []) as $k => $v) {
                    $fields[$k] = (string) $v;
                }
                $fields['file'] = $source->curlFile();
                $res = $this->http->sendSigned('POST', $result->form->url, $fields);
                if (is_array($res) && isset($res['data']) && is_array($res['data'])) {
                    return $task->populate($res['data']);
                }
                return $task->populate($this->http->request('GET', '/v2/tasks/' . rawurlencode((string) $task->getId()))['data']);
            }
        } finally {
            $source->cleanup();
        }
        throw new SwitchfileException("Task {$task->getName()} has no upload URL. Pass the task from the job returned by jobs()->create().", 'INVALID_TASK');
    }
}
