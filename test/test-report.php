<?php

// Run with: php test/test-report.php. Browser I/O is in-memory; recording and captures are real.
require __DIR__ . '/fixtures/test-runner.php';

use ThriveData\ThrivePHP\Settings;
use ThriveData\ThrivePHP\Test as Runner;
use ThriveData\ThrivePHP\Test\CaseAfter;
use ThriveData\ThrivePHP\Test\CaseBefore;
use ThriveData\ThrivePHP\Test\SuiteAfter;
use ThriveData\ThrivePHP\Test\SuiteBefore;
use ThriveData\ThrivePHP\Test\SuiteBrowser;
use ThriveData\ThrivePHP\Test\Test;
use ThriveData\ThrivePHP\WebDriver\Browser;
use ThriveData\ThrivePHP\WebDriver\Element;
use ThriveData\ThrivePHP\WebDriver\Screenshot;
use ThriveData\ThrivePHP\WebDriver\Window;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** Replace only session transport; public fluent methods and the recording guard remain real. */
class ReportBrowser extends Browser
{
    public array $trace = [];
    public string $value = '';
    public bool $captureFails = false;
    public string $liveConsole = '';
    private Window $fixtureWindow;
    public function __construct() { $this->fixtureWindow = new Window($this, 'fixture'); }
    public function window(?string $handle = null): Window { return $this->fixtureWindow; }
    public function activate(Window $window, ?array $frames): void {}
    public function timeouts(): array { return ['script' => 0]; }
    public function sessionRequest(string $method, string $path, ?array $body = null, float $extraTimeout = 0): mixed
    {
        $this->trace[] = [$method, $path, $body];
        return match ($path) {
            '/element' => [Element::REFERENCE => 'input'],
            '/element/input/clear' => $this->value = '',
            '/element/input/value' => $this->value .= $body['text'],
            '/element/input/property/value' => $this->value,
            '/element/input/text' => 'Actual',
            '/element/input/click' => throw new RuntimeException('click failed'),
            '/execute/sync' => null,
            '/screenshot' => $this->captureFails ? throw new RuntimeException('capture unavailable') : base64_encode(self::png()),
            default => throw new RuntimeException('Unexpected fixture request: ' . $path),
        };
    }
    public static function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    }
}

/** A helper call must retain this source location rather than the owning case invocation. */
function reportHelper(Element $element): void
{
    $element->value('Jane </script><script>window.injected=true</script>');
}

$browser = null;
$runner = new FixtureTest(PATH_ROOT);
Settings::$data['test']['screenshots']['suites'] = 'always';
[$status, $console, $record] = runChecks(['suite' => function (Runner $test) use (&$browser) {
    return new class($test, $browser) extends SuiteBrowser {
        public function __construct(Runner $test, private mixed &$reference) { parent::__construct($test); }
        #[SuiteBefore]
        public function startBrowser(): void
        {
            $this->reference = $this->browser = new ReportBrowser();
            $this->test->browserSession(['sessionId' => 'session-1', 'capabilities' => [
                'browserName' => 'fixture', 'browserVersion' => '123.4', 'platformName' => 'test-platform',
            ]], fn () => $this->browser->screenshot());
            $this->test->assert(true, 'suite setup');
        }
        #[CaseBefore]
        public function prepare(): void { $this->test->assert(true, 'case setup'); }
        #[Test('Successful body')]
        public function first(): void
        {
            $element = $this->browser->find('#name', wait: false);
            reportHelper($element);
            $element->valueEquals('Jane </script><script>window.injected=true</script>', wait: false);
            $element->property('value');
            $element->value();
            $element->value(null);
            $element->firstChild();
            $this->browser->sleep(0);
            $this->browser->trace[] = ['body returned'];
        }
        #[Test('Continuing failure')]
        public function second(): void
        {
            $element = $this->browser->find('#name', wait: false);
            $element->textEquals('Expected', wait: false, verify: true);
            $this->browser->liveConsole = ob_get_contents();
            $this->browser->trace[] = ['continued after verification'];
            $this->browser->wait(fn () => $this->browser->sleep(0), timeout: 1);
            $this->browser->find('#name', wait: false)->click();
        }
        #[Test('Skipped after failure')]
        public function third(): void { throw new LogicException('Should be skipped'); }
        #[CaseAfter]
        public function clean(): void
        {
            $this->browser->trace[] = ['case teardown'];
            $this->test->assert(true, 'case teardown');
        }
        #[SuiteAfter]
        public function done(): void
        {
            $this->browser->trace[] = ['suite teardown'];
            $this->test->assert(true, 'suite teardown');
        }
    };
}], $runner);
check($status === 1, $console);
$suite = $record['suites'][0]['attempts'][0];
$first = $suite['cases'][0]['attempts'][0];
$second = $suite['cases'][1]['attempts'][0];
check($record['environment']['browserVersion'] === '123.4' && $record['environment']['platform'] === 'test-platform'
    && $suite['session']['sessionId'] === 'session-1', 'Session capabilities were lost.');
