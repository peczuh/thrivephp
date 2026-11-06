<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP\Test;

use ThriveData\ThrivePHP\Test;

/** Shared state for one suite. Annotated public methods define its cases and hooks. */
abstract class Suite
{
    public function __construct(public Test $test) {}

    /** Override to change this suite's defaults before managed resources are started. */
    protected function configure(SuiteConfig $config): void {}
}
