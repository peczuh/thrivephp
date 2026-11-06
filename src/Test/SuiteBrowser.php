<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP\Test;

use ThriveData\ThrivePHP\WebDriver\Browser;
use ThriveData\ThrivePHP\WebDriver\WebDriver;

/** Owns a fresh browser session shared by this suite's hooks and cases. */
abstract class SuiteBrowser extends Suite
{
    /** Available after startBrowser() succeeds, through the end of child suite cleanup. */
    public Browser $browser;
    private ?WebDriver $driver = null;

    /** Configure this suite and start its owned browser before child setup hooks. */
    #[SuiteBefore]
    public function startBrowser(): void
    {
        $config = new SuiteConfig();
        $this->configure($config);
        $this->driver = $this->createDriver($config);
        $this->browser = $this->driver->browser(
            headless: $config->headless,
            timeout: $config->timeout,
            capabilities: $config->capabilities,
        );
    }

    /** Driver creation is overridable so protocol fixtures can exercise the real lifecycle. */
    protected function createDriver(SuiteConfig $config): WebDriver
    {
        return new WebDriver(browser: $config->browser);
    }

    /** A nullable driver permits cleanup when configuration or startup failed. */
    #[SuiteAfter]
    public function stopBrowser(): void
    {
        $this->driver?->stop();
    }
}
