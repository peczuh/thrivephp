<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP\Test;

/** Cleanup runs child class first, including after partial setup or a failed case. */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class After
{
    /** case: true selects cleanup after each case attempt; false selects suite cleanup. */
    public function __construct(public bool $case = false) {}
}
