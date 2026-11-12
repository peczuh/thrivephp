<?php

// Native reporting and HTML interaction: php -d short_open_tag=1 test/test-browser-network.php [chrome|firefox] [1|2].
require __DIR__ . '/fixtures/test-runner.php';

use ThriveData\ThrivePHP\Settings;
use ThriveData\ThrivePHP\Test;
use ThriveData\ThrivePHP\WebDriver\WebDriver;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
check($listener !== false, $error);
$base = 'http://' . stream_socket_get_name($listener, false);
$pid = pcntl_fork();
check($pid !== -1, 'Could not fork HTTP fixture.');
if ($pid === 0) {
    while ($client = @stream_socket_accept($listener, -1)) {
        stream_set_timeout($client, 3);
        $line = fgets($client);
        if ($line === false) { fclose($client); continue; }
        while (($header = fgets($client)) !== false && $header !== "\r\n") {}
        $body = '<!doctype html><title>Network reporting</title><script>console.log("network-fixture")</script>';
        fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nX-Report: </script><script>window.networkInjected=true</script>\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
        fclose($client);
    }
    exit;
}
fclose($listener);
$root = sys_get_temp_dir() . '/thrive-network-report-' . bin2hex(random_bytes(8));
$driver = null;
try {
    mkdir($root . '/test/cases', 0700, true);
    mkdir($root . '/vendor');
    $source = dirname(__DIR__) . '/src/';
    file_put_contents($root . '/vendor/autoload.php', '<?php spl_autoload_register(static function ($class) {
        $prefix = "ThriveData\\\\ThrivePHP\\\\";
        if (str_starts_with($class, $prefix)) require ' . var_export($source, true) . ' . str_replace("\\\\", "/", substr($class, strlen($prefix))) . ".php";
    });');
    $suite = <<<'PHP'
<?php
use ThriveData\ThrivePHP\Test\{SuiteBrowser, SuiteConfig, Before, After, Test};
return new class($test) extends SuiteBrowser {
    private int $attempt = 0;
    protected function configure(SuiteConfig $config): void {
        $config->browserNetwork = true;
        $config->browserLogs = __LOGS__;
    }
    private function visit(string $path): void {
        $url = __BASE__ . $path;
        $this->browser->navigate($url);
        $this->browser->wait(fn () => array_any($this->browser->network()->requests(), fn ($hop) => $hop['request']['url'] === $url
            && array_any($hop['events'], fn ($event) => $event['method'] === 'network.responseCompleted')));
    }
    #[Before]
    public function setup(): void { $this->visit('/setup'); }
    #[Test('Network retry', retry: 1)]
    public function body(): void {
        $this->visit('/case-' . ++$this->attempt);
        $this->test->assert($this->attempt === 2, 'second attempt passes');
    }
    #[After(case: true)]
    public function afterCase(): void { $this->visit('/case-cleanup-' . $this->attempt); }
    #[After]
    public function cleanup(): void { $this->visit('/suite-cleanup'); }
};
PHP;
    foreach (['network_only' => false, 'combined' => true] as $name => $logs) {
        file_put_contents($root . '/test/cases/' . $name . '.php', str_replace(['__BASE__', '__LOGS__'], [var_export($base, true), $logs ? 'true' : 'false'], $suite));
    }
    Settings::set('', ['test' => ['screenshots' => ['steps' => 'off', 'cases' => 'off', 'suites' => 'off']]]);
    $name = $argv[1] ?? 'chrome';
    $workers = (int)($argv[2] ?? 1);
    $status = (new Test($root))->run(workers: $workers, browser: $name, headless: true);
    $file = glob($root . '/test/runs/*/report.json')[0] ?? null;
    check($status === 0 && $file !== null, 'Network reporting run failed.');
    $record = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    check($record['totals']['casesPassed'] === 2 && $record['totals']['assertionsFailed'] === 0, 'Retry totals changed.');
    foreach ($record['suites'] as $suite) {
        $attempt = $suite['attempts'][0];
        foreach (['/setup' => 'suite-setup', '/suite-cleanup' => 'suite-teardown'] as $path => $phase) {
            check(array_any($attempt['browserNetwork'], fn ($entry) => ($entry['event']['params']['request']['url'] ?? null) === $base . $path
                && $entry['phase'] === $phase && $entry['hook'] !== null), 'Suite hook lost network events.');
        }
        $cases = $attempt['cases'][0]['attempts'];
        check(array_column($cases, 'status') === ['FAIL', 'PASS'], 'Retry history missing.');
        foreach ($cases as $index => $case) {
            foreach (['/case-' => 'test', '/case-cleanup-' => 'case-teardown'] as $prefix => $phase) {
                check(array_any($case['browserNetwork'], fn ($entry) => ($entry['event']['params']['request']['url'] ?? null) === $base . $prefix . ($index + 1)
                    && $entry['phase'] === $phase && $entry['stepId'] !== null), 'Case or retry lost observed-during attribution.');
            }
            foreach ($case['browserNetwork'] as $entry) check($entry['sessionId'] === $attempt['session']['sessionId'], 'Sessions mixed across workers.');
        }
        check(($attempt['browserLogs'] !== []) === ($suite['id'] === 'combined'), 'Log/network options are not independent.');
    }
    $driver = new WebDriver(browser: $name);
    $browser = $driver->browser(headless: true);
    $browser->navigate('file://' . dirname($file) . '/report.html');
    $browser->wait(fn () => $browser->script('return document.querySelectorAll(".inner-network").length > 0'));
    $result = $browser->script(<<<'JS'
const panels = [...document.querySelectorAll('.inner-network')];
const panel = panels.find(node => node.getClientRects().length || node.closest('.suite-tabs'));
for (let ancestor = panel.parentElement; ancestor; ancestor = ancestor.parentElement) {
    if (ancestor.tagName === 'DETAILS') ancestor.open = true;
    if (ancestor.classList.contains('tabpanel')) document.getElementById(ancestor.getAttribute('aria-labelledby'))?.click();
}
const tab = document.getElementById(panel.getAttribute('aria-labelledby'));
tab.click();
const raw = [...panel.querySelectorAll('details')].find(node => node.textContent.includes('window.networkInjected=true'));
raw.querySelector('summary').click();
return {selected: tab.getAttribute('aria-selected'), active: panel.classList.contains('is-active'),
    expanded: raw.open, text: raw.querySelector('pre').textContent,
    injected: typeof window.networkInjected !== 'undefined', scripts: raw.querySelectorAll('script').length};
JS);
    check($result['selected'] === 'true' && $result['active'] && $result['expanded'], 'Network tab or raw disclosure did not operate.');
    check(str_contains($result['text'], '</script><script>window.networkInjected=true</script>')
        && !$result['injected'] && $result['scripts'] === 0, 'Protocol text executed or changed in HTML.');
    echo "PASS: native $name network reports and HTML interaction, workers=$workers\nArtifacts: " . dirname($file) . PHP_EOL;
} finally {
    try { $driver?->stop(); }
    finally { posix_kill($pid, SIGTERM); pcntl_waitpid($pid, $status); }
}
