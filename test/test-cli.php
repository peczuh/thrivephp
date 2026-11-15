<?php

// Run with: php test/test-cli.php. Uses isolated projects and real runners/workers, with a fixture browser inventory.
$root = sys_get_temp_dir() . '/thrive-cli-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$root = realpath($root);
$source = dirname(__DIR__) . '/src/';
$cli = dirname(__DIR__) . '/bin/cli.php';
$sequence = 0;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** Only browser inventory and application initialization are fixtures; the runner and workers are real. */
function project(string $directory): string
{
    global $source, $cli;
    mkdir($directory . '/vendor/peczuh/thrivephp/bin', 0700, true);
    mkdir($directory . '/vendor/peczuh/thrivephp/src');
    mkdir($directory . '/local');
    mkdir($directory . '/test/cases/folder1', 0700, true);
    mkdir($directory . '/test/cases/folder2');
    copy($cli, $directory . '/vendor/peczuh/thrivephp/bin/cli.php');
    copy($source . 'CLI.php', $directory . '/vendor/peczuh/thrivephp/src/CLI.php');
    file_put_contents($directory . '/settings.json', '{"root":".","test":{"project":"project-default"},"fixture":{"value":0}}');
    file_put_contents($directory . '/local/settings.json', '{"test":{"project":"local-default"},"fixture":{"value":1}}');
    file_put_contents($directory . '/vendor/autoload.php', '<?php
namespace ThriveData\ThrivePHP;
class Application {
    public static function init(): void {
        Settings::init();
        file_put_contents(PATH_ROOT . "/bootstrapped", "yes");
    }
}
spl_autoload_register(static function ($class) {
    $prefix = "ThriveData\\\\ThrivePHP\\\\";
    if (str_starts_with($class, $prefix)) require ' . var_export($source, true) . ' . str_replace("\\\\", "/", substr($class, strlen($prefix))) . ".php";
});
namespace ThriveData\ThrivePHP\WebDriver;
class WebDriver {
    public const PREFERENCE = ["chrome", "chromium", "firefox", "safaripreview", "safari"];
    public static function bootstrap(): object {
        return (object)["browsers" => (object)[
            "chrome" => (object)["ready" => true, "driver" => (object)["ready" => true]],
            "firefox" => (object)["ready" => true, "driver" => (object)["ready" => true]],
            "chromium" => (object)["ready" => true, "driver" => (object)["ready" => false]],
            "safaripreview" => (object)["ready" => true, "driver" => (object)["ready" => true]],
            "safari" => (object)["ready" => true, "driver" => (object)["ready" => true]],
        ]];
    }
}');
    $suite = <<<'PHP'
        <?php
        use ThriveData\ThrivePHP\Settings;
        use ThriveData\ThrivePHP\Test\Suite;
        use ThriveData\ThrivePHP\Test\Test;
        return new class($test) extends Suite {
            #[Test('First case', tags: ['smoke'])]
            public function caseA(): void {
                $this->test->assert(Settings::get('fixture.value') === 1, 'Local settings loaded');
            }
            #[Test('Second case', tags: ['slow'])]
            public function caseB(): void {
                if (Settings::fetch('fixture.fail')) $this->test->assert(false, 'Intentional CLI failure');
                $this->test->assert(true, 'Second case ran');
            }
        };
        PHP;
    file_put_contents($directory . '/test/cases/folder1/suite1.php', $suite);
    file_put_contents($directory . '/test/cases/folder2/suite2.php', $suite);
    return $directory . '/vendor/peczuh/thrivephp/bin/cli.php';
}

/** Each invocation gets a unique report directory, including calls expected to fail before bootstrap. */
function invoke(string $script, string $cwd, array $arguments, bool $shortTags = true): array
{
    global $root, $sequence;
    $save = $root . '/runs-' . ++$sequence;
    $errors = $root . '/stderr-' . $sequence;
    $process = proc_open([PHP_BINARY, '-d', 'short_open_tag=' . (int)$shortTags, $script,
        '--set', 'test.savepath=' . json_encode($save, JSON_THROW_ON_ERROR), ...$arguments],
        [0 => ['null'], 1 => ['pipe', 'w'], 2 => ['file', $errors, 'w']], $pipes, cwd: $cwd);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $code = proc_close($process);
    $err = file_get_contents($errors);
    $reports = glob($save . '/*/report.json');
    $report = $reports ? json_decode(file_get_contents($reports[0]), true, flags: JSON_THROW_ON_ERROR) : null;
    return [$code, $out, $err, $report];
}

function selected(array $report): array
{
    $selected = [];
    foreach ($report['suites'] as $suite) foreach ($suite['attempts'][0]['cases'] ?? [] as $case) {
        $selected[] = basename($suite['name']) . '::' . $case['method'];
    }
    sort($selected);
    return $selected;
}

try {
    $script = project($root . '/project');
    $cwd = $root . '/project';
    foreach ([[], ['--help'], ['test', '--help'], ['help']] as $arguments) {
        [$code, $out, $err, $report] = invoke($cli, $root, $arguments, shortTags: false);
        check($code === 0 && str_contains($out, 'Usage:') && $err === '' && $report === null, $out . $err);
    }
    check(!file_exists($cwd . '/bootstrapped'), 'Help bootstrapped a project.');
    echo "PASS: help needs no project or enabled short tags\n";

    [$code, $out, $err, $report] = invoke($script, $cwd, ['test', '--workers', '1']);
    check($code === 0 && $err === '' && selected($report) === ['suite1::caseA', 'suite1::caseB', 'suite2::caseA', 'suite2::caseB'], $out . $err);
    check($report['environment']['project'] === 'local-default', 'Bootstrap did not load local settings.');
    check($report['environment']['headless'] === true, 'An omitted headless option did not default to true.');
    [$code, $out, $err, $report] = invoke($script, $cwd, ['--workers', '1', 'test', 'folder1/suite1::caseA', 'folder2/suite2::caseB', 'folder1/suite1::caseA']);
    check($code === 0 && $err === '' && selected($report) === ['suite1::caseA', 'suite2::caseB'], $out . $err);
    [$code, $out, $err, $report] = invoke($script, $cwd, ['test', 'folder1/::caseA##smoke', 'folder2/::caseB#!smoke', '--workers', '1']);
    check($code === 0 && $err === '' && selected($report) === ['suite1::caseA', 'suite2::caseB'], $out . $err);
    [$code, $out, $err, $report] = invoke($script, $cwd, ['test', '--workers', '1', '--', '--help']);
    check($code === 0 && $report !== null && selected($report) === [], 'Option terminator was not honored: ' . $out . $err);
    [$code, $out, $err, $report] = invoke($script, $cwd, ['test', '--workers', '1',
        '--set', 'test.basepath=' . json_encode($cwd . '/test', JSON_THROW_ON_ERROR), '--set', 'test.casepath="cases/folder1"']);
    check($code === 0 && selected($report) === ['suite1::caseA', 'suite1::caseB'], $out . $err);
    echo "PASS: full runs, positional selector OR, tags/exclusions, deduplication and option placement\n";

    file_put_contents($cwd . '/test/cases/overrides.php', <<<'PHP'
        <?php
        use ThriveData\ThrivePHP\Settings;
        return function ($test) {
            $test->assert(Settings::get('fixture.override') === false, 'Last CLI override wins');
            $test->assert(Settings::get('fixture.string') === 'a=b', 'Split --set on the first equals sign');
            $snapshot = Settings::export();
            $test->assert($snapshot->fixture->object instanceof stdClass && $snapshot->fixture->list === [], 'Typed JSON reaches workers');
        };
        PHP);
    foreach ([1, 2] as $workers) {
        [$code, $out, $err, $report] = invoke($script, $cwd, ['test', '^overrides$', '--workers', (string)$workers,
            '--browser', 'firefox', '--headless', '--set', 'fixture.override=true', '--set', 'fixture.override=false',
            '--set', 'fixture.string="a=b"', '--set', 'fixture.object={}', '--set', 'fixture.list=[]',
            '--set', 'test.project="CLI project"']);
        check($code === 0 && $err === '' && $report['environment']['project'] === 'CLI project'
            && $report['environment']['browser'] === 'firefox' && $report['environment']['headless'] === true, $out . $err);
    }
    [$code, $out, $err, $report] = invoke($script, $cwd, ['test', '^folder1/', '--workers', '1',
        '--set', 'test.project="settings title"', '--title', 'explicit title']);
    check($code === 0 && $report['environment']['project'] === 'explicit title', $out . $err);
    echo "PASS: typed overrides, explicit options and titles reach real serial/parallel runners\n";

    $otherScript = project($root . '/other');
    symlink($script, $root . '/other/symlink.php');
    [$code, $out, $err, $report] = invoke($root . '/other/symlink.php', $root . '/other', ['--help'], shortTags: false);
    check($code === 0 && str_contains($out, 'Usage:') && $err === '' && $report === null, $out . $err);
    [$code, $out, $err, $report] = invoke($root . '/other/symlink.php', $root . '/other', ['test', '^folder1/', '--workers', '1']);
    check($code === 0 && $report['environment']['root'] === realpath($cwd), $out . $err);
    echo "PASS: symlinks load the CLI and discover from the target directory\n";

    $marker = dirname($script) . '/settings.json';
    file_put_contents($marker, '{"nested":{"root":"ignored"}}');
    [$code, $out, $err, $report] = invoke($script, $cwd, ['test', '^folder1/', '--workers', '1']);
    check($code === 0 && $report['environment']['root'] === realpath($cwd), $out . $err);
    file_put_contents($marker, '{"root":"../../../../../other"}');
    [$code, $out, $err, $report] = invoke($script, $cwd, ['test', '^folder1/', '--workers', '1']);
    check($code === 0 && $report['environment']['root'] === realpath($root . '/other'), $out . $err);
    file_put_contents($marker, json_encode(['root' => $root . '/other'], JSON_THROW_ON_ERROR));
    [$code, $out, $err, $report] = invoke($script, $cwd, ['test', '^folder1/', '--workers', '1']);
    check($code === 0 && $report['environment']['root'] === realpath($root . '/other'), $out . $err);
    unlink($marker);
    [$code, $out, $err] = invoke($script, dirname($script), ['test', '--workers', '1']);
    check($code === 1 && str_contains($err, 'discovery stopped at ' . dirname($script)), $out . $err);
    file_put_contents($cwd . '/settings.json', '{}');
    [$code, $out, $err] = invoke($script, $cwd, ['test', '--workers', '1']);
    check($code === 1 && str_contains($err, 'discovery stopped at ' . $cwd), $out . $err);
    file_put_contents($marker, '{malformed');
    [$code, $out, $err] = invoke($script, $cwd, ['test', '--workers', '1']);
    check($code === 1 && str_contains($err, 'Syntax error'), $out . $err);
    [$code, $out, $err, $report] = invoke($script, $root, ['--root', 'other', 'test', '^folder1/', '--workers', '1']);
    check($code === 0 && $report['environment']['root'] === realpath($root . '/other'), $out . $err);
    foreach (['null', '42', '""', '[]'] as $invalidRoot) {
        file_put_contents($marker, '{"root":' . $invalidRoot . '}');
        [$code, $out, $err] = invoke($script, $cwd, ['test']);
        check($code === 1 && str_contains($err, 'must be a nonempty string'), $out . $err);
    }
    file_put_contents($marker, json_encode(['root' => $root], JSON_THROW_ON_ERROR));
    [$code, $out, $err] = invoke($script, $cwd, ['test']);
    check($code === 1 && str_contains($err, 'Project autoloader not found'), $out . $err);
    echo "PASS: discovery markers, relative/absolute roots, CWD boundary, malformed settings and explicit bypass\n";

    foreach ([['unknown'], ['test', '--unknown'], ['test', '--root'], ['test', '--workers'],
        ['test', '--workers', '0'], ['test', '--workers', '-1'], ['test', '--workers', '1.5'],
        ['test', '--workers', 'two'], ['test', '--set', 'key=bad'], ['test', '--set', '=true'],
        ['test', '--set', 'key'], ['test', '--title', '--workers', '1']] as $arguments) {
        [$code, $out, $err, $report] = invoke($cli, $root, $arguments);
        check($code === 1 && str_starts_with($err, 'ERROR ') && $report === null, json_encode($arguments) . $out . $err);
    }
    [$code, $out, $err, $report] = invoke($otherScript, $root . '/other', ['test', '[', '--workers', '1']);
    check($code === 1 && $report !== null && str_contains($out, 'Invalid'), $out . $err);
    [$code, $out, $err, $report] = invoke($otherScript, $root . '/other', ['test', '::caseB', '--workers', '1', '--set', 'fixture.fail=true']);
    check($code === 1 && $report['totals']['casesFailed'] === 2, $out . $err);
    [$code, $out, $err] = invoke($otherScript, $root . '/other', ['test', '--workers', '1'], shortTags: false);
    check($code === 1 && str_contains($out . $err, 'Enable short_open_tag'), $out . $err);
    echo "PASS: usage errors, selector diagnostics and failing tests return nonzero status\n";

    $preferenceProject = $root . '/other';
    foreach (['preference_a', 'preference_b'] as $name) {
        file_put_contents($preferenceProject . '/test/cases/' . $name . '.php', <<<'PHP'
            <?php
            return function ($test) {
                $test->assert($test->browser() === $test->settings('fixture.expectedBrowser'), 'Resolved browser selection reaches this process');
            };
            PHP);
    }
    [$code, $out, $err, $report] = invoke($otherScript, $preferenceProject,
        ['test', '^preference_', '--workers', '2', '--set', 'fixture.expectedBrowser="chrome"']);
    check($code === 0 && $err === '' && $report['environment']['browser'] === 'chrome', $out . $err);
    $projectSettings = json_decode(file_get_contents($preferenceProject . '/settings.json'), true, flags: JSON_THROW_ON_ERROR);
    $projectSettings['test']['browser']['driver'] = ['chrome'];
    file_put_contents($preferenceProject . '/settings.json', json_encode($projectSettings, JSON_THROW_ON_ERROR));
    $localSettings = json_decode(file_get_contents($preferenceProject . '/local/settings.json'), true, flags: JSON_THROW_ON_ERROR);
    $localSettings['test']['browser']['driver'] = ['firefox', 'chrome'];
    file_put_contents($preferenceProject . '/local/settings.json', json_encode($localSettings, JSON_THROW_ON_ERROR));
    foreach ([1, 2] as $workers) {
        foreach ([
            [[], 'firefox'],
            [['--set', 'test.browser.driver=["chrome","firefox"]'], 'chrome'],
            [['--set', 'test.browser.driver=["chromium","firefox"]'], 'firefox'],
            [['--browser', 'chrome'], 'chrome'],
            [['--set', 'test.browser.driver="invalid"', '--browser', 'chrome'], 'chrome'],
        ] as [$options, $expected]) {
            [$code, $out, $err, $report] = invoke($otherScript, $preferenceProject,
                ['test', '^preference_', '--workers', (string)$workers,
                    '--set', 'fixture.expectedBrowser=' . json_encode($expected), ...$options]);
            check($code === 0 && $err === '' && $report['totals']['assertionsPassed'] === 2, $out . $err);
            if ($workers === 2) check($report['environment']['browser'] === $expected, $out . $err);
        }
    }
    foreach (['"firefox"', '{"first":"firefox"}', '["unknown"]', '[1]'] as $invalid) {
        [$code, $out, $err, $report] = invoke($otherScript, $preferenceProject,
            ['test', '^preference_', '--workers', '2', '--set', 'test.browser.driver=' . $invalid]);
        check($code === 1 && $err === '' && $report !== null && str_contains($out, 'test.browser.driver must be a list'), $out . $err);
    }
    foreach (['[]', '["chromium"]'] as $unavailable) {
        [$code, $out, $err, $report] = invoke($otherScript, $preferenceProject,
            ['test', '^preference_', '--workers', '2', '--set', 'test.browser.driver=' . $unavailable]);
        check($code === 1 && $err === '' && str_contains($out, 'No ready browser/driver pair.')
            && $report['environment']['browser'] === null, $out . $err);
    }
    [$code, $out, $err] = invoke($otherScript, $preferenceProject,
        ['test', '^preference_', '--workers', '2', '--browser', 'chromium']);
    check($code === 1 && $err === '' && str_contains($out, 'No ready browser/driver pair for chromium.'), $out . $err);
    echo "PASS: browser preferences honor defaults, local replacement, order, readiness, explicit overrides and worker selection\n";

    foreach (['headless_a', 'headless_b'] as $name) {
        file_put_contents($preferenceProject . '/test/cases/' . $name . '.php', <<<'PHP'
            <?php
            return function ($test) {
                $test->assert($test->headless() === $test->settings('fixture.expectedHeadless'), 'Resolved headless mode reaches this process');
            };
            PHP);
    }
    $projectSettings['test']['browser']['headless'] = true;
    file_put_contents($preferenceProject . '/settings.json', json_encode($projectSettings, JSON_THROW_ON_ERROR));
    foreach ([1, 2] as $workers) {
        foreach ([
            [false, [], false],
            [true, [], true],
            [false, ['--headless'], true],
            [true, ['--headed'], false],
            ['invalid', ['--headed'], false],
            [false, ['--headed', '--headless'], true],
            [true, ['--headless', '--headed'], false],
            [true, ['--set', 'test.browser.headless=false'], false],
        ] as [$setting, $options, $expected]) {
            $localSettings['test']['browser']['headless'] = $setting;
            file_put_contents($preferenceProject . '/local/settings.json', json_encode($localSettings, JSON_THROW_ON_ERROR));
            [$code, $out, $err, $report] = invoke($otherScript, $preferenceProject,
                ['test', '^headless_', '--workers', (string)$workers,
                    '--set', 'fixture.expectedHeadless=' . json_encode($expected), ...$options]);
            check($code === 0 && $err === '' && $report['totals']['assertionsPassed'] === 2
                && $report['environment']['headless'] === $expected, $out . $err);
        }
    }
    [$code, $out, $err, $report] = invoke($otherScript, $preferenceProject,
        ['test', '^headless_', '--workers', '1', '--set', 'test.browser.headless="false"']);
    check($code === 1 && $err === '' && $report !== null && str_contains($out, 'test.browser.headless must be a boolean'), $out . $err);
    [$code, $out, $err] = invoke($otherScript, $preferenceProject,
        ['test', '^headless_', '--workers', '1', '--browser', 'safari']);
    check($code === 1 && $err === '' && str_contains($out, 'Safari does not support headless'), $out . $err);
    [$code, $out, $err, $report] = invoke($otherScript, $preferenceProject,
        ['test', '^headless_', '--workers', '1', '--browser', 'safari', '--headed', '--set', 'fixture.expectedHeadless=false']);
    check($code === 0 && $err === '' && $report['environment']['headless'] === false, $out . $err);
    echo "PASS: headless defaults, local settings, explicit headed/headless overrides and worker propagation\n";

    $displayRoot = $root . '/display';
    $displayScript = project($displayRoot);
    $displaySuite = <<<'PHP'
        <?php
        use ThriveData\ThrivePHP\Test\{Before, Suite, Test};
        use ThriveData\ThrivePHP\WebDriver\Screenshot;
        return new class($test) extends Suite {
            private int $attempt = 0;
            #[Before]
            public function capture(): void {
                $this->test->browserSession(['sessionId' => 'fixture', 'capabilities' => []],
                    fn () => new Screenshot(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')));
            }
            #[Test('passed')]
            public function passed(): void {
                $this->test->assert(true, 'passing detail');
            }

            #[Test('failed')]
            public function failed(): void {
                $this->test->assert(false, 'failing detail');
            }

            #[Test('retried', retry: 1)]
            public function retried(): void {
                $this->test->assert(++$this->attempt === 2, 'retry detail');
            }

            #[Test('skipped', skip: true)]
            public function skipped(): void { throw new LogicException('Skipped case ran'); }

            #[Test('error')]
            public function error(): void {
                throw new RuntimeException('case execution error');
            }

            #[Test('step_error')]
            public function step_error(): void {
                $this->test->recordStep('broken', 'error target', [], fn () => throw new RuntimeException('step execution error'));
            }

            #[Test('logs')]
            public function logs(): void {
                foreach (['info', 'warn', 'error'] as $level) {
                    $this->test->browserLog(['sessionId' => 'fixture'], ['type' => 'console', 'level' => $level, 'text' => $level . ' browser message']);
                }
                $this->test->browserNetwork('fixture', ['type' => 'event', 'method' => 'network.beforeRequestSent',
                    'params' => ['request' => ['method' => 'GET', 'url' => 'https://example.test/log']]]);
            }
        };
        PHP;
    foreach (['display_a', 'display_b'] as $name) file_put_contents($displayRoot . '/test/cases/' . $name . '.php', $displaySuite);
    $displaySettings = ['test' => ['console' => ['steps' => true], 'screenshots' => ['steps' => 'always', 'suites' => 'always']]];
    $switches = ['run', 'pass', 'fail', 'skip', 'screenshots'];
    foreach ([1, 2] as $workers) {
        foreach (['default', 'config', 'cli', 'override', 'quiet_first', 'quiet_last'] as $mode) {
            foreach (in_array($mode, ['config', 'cli'], true) ? $switches : [null] as $disabled) {
                $settings = $displaySettings;
                $options = [];
                if ($mode === 'config') {
                    if ($disabled === 'screenshots') $settings['test']['console']['screenshots']['enabled'] = false;
                    else $settings['test']['console'][$disabled] = false;
                } elseif ($mode === 'cli') {
                    $options = ['--no-' . $disabled];
                } elseif ($mode === 'override') {
                    foreach ($switches as $switch) {
                        $path = $switch === 'screenshots' ? 'screenshots.enabled' : $switch;
                        $options = [...$options, '--no-' . $switch, '--' . $switch, '--set', 'test.console.' . $path . '=false'];
                    }
                } elseif (str_starts_with($mode, 'quiet')) {
                    $options = ['--run', '--pass', '--no-fail', '--skip', '--screenshots'];
                    if ($mode === 'quiet_first') array_unshift($options, '--quiet');
                    else $options[] = '--quiet';
                }
                file_put_contents($displayRoot . '/local/settings.json', json_encode($settings, JSON_THROW_ON_ERROR));
                [$code, $out, $err, $report] = invoke($displayScript, $displayRoot,
                    ['test', '^display_', '--workers', (string)$workers, ...$options]);
                $context = "$mode/$disabled/workers=$workers: " . $out . $err;
                check($code === 1 && $err === '' && $report['totals']['casesPassed'] === 6
                    && $report['totals']['casesFailed'] === 6 && $report['totals']['casesSkipped'] === 2, $context);
                $quiet = str_starts_with($mode, 'quiet');
                foreach (['run', 'pass', 'fail', 'skip'] as $status) {
                    $expected = $quiet ? $status === 'fail' : $disabled !== $status;
                    check((bool)preg_match('/^' . strtoupper($status) . ' +/m', $out) === $expected, $context);
                }
                check(str_contains($out, '  Screenshot: ') === (!$quiet && $disabled !== 'screenshots'), $context);
                check(str_contains($out, '    passing detail') === (!$quiet && $disabled !== 'pass'), $context);
                check(str_contains($out, '    AssertionFailure: failing detail') === ($quiet || $disabled !== 'fail'), $context);
                check(str_contains($out, 'INFO  BROWSER') === !$quiet && str_contains($out, 'WARN  BROWSER') === !$quiet
                    && str_contains($out, 'INFO  NETWORK') === !$quiet && str_contains($out, 'RETRY ') === !$quiet
                    && str_contains($out, 'Suites:') === !$quiet, $context);
                check(str_contains($out, 'ERROR BROWSER') && substr_count($out, 'Execution error: RuntimeException: case execution error') === 2
                    && substr_count($out, 'Execution error: RuntimeException: step execution error') === 2, $context);
                if ($disabled === 'pass') check(!str_contains($out, '/passed/'), 'Passing screenshots escaped the status filter: ' . $context);
                if ($disabled === 'fail') check(!str_contains($out, '/failed/'), 'Failure screenshots escaped the status filter: ' . $context);
                $reportPath = glob($root . '/runs-' . $sequence . '/*/report.json')[0];
                $image = $report['suites'][0]['attempts'][0]['cases'][0]['attempts'][0]['screenshots'][0]['path'];
                check(is_file(dirname($reportPath) . '/' . $image) && is_file(dirname($reportPath) . '/report.html'), 'Display switches removed saved artifacts.');
            }
        }
        [$code, $out, $err, $report] = invoke($displayScript, $displayRoot,
            ['test', '^display_::^passed$', '--workers', (string)$workers, '--quiet']);
        check($code === 0 && $out === '' && $err === '' && $report['totals']['casesPassed'] === 2, 'Successful quiet run printed output: ' . $out . $err);
        echo "PASS: console switches, settings, precedence, quiet diagnostics and saved artifacts, workers=$workers\n";
    }
    [$code, $out, $err, $report] = invoke($displayScript, $displayRoot, ['test', '[', '--quiet']);
    check($code === 1 && str_contains($out, 'ERROR RUN') && !str_contains($out, 'Suites:') && $report !== null, $out . $err);
    [$code, $out, $err] = invoke($displayScript, $displayRoot, ['test', '--quiet'], shortTags: false);
    check($code === 1 && str_contains($out . $err, 'Enable short_open_tag') && !str_contains($out, 'Suites:'), $out . $err);
    echo "PASS: quiet retains preparation and bootstrap errors without summaries\n";
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
