<?php

// Run with: php -d short_open_tag=1 test/test-report-paths.php. Uses real workers and fixture PNGs.
require __DIR__ . '/fixtures/test-runner.php';

use ThriveData\ThrivePHP\Settings;
use ThriveData\ThrivePHP\Test;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = sys_get_temp_dir() . '/thrive-capture-paths-' . bin2hex(random_bytes(8));
mkdir($root . '/vendor', 0700, true);
register_shutdown_function(static function () use ($root): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
});
$source = var_export(dirname(__DIR__) . '/src/', true);
file_put_contents($root . '/vendor/autoload.php', '<?php spl_autoload_register(static function ($class) {
    $prefix = "ThriveData\\\\ThrivePHP\\\\";
    if (str_starts_with($class, $prefix)) require_once ' . $source . ' . str_replace("\\\\", "/", substr($class, strlen($prefix))) . ".php";
});');
$names = ['folder/one_case', 'folder-one_case', 'shared/customer-login', 'shared/customer_login', 'shared/_login', 'shared/login2', 'folder/one_case/save', 'estimates/create', 'projects/create'];
$fixture = <<<'PHP'
<?php
use ThriveData\ThrivePHP\Test\Test;
use ThriveData\ThrivePHP\Test\Suite;
use ThriveData\ThrivePHP\Test\Before;
use ThriveData\ThrivePHP\WebDriver\Screenshot;
$GLOBALS['attempts'][__FILE__] = ($GLOBALS['attempts'][__FILE__] ?? 0) + 1;
return new #[Test('Capture paths', retry: 1)] class($test, __FILE__, $GLOBALS['attempts'][__FILE__]) extends Suite {
    private int $captures = 0;
    private int $cases = 0;
    public function __construct($test, private string $file, private int $attempt) { parent::__construct($test); }
    #[Before]
    public function capture(): void {
        $this->test->browserSession(['capabilities' => []], fn () => new Screenshot(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j3ioAAAAASUVORK5CYII=') . $this->file . '|' . ++$this->captures));
    }
    #[Test('Save', retry: 1)]
    public function save(): void { $this->test->assert(++$this->cases === 2 && $this->attempt === 2, 'retry'); }
    #[Test('Unicode description: sáve')]
    public function save_again(): void { $this->test->assert(true, 'Unicode description'); }
};
PHP;
foreach ($names as $name) {
    $file = $root . '/test/cases/' . $name . '.php';
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0700, true);
    file_put_contents($file, $fixture);
}
foreach ([1, 2] as $workers) {
    Settings::set('', ['test' => ['savepath' => $root . '/runs-' . $workers,
        'screenshots' => ['steps' => 'always', 'cases' => 'always', 'suites' => 'always']]]);
    $runner = new class($root) extends Test {
        protected function resolveBrowser(?string $browser): ?string { return null; }
    };
    check($runner->run(workers: $workers) === 0, 'Retry fixture did not pass.');
    $file = glob($root . '/runs-' . $workers . '/*/report.json')[0];
    $record = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    $directory = $root . '/moved-' . $workers;
    rename(dirname($file), $directory);
    $paths = [];
    foreach ($record['suites'] as $suite) {
        check(count($suite['attempts']) === 2, 'Suite retry history missing.');
        foreach ($suite['attempts'] as $attempt) {
            $nodes = [[$attempt, null]];
            foreach ($attempt['cases'] as $case) foreach ($case['attempts'] as $caseAttempt) $nodes[] = [$caseAttempt, $case['method']];
            foreach ($nodes as [$node, $method]) {
                $images = $node['screenshots'];
                foreach ($node['steps'] as $step) $images = [...$images, ...$step['screenshots']];
                foreach ($images as $image) {
                    check(!isset($image['diagnostic']), json_encode($image));
                    $path = $image['path'];
                    $prefix = 'screenshots/' . $suite['id'] . '/' . ($method === null ? '' : $method . '/');
                    check(str_starts_with($path, $prefix), 'Wrong suite/case directory: ' . $path);
                    $bytes = file_get_contents($directory . '/' . $path);
                    check(str_contains($bytes, '/test/cases/' . $suite['id'] . '.php|'), 'Capture was overwritten by another suite: ' . $path);
                    if (isset($paths[$path])) check($paths[$path] === $suite['id'], 'Different suites shared a capture.');
                    $paths[$path] = $suite['id'];
                }
            }
        }
    }
    $files = iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory . '/screenshots', FilesystemIterator::SKIP_DOTS)));
    check(count($files) === count($paths) && count($paths) === count($names) * 11, 'Captures lost or duplicated: ' . count($paths));
    check(isset($paths['screenshots/folder/one_case/save/a2__a2__s1__pass.png'])
        && isset($paths['screenshots/folder/one_case/a2__suite.png'])
        && isset($paths['screenshots/folder/one_case/save/a2__suite.png'])
        && isset($paths['screenshots/estimates/create/save/a2__a2__s1__pass.png'])
        && isset($paths['screenshots/projects/create/save/a2__a2__s1__pass.png']), 'Suite paths, attempts or suite-level filenames changed.');
    echo "PASS: safe suite/case directories preserve serial/parallel retries, source-path separation and moved reports, workers=$workers\n";
}
