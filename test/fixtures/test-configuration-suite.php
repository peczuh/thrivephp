<?php

use ThriveData\ThrivePHP\Settings;
use ThriveData\ThrivePHP\Test\SuiteBrowser;
use ThriveData\ThrivePHP\Test\SuiteConfig;
use ThriveData\ThrivePHP\Test\Test;

$attempt = ($GLOBALS['configurationAttempts'][$name] ?? 0) + 1;
$GLOBALS['configurationAttempts'][$name] = $attempt;
return new #[Test('Configuration', retry: 1)] class($test, $name, $attempt) extends SuiteBrowser {
    public function __construct($test, private string $name, private int $attempt) { parent::__construct($test); }

    // Exercise browser suite configuration without starting a native browser in this process test.
    public function startBrowser(): void {}

    protected function configure(SuiteConfig $config): void
    {
        $this->test->assert($config->capabilities === ['acceptInsecureCerts' => false,
            'pageLoadStrategy' => 'eager', 'example:options' => ['args' => ['run']]], 'Settings and run capability precedence');
        if ($this->name === 'replace') $config->capabilities = ['acceptInsecureCerts' => true];
        else $config->capabilities['example:options']['args'][] = 'suite';
    }

    #[Test('Runtime overrides and fresh suite defaults')]
    public function settings(): void
    {
        $this->test->assert(Settings::get('runtime.marker') === 'runtime-only-marker'
            && strlen(Settings::get('runtime.large')) === 200000, 'Runtime overrides survive multi-chunk worker delivery');
        $snapshot = Settings::export();
        $this->test->assert($snapshot->runtime->emptyObject instanceof stdClass
            && $snapshot->runtime->emptyList === [] && $snapshot->runtime->numericObject instanceof stdClass,
            'Worker settings preserve JSON object/list types');
        $snapshot->runtime->marker = 'changed';
        $this->test->assert(Settings::get('runtime.marker') === 'runtime-only-marker', 'Export is an independent copy');
        $expected = $this->name === 'replace' ? ['acceptInsecureCerts' => true] : ['acceptInsecureCerts' => false,
            'pageLoadStrategy' => 'eager', 'example:options' => ['args' => ['run', 'suite']]];
        $this->test->assert($this->configuration()->capabilities === $expected, 'Suite may replace or edit inherited capabilities');
        if (PHP_OS_FAMILY !== 'Windows') {
            $process = proc_open(['ps', '-p', (string)getmypid(), '-o', 'command='], [0 => ['null'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
            $command = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $this->test->assert(proc_close($process) === 0 && !str_contains($command, 'runtime-only-marker')
                && !str_contains($command, base64_encode('runtime-only-marker')), 'Settings are absent from process arguments');
        }
        if (basename($GLOBALS['argv'][0]) === 'worker.php') {
            $this->test->assert(count($GLOBALS['argv']) === 3, 'Worker arguments contain only its endpoint and token');
        }
        $this->test->assert($this->attempt === 2, 'Retry gets a fresh configuration');
    }
};
