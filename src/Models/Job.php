<?php

declare(strict_types=1);

namespace Switchfile\Models;

/**
 * Job (/v2). To create: (new Job())->setTag('x')->addTask(new Task('import/url', 'import-my-file')) …, as in the
 * CloudConvert SDK. jobs()->create($job) and jobs()->wait($job) fill the same object from the response.
 */
class Job
{
    public const STATUS_WAITING = 'waiting';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_ERROR = 'error';

    protected ?string $id = null;
    protected ?string $tag = null;
    protected ?string $status = null;
    protected ?string $webhookUrl = null;
    protected ?bool $store = null;
    protected ?string $createdAt = null;
    protected ?string $startedAt = null;
    protected ?string $endedAt = null;
    protected TaskCollection $tasks;
    /** @var array<string, mixed> */
    protected array $raw = [];

    public function __construct()
    {
        $this->tasks = new TaskCollection();
    }

    public function addTask(Task $task): self
    {
        $this->tasks->append($task);
        return $this;
    }

    public function setTag(?string $tag): self
    {
        $this->tag = $tag;
        return $this;
    }

    public function setWebhookUrl(?string $url): self
    {
        $this->webhookUrl = $url;
        return $this;
    }

    /**
     * false: without storage (exactly import/base64, convert, export/url). jobs()->create() then sends the job to
     * /sync/v2/jobs, the files are base64 in content of the export/url files.
     */
    public function setStore(?bool $store): self
    {
        $this->store = $store;
        return $this;
    }

    /** @return array<string, mixed> data for POST /v2/jobs */
    public function getPayload(): array
    {
        $tasks = [];
        foreach ($this->tasks as $task) {
            $tasks[(string) $task->getName()] = $task->getPayload();
        }
        $payload = ['tasks' => $tasks];
        if ($this->tag !== null) {
            $payload['tag'] = $this->tag;
        }
        if ($this->webhookUrl !== null) {
            $payload['webhook_url'] = $this->webhookUrl;
        }
        if ($this->store !== null) {
            $payload['store'] = $this->store;
        }
        return $payload;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return (new self())->populate($data);
    }

    /** @param array<string, mixed> $data job from the API */
    public function populate(array $data): self
    {
        $this->raw = $data;
        $this->id = $data['id'] ?? $this->id;
        $this->tag = array_key_exists('tag', $data) ? $data['tag'] : $this->tag;
        $this->status = $data['status'] ?? null;
        $this->createdAt = $data['created_at'] ?? null;
        $this->startedAt = $data['started_at'] ?? null;
        $this->endedAt = $data['ended_at'] ?? null;
        if (isset($data['tasks']) && is_array($data['tasks'])) {
            $this->tasks = new TaskCollection(array_map(static fn (array $t) => Task::fromArray($t), $data['tasks']));
        }
        return $this;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getTag(): ?string
    {
        return $this->tag;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function getWebhookUrl(): ?string
    {
        return $this->webhookUrl;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt === null ? null : new \DateTimeImmutable($this->createdAt);
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt === null ? null : new \DateTimeImmutable($this->startedAt);
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt === null ? null : new \DateTimeImmutable($this->endedAt);
    }

    public function getTasks(): TaskCollection
    {
        return $this->tasks;
    }

    /** @return list<object> files (filename, size, url; without storage content instead of url) of all finished export/url tasks */
    public function getExportUrls(): array
    {
        $files = [];
        foreach ($this->tasks->whereOperation('export/url')->whereStatus(Task::STATUS_FINISHED) as $task) {
            foreach ($task->getResult()->files ?? [] as $file) {
                $files[] = $file;
            }
        }
        return $files;
    }

    /** @return array<string, mixed> job as returned by the API */
    public function toArray(): array
    {
        return $this->raw;
    }
}
