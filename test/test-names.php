<?php

// Run with: php -d short_open_tag=1 test/test-names.php. Uses isolated files and real workers.
require __DIR__ . '/fixtures/test-runner.php';

use ThriveData\ThrivePHP\Test\Record;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = sys_get_temp_dir() . '/thrive-names-' . bin2hex(random_bytes(8));
mkdir($root, 0700, true);
register_shutdown_function(static function () use ($root): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
});

/** Each scenario has separate source files, worker autoloading and a marker for forbidden execution. */
function runNames(array $files, int $workers, array $selectors = []): array
{
    global $root;
    $project = $root . '/' . bin2hex(random_bytes(6));
    mkdir($project . '/vendor', 0700, true);
    $source = var_export(dirname(__DIR__) . '/src/', true);
    file_put_contents($project . '/vendor/autoload.php', '<?php spl_autoload_register(static function ($class) {
        $prefix = "ThriveData\\\\ThrivePHP\\\\";
        if (str_starts_with($class, $prefix)) require_once ' . $source . ' . str_replace("\\\\", "/", substr($class, strlen($prefix))) . ".php";
    });');
    foreach ($files as $name => $body) {
        $path = $project . '/cases/' . $name . '.php';
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        file_put_contents($path, str_replace('{{MARKER}}', var_export($project . '/executed', true), $body));
    }
    $runner = new FixtureTest($project, '/cases');
    ob_start();
    try { $status = $runner->run(workers: $workers, selectors: $selectors); }
    finally { $console = ob_get_clean(); }
    $record = json_decode(file_get_contents(glob($runner->saved . '/*/report.json')[0]), true, flags: JSON_THROW_ON_ERROR);
    return [$status, $console, $record, is_file($project . '/executed')];
}

$loadMarker = '<?php file_put_contents({{MARKER}}, "loaded"); return static function () {};';
foreach ([1, 2] as $workers) {
    foreach (['customer login', 'customer.login', 'customer;login', 'customer$login', '-login', '9login', 'sáve', 'CON', 'nul', 'COM1', 'lpt9'] as $invalid) {
        [$status, $console, $record, $executed] = runNames(['customer-login' => $loadMarker, $invalid => $loadMarker], $workers);
        check($status === 1 && !$executed && $record['suites'] === [] && $record['totals'] === Record::TOTALS
            && str_contains($record['errors'][0]['message'], 'Invalid suite name'), 'Invalid suite name loaded files or produced a sanitized alias: ' . $console);
    }
    foreach ([['admin/login', 'public/login'], ['admin/Login', 'public/login']] as $names) {
        [$status, $console, $record, $executed] = runNames(array_fill_keys($names, $loadMarker), $workers);
        check($status === 1 && !$executed && $record['suites'] === [] && $record['totals'] === Record::TOTALS
            && str_contains($record['errors'][0]['message'], 'Duplicate suite name')
            && str_contains($console, $names[0]) && str_contains($console, $names[1]), 'Duplicate suite names were accepted: ' . $console);
    }
    [$status, $console, $record, $executed] = runNames(['admin/login' => $loadMarker, 'public/Login' => $loadMarker], $workers, ['^admin/']);
    check($status === 0 && $executed && count($record['suites']) === 1, 'Excluded suites affected name uniqueness: ' . $console);
    [$status, $console, $record, $executed] = runNames(array_fill_keys(['a/customer-login', 'b/customer_login', 'b/_Login2'], $loadMarker), $workers);
    check($status === 0 && $executed && count($record['suites']) === 3, 'Distinct safe names were collapsed: ' . $console);
    echo "PASS: suite names are literal, safe and unique before serial/parallel execution, workers=$workers\n";
}

$suite = <<<'PHP'
<?php
use ThriveData\ThrivePHP\Test\Test;
use ThriveData\ThrivePHP\Test\Suite;
use ThriveData\ThrivePHP\Test\SuiteConfig;
use ThriveData\ThrivePHP\Test\Before;
return new #[Test('Customer login / résumé')] class($test) extends Suite {
    protected function configure(SuiteConfig $config): void { file_put_contents({{MARKER}}, 'configured'); }
    #[Before]
    public function before(): void { file_put_contents({{MARKER}}, 'hook'); }
    #[Test('Save customer / résumé')]
    public function METHOD(): void { file_put_contents({{MARKER}}, 'case'); }
};
PHP;
foreach ([1, 2] as $workers) {
    foreach (['sáve', 'CON', 'lpt1', str_repeat('x', 256)] as $method) {
        [$status, $console, $record, $executed] = runNames(['customer-login' => str_replace('METHOD', $method, $suite)], $workers);
        check($status === 1 && !$executed && str_contains($console, 'Invalid case name')
            && $record['totals']['assertionsFailed'] === 0, 'Invalid case name reached configuration/hooks: ' . $console);
    }
    [$status, $console, $record, $executed] = runNames(['customer-login' => str_replace('METHOD', 'save_customer', $suite)], $workers);
    check($status === 0 && $executed && $record['suites'][0]['name'] === 'Customer login / résumé'
        && $record['suites'][0]['attempts'][0]['cases'][0]['name'] === 'Save customer / résumé', 'Safe identifiers restricted display descriptions: ' . $console);
    foreach (['custom action', '../click', 'click;exit', 'NUL', str_repeat('x', 256)] as $action) {
        $body = '<?php return static function ($test) { $test->recordStep(' . var_export($action, true)
            . ', null, [], static function () { file_put_contents({{MARKER}}, "action"); }); };';
        [$status, $console, $record, $executed] = runNames(['customer-login' => $body], $workers);
        check($status === 1 && !$executed && str_contains($console, 'Invalid action name'), 'Unsafe action callback executed: ' . $console);
    }
    $body = '<?php return static function ($test) { for ($i = 0; $i < 2; $i++) $test->recordStep("save-customer", null, [], static function () { file_put_contents({{MARKER}}, "action"); }); };';
    [$status, $console, $record, $executed] = runNames(['customer-login' => $body], $workers);
    $steps = $record['suites'][0]['attempts'][0]['steps'];
    check($status === 0 && $executed && count($steps) === 2 && $steps[0]['id'] !== $steps[1]['id'], 'Repeated safe actions lost separate identities: ' . $console);
    echo "PASS: case/action names fail before hooks or callbacks, descriptions stay readable and repeated actions stay distinct, workers=$workers\n";
}
