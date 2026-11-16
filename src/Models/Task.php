<?php

declare(strict_types=1);

namespace Switchfile\Models;

/**
 * Task of a job (/v2). To create: new Task('convert', 'convert-my-file') and set('output_format', 'pdf'),
 * as in the CloudConvert SDK. After creation the SDK fills the fields from the response.
 */
class Task
{
    public const STATUS_WAITING = 'waiting';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_ERROR = 'error';

    protected ?string $id = null;
    protected ?string $jobId = null;
    protected ?string $status = null;
    protected ?string $message = null;
    protected ?string $code = null;
    protected ?int $credits = null;
    protected ?string $createdAt = null;
    protected ?string $startedAt = null;
    protected ?string $endedAt = null;
    /** @var list<string> */
    protected array $dependsOnTaskIds = [];
    protected ?string $retryOfTaskId = null;
    protected ?string $engine = null;
    protected ?string $engineVersion = null;
    protected ?object $result = null;
    /** @var array<string, mixed> */
    protected array $payload = [];
    /** @var array<string, mixed> */
    protected array $raw = [];

    public function __construct(protected ?string $operation = null, protected ?string $name = null)
    {
    }

    /** Set an option for creation, e.g. set('input', 'import-my-file'). */
    public function set(string $key, mixed $value): self
    {
        $this->payload[$key] = $value;
        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->payload[$key] ?? $default;
    }

    /** @return array<string, mixed> data for POST /v2/jobs */
    public function getPayload(): array
    {
        return ['operation' => $this->operation] + $this->payload;
    }

    /** @param array<string, mixed> $data task from the API */
    public static function fromArray(array $data): self
    {
        return (new self())->populate($data);
    }

    /** @param array<string, mixed> $data */
    public function populate(array $data): self
    {
        $this->raw = $data;
        $this->id = $data['id'] ?? $this->id;
        $this->jobId = $data['job_id'] ?? $this->jobId;
        $this->name = $data['name'] ?? $this->name;
        $this->operation = $data['operation'] ?? $this->operation;
        $this->status = $data['status'] ?? null;
        $this->message = $data['message'] ?? null;
        $this->code = $data['code'] ?? null;
        $this->credits = isset($data['credits']) ? (int) $data['credits'] : null;
        $this->createdAt = $data['created_at'] ?? null;
        $this->startedAt = $data['started_at'] ?? null;
        $this->endedAt = $data['ended_at'] ?? null;
        $this->dependsOnTaskIds = $data['depends_on_task_ids'] ?? [];
        $this->retryOfTaskId = $data['retry_of_task_id'] ?? null;
        $this->engine = $data['engine'] ?? null;
        $this->engineVersion = $data['engine_version'] ?? null;
        // result as an object as in the CloudConvert SDK: $task->getResult()->form->url, $file->url
        $this->result = isset($data['result']) && is_array($data['result'])
            ? json_decode(json_encode($data['result'], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR)
            : null;
        if (isset($data['payload']) && is_array($data['payload'])) {
            $this->payload = $data['payload'];
        }
        return $this;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getJobId(): ?string
    {
        return $this->jobId;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getOperation(): ?string
    {
        return $this->operation;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function getCredits(): ?int
    {
        return $this->credits;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return self::date($this->createdAt);
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return self::date($this->startedAt);
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return self::date($this->endedAt);
    }

    /** @return list<string> */
    public function getDependsOnTaskIds(): array
    {
        return $this->dependsOnTaskIds;
    }

    public function getRetryOfTaskId(): ?string
    {
        return $this->retryOfTaskId;
    }

    public function getEngine(): ?string
    {
        return $this->engine;
    }

    public function getEngineVersion(): ?string
    {
        return $this->engineVersion;
    }

    /** Result as an object: files (filename, size, url), for import/upload form or upload. */
    public function getResult(): ?object
    {
        return $this->result;
    }

    /** @return array<string, mixed> task as returned by the API */
    public function toArray(): array
    {
        return $this->raw;
    }

    private static function date(?string $v): ?\DateTimeImmutable
    {
        return $v === null ? null : new \DateTimeImmutable($v);
    }
}
