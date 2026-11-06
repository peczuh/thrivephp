<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP\Test;

/** Per-suite browser settings. Timeout is in seconds, as in WebDriver::browser(). */
class SuiteConfig
{
    public function __construct(
        public string $browser = 'firefox',
        public bool $headless = false,
        public float $timeout = 10,
        public array $capabilities = [],
    ) {}

    /** Reject misspelled options on every supported PHP version. */
    public function __set(string $name, mixed $value): void
    {
        throw new \InvalidArgumentException("Unknown suite configuration option: $name");
    }
}
