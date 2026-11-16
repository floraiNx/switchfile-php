<?php

declare(strict_types=1);

namespace Switchfile\Transport;

use Switchfile\Exceptions\SwitchfileException;

/**
 * File for uploads with path, name and size. Paths and file streams are sent directly from disk;
 * string contents and other streams go to a temp file first (deleted afterwards).
 */
final class FileSource
{
    private function __construct(private readonly string $path, private readonly ?string $filename, private readonly bool $temporary)
    {
    }

    /**
     * Argument of tasks()->upload() as in the CloudConvert SDK: string = file contents, resource, \SplFileInfo = path.
     */
    public static function fromUploadArgument(mixed $file, ?string $filename): self
    {
        if (is_string($file)) {
            return self::fromContent($file, $filename);
        }
        return self::fromOther($file, $filename);
    }

    /**
     * Argument of convert(): string = path, resource, \SplFileInfo, ['content' => ..., 'filename' => ...].
     */
    public static function fromConvertArgument(mixed $file, ?string $filename): self
    {
        if (is_array($file) && array_key_exists('content', $file)) {
            return self::fromContent((string) $file['content'], $filename ?? ($file['filename'] ?? null));
        }
        if (is_string($file)) {
            return self::fromPath($file, $filename);
        }
        return self::fromOther($file, $filename);
    }

    /**
     * First byte and file name of a convert() argument, without a temp file. A seekable stream of the caller is
     * restored to its position afterwards, so that a following convert() with the same stream sends the whole file.
     *
     * @return array{0: string, 1: ?string}
     */
    public static function head(mixed $file, ?string $filename): array
    {
        if (is_array($file) && array_key_exists('content', $file)) {
            return [substr((string) $file['content'], 0, 1), $filename ?? ($file['filename'] ?? null)];
        }
        if (is_resource($file) && self::plainFilePath($file) === null && stream_get_meta_data($file)['seekable']) {
            $pos = ftell($file);
            if ($pos !== false) {
                try {
                    return [(string) fread($file, 1), $filename];
                } finally {
                    fseek($file, $pos);
                }
            }
        }
        $source = self::fromConvertArgument($file, $filename);
        try {
            $fh = $source->openStream();
            try {
                return [(string) fread($fh, 1), $source->filename()];
            } finally {
                fclose($fh);
            }
        } finally {
            $source->cleanup();
        }
    }

    /** @param resource $file */
    private static function plainFilePath($file): ?string
    {
        $meta = stream_get_meta_data($file);
        $uri = $meta['uri'] ?? null;
        return ($meta['wrapper_type'] ?? '') === 'plainfile' && is_string($uri) && is_file($uri) ? $uri : null;
    }

    private static function fromOther(mixed $file, ?string $filename): self
    {
        if ($file instanceof \SplFileInfo) {
            return self::fromPath($file->getPathname(), $filename);
        }
        if (is_resource($file)) {
            $path = self::plainFilePath($file);
            if ($path !== null) {
                return self::fromPath($path, $filename);
            }
            $tmp = self::tempFile();
            $out = fopen($tmp, 'wb');
            stream_copy_to_stream($file, $out);
            fclose($out);
            return new self($tmp, $filename, true);
        }
        if (is_object($file) && method_exists($file, 'detach')) {
            // PSR-7 StreamInterface
            return self::fromOther($file->detach(), $filename);
        }
        throw new SwitchfileException('Unsupported file source. Pass a path, a stream resource or file contents.', 'INVALID_SOURCE');
    }

    private static function fromPath(string $path, ?string $filename): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new SwitchfileException("Could not read {$path}.", 'READ_FAILED');
        }
        return new self($path, $filename ?? basename($path), false);
    }

    private static function fromContent(string $content, ?string $filename): self
    {
        $tmp = self::tempFile();
        file_put_contents($tmp, $content);
        return new self($tmp, $filename, true);
    }

    private static function tempFile(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'switchfile-');
        if ($tmp === false) {
            throw new SwitchfileException('Could not create a temporary file.', 'WRITE_FAILED');
        }
        return $tmp;
    }

    public function filename(): ?string
    {
        return $this->filename;
    }

    public function size(): int
    {
        clearstatcache(true, $this->path);
        return (int) filesize($this->path);
    }

    /** @return resource */
    public function openStream()
    {
        $fh = fopen($this->path, 'rb');
        if ($fh === false) {
            throw new SwitchfileException("Could not read {$this->path}.", 'READ_FAILED');
        }
        return $fh;
    }

    public function curlFile(): \CURLFile
    {
        return new \CURLFile($this->path, 'application/octet-stream', $this->filename ?? 'file');
    }

    public function cleanup(): void
    {
        if ($this->temporary) {
            @unlink($this->path);
        }
    }

    /**
     * Header lines for the PUT to a signed URL: those from the API (filtered), otherwise Content-Type octet-stream.
     *
     * @param array<string, mixed> $headers
     * @return list<string>
     */
    public static function putHeaders(array $headers): array
    {
        $lines = [];
        $hasType = false;
        foreach (self::safeHeaders($headers) as $k => $v) {
            $hasType = $hasType || strcasecmp($k, 'content-type') === 0;
            $lines[] = "{$k}: {$v}";
        }
        if (!$hasType) {
            $lines[] = 'Content-Type: application/octet-stream';
        }
        return $lines;
    }

    /**
     * Take over the API's headers for a PUT, without Authorization, Host and length headers.
     *
     * @param array<string, mixed> $headers
     * @return array<string, string>
     */
    public static function safeHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            if ($v === null || is_array($v) || is_object($v)) {
                continue;
            }
            $value = (string) $v;
            // Discard values containing CR, LF or NUL instead of repairing them.
            if (strpbrk($value, "\r\n\0") !== false) {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9-]{1,64}$/', (string) $k) && !preg_match('/^(authorization|host|content-length|transfer-encoding|connection|cookie)$/i', (string) $k)) {
                $out[(string) $k] = $value;
            }
        }
        return $out;
    }
}