check(array_column($first['steps'], 'label') === ['assert', 'find', 'value', 'valueEquals', 'sleep', 'assert'],
    'Nested actions or observations became steps: ' . json_encode(array_column($first['steps'], 'label')));
check(array_column($second['steps'], 'label') === ['assert', 'find', 'textEquals', 'wait', 'find', 'click', 'assert'],
    'Callback/internal steps were not suppressed or the guard leaked after failure.');
check(array_column($suite['steps'], 'phase') === ['suite-setup', 'suite-teardown'], 'Suite hook steps collided.');
check($first['steps'][0]['phase'] === 'case-setup' && end($first['steps'])['phase'] === 'case-teardown', 'Case phase attribution failed.');
check($first['steps'][2]['phase'] === 'test' && $second['steps'][2]['phase'] === 'test', 'Test phase was not renamed.');
check($record['environment']['root'] === realpath(PATH_ROOT)
    && $first['steps'][2]['location']['file'] === 'test/test-report.php'
    && $record['environment']['root'] . '/' . $first['steps'][2]['location']['file'] === __FILE__
    && $first['steps'][2]['location']['line'] === (new ReflectionFunction('reportHelper'))->getStartLine() + 2,
    'Helper location was not retained: ' . json_encode($first['steps'][2]['location']));
check($first['steps'][2]['values']['value'] === 'Jane </script><script>window.injected=true</script>', 'Input was redacted or altered.');
check($second['steps'][2]['assertions'][0]['expected'] === 'Expected'
    && $second['steps'][2]['assertions'][0]['actual'] === 'Actual', 'Structured comparison lost its values.');
check(!array_key_exists('expected', $first['steps'][0]['assertions'][0]), 'Plain assertion invented a comparison.');
check($second['steps'][2]['error'] === null
    && $second['steps'][2]['assertions'][0]['class'] === 'AssertionFailure'
    && $second['steps'][2]['assertions'][0]['location'] === $second['steps'][2]['location']
    && !isset($second['steps'][2]['assertions'][0]['stack']), 'Assertion retained duplicate or framework diagnostics.');
check($record['totals']['assertionsPassed'] === 7 && $record['totals']['assertionsFailed'] === 1
    && $record['totals']['casesSkipped'] === 1, 'Actions changed assertion counts: ' . json_encode($record['totals']));
check($suite['cases'][2]['attempts'] === [], 'Skipped case had an attempt.');
$trace = array_column($browser->trace, 0);
$body = array_search('body returned', $trace, true);
$continued = array_search('continued after verification', $trace, true);
$teardown = array_search('suite teardown', $trace, true);
check($browser->trace[$body + 1][1] === '/screenshot' && $trace[$body + 2] === 'case teardown', 'Success capture did not precede CaseAfter.');
check($browser->trace[$continued - 1][1] === '/screenshot', 'Verification capture was delayed until after continuation.');
check($browser->trace[$teardown - 1][1] === '/screenshot', 'Suite capture did not precede SuiteAfter.');
check($second['screenshots'][0]['path'] === $second['steps'][5]['screenshots'][0]['path'], 'Propagating action/case failure captured twice.');
$reportFile = glob($runner->saved . '/*/report.json')[0];
$directory = dirname($reportFile);
check(count(glob($directory . '/screenshots/*.png')) === 4, 'Unexpected capture count.');
foreach (glob($directory . '/screenshots/*.png') as $path) {
    check(file_get_contents($path) === ReportBrowser::png(), 'Saved PNG bytes changed.');
}
$html = file_get_contents($directory . '/report.html');
preg_match('~<script id="test-report-data" type="application/json">(.*?)</script>~s', $html, $match);
check(isset($match[1]) && !str_contains($match[1], '</script>')
    && json_decode($match[1], true, flags: JSON_THROW_ON_ERROR) === $record, 'Embedded HTML data differs or can close its script tag.');
