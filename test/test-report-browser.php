<?php

// Run with: php test/test-report-browser.php [chrome|firefox] [1|2]. Uses isolated data: pages.
$source = dirname(__DIR__) . '/src/';
spl_autoload_register(static function ($class) use ($source) {
    $prefix = 'ThriveData\\ThrivePHP\\';
    if (str_starts_with($class, $prefix)) require $source . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
});

use ThriveData\ThrivePHP\Settings;
use ThriveData\ThrivePHP\Test;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = sys_get_temp_dir() . '/thrive-browser-report-' . bin2hex(random_bytes(8));
mkdir($root . '/test/cases/folder', 0700, true);
$project = json_decode(file_get_contents(dirname(__DIR__) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR)['name'];
file_put_contents($root . '/composer.json', json_encode(['name' => $project], JSON_THROW_ON_ERROR));
mkdir($root . '/vendor');
file_put_contents($root . '/vendor/autoload.php', '<?php spl_autoload_register(static function ($class) {
    $prefix = "ThriveData\\\\ThrivePHP\\\\";
    if (str_starts_with($class, $prefix)) require ' . var_export($source, true) . ' . str_replace("\\\\", "/", substr($class, strlen($prefix))) . ".php";
});');
$suite = <<<'PHP'
<?php
use ThriveData\ThrivePHP\Test\SuiteBrowser;
use ThriveData\ThrivePHP\Test\SuiteBefore;
use ThriveData\ThrivePHP\Test\CaseAfter;
use ThriveData\ThrivePHP\Test\Test;
return new class($test) extends SuiteBrowser {
    private int $attempt = 0;
    #[SuiteBefore]
    public function page(): void {
        $this->browser->resize(640, 480)->navigate('data:text/html,' . rawurlencode('<!doctype html><title>Reporting</title><input id="name"><p id="status">Ready</p>'));
    }
    #[Test('Retry a body', retry: 1)]
    public function retry_body(): void {
        $this->browser->find('#name')->value('Jane </script>');
        $this->browser->find('#name')->valueEquals('Jane </script>');
        $this->test->assert(++$this->attempt === 2, 'second attempt passes');
    }
    #[Test('Capture before continuing')]
    public function failure_body(): void {
        $this->browser->find('#status')->textEquals('Different', wait: false, verify: true);
        $this->browser->script('document.body.style.background = "lightblue";');
    }
    #[Test('Skipped body')]
    public function skipped(): void { throw new LogicException('Skipped body ran'); }
    #[CaseAfter]
    public function cleanup(): void { $this->browser->script('document.body.style.border = "3px solid red";'); }
};
PHP;
file_put_contents($root . '/test/cases/folder/one_case.php', $suite);
file_put_contents($root . '/test/cases/folder/two_case.php', $suite);
Settings::$data = ['test' => ['screenshots' => ['suites' => 'always']]];
$runner = new Test($root);
$status = $runner->run(workers: (int)($argv[2] ?? 2), browser: $argv[1] ?? 'chrome', headless: true);
$file = glob($root . '/test/runs/*/report.json')[0] ?? null;
check($status === 1 && $file !== null, 'Expected intentional assertion failures and report artifacts.');
$record = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
check($record['environment']['root'] === realpath($root), 'Report does not retain the resolved absolute project root.');
check($record['environment']['project'] === $project, 'Report does not retain the Composer project name.');
check($record['environment']['browserVersion'] !== null && $record['environment']['browserVersion'] !== '', 'Actual browser version was not retained.');
check($record['totals']['casesPassed'] === 2 && $record['totals']['casesFailed'] === 2
    && $record['totals']['casesSkipped'] === 2 && $record['totals']['assertionsPassed'] === 4
    && $record['totals']['assertionsFailed'] === 2, 'Incorrect final outcomes: ' . json_encode($record['totals']));
$paths = [];
$sessionIds = [];
foreach ($record['suites'] as $suite) {
    $attempt = $suite['attempts'][0];
    check($suite['id'] === $suite['name'] && $attempt['id'] === $suite['name'] . '/a1', 'Suite IDs are not readable.');
    check($suite['file'] === 'test/cases/' . $suite['name'] . '.php'
        && realpath($record['environment']['root'] . '/' . $suite['location']['file']) === realpath($root . '/' . $suite['file']),
        'Suite paths cannot be reconstructed from the record.');
    $sessionIds[] = $attempt['session']['sessionId'];
    check(count($attempt['cases'][0]['attempts']) === 2, 'Retry history missing.');
    $nodes = [$attempt];
    foreach ($attempt['cases'] as $case) foreach ($case['attempts'] as $caseAttempt) $nodes[] = $caseAttempt;
    foreach ($nodes as $node) {
        $images = $node['screenshots'];
        foreach ($node['steps'] as $step) {
            check(str_starts_with($step['id'], $node['id'] . '/s'), 'Step ID does not match its readable attempt ID.');
            $images = [...$images, ...$step['screenshots']];
        }
        foreach ($images as $image) {
            check(!isset($image['diagnostic']), 'Capture failed: ' . json_encode($image));
            $path = dirname($file) . '/' . $image['path'];
            check(is_file($path) && getimagesize($path)[0] === $image['width'] && $image['height'] > 0, 'PNG metadata/path mismatch.');
            $paths[$image['path']] = true;
        }
    }
}
check(count(array_unique($sessionIds)) === 2, 'Suites shared a session.');
check(count($paths) === 10 && count(glob(dirname($file) . '/screenshots/*.png')) === 10, 'Capture paths collided or images were lost.');
check(isset($paths['screenshots/folder-one_case__a1__failure_body__a1__s2__fail.png'])
    && isset($paths['screenshots/folder-one_case__a1__retry_body__a2__case.png'])
    && isset($paths['screenshots/folder-one_case__a1__suite.png']), 'Screenshot filenames are not readable or lost attempt/step identity.');
echo 'PASS: native ' . ($argv[1] ?? 'chrome') . ' reporting, workers=' . ($argv[2] ?? 2) . PHP_EOL;
echo 'Artifacts retained for inspection: '
    . (new \ThriveData\ThrivePHP\Test\Diagnostic($record['environment']['root']))->path(dirname($file)) . PHP_EOL;
