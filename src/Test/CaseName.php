<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP\Test;

/** Identifies a case and supplies the descriptive name used in its report. */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class CaseName
{
    public function __construct(public string $name) {}
}
