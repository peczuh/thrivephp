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
    mkdir($directory . '/local');
    mkdir($directory . '/test/cases/folder1', 0700, true);
    mkdir($directory . '/test/cases/folder2');
    copy($cli, $directory . '/vendor/peczuh/thrivephp/bin/cli.php');
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
    public const PREFERENCE = ["chrome", "firefox"];
    public static function bootstrap(): object {
        return (object)["browsers" => (object)[
            "chrome" => (object)["ready" => true, "driver" => (object)["ready" => true]],
            "firefox" => (object)["ready" => true, "driver" => (object)["ready" => true]],
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
    link($script, $root . '/other/hardlink.php');
    foreach (['symlink' => $cwd, 'hardlink' => $root . '/other'] as $link => $expectedRoot) {
        [$code, $out, $err, $report] = invoke($root . '/other/' . $link . '.php', $root . '/other', ['test', '^folder1/', '--workers', '1']);
        check($code === 0 && $report['environment']['root'] === realpath($expectedRoot), $link . ': ' . $out . $err);
    }
    echo "PASS: symlinks discover from the target directory; hard links discover from the invoked directory\n";

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
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