check(str_contains($console, 'test/test-report.php:') && !str_contains($console, __FILE__ . ':')
    && str_contains($console, 'Expected: "Expected"')
    && str_contains($console, '#0 ') && str_contains($console, 'Screenshot: ' . realpath($directory) . '/screenshots/'), 'Console omitted failure details.');
check(str_contains($browser->liveConsole, 'FAIL  STEP  suite > second: Continuing failure > textEquals [test, ')
    && str_contains($browser->liveConsole, 'Expected: "Expected"')
    && !str_contains($browser->liveConsole, 'FAIL  CASE') && !str_contains($browser->liveConsole, 'FAIL  SUITE'),
    'Failed step diagnostics were delayed until case or suite completion.');
check(substr_count($browser->liveConsole, 'AssertionFailure: ') === 1
    && !str_contains($browser->liveConsole, 'ThriveData\\ThrivePHP\\Test\\AssertionFailure')
    && !str_contains($browser->liveConsole, 'src/Test.php') && !str_contains($browser->liveConsole, '#0 ')
    && substr_count($browser->liveConsole, 'test/test-report.php:') === 1,
    'Live assertion output duplicated its message/source or exposed instrumentation.');
check(!str_contains($console, 'PASS  STEP') && substr_count($console, 'FAIL  STEP') === 2
    && substr_count($console, 'Execution error: RuntimeException: click failed') === 1
    && substr_count($console, 'Screenshot: ' . realpath($directory) . '/' . $second['steps'][5]['screenshots'][0]['path']) === 1,
    'Compact output replayed successful steps or duplicated failure diagnostics.');
echo "PASS: canonical steps, source attribution, comparisons, captures, capability metadata and HTML encoding\n";

$innerRecord = null;
[$status, $console, $outerRecord] = runChecks(['check' => function (Runner $test) use (&$innerRecord) {
    [, , $innerRecord] = runChecks(['check' => function (Runner $inner) {
        $inner->verify(false, 'inner root');
        throw new RuntimeException('outer exception', 0, new LogicException('previous exception'));
    }], new FixtureTest(__DIR__, '/fixtures/test-checks'));
    $test->verify(false, 'outer root restored');
}]);
$inner = $innerRecord['suites'][0]['attempts'][0];
$outer = $outerRecord['suites'][0]['attempts'][0];
check($innerRecord['environment']['root'] === __DIR__ && $outerRecord['environment']['root'] === PATH_ROOT
    && $inner['steps'][0]['location']['file'] === 'test-report.php'
    && $outer['steps'][0]['location']['file'] === 'test/test-report.php', 'Nested run changed the path prefix.');
check($innerRecord['suites'][0]['file'] === 'fixtures/test-checks/check.php'
    && $inner['errors'][0]['location']['file'] === 'test-report.php'
    && $inner['errors'][0]['previous']['location']['file'] === 'test-report.php'
    && $outer['steps'][0]['assertions'][0]['location']['file'] === 'test/test-report.php'
    && $inner['steps'][0]['assertions'][0]['location']['file'] === 'test-report.php', 'Suite or error paths lost their root.');
check(str_contains($inner['errors'][0]['stack'], PATH_ROOT . '/src/Test.php(')
    && !preg_match('~^#\d+ ' . preg_quote(__DIR__ . '/', '~') . '~m', $inner['errors'][0]['stack'])
    && $outer['steps'][0]['error'] === null && $inner['steps'][0]['error'] === null,
    'Stack frames were not shortened or an external file was made relative.');
$external = (new \ThriveData\ThrivePHP\Test\Diagnostic(__DIR__ . '/fixtures'))->error(new RuntimeException('external source'));
check($external['location']['file'] === __FILE__, 'External exception source was made relative.');
try {
    (new Runner(PATH_ROOT))->assert(false, 'previous assertion');
} catch (\ThriveData\ThrivePHP\Test\AssertionFailure $error) {
    $diagnostic = (new \ThriveData\ThrivePHP\Test\Diagnostic(PATH_ROOT))->error(new RuntimeException('wrapped error', 0, $error));
    check($diagnostic['class'] === RuntimeException::class && $diagnostic['stack'] !== ''
        && $diagnostic['previous']['class'] === 'AssertionFailure'
        && $diagnostic['previous']['location']['file'] === 'test/test-report.php'
        && !array_key_exists('stack', $diagnostic['previous']), 'Previous assertion exposed instrumentation or lost its author.');
}
$diagnostic = new \ThriveData\ThrivePHP\Test\Diagnostic(__DIR__);
check($diagnostic->path(__DIR__ . '-other/file.php') === __DIR__ . '-other/file.php', 'Root matching crossed a directory boundary.');
echo "PASS: relative paths retain reconstructable roots, nested-run isolation and absolute external files\n";

