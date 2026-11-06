<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP\Test;

/** Identifies a case and supplies the descriptive name used in its report. */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class CaseName
{
    /** Deadline is the maximum case-body duration in seconds, checked after it returns. */
    public function __construct(public string $name, public ?float $deadline = null)
    {
        if ($deadline !== null && (!is_finite($deadline) || $deadline <= 0)) {
            throw new \InvalidArgumentException('Case deadline must be positive and finite, or null.');
        }
    }
}
