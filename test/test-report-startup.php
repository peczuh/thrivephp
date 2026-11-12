<?php

// Run with: php -d short_open_tag=1 test/test-report-startup.php.
require __DIR__ . '/fixtures/test-runner.php';

use ThriveData\ThrivePHP\Settings;
use ThriveData\ThrivePHP\Test;
use ThriveData\ThrivePHP\Test\Record;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = sys_get_temp_dir() . '/thrive-startup-' . bin2hex(random_bytes(8));
mkdir($root . '/test/cases', 0700, true);
mkdir($root . '/test/empty');
mkdir($root . '/vendor');
register_shutdown_function(static function () use ($root): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
});
file_put_contents($root . '/test/cases/check.php', '<?php return static function () { throw new LogicException("Suite must not load"); };');

/** Require identical serialized diagnostics and honest zero-suite totals in both file reports. */
function failedReport(string $directory, string $console, string $message, string $phase): array
{
    $files = glob($directory . '/*/report.json');
    check(count($files) === 1, 'Expected one run report in ' . $directory . ': ' . $console);
    $record = json_decode(file_get_contents($files[0]), true, flags: JSON_THROW_ON_ERROR);
    $html = file_get_contents(dirname($files[0]) . '/report.html');
    preg_match('~<script id="test-report-data" type="application/json">(.*?)</script>~s', $html, $match);
    check(json_decode($match[1], true, flags: JSON_THROW_ON_ERROR) === $record, 'HTML and JSON disagree.');
    check($record['status'] === 'FAIL' && $record['suites'] === [] && $record['totals'] === Record::TOTALS,
        'Startup failure invented suite/assertion outcomes.');
    $error = $record['errors'][0];
    check($error['phase'] === $phase && str_contains($error['message'], $message)
        && $error['location']['line'] > 0 && $error['location']['source'] !== [] && isset($error['stack'])
        && $record['endedAt'] !== null && $record['duration'] >= 0, 'Startup diagnostics or timing missing: ' . json_encode($error));
    check(str_contains($console, $message) && str_contains($console, 'ERROR RUN') && str_contains($console, 'Run: FAIL')
        && str_contains($console, 'Assertions: 0 passed, 0 failed'), 'Console disagrees with file reports: ' . $console);
    return $record;
}

foreach ([
    [['workers' => 0], [], 'Workers must be'],
    [['selectors' => ['[']], [], 'selector'],
    [['selectors' => [42]], [], 'selectors must be strings'],
    [['names' => ['missing']], [], 'Unknown check'],
    [[], ['casepath' => 'absent'], 'absent'],
    [[], ['casepath' => 'empty'], 'No checks found'],
    [[], ['casepath' => []], 'string'],
    [[], ['screenshots' => ['steps' => 'invalid']], 'test.screenshots.steps'],
    [['browser' => 'broken'], [], 'browser selection failed'],
    [['browser' => 'safari', 'headless' => true], [], 'headless'],
    [['browser' => 'safari', 'workers' => 2, 'headless' => false], [], 'parallel sessions'],
    [[], ['browser' => ['headless' => 'false']], 'test.browser.headless'],
] as $index => [$options, $settings, $message]) {
    $save = $root . '/run-' . $index;
    Settings::set('', ['test' => $settings + ['savepath' => $save]]);
    $runner = new class($root) extends Test {
        protected function resolveBrowser(?string $browser): ?string {
            if ($browser === 'broken') throw new RuntimeException('browser selection failed', 0, new LogicException('original cause'));
            return $browser;
        }
    };
    ob_start();
    try { $status = $runner->run(...($options + ['workers' => 1])); }
    finally { $console = ob_get_clean(); }
    check($status === 1, 'Startup error returned success.');
    $record = failedReport($save, $console, $message, 'preparation');
    if (($options['browser'] ?? null) === 'broken') check($record['errors'][0]['previous']['message'] === 'original cause', 'Cause lost.');
}
Settings::set('', ['test' => ['savepath' => $root . '/composer-report']]);
file_put_contents($root . '/composer.json', '{');
ob_start();
try { $status = (new Test($root))->run(workers: 1); }
finally { $console = ob_get_clean(); }
check($status === 1, 'Invalid Composer metadata returned success.');
failedReport($root . '/composer-report', $console, 'Syntax error', 'preparation');
unlink($root . '/composer.json');
Settings::set('', ['test' => ['savepath' => []]]);
ob_start();
try { $status = (new Test($root))->run(workers: 1); }
finally { $console = ob_get_clean(); }
check($status === 1, 'Invalid save setting returned success.');
failedReport($root . '/test/runs', $console, 'string', 'preparation');
echo "PASS: preparation failures retain console, JSON, HTML, causes and zero-suite totals\n";

