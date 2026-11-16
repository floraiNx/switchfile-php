<?php

declare(strict_types=1);

namespace Switchfile\Resources;

use Switchfile\Models\Job;
use Switchfile\Switchfile;
use Switchfile\Transport\HttpTransport;

/** /v2/jobs, methods like $cloudconvert->jobs(). */
class JobsResource
{
    public function __construct(private readonly HttpTransport $http, private readonly float $pollInterval)
    {
    }

    /**
     * Create a job. Fills the given object (ID, tasks with upload form) and returns it. With
     * setStore(false) it runs without storage via /sync/v2/jobs and comes back finished (files in content).
     */
    public function create(Job $job): Job
    {
        $payload = $job->getPayload();
        if (($payload['store'] ?? null) === false) {
            // The header raises the JSON body limit to the file limit without storage (base64 of 50 MB).
            $res = $this->http->request('POST', '/sync/v2/jobs', json: $payload, timeout: max($this->http->getTimeout(), Switchfile::NO_STORE_TIMEOUT), retry: false, headers: ['Switchfile-Store: false']);
            return $job->populate($res['data']);
        }
        // POST /v2/jobs has no idempotency key: only 429 is retried (then the API has created nothing).
        $res = $this->http->request('POST', '/v2/jobs', json: $payload, retry: false);
        return $job->populate($res['data']);
    }

    /** @param array<string, scalar> $query e.g. ['include' => 'tasks'] */
    public function get(string $id, array $query = []): Job
    {
        return Job::fromArray($this->http->request('GET', '/v2/jobs/' . rawurlencode($id), $query)['data']);
    }

    /**
     * Waits until the job is finished or error. A Job object is filled and returned.
     * Does not throw on error.
     */
    public function wait(Job|string $job, ?float $timeout = null): Job
    {
        $id = is_string($job) ? $job : (string) $job->getId();
        $data = Poll::until(
            fn () => $this->http->request('GET', '/v2/jobs/' . rawurlencode($id))['data'],
            static fn (array $d) => in_array($d['status'] ?? null, ['finished', 'error'], true),
            $timeout,
            $this->pollInterval
        );
        return is_string($job) ? Job::fromArray($data) : $job->populate($data);
    }

    /**
     * @param array<string, scalar> $query e.g. ['filter[status]' => 'finished', 'filter[tag]' => 'x', 'per_page' => 50]
     * @return list<Job>
     */
    public function all(array $query = []): array
    {
        $res = $this->http->request('GET', '/v2/jobs', $query);
        return array_map(static fn (array $j) => Job::fromArray($j), $res['data']);
    }

    public function delete(Job|string $job): void
    {
        $id = is_string($job) ? $job : (string) $job->getId();
        $this->http->request('DELETE', '/v2/jobs/' . rawurlencode($id));
    }
}
