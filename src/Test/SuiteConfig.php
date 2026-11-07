<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP\Test;

/** Per-suite settings. Command/inactivity timeouts are seconds; polling budgets and interval are milliseconds. */
class SuiteConfig
{
    public function __construct(
        public float $timeout = 10,
        public array $capabilities = [],
        public ?float $idleTimeout = null,
        /** Skip later cases after a final case failure; SuiteBrowser supplies true before configure(). */
        public bool $skipRemainingOnFailure = false,
        public float $waitTimeout = 5000,
        public float $assertionTimeout = 5000,
        public float $pollInterval = 500,
    ) {}

    /** Reject misspelled options on every supported PHP version. */
    public function __set(string $name, mixed $value): void
    {
        throw new \InvalidArgumentException("Unknown suite configuration option: $name");
    }
}
