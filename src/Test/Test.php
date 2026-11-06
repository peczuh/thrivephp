<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP\Test;

/** Describes a suite or case and configures its deadline, additional attempts, tags, and skipping. */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class Test
{
    /** Deadlines are seconds, checked after the case body or whole suite lifecycle completes. */
    public function __construct(
        public string $description,
        public ?float $deadline = null,
        public int $retry = 0,
        public array $tags = [],
        public bool $skip = false,
    )
    {
        if ($deadline !== null && (!is_finite($deadline) || $deadline <= 0)) {
            throw new \InvalidArgumentException('Test deadline must be positive and finite, or null.');
        }
        if ($retry < 0) {
            throw new \InvalidArgumentException('Test retry must be nonnegative.');
        }
        foreach ($tags as $tag) {
            if (!is_string($tag) || $tag === '') {
                throw new \InvalidArgumentException('Tags must be nonempty strings.');
            }
        }
    }
}
