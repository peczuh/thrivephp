<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP\Test;

/** Per-suite settings. Browser command timeout and worker inactivity timeout are seconds. */
class SuiteConfig
{
    public function __construct(
        public float $timeout = 10,
        public array $capabilities = [],
        public ?float $idleTimeout = null,
        /** Skip later cases after a final case failure; SuiteBrowser supplies true before configure(). */
        public bool $skipRemainingOnFailure = false,
    ) {}

    /** Reject misspelled options on every supported PHP version. */
    public function __set(string $name, mixed $value): void
    {
        throw new \InvalidArgumentException("Unknown suite configuration option: $name");
    }
}