Settings::$data['test']['screenshots'] = ['steps' => 'always', 'cases' => 'always', 'suites' => 'off'];
Settings::$data['test']['console']['steps'] = true;
[$status, $console, $record] = runChecks(['check' => function (Runner $test) {
    $browser = new ReportBrowser();
    $browser->captureFails = true;
    $test->browserSession(['sessionId' => 'unavailable', 'capabilities' => []], fn () => $browser->screenshot());
    $browser->sleep(0)->sleep(0);
}]);
$steps = $record['suites'][0]['attempts'][0]['steps'];
check($status === 0 && count($steps) === 2 && isset($steps[0]['screenshots'][0]['diagnostic'])
    && str_contains($console, 'Screenshot unavailable: capture unavailable') && str_contains($console, 'PASS  STEP  check > sleep [test, '),
    'Capture failure changed the result or step-wise output omitted successful actions.');
echo "PASS: capture diagnostics preserve results, successful-step capture and step-wise console\n";

[$status, $console, $record] = runChecks(['suite' => function (Runner $test) {
    return new class($test) extends \ThriveData\ThrivePHP\Test\Suite {
        #[SuiteBefore]
        public function prepare(): void { $this->test->recordStep('prepare', null, [], static fn () => null); }
        #[Test('live steps')]
        public function run(): void { $this->test->verify(false, 'live failure'); }
        #[SuiteAfter]
        public function cleanup(): void { $this->test->recordStep('cleanup', null, [], static fn () => null); }
    };
}]);
$headers = preg_grep('/^(RUN|PASS|FAIL) +(SUITE|CASE|STEP) /', explode("\n", $console));
$headers = array_map(static fn ($line) => preg_replace('/ \\[.*$/', '', $line), array_values($headers));
check($status === 1 && $headers === [
    'RUN   SUITE suite',
    'PASS  STEP  suite > prepare',
    'RUN   CASE  suite > run: live steps',
    'FAIL  STEP  suite > run: live steps > verify',
    'FAIL  CASE  suite > run: live steps',
    'PASS  STEP  suite > cleanup',
    'FAIL  SUITE suite',
], 'Step output is not chronological or was replayed: ' . $console);
check(preg_match('/^PASS  STEP  suite > prepare \\[suite-setup, \\d+\\.\\d{3}s\\]$/m', $console)
    && preg_match('/^FAIL  STEP  suite > run: live steps > verify \\[test, \\d+\\.\\d{3}s\\]$/m', $console)
    && preg_match('/^FAIL  CASE  suite > run: live steps \\[\\d+\\.\\d{3}s, 1 assertions\\]$/m', $console)
    && preg_match('/^FAIL  SUITE suite \\[\\d+\\.\\d{3}s\\]$/m', $console), 'Completed entries lost phase or timing.');
echo "PASS: successful and failed steps print in execution order with consistent labels and timing\n";

[$status, $console, $record] = runChecks(['check' => function () {
    $browser = new ReportBrowser();
    try {
        $browser->sleep(NAN);
        throw new LogicException('Nonfinite sleep was accepted.');
    } catch (InvalidArgumentException) {}
    $browser->sleep(0);
}]);
$steps = $record['suites'][0]['attempts'][0]['steps'];
check($status === 0 && array_column($steps, 'status') === ['FAIL', 'PASS']
    && $steps[0]['error']['class'] === InvalidArgumentException::class
    && $steps[0]['values']['seconds'] === ['type' => 'float', 'value' => 'NAN'],
    'Recording replaced argument validation or leaked its guard after a caught exception: ' . json_encode($steps) . $console);
echo "PASS: invalid supplied values retain their original errors and unwind the shared guard\n";

