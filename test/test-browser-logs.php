<?php

// Native report integration: php -d short_open_tag=1 test/test-browser-logs.php [chrome|firefox] [1|2].
$source = dirname(__DIR__) . '/src/';
spl_autoload_register(static function ($class) use ($source) {
    $prefix = 'ThriveData\\ThrivePHP\\';
    if (str_starts_with($class, $prefix)) require $source . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
});

use ThriveData\ThrivePHP\Settings;
use ThriveData\ThrivePHP\Test;

function check(bool $value, string $message): void
{
    if (!$value) throw new RuntimeException($message);
}

$root = sys_get_temp_dir() . '/thrive-browser-logs-' . bin2hex(random_bytes(8));
mkdir($root . '/test/cases', 0700, true);
mkdir($root . '/vendor');
file_put_contents($root . '/vendor/autoload.php', '<?php spl_autoload_register(static function ($class) {
    $prefix = "ThriveData\\\\ThrivePHP\\\\";
    if (str_starts_with($class, $prefix)) require ' . var_export($source, true) . ' . str_replace("\\\\", "/", substr($class, strlen($prefix))) . ".php";
});');
$suite = <<<'PHP'
<?php
use ThriveData\ThrivePHP\Test\{SuiteBrowser, SuiteConfig, SuiteBefore, SuiteAfter, CaseAfter, Test};
return new class($test) extends SuiteBrowser {
    private int $attempt = 0;
    protected function configure(SuiteConfig $config): void { $config->browserLogs = true; }
    #[SuiteBefore]
    public function setup(): void {
        $this->browser->navigate('data:text/html,' . rawurlencode('<!doctype html><title>Logs</title><script>console.log("suite-setup")</script>'));
        // Keep asynchronous delivery inside the hook whose observation context is being checked.
        $this->browser->wait(fn ($browser) => array_any($browser->logs(), fn ($entry) => ($entry['text'] ?? '') === 'suite-setup'));
    }
    #[Test('Browser errors remain diagnostic', retry: 1)]
    public function retry_case(): void {
        $before = count($this->browser->logs('javascript'));
        $this->test->recordStep('browser_messages', null, [], function () use ($before) {
            $this->browser->script('console.error("case-entry", {answer: 42}); setTimeout(() => {throw new Error("case-exception")}, 0)');
            $this->browser->wait(function ($browser) use ($before) { return count($browser->logs('javascript')) > $before; });
        });
        $this->test->assert(++$this->attempt === 2, 'second attempt passes');
    }
    #[CaseAfter]
    public function afterCase(): void {
        $before = count(array_filter($this->browser->logs(), fn ($entry) => ($entry['text'] ?? '') === 'case-cleanup'));
        $this->browser->script('console.warn("case-cleanup")');
        // Delivery is asynchronous; wait explicitly when testing attribution to this hook.
        $this->browser->wait(fn ($browser) => count(array_filter($browser->logs(), fn ($entry) => ($entry['text'] ?? '') === 'case-cleanup')) > $before);
    }
    #[SuiteAfter]
    public function cleanup(): void {
        $this->browser->script('console.log("suite-cleanup")');
        $this->browser->wait(fn ($browser) => array_any($browser->logs(), fn ($entry) => ($entry['text'] ?? '') === 'suite-cleanup'));
    }
};
PHP;
foreach (['one', 'two'] as $name) file_put_contents($root . '/test/cases/' . $name . '.php', $suite);
Settings::set('', ['test' => ['screenshots' => ['steps' => 'off', 'cases' => 'off', 'suites' => 'off']]]);
$status = (new Test($root))->run(workers: (int)($argv[2] ?? 1), browser: $argv[1] ?? 'chrome', headless: true);
$path = glob($root . '/test/runs/*/report.json')[0] ?? null;
check($status === 0 && $path !== null, 'Expected passing diagnostic-only run.');
$record = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
check($record['totals']['assertionsFailed'] === 0 && $record['totals']['casesPassed'] === 2, 'Browser errors changed final totals.');
foreach ($record['suites'] as $suite) {
    $attempt = $suite['attempts'][0];
    $texts = array_column(array_column($attempt['browserLogs'], 'entry'), 'text');
    check(in_array('suite-setup', $texts, true) && in_array('suite-cleanup', $texts, true), 'Suite hooks lost logs.');
    $cases = $attempt['cases'][0]['attempts'];
    check(count($cases) === 2 && $cases[0]['status'] === 'FAIL' && $cases[1]['status'] === 'PASS', 'Retry history lost.');
    foreach ($cases as $case) {
        $entries = array_column($case['browserLogs'], 'entry');
        check(array_any($entries, fn ($entry) => str_contains($entry['text'] ?? '', 'case-entry'))
            && array_any($entries, fn ($entry) => ($entry['type'] ?? '') === 'javascript')
            && array_any($entries, fn ($entry) => ($entry['text'] ?? '') === 'case-cleanup'), 'Case/retry logs missing.');
        check(array_any($case['browserLogs'], fn ($log) => $log['stepId'] !== null && $log['phase'] === 'test'), 'Observed action attribution missing.');
    }
}
echo 'PASS: browser log reporting, retries and hooks, workers=' . ($argv[2] ?? 1) . PHP_EOL;
echo 'Artifacts: ' . dirname($path) . PHP_EOL;
