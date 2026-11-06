<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP\Test;

/** @internal Newline-delimited JSON on a worker's dedicated TCP connection. */
final class Channel
{
    private string $buffer = '';
    public int $bytesRead = 0;

    /** The parent uses nonblocking reads; workers use blocking writes with partial-write handling. */
    public function __construct(public readonly mixed $stream) {}

    public function send(array $message): void
    {
        $bytes = json_encode($message, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        for ($offset = 0, $length = strlen($bytes); $offset < $length; $offset += $written) {
            $written = @fwrite($this->stream, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Worker result connection closed while writing.');
            }
        }
    }

    /** Consume one available chunk, retaining partial messages until a later readable event. */
    public function receive(): \Generator
    {
        $chunk = @fread($this->stream, 65536);
        if ($chunk === false) {
            throw new \RuntimeException('Could not read worker result connection.');
        }
        $this->bytesRead = strlen($chunk);
        $this->buffer .= $chunk;
        while (($end = strpos($this->buffer, "\n")) !== false) {
            $message = json_decode(substr($this->buffer, 0, $end), true, flags: JSON_THROW_ON_ERROR);
            $this->buffer = substr($this->buffer, $end + 1);
            if (!is_array($message) || !is_string($message['type'] ?? null)) {
                throw new \RuntimeException('Invalid worker message.');
            }
            yield $message;
        }
        if (feof($this->stream) && $this->buffer !== '') {
            throw new \RuntimeException('Worker disconnected with an incomplete message.');
        }
    }

    public function complete(): bool
    {
        return $this->buffer === '';
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
