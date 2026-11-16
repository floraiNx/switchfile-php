<?php

declare(strict_types=1);

namespace Switchfile\Transport;

/**
 * Small stream for downloads, with the methods that code written for the CloudConvert SDK uses
 * (getHttpTransport()->download($url)->detach() or ->getContents()).
 */
class Stream
{
    /** @var resource|null */
    private $resource;

    /** @param resource $resource */
    public function __construct($resource)
    {
        $this->resource = $resource;
    }

    /** Returns the PHP resource; the stream is empty afterwards. @return resource|null */
    public function detach()
    {
        $r = $this->resource;
        $this->resource = null;
        return $r;
    }

    public function getContents(): string
    {
        return $this->resource === null ? '' : (string) stream_get_contents($this->resource);
    }

    public function getSize(): ?int
    {
        if ($this->resource === null) {
            return null;
        }
        $stat = fstat($this->resource);
        return $stat === false ? null : (int) $stat['size'];
    }

    public function close(): void
    {
        if ($this->resource !== null) {
            fclose($this->resource);
            $this->resource = null;
        }
    }

    public function __toString(): string
    {
        if ($this->resource === null) {
            return '';
        }
        rewind($this->resource);
        return $this->getContents();
    }
}
