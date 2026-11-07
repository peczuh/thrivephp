<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP\Test;

/** Portable failure details, captured while author source files are still available. */
final class Diagnostic
{
    /** Each runner owns its root so nested runs cannot change the outer report's paths. */
    public function __construct(private string $root) {}

    /** Only files within the resolved project root become relative. */
    public function path(string $file): string
    {
        $file = realpath($file) ?: $file;
        $prefix = rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
    }

    public function location(?string $file, ?int $line): ?array
    {
        if ($file === null || $line === null) {
            return null;
        }
        $source = [];
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if ($lines !== false) {
            for ($index = max(1, $line - 2); $index <= min(count($lines), $line + 2); $index++) {
                $source[] = ['line' => $index, 'text' => $lines[$index - 1]];
            }
        }
        return ['file' => $this->path($file), 'line' => $line, 'source' => $source];
    }

    public function author(): ?array
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? null;
            if ($file !== null && !str_starts_with($file, dirname(__DIR__) . DIRECTORY_SEPARATOR)) {
                return $this->location($file, $frame['line'] ?? null);
            }
        }
        return null;
    }

    public function error(\Throwable $error): array
    {
        if ($error instanceof AssertionFailure) {
            $location = null;
            foreach ([['file' => $error->getFile(), 'line' => $error->getLine()], ...$error->getTrace()] as $frame) {
                if (isset($frame['file']) && !str_starts_with($frame['file'], dirname(__DIR__) . DIRECTORY_SEPARATOR)) {
                    $location = $this->location($frame['file'], $frame['line'] ?? null);
                    break;
                }
            }
            return ['class' => 'AssertionFailure', 'message' => $error->getMessage(), 'location' => $location];
        }
        // Shorten frame and closure locations without stripping arbitrary argument strings.
        $prefix = preg_quote(rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, '~');
        return ['class' => get_class($error), 'message' => $error->getMessage(),
            'location' => $this->location($error->getFile(), $error->getLine()),
            'stack' => preg_replace('~(^#\d+ |\{closure:)' . $prefix . '~m', '$1', $error->getTraceAsString()),
            'previous' => $error->getPrevious() === null ? null : $this->error($error->getPrevious())];
    }
}