Settings::$data = [];
$attempts = 0;
[$status, $console, $record] = runChecks(['suite' => function (Runner $test) use (&$attempts) {
    $attempts++;
    return new #[Test('Retry suite', retry: 1)] class($test, $attempts) extends \ThriveData\ThrivePHP\Test\Suite {
        private int $caseAttempt = 0;
        public function __construct(Runner $test, private int $suiteAttempt) { parent::__construct($test); }
        #[Test('Retry case', retry: 1)]
        public function body(): void { $this->test->assert($this->suiteAttempt === 2 && ++$this->caseAttempt === 2, 'retry result'); }
    };
}]);
$attempts = $record['suites'][0]['attempts'];
check($status === 0 && count($attempts) === 2 && count($attempts[0]['cases'][0]['attempts']) === 2
    && count($attempts[1]['cases'][0]['attempts']) === 2 && $record['totals']['assertionsFailed'] === 0
    && $record['totals']['assertionsPassed'] === 1, 'Retry history or final totals changed.');
check($record['suites'][0]['maxAttempts'] === 2 && $attempts[0]['cases'][0]['maxAttempts'] === 2
    && str_contains($console, "RETRY CASE  suite (attempt 1/2) > body: Retry case (attempt 1/2)\n")
    && str_contains($console, "RETRY SUITE suite: Retry suite (attempt 1/2)\n")
    && str_contains($console, 'PASS  CASE  suite (attempt 2/2) > body: Retry case (attempt 2/2)')
    && str_contains($console, 'PASS  SUITE suite: Retry suite (attempt 2/2)'), 'Retry labels do not identify the completed attempt.');
$ids = [];
foreach ($attempts as $attempt) foreach ($attempt['cases'][0]['attempts'] as $case) $ids[] = $case['steps'][0]['id'];
check(count(array_unique($ids)) === 4, 'Retry step identities collided.');
foreach ($attempts as $attempt) foreach ($attempt['cases'][0]['attempts'] as $case) {
    $step = $case['steps'][0];
    check($step['error'] === null && ($step['status'] === 'PASS'
        || ($step['assertions'][0]['class'] === 'AssertionFailure' && !isset($step['assertions'][0]['stack']))),
        'Stopping assertion retained a duplicate exception in retry history.');
}
check(substr_count($console, 'AssertionFailure: retry result') === 3 && !str_contains($console, '#0 ')
    && !str_contains($console, 'src/Test.php'), 'Retry output replayed assertion diagnostics or instrumentation.');
echo "PASS: complete retry histories use distinct identities and final-outcome totals\n";

[$status, $console, $record] = runChecks(['suite' => function (Runner $test) {
    return new #[Test('Unused retries', retry: 3)] class($test) extends \ThriveData\ThrivePHP\Test\Suite {
        private int $attempt = 0;
        #[Test('Second succeeds', retry: 2)]
        public function second(): void { $this->test->assert(++$this->attempt === 2, 'second succeeds'); }
        #[Test('First succeeds', retry: 4)]
        public function first(): void { $this->test->assert(true, 'first succeeds'); }
    };
}]);
$suite = $record['suites'][0];
$cases = $suite['attempts'][0]['cases'];
check($status === 0 && $suite['maxAttempts'] === 4 && count($suite['attempts']) === 1
    && $cases[0]['maxAttempts'] === 3 && array_column($cases[0]['attempts'], 'index') === [1, 2]
    && $cases[1]['maxAttempts'] === 5 && array_column($cases[1]['attempts'], 'index') === [1]
    && str_contains($console, "RETRY CASE  suite (attempt 1/4) > second: Second succeeds (attempt 1/3)\n")
    && str_contains($console, 'PASS  CASE  suite (attempt 1/4) > second: Second succeeds (attempt 2/3)')
    && str_contains($console, 'PASS  CASE  suite (attempt 1/4) > first: First succeeds (attempt 1/5)')
    && str_contains($console, 'PASS  SUITE suite: Unused retries (attempt 1/4)'), 'Attempt limits were inferred from attempts used.');
echo "PASS: case and suite attempt labels retain configured limits after early success\n";

$record = new \ThriveData\ThrivePHP\Test\Record(['root' => PATH_ROOT]);
$clock = hrtime(true) / 1e9;
$record->accept('folder', ['type' => 'configured', 'name' => 'folder', 'clock' => $clock - 10,
    'definitions' => [['method' => 'body', 'name' => 'body', 'location' => null, 'maxAttempts' => 1]]]);
