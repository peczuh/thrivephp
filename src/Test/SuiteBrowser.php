<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP\Test;

use ThriveData\ThrivePHP\WebDriver\Browser;
use ThriveData\ThrivePHP\WebDriver\WebDriver;

/** Owns a fresh browser session shared by its hooks and cases; defaults to skipping cases after failure. */
abstract class SuiteBrowser extends Suite
{
    /** Available after startBrowser() succeeds, through the end of child suite cleanup. */
    public Browser $browser;
    private ?WebDriver $driver = null;

    /** Start the run-level browser before child setup hooks, using this suite's session settings. */
    #[SuiteBefore]
    public function startBrowser(): void
    {
        $config = $this->configuration();
        $this->driver = $this->createDriver($config);
        $this->browser = $this->driver->browser(
            headless: $this->test->headless(),
            timeout: $config->timeout,
            capabilities: $config->capabilities,
        );
    }

    /** Driver creation is overridable so protocol fixtures can exercise the real lifecycle. */
    protected function createDriver(SuiteConfig $config): WebDriver
    {
        return new WebDriver(browser: $this->test->browser());
    }

    /** A nullable driver permits cleanup when configuration or startup failed. */
    #[SuiteAfter]
    public function stopBrowser(): void
    {
        $this->driver?->stop();
    }
}
