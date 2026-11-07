<?php

use ThriveData\ThrivePHP\Test\Suite;
use ThriveData\ThrivePHP\Test\SuiteConfig;
use ThriveData\ThrivePHP\Test\Test;
use ThriveData\ThrivePHP\Test\SuiteBefore;
use ThriveData\ThrivePHP\Test\SuiteAfter;

$suite = new class($test) extends Suite {
    public string $mode;
    public string $name;
    private mixed $child = null;

    protected function configure(SuiteConfig $config): void
    {
        if ($this->mode === 'config-error') throw new RuntimeException('configuration failed');
        if (in_array($this->mode, ['timeout', 'step-timeout', 'progress', 'descendants'], true)) $config->idleTimeout = 0.6;
    }

    #[SuiteBefore]
    public function start(): void
    {
        $this->log('start');
        if (in_array($this->mode, ['actions', 'action-error'], true)) return;
        $this->test->assert(!isset($GLOBALS['isolated']), 'A fresh process owns each suite.');
        $GLOBALS['isolated'] = true;
        if ($this->mode === 'mismatch') register_shutdown_function(static function () { exit(7); });
    }

    #[Test('first')]
    public function first(): void
    {
        if (in_array($this->mode, ['actions', 'action-error'], true)) return;
        $this->test->assert(true, 'completed before later failures');
        if ($this->mode === 'concurrent') usleep(200000);
    }

    #[Test('body', deadline: 0.9)]
    public function body(): void
    {
        switch ($this->mode) {
            case 'live-failure':
                $this->test->verify(false, 'reported before the case returns');
                usleep(200000);
                break;
            case 'actions':
                $this->log('action');
                return;
            case 'action-error':
                throw new RuntimeException('action failed');
            case 'crash':
                $this->test->assert(true, 'retain active-case assertions');
                exit(23);
            case 'descendants':
                $this->child = proc_open([PHP_BINARY, __DIR__ . '/test-parallel-child.php', PATH_ROOT],
                    [0 => ['null'], 1 => STDOUT, 2 => STDERR], $pipes);
                // Keep the process handle alive so only the parent's recovery owns termination.
                sleep(30);
                break;
            case 'timeout':
                sleep(30);
                break;
            case 'step-timeout':
                $this->test->recordStep('blocked operation', null, ['fixture' => true], function () {
                    $this->test->verify(false, 'before timeout');
                    sleep(30);
                });
                break;
            case 'progress':
                for ($i = 0; $i < 8; $i++) {
                    usleep(150000);
                    $this->test->assert(true, 'assertions reset inactivity');
                }
                break;
            case 'large':
                $this->test->verify(false, 'LARGE-BEGIN' . str_repeat('x', 2 * 1024 * 1024) . 'LARGE-END');
                break;
            case 'diagnostics':
                fwrite(STDOUT, 'OUT-BEGIN' . str_repeat('o', 1024 * 1024) . 'OUT-END');
                fwrite(STDERR, 'ERR-BEGIN' . str_repeat('e', 1024 * 1024) . 'ERR-END');
                break;
            case 'configuration':
                $this->test->assert(ini_get('precision') === '12', 'runtime ini override');
                $this->test->assert(ini_get('include_path') === 'fixture="quoted";${HOME};C:\\fixture\\', 'quoted ini value');
                $this->test->assert(ini_get('memory_limit') === '192M', 'startup ini override');
                $this->test->assert($this->test->browser() === 'chromium' && $this->test->headless(), 'run selection');
                $this->test->assert(feof(STDIN) || fread(STDIN, 1) === '', 'null stdin');
                $this->test->assert(getcwd() === getenv('TEST_CWD'), 'inherited working directory');
                break;
            case 'bootstrap':
                $this->test->assert(($GLOBALS['applicationStarted'] ?? false) === true, 'application bootstrap repeated');
                break;
            case 'concurrent':
                usleep(200000);
                break;
        }
        $this->test->assert(true, 'body completed');
    }

    #[Test('last')]
    public function last(): void
    {
        if (in_array($this->mode, ['actions', 'action-error'], true)) return;
        $this->test->assert(true, 'remaining case runs');
    }

    #[SuiteAfter]
    public function finish(): void { $this->log('finish'); }

    private function log(string $event): void
    {
        file_put_contents(PATH_ROOT . '/events.jsonl', json_encode([
            'name' => $this->name, 'event' => $event, 'pid' => getmypid(), 'time' => hrtime(true),
        ]) . "\n", FILE_APPEND | LOCK_EX);
    }
};
$suite->mode = $mode;
$suite->name = $name;
return $suite;
