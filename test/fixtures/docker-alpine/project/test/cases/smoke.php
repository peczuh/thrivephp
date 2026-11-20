<?php

use ThriveData\ThrivePHP\Test\SuiteBrowser;
use ThriveData\ThrivePHP\Test\SuiteConfig;
use ThriveData\ThrivePHP\Test\Test;

return new class($test) extends SuiteBrowser {
    protected function configure(SuiteConfig $config): void
    {
        if ($this->test->browser() === 'firefox') $config->timeout = 30;
    }

    #[Test('A managed browser changes the title by clicking a button')]
    public function interact(): void
    {
        $this->browser->navigate('data:text/html,' . rawurlencode(
            "<title>Before</title><button onclick=\"document.title='After'\">Click</button>"
        ));
        $this->browser->titleEquals('Before', assert: true);
        $this->browser->find('button')->click();
        $this->browser->titleEquals('After', assert: true);
    }
};
