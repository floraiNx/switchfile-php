<?php

declare(strict_types=1);

namespace Switchfile\Models;

/**
 * Tasks of a job with filters as in the CloudConvert SDK: whereName('x')[0], whereStatus(Task::STATUS_FINISHED).
 *
 * @extends \ArrayObject<int, Task>
 */
class TaskCollection extends \ArrayObject
{
    public function whereName(string $name): self
    {
        return $this->filter(static fn (Task $t) => $t->getName() === $name);
    }

    public function whereStatus(string $status): self
    {
        return $this->filter(static fn (Task $t) => $t->getStatus() === $status);
    }

    public function whereOperation(string $operation): self
    {
        return $this->filter(static fn (Task $t) => $t->getOperation() === $operation);
    }

    public function filter(callable $fn): self
    {
        return new self(array_values(array_filter($this->getArrayCopy(), $fn)));
    }
}
