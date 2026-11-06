<?php

use ThriveData\ThrivePHP\Test\Test;
use ThriveData\ThrivePHP\Test\Suite;
use ThriveData\ThrivePHP\Test\SuiteConfig;
use ThriveData\ThrivePHP\Test\SuiteBefore;
use ThriveData\ThrivePHP\Test\SuiteAfter;
use ThriveData\ThrivePHP\Test\CaseBefore;
use ThriveData\ThrivePHP\Test\CaseAfter;

if ($mode === 'attribute-load-error') throw new RuntimeException('Excluded file loaded.');

if ($mode === 'attribute-skip') {
    return new #[Test('Skipped suite', retry: 3, tags: ['suite'], skip: true)] class($test) extends Suite {
        protected function configure(SuiteConfig $config): void { throw new RuntimeException('Skipped configuration ran.'); }
        #[SuiteBefore]
        public function setup(): void { throw new RuntimeException('Skipped setup ran.'); }
        #[Test('skipped body')]
        public function body(): void { throw new RuntimeException('Skipped body ran.'); }
        #[SuiteAfter]
        public function cleanup(): void { throw new RuntimeException('Skipped cleanup ran.'); }
    };
}

$suite = new #[Test('Attribute suite', deadline: 0.5, retry: 1, tags: ['suite'])] class($test) extends Suite {
    public string $mode;
    public string $name;
    public int $attempt;
    private int $prepared = 0;
    private int $bodies = 0;

    protected function configure(SuiteConfig $config): void
    {
        $this->log('configure');
        if ($this->mode === 'attribute-config' && $this->attempt === 1) throw new RuntimeException('configuration failed');
        if ($this->mode === 'attribute-retry-timeout') $config->idleTimeout = 0.2;
    }

    #[SuiteBefore]
    public function setup(): void
    {
        $this->log('setup');
        if ($this->mode === 'attribute-suite-deadline' && $this->attempt === 1) usleep(600000);
        $this->test->assert($this->mode !== 'attribute-suite-before' || $this->attempt > 1, 'suite setup');
    }

    #[CaseBefore]
    public function prepare(): void
    {
        $this->prepared++;
        $this->log('prepare');
        if ($this->mode === 'attribute-case-before' && $this->prepared === 1) throw new RuntimeException('case setup failed');
    }

    #[Test('first', deadline: 0.03, retry: 1, tags: ['smoke'])]
    public function first(): void
    {
        $this->bodies++;
        $this->log('first');
        if ($this->mode === 'attribute-case-error' && $this->bodies === 1) throw new RuntimeException('body failed');
        if ($this->mode === 'attribute-case-deadline' && $this->bodies === 1) usleep(60000);
        if ($this->mode === 'attribute-retry-crash' && $this->bodies === 2) exit(23);
        if ($this->mode === 'attribute-retry-timeout' && $this->bodies === 2) sleep(30);
        if ($this->mode === 'attribute-suite-crash' && $this->attempt === 2) exit(23);
        $passed = match ($this->mode) {
            'attribute-case-retry', 'attribute-retry-crash', 'attribute-retry-timeout' => $this->bodies > 1,
            'attribute-suite-retry', 'attribute-suite-crash' => $this->attempt > 1,
            'attribute-always-fail' => false,
            default => true,
        };
        $this->test->verify($passed, 'first body assertion');
    }

    #[Test('second', tags: ['slow'])]
    public function second(): void { $this->log('second'); $this->test->assert(true, 'second body'); }

    #[Test('skipped', retry: 5, tags: ['skip'], skip: true)]
    public function skipped(): void { throw new RuntimeException('Skipped case ran.'); }

    #[CaseAfter]
    public function clean(): void
    {
        $this->log('clean');
        if ($this->mode === 'attribute-case-after' && $this->prepared === 1) throw new RuntimeException('case cleanup failed');
    }

    #[SuiteAfter]
    public function cleanup(): void
    {
        $this->log('cleanup');
        $this->test->assert($this->mode !== 'attribute-suite-after' || $this->attempt > 1, 'suite cleanup');
    }

    private function log(string $event): void
    {
        $entry = ['name' => $this->name, 'event' => $event, 'attempt' => $this->attempt,
            'prepared' => $this->prepared, 'bodies' => $this->bodies];
        if (isset($GLOBALS['attributeTrace'])) {
            $GLOBALS['attributeTrace'][] = $entry;
        } else {
            file_put_contents(PATH_ROOT . '/events.jsonl', json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
        }
    }
};
$suite->mode = $mode;
$suite->name = $name;
$suite->attempt = $GLOBALS['attributeAttempts'][$name] = ($GLOBALS['attributeAttempts'][$name] ?? 0) + 1;
return $suite;