file_put_contents($root . '/blocked', 'not a directory');
Settings::set('', ['test' => ['savepath' => $root . '/blocked/runs']]);
ob_start();
try {
    try {
        (new Test($root))->run(workers: 1);
        throw new LogicException('Unwritable report storage was accepted.');
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'save directory'), $error->getMessage());
    }
} finally { $console = ob_get_clean(); }
check(str_contains($console, 'ERROR Could not initialize test report'), 'Storage failure not printed.');
mkdir($root . '/write-failure/report.html', 0700, true);
$report = new ThriveData\ThrivePHP\Test\Report($root . '/write-failure', ['root' => realpath($root)]);
ob_start();
try {
    try { $report->summary(); throw new LogicException('Incomplete report was accepted.'); }
    catch (RuntimeException $error) { check(str_contains($error->getMessage(), 'report.html'), $error->getMessage()); }
} finally { $console = ob_get_clean(); }
check(str_contains($console, 'ERROR Could not write complete report: write-failure/report.html'), 'Write failure not printed relatively.');
echo "PASS: unavailable report storage and partial writes produce accurate console errors\n";

/** Bootstrap exits; use a separate PHP process with no framework autoloader. */
function bootstrapProcess(string $code, bool $shortTags = true): array
{
    $entry = 'require ' . var_export(dirname(__DIR__) . '/src/Test.php', true) . '; ' . $code;
    $process = proc_open([PHP_BINARY, '-d', 'short_open_tag=' . (int)$shortTags, '-r', $entry],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]);
    $output .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $output];
}

$source = dirname(__DIR__) . '/src/';
foreach (['short-tags', 'composer', 'settings', 'configured', 'invalid-save'] as $mode) {
    $project = $root . '/' . $mode;
    mkdir($project . '/vendor', 0700, true);
    $autoload = '<?php ';
    $save = $project . '/test/runs';
    if ($mode === 'composer') {
        $autoload .= 'throw new RuntimeException("Composer platform failure");';
        $message = 'Composer platform failure';
    } elseif ($mode !== 'short-tags') {
        $autoload .= 'require ' . var_export($source . 'Exception.php', true) . '; require ' . var_export($source . 'Settings.php', true) . '; ';
        if ($mode !== 'settings') {
            $save = $mode === 'configured' ? $project . '/artifacts' : $save;
            $autoload .= '\ThriveData\ThrivePHP\Settings::set("", ' . var_export(['test' => ['savepath' => $mode === 'configured' ? $save : []]], true) . '); ';
        }
        $autoload .= 'class_alias("StartupApplication", "ThriveData\\ThrivePHP\\Application"); class StartupApplication {
            public static function init() { json_decode("{", true, flags: JSON_THROW_ON_ERROR); }
        }';
        $message = 'Syntax error';
    } else {
        $message = 'Enable short_open_tag';
    }
    file_put_contents($project . '/vendor/autoload.php', $autoload);
    [$status, $console] = bootstrapProcess('(new \ThriveData\ThrivePHP\Test(' . var_export($project, true) . '))->bootstrap();', $mode !== 'short-tags');
    check($status === 1, 'Bootstrap did not preserve failure exit: ' . $console);
    failedReport($save, $console, $message, 'bootstrap');
}
echo "PASS: bootstrap failures report without Composer, short tags or valid settings\n";

$project = $root . '/worker';
mkdir($project . '/vendor', 0700, true);
mkdir($project . '/test/cases', 0700, true);
file_put_contents($project . '/test/cases/check.php', '<?php return static function () {};');
file_put_contents($project . '/vendor/autoload.php', '<?php
spl_autoload_register(static function ($class) {
    $prefix = "ThriveData\\\\ThrivePHP\\\\";
    if (str_starts_with($class, $prefix)) require_once ' . var_export($source, true) . ' . str_replace("\\\\", "/", substr($class, strlen($prefix))) . ".php";
});
class_alias("StartupApplication", "ThriveData\\ThrivePHP\\Application");
class StartupApplication { public static function init() {
    if (!defined("STARTUP_PARENT")) throw new RuntimeException("worker bootstrap failure");
} }');
[$status, $console] = bootstrapProcess('define("STARTUP_PARENT", true);
$test = new class(' . var_export($project, true) . ') extends \ThriveData\ThrivePHP\Test {
    protected function resolveBrowser(?string $browser): ?string { return null; }
};
$test->bootstrap(); exit($test->run(workers: 2));');
$files = glob($project . '/test/runs/*/report.json');
check($status === 1 && count($files) === 1, 'Worker created a competing report: ' . $console);
$record = json_decode(file_get_contents($files[0]), true, flags: JSON_THROW_ON_ERROR);
check($record['status'] === 'FAIL' && $record['suites'][0]['attempts'][0]['errors'][0]['message'] === 'worker bootstrap failure'
    && $record['suites'][0]['attempts'][0]['errors'][0]['phase'] === 'bootstrap'
    && !str_contains($console, 'Could not create run directory'), 'Parent lost worker bootstrap diagnostic: ' . $console);
echo "PASS: worker bootstrap diagnostics remain in the parent-owned report\n";