$record->accept('folder', ['type' => 'case_started', 'method' => 'body']);
$record->accept('folder', ['type' => 'case_attempt_started', 'method' => 'body', 'clock' => $clock - 10]);
$record->accept('folder/a1/body', ['type' => 'suite_started', 'clock' => $clock - 1]);
$record->interrupt('folder/a1/body', 'nested suite interrupted');
$record->interrupt('folder', 'outer suite interrupted');
$outer = $record->suite('folder')['attempts'][1]['cases']['body']['attempts'][1];
$nested = $record->suite('folder/a1/body');
check($nested['id'] === 'folder/a1/body' && $nested['attempts'][1]['id'] === 'folder/a1/body/a1'
    && $outer['id'] === $nested['attempts'][1]['id']
    && $outer['duration'] - $nested['attempts'][1]['duration'] > 8,
    'Readable nested file IDs shared interruption clocks with another suite.');
echo "PASS: readable nested suite IDs retain independent interruption timing\n";

/** Expose the production resolvers for contract checks, with an isolated project root. */
class PathRunner extends Runner
{
    public function paths(): array { return [$this->basePath(), $this->casePath(), $this->savePath()]; }
}
$root = sys_get_temp_dir() . '/thrive-report-paths-' . bin2hex(random_bytes(8));
$test = new PathRunner($root);
check($test->paths() === [$root . '/test', $root . '/test/cases', $root . '/test/runs'], 'Default paths changed.');
Settings::$data['test'] = ['basepath' => 'spec', 'casepath' => 'checks', 'savepath' => 'artifacts'];
check($test->paths() === [$root . '/spec', $root . '/spec/checks', $root . '/spec/artifacts'], 'Relative path anchors changed.');
Settings::$data['test'] = ['basepath' => $root, 'casepath' => $root . '/cases', 'savepath' => $root . '/runs'];
mkdir($root . '/cases', 0700, true);
mkdir($root . '/runs');
file_put_contents($root . '/cases/check.php', '<?php return static function () {};');
// Cover the second boundary without sleeping or weakening the production collision contract.
foreach ([time(), time() + 1] as $time) mkdir($root . '/runs/' . date('Y-m-d_H-i-s', $time));
try {
    try {
        $test->run(workers: 1);
        throw new LogicException('Existing run directory was accepted.');
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'run directory'), $error->getMessage());
    }
} finally {
    foreach (glob($root . '/runs/*') as $path) rmdir($path);
    unlink($root . '/cases/check.php');
    rmdir($root . '/cases');
    rmdir($root . '/runs');
    rmdir($root);
    Settings::$data = [];
}
echo "PASS: settings-driven paths retain their anchors and run collisions throw\n";

$root = sys_get_temp_dir() . '/thrive-report-project-' . bin2hex(random_bytes(8));
mkdir($root . '/cases', 0700, true);
file_put_contents($root . '/cases/check.php', '<?php return static function () {};');
try {
    foreach ([
        [null, null, null, null],
        ['{}', null, null, null],
        ['{"name":"example/project"}', null, null, 'example/project'],
        ['{"name":"example/project"}', 'Configured project', null, 'Configured project'],
        ['{"name":"example/project"}', 'Configured project', 'Explicit project', 'Explicit project'],
        ['invalid JSON', 'Configured project', null, 'Configured project'],
        ['invalid JSON', 'Configured project', '', ''],
    ] as [$composer, $setting, $title, $expected]) {
        if ($composer !== null) file_put_contents($root . '/composer.json', $composer);
        Settings::$data = ['test' => ['project' => $setting]];
        $runner = new FixtureTest($root, '/cases');
        ob_start();
        try {
            $status = $runner->run(workers: 1, title: $title);
        } finally {
            ob_end_clean();
        }
        $record = json_decode(file_get_contents(glob($runner->saved . '/*/report.json')[0]), true, flags: JSON_THROW_ON_ERROR);
        check($status === 0 && array_key_exists('project', $record['environment'])
            && $record['environment']['project'] === $expected, 'Project title precedence changed.');
    }
} finally {
    if (is_file($root . '/composer.json')) unlink($root . '/composer.json');
    unlink($root . '/cases/check.php');
    rmdir($root . '/cases');
    rmdir($root);
    Settings::$data = [];
}
echo "PASS: project titles use explicit, settings, and Composer precedence without directory guesses\n";
