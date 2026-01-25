<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Http;

use Psr\Http\Message\StreamInterface;

/**
 * Simple string-based stream implementation
 *
 * @version 1.0.0
 */
final class StringStream implements StreamInterface
{
    private string $content;
    private int $position = 0;

    public function __construct(string $content = '')
    {
        $this->content = $content;
    }

    public function __toString(): string
    {
        return $this->content;
    }

    public function close(): void
    {
        $this->content = '';
        $this->position = 0;
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return strlen($this->content);
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->position >= strlen($this->content);
    }

    public function isSeekable(): bool
    {
        return true;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->position = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => strlen($this->content) + $offset,
            default => throw new \RuntimeException('Invalid whence'),
        };
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function isWritable(): bool
    {
        return true;
    }

    public function write(string $string): int
    {
        $before = substr($this->content, 0, $this->position);
        $after = substr($this->content, $this->position + strlen($string));
        $this->content = $before . $string . $after;
        $length = strlen($string);
        $this->position += $length;

        return $length;
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        $result = substr($this->content, $this->position, $length);
        $this->position += strlen($result);

        return $result;
    }

    public function getContents(): string
    {
        $result = substr($this->content, $this->position);
        $this->position = strlen($this->content);

        return $result;
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
