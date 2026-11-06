<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP\Test;

/** @internal One OR alternative whose file, method, and tag conditions must all hold. */
final class Selector
{
    /** Compiled patterns for supplied components; omitted components impose no condition. */
    private array $patterns = [];

    public function __construct(private string $selector)
    {
        if ($selector === '') {
            throw new \InvalidArgumentException('Test selector must not be empty.');
        }
        $remaining = str_starts_with($selector, '/') ? substr($selector, 1) : $selector;
        $components = [];
        // Split later components first. Earlier or repeated delimiters inside their regexes
        // stay literal; only the first occurrence in the remaining prefix is structural.
        foreach (['#!' => 'tags exclude', '##' => 'tags include', ':!' => 'cases exclude',
            '::' => 'cases include', '!!' => 'files exclude'] as $delimiter => $component) {
            $position = strpos($remaining, $delimiter);
            if ($position !== false) {
                $components[$component] = substr($remaining, $position + 2);
                $remaining = substr($remaining, 0, $position);
            }
        }
        if ($remaining !== '' || !$components) {
            $components['files include'] = $remaining;
        }
        foreach ($components as $component => $regex) {
            if ($regex === '') {
                throw new \InvalidArgumentException('Empty ' . $component . ' regex in selector ' . var_export($selector, true) . '.');
            }
            // A control delimiter preserves ordinary punctuation and quoted regex text verbatim.
            $this->patterns[$component] = "\x01" . $regex . "\x01";
            $this->matches($component, '');
        }
    }

    /** File paths are relative to the configured root, use / separators, and omit .php. */
    public function file(string $path): bool
    {
        return (!isset($this->patterns['files include']) || $this->matches('files include', $path))
            && (!isset($this->patterns['files exclude']) || !$this->matches('files exclude', $path));
    }

    /** Null identifies a callable check, which has no case method; tags include suite tags. */
    public function test(?string $method, array $tags): bool
    {
        return (!isset($this->patterns['cases include']) || ($method !== null && $this->matches('cases include', $method)))
            && (!isset($this->patterns['cases exclude']) || $method === null || !$this->matches('cases exclude', $method))
            && (!isset($this->patterns['tags include']) || array_any($tags, fn ($tag) => $this->matches('tags include', $tag)))
            && (!isset($this->patterns['tags exclude']) || !array_any($tags, fn ($tag) => $this->matches('tags exclude', $tag)));
    }

    /** Validate at construction and report runtime PCRE failures rather than treating them as nonmatches. */
    private function matches(string $component, string $value): bool
    {
        $result = @preg_match($this->patterns[$component], $value);
        if ($result === false) {
            $error = preg_last_error_msg();
            error_clear_last();
            throw new \InvalidArgumentException('Invalid ' . $component . ' regex in selector '
                . var_export($this->selector, true) . ': ' . $error);
        }
        return $result === 1;
    }
}
