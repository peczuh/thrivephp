<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP\Test;

use ThriveData\ThrivePHP\Test;

/** Shared state for one suite. Annotated public methods define its cases and hooks. */
abstract class Suite
{
    private ?SuiteConfig $configuration = null;

    public function __construct(public Test $test) {}

    /** @internal Configure once, before hooks, so the parent learns the inactivity limit. */
    final public function configuration(): SuiteConfig
    {
        if ($this->configuration === null) {
            $config = new SuiteConfig(capabilities: $this instanceof SuiteBrowser ? $this->test->browserCapabilities() : [],
                skipRemainingOnFailure: $this instanceof SuiteBrowser);
            $this->configure($config);
            if ($config->idleTimeout !== null && (!is_finite($config->idleTimeout) || $config->idleTimeout <= 0)) {
                throw new \InvalidArgumentException('Suite idleTimeout must be positive and finite, or null.');
            }
            $this->configuration = $config;
        }
        return $this->configuration;
    }

    /** Override to change this suite's defaults before managed resources are started. */
    protected function configure(SuiteConfig $config): void {}
}
