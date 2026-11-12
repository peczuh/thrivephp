<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP\Test;

/** Setup runs base class first, once per suite attempt or before each case attempt. */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Before
{
    /** case: true selects per-case setup; false selects suite setup. */
    public function __construct(public bool $case = false) {}
}
