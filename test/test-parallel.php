<?php

// Run with: php test/test-parallel.php. Uses real PHP workers, without real browsers.
require __DIR__ . '/fixtures/test-runner.php';

use ThriveData\ThrivePHP\Test\Channel;
use ThriveData\ThrivePHP\Test\WorkerProcess;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = sys_get_temp_dir() . '/thrive-parallel-' . bin2hex(random_bytes(6));
mkdir($root . '/vendor', 0700, true);
mkdir($root . '/checks');
$source = var_export(dirname(__DIR__) . '/src/', true);
file_put_contents($root . '/vendor/autoload.php', '<?php
namespace ThriveData\\ThrivePHP;
class Application { public static function init() { $GLOBALS["applicationStarted"] = true; Log::init(); } }
spl_autoload_register(static function ($class) {
    $prefix = "ThriveData\\\\ThrivePHP\\\\";
    if (str_starts_with($class, $prefix)) require ' . $source . ' . str_replace("\\\\", "/", substr($class, strlen($prefix))) . ".php";
});');

/** Capture inherited diagnostics and parent progress concurrently, with a test-only watchdog. */
function runParallel(array $modes, array $options = [], array $environment = [], bool $interrupt = false): array
{
    global $root;
    foreach (new DirectoryIterator($root . '/checks') as $entry) {
        if ($entry->isFile()) unlink($entry->getPathname());
    }
    file_put_contents($root . '/events.jsonl', '');
    foreach (['descendant', 'grandchild'] as $name) {
        if (is_file($root . '/' . $name . '.pid')) unlink($root . '/' . $name . '.pid');
    }
    foreach ($modes as $name => $mode) {
        $fixture = str_starts_with($mode, 'attribute-') ? 'test-attributes-suite.php' : 'test-parallel-suite.php';
        file_put_contents($root . '/checks/' . $name . '.php', '<?php $mode = ' . var_export($mode, true)
            . '; $name = ' . var_export($name, true) . '; return require '
            . var_export(__DIR__ . '/fixtures/' . $fixture, true) . ';');
    }
    $process = proc_open([PHP_BINARY, '-d', 'memory_limit=192M', '-d', 'short_open_tag=1',
        __DIR__ . '/fixtures/test-parallel-run.php', $root, json_encode($options, JSON_THROW_ON_ERROR)],
        [0 => ['null'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
        env_vars: $environment + ['TEST_CWD' => getcwd()] + getenv());
    foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
    $out = $err = '';
    $live = false;
    $liveStep = false;
    $until = hrtime(true) / 1e9 + 20;
    try {
        do {
            $out .= stream_get_contents($pipes[1]);
            $err .= stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if ($status['running'] && str_contains($out, 'PASS  CASE  a > first') && !str_contains($out, 'PASS  CASE  a > last')) $live = true;
            if ($status['running'] && ((str_contains($out, 'PASS  STEP  a > first > assert') && !str_contains($out, 'PASS  CASE  a > first'))
                || (str_contains($out, 'FAIL  STEP  a > body > verify') && !str_contains($out, 'FAIL  CASE  a > body')))) $liveStep = true;
            if (!$status['running']) break;
            if ($interrupt && is_file($root . '/grandchild.pid')) {
                proc_terminate($process);
                $interrupt = false;
            }
            check(hrtime(true) / 1e9 < $until, 'Parallel regression watchdog expired: ' . substr($out, -4000) . $err);
            usleep(1000);
        } while (true);
        $out .= stream_get_contents($pipes[1]);
        $err .= stream_get_contents($pipes[2]);
        preg_match('/^Reports: (.+)$/m', $out, $report);
        $record = isset($report[1]) ? json_decode(file_get_contents($root . '/' . $report[1] . '/report.json'), true, flags: JSON_THROW_ON_ERROR) : null;
        return [$status['exitcode'], $out, $err, $live, $record, $liveStep];
    } finally {
        if (proc_get_status($process)['running']) proc_terminate($process);
        foreach ($pipes as $pipe) fclose($pipe);
        proc_close($process);
    }
}

try {
    [$code, $out, $err, , $record] = runParallel(['a' => 'concurrent', 'b' => 'concurrent'], environment: ['TEST_BOOTSTRAP' => '1']);
    check($code === 0 && $err === '' && $record['totals']['checksPassed'] === 2
        && $record['totals']['casesPassed'] === 6 && !str_contains($out, 'Accept failed'), $out . $err);
    echo "PASS: parallel connections and process shutdown work with the real application logging handler\n";

    foreach ([1, 2] as $workers) {
        [$code, $out, $err, , $record] = runParallel(['a' => 'value-comparisons'], ['workers' => $workers],
            environment: ['TEST_CONSOLE_STEPS' => '1']);
        check($code === 1 && $err === '' && $record['totals']['assertionsFailed'] === 2
            && $record['totals']['casesPassed'] === 2 && $record['totals']['casesFailed'] === 1, $out . $err);
        $steps = $record['suites'][0]['attempts'][0]['cases'][1]['attempts'][0]['steps'];
        check(array_column($steps, 'label') === ['equal', 'same', 'same', 'assert']
            && $steps[0]['assertions'][0]['actual'] === ['type' => 'float', 'value' => 'INF']
            && $steps[1]['assertions'][0]['expected']['rows'] === [[1, 2]]
            && $steps[1]['assertions'][0]['actual']['rows'] === [[1, '2']], $out . $err);
        check(str_contains($out, 'worker strict mismatch') && str_contains($out, 'worker structural mismatch')
            && str_contains($out, 'Expected:') && str_contains($out, 'Actual:'), $out);
    }
    echo "PASS: serial/parallel value comparisons retain typed diagnostics, single steps and continuation\n";

    [$code, $out, $err, , , $liveStep] = runParallel(['a' => 'concurrent', 'b' => 'concurrent'], environment: ['TEST_CONSOLE_STEPS' => '1']);
    check($code === 0 && $err === '' && $liveStep
        && substr_count($out, 'PASS  STEP  a > first > assert') === 1
        && strpos($out, 'PASS  STEP  a > assert [suite-setup, ') < strpos($out, 'RUN   CASE  a > first'), $out . $err);
    [$code, $out, $err, , , $liveStep] = runParallel(['a' => 'live-failure', 'b' => 'concurrent']);
    check($code === 1 && $err === '' && $liveStep && !str_contains($out, 'PASS  STEP')
        && substr_count($out, 'FAIL  STEP  a > body > verify') === 1, $out . $err);
    echo "PASS: parallel successful and failed step diagnostics arrive before case completion without replay\n";

    foreach ([1, 2] as $workers) {
        foreach ([
            [['^a$::^first$##smoke', '^b$::^second$##slow', '^a$::^first$'], [], ['a:first', 'b:second']],
            [['^a$::^first$##slow', '^b$::^second$##smoke'], [], []],
            [['^[ab]$::^first$#!smoke', '^b$::^first$'], [], ['b:first']],
            [['^[ab]$::^first$'], ['b'], ['b:first']],
            [['.*!!^(b|excluded)$::^first$'], [], ['a:first']],
            [['^missing$'], [], []],
        ] as [$selectors, $names, $expected]) {
            [$code, $out, $err] = runParallel(['a' => 'attribute-normal', 'b' => 'attribute-normal', 'excluded' => 'attribute-load-error'],
                ['workers' => $workers, 'selectors' => $selectors, 'names' => $names]);
            $events = array_map(fn ($line) => json_decode($line, true), file($root . '/events.jsonl', FILE_IGNORE_NEW_LINES));
            $bodies = array_values(array_map(fn ($event) => $event['name'] . ':' . $event['event'],
                array_filter($events, fn ($event) => in_array($event['event'], ['first', 'second'], true))));
            sort($bodies);
            check($code === 0 && $err === '' && $bodies === $expected, $out . $err . json_encode($bodies));
            check(str_contains($out, 'Suites: ' . count($expected) . ' passed, 0 failed, 0 skipped'), $out);
            if (!$expected) check($events === [], 'Fully filtered suite ran hooks: ' . $out);
        }
        [$code, $out, $err] = runParallel(['a' => 'attribute-load-error'], ['workers' => $workers, 'selectors' => ['^a$', '##[']]);
        check($code === 1 && str_contains($out, 'Invalid tags include regex') && !str_contains($out, 'Excluded file loaded.'), $out . $err);
        [$code, $out, $err] = runParallel(['aaa' => 'attribute-load-error'],
            ['workers' => $workers, 'selectors' => ['^aaa$', '(*LIMIT_MATCH=1)(*NO_JIT)^a+$']]);
        check($code === 1 && str_contains($out, 'Backtrack limit exhausted')
            && !str_contains($out . $err, 'Excluded file loaded.') && !str_contains($out, 'protocol error'), $out . $err);
    }
    echo "PASS: serial/parallel selectors preserve file/case/tag associations, local exclusions, deduplication, and pre-load validation\n";

    foreach (['case-retry', 'case-before', 'case-after', 'case-deadline', 'suite-retry', 'suite-before', 'suite-after', 'config', 'suite-deadline'] as $mode) {
        [$code, $out, $err] = runParallel(['a' => 'attribute-' . $mode]);
        check($code === 0 && $err === '' && preg_match('/^RETRY (?:CASE  a |SUITE a: Attribute suite )/m', $out)
            && str_contains($out, 'Suites: 1 passed, 0 failed, 0 skipped')
            && str_contains($out, 'Cases: 2 passed, 0 failed, 1 skipped')
            && str_contains($out, 'Assertions: 4 passed, 0 failed'), $out . $err);
    }
    [$code, $out, $err] = runParallel(['a' => 'attribute-always-fail']);
    check($code === 1 && $err === '' && str_contains($out, 'Cases: 1 passed, 1 failed, 1 skipped')
        && str_contains($out, 'Assertions: 3 passed, 1 failed')
        && preg_match_all('~^RETRY CASE  a \\(attempt \\d+/2\\) > first \\(attempt 1/2\\)$~m', $out) === 2, $out . $err);
    echo "PASS: parallel case and whole-suite retries discard earlier counts and retain final outcomes\n";

    foreach ([1, 2] as $workers) {
        foreach (['case-retry', 'suite-retry', 'always-fail'] as $mode) {
            [$code, $out, $err] = runParallel(['a' => 'attribute-stop-' . $mode, 'b' => 'attribute-normal'], ['workers' => $workers]);
            $failed = $mode === 'always-fail';
            check($code === (int)$failed && $err === '' && str_contains($out, $failed
                ? 'Suites: 1 passed, 1 failed, 0 skipped' : 'Suites: 2 passed, 0 failed, 0 skipped'), $out . $err);
            check(str_contains($out, $failed ? 'Cases: 2 passed, 1 failed, 3 skipped' : 'Cases: 4 passed, 0 failed, 2 skipped')
                && str_contains($out, $failed ? 'Assertions: 6 passed, 1 failed' : 'Assertions: 8 passed, 0 failed'), $out);
            check(preg_match_all('~^SKIP  CASE  a \\(attempt \\d+/2\\) > second \\(previous case failed\\)~m', $out) === ($failed ? 2 : (int)($mode === 'suite-retry')), $out);
            $events = array_map(fn ($line) => json_decode($line, true), file($root . '/events.jsonl', FILE_IGNORE_NEW_LINES));
            $secondCases = array_values(array_map(fn ($event) => $event['name'], array_filter($events, fn ($event) => $event['event'] === 'second')));
            sort($secondCases);
            check($secondCases === ($failed ? ['b'] : ['a', 'b']), json_encode($events));
            check(count(array_filter($events, fn ($event) => $event['event'] === 'cleanup')) === ($mode === 'case-retry' ? 2 : 3), json_encode($events));
        }
    }
    echo "PASS: serial/parallel skip-on-failure preserves retries, skip reasons, totals, cleanup and other suites\n";

    foreach (['retry-crash', 'retry-timeout', 'suite-crash'] as $mode) {
        [$code, $out, $err] = runParallel(['a' => 'attribute-' . $mode]);
        check($code === 1 && preg_match('/^RETRY (?:CASE  a |SUITE a: Attribute suite )/m', $out) && str_contains($out, 'Suite incomplete.')
            && str_contains($out, 'Cases: 0 passed, 1 failed, 2 skipped')
            && str_contains($out, 'Assertions: 1 passed, 0 failed') && !str_contains($out, 'protocol error'), $out . $err);
    }
    echo "PASS: crashes and inactivity during retries recover only the current attempt without retrying dead workers\n";

    [$code, $out, $err] = runParallel(['a' => 'attribute-normal'], ['selectors' => ['##^suite$#!^slow$']]);
    check($code === 0 && $err === '' && str_contains($out, 'Cases: 1 passed, 0 failed, 1 skipped')
        && str_contains($out, 'Assertions: 3 passed, 0 failed') && !str_contains($out, '> second'), $out . $err);
    [$code, $out, $err] = runParallel(['a' => 'attribute-normal', 'b' => 'attribute-skip'], ['selectors' => ['#!^suite$']]);
    check($code === 0 && $err === '' && str_contains($out, 'Suites: 0 passed, 0 failed, 0 skipped')
        && file_get_contents($root . '/events.jsonl') === '' && !str_contains($out, 'SKIP'), $out . $err);
    foreach ([['a' => 'attribute-normal'], ['a' => 'attribute-skip']] as $modes) {
        [$code, $out, $err] = runParallel($modes, ['selectors' => [$modes['a'] === 'attribute-normal' ? '##^skip$' : '##^suite$']]);
        check($code === 0 && $err === '' && str_contains($out, 'Suites: 0 passed, 0 failed, 1 skipped')
            && str_contains($out, 'Cases: 0 passed, 0 failed, 1 skipped') && file_get_contents($root . '/events.jsonl') === '', $out . $err);
    }
    echo "PASS: parallel tag filters, explicit skips, and empty selections avoid lifecycle hooks and pass protocol validation\n";

    [$code, $out, $err] = runParallel(['a' => 'actions', 'b' => 'action-error']);
    check($code === 1 && $err === '' && str_contains($out, 'PASS  CASE  a > body (No assertions)')
        && str_contains($out, 'PASS  SUITE a (No assertions)') && str_contains($out, 'FAIL  CASE  b > body')
        && str_contains($out, 'action failed') && str_contains($out, 'Suites: 1 passed, 1 failed')
        && str_contains($out, 'Cases: 5 passed, 1 failed, 0 skipped')
        && str_contains($out, 'Assertions: 0 passed, 0 failed'), $out . $err);
    $events = array_map(fn ($line) => json_decode($line, true), file($root . '/events.jsonl', FILE_IGNORE_NEW_LINES));
    check(count(array_filter($events, fn ($event) => $event['event'] === 'action')) === 1
        && count(array_filter($events, fn ($event) => $event['event'] === 'finish')) === 2, 'Action-only bodies or cleanup did not run.');
    echo "PASS: action-only cases pass with no-assertion labels, while execution errors still fail\n";

    [$code, $out, $err, $live] = runParallel(array_fill_keys(['a', 'b', 'c', 'd', 'e'], 'concurrent'));
    check($code === 0 && $err === '' && str_contains($out, 'Suites: 5 passed, 0 failed')
        && str_contains($out, 'Cases: 15 passed, 0 failed, 0 skipped')
        && str_contains($out, 'Assertions: 20 passed, 0 failed'), $out . $err);
    $active = $peak = 0;
    $pids = [];
    foreach (file($root . '/events.jsonl', FILE_IGNORE_NEW_LINES) as $line) {
        $event = json_decode($line, true);
        $active += $event['event'] === 'start' ? 1 : -1;
        $peak = max($peak, $active);
        $pids[$event['pid']] = true;
    }
    check($peak === 2 && $active === 0 && count($pids) === 5 && $live, 'Concurrency, process isolation, or live case reporting failed.');
    echo "PASS: default two-worker bound, fresh processes, live results and exact totals\n";

    [$code, $out, $err] = runParallel(array_fill_keys(array_map(fn ($n) => 'fast-' . $n, range(1, 80)), 'normal'), ['workers' => 4]);
    check($code === 0 && $err === '' && str_contains($out, 'Suites: 80 passed, 0 failed')
        && str_contains($out, 'Assertions: 320 passed, 0 failed'), $out . $err);
    echo "PASS: fast-worker scheduling and exit/socket races retain every result across 80 suites\n";

    [$code, $out, $err] = runParallel(['a' => 'configuration'], ['workers' => 3, 'browser' => 'chromium', 'headless' => true]);
    check($code === 0 && $err === '', $out . $err);
    [$code, $out, $err] = runParallel(['a' => 'bootstrap'], environment: ['TEST_BOOTSTRAP' => '1']);
    check($code === 0 && $err === '', $out . $err);
    echo "PASS: startup/runtime ini values, quoted values, run options, environment, null stdin and application bootstrap\n";

    [$code, $out, $err] = runParallel(['a' => 'crash', 'b' => 'normal'], ['workers' => 2]);
    check($code === 1 && str_contains($out, 'PASS  CASE  a > first') && str_contains($out, 'FAIL  CASE  a > body')
        && str_contains($out, 'SKIP  CASE  a > last') && str_contains($out, 'PASS  SUITE b')
        && str_contains($out, 'Cases: 4 passed, 1 failed, 1 skipped')
        && str_contains($out, 'Assertions: 7 passed, 0 failed') && str_contains($out, 'Suite incomplete.'), $out . $err);
    [$code, $out, $err] = runParallel(['a' => 'mismatch']);
    check($code === 1 && str_contains($out, 'exit status 7 disagrees') && str_contains($out, 'Cases: 3 passed, 0 failed, 0 skipped'), $out . $err);
    [$code, $out, $err] = runParallel(['a' => 'config-error']);
    check($code === 1 && str_contains($out, 'configuration failed') && str_contains($out, 'Cases: 0 passed, 0 failed, 3 skipped')
        && !str_contains($out, 'protocol error'), $out . $err);
    echo "PASS: crash recovery retains completed work, skips remaining cases, checks exit status and reports configuration failures\n";

    [$code, $out, $err] = runParallel(['a' => 'timeout', 'b' => 'normal']);
    check($code === 1 && str_contains($out, 'inactivity timeout') && str_contains($out, 'case test: body')
        && str_contains($out, 'SKIP  CASE  a > last') && str_contains($out, 'PASS  SUITE b'), $out . $err);
    [$code, $out, $err] = runParallel(['a' => 'progress']);
    check($code === 1 && !str_contains($out, 'inactivity timeout') && str_contains($out, 'Case deadline exceeded')
        && str_contains($out, 'PASS  CASE  a > last') && str_contains($out, 'Assertions: 12 passed, 0 failed'), $out . $err);
    echo "PASS: inactivity recovery, assertion progress resets, and post-body deadlines without interruption\n";

    [$code, $out, $err] = runParallel(['a' => 'large']);
    check($code === 1 && str_contains($out, 'LARGE-BEGIN' . str_repeat('x', 2 * 1024 * 1024) . 'LARGE-END')
        && str_contains($out, 'Assertions: 4 passed, 1 failed'), 'Large result lost or miscounted: ' . substr($out, -1000) . $err);
    [$code, $out, $err] = runParallel(['a' => 'diagnostics']);
    check($code === 0 && str_contains($out, 'OUT-BEGIN' . str_repeat('o', 1024 * 1024) . 'OUT-END')
        && $err === 'ERR-BEGIN' . str_repeat('e', 1024 * 1024) . 'ERR-END',
        'Inherited diagnostics failed: exit=' . $code . ', stdout=' . strlen($out) . ', stderr=' . strlen($err) . ', tail=' . substr($out, -1000));
    echo "PASS: multi-megabyte TCP results and inherited stdout/stderr do not block or lose bytes\n";

    $networks = [];
    foreach ([1, 2] as $workers) {
        [$code, $out, $err, , $record] = runParallel(['a' => 'network'], ['workers' => $workers]);
        check($code === 0 && $err === '', $out . $err);
        $events = $record['suites'][0]['attempts'][0]['cases'][1]['attempts'][0]['browserNetwork'];
        check(count($events) === 2 && $events[0]['phase'] === 'test' && $events[0]['hook'] === null
            && $events[0]['stepId'] === null && $events[0]['sessionId'] === 'worker-session', 'Worker attribution changed.');
        $headers = $events[0]['event']['params']['request']['headers'];
        check($headers[0]['value']['value'] === str_repeat('n', 131072) && $headers[1]['value'] === ['type' => 'base64', 'value' => '/w==']
            && array_column($headers, 'name') === ['X-Duplicate', 'X-Duplicate'], 'Large network event lost bytes or duplicate headers.');
        check($events[1]['event']['params'] === ['futureField' => ['retained' => true]], 'Worker validation rewrote raw protocol fields.');
        foreach ($events as &$event) unset($event['observedAt']);
        unset($event);
        $networks[] = $events;
    }
    check($networks[0] === $networks[1], 'Serial/parallel network records differ.');
    foreach (['type', 'method', 'params'] as $field) {
        [$code, $out, $err, , $record] = runParallel(['a' => 'network-invalid-' . $field]);
        check($code === 1 && str_contains($out, 'Invalid browser network event.'), $out . $err);
        check(count($record['suites'][0]['attempts'][0]['cases'][1]['attempts'][0]['browserNetwork']) === 1, 'Malformed event discarded earlier valid network records.');
    }
    echo "PASS: serial/parallel network records retain large raw payloads and reject malformed envelopes\n";

    [$code, $out, $err] = runParallel(['a' => 'normal'], ['workers' => 2], ['TEST_BROWSER' => 'safari']);
    check($code === 1 && str_contains($out, 'does not support parallel') && !str_contains($out, 'RUN   SUITE a'), $out . $err);
    [$code, $out, $err] = runParallel(['a' => 'normal'], environment: ['TEST_BROWSER' => 'safaripreview']);
    check($code === 0 && !str_contains($out, '(worker'), $out . $err);
    [$code, $out, $err] = runParallel(['a' => 'normal'], ['headless' => true], ['TEST_BROWSER' => 'safari']);
    check($code === 1 && str_contains($out, 'does not support headless') && !str_contains($out, 'RUN   SUITE a'), $out . $err);
    [$code, $out, $err] = runParallel(['a' => 'normal'], ['workers' => 1]);
    check($code === 0 && !str_contains($out, '(worker'), $out . $err);
    [$code, $out, $err] = runParallel(['a' => 'normal'], ['workers' => 0]);
    check($code === 1 && str_contains($out, 'positive integer'), $out . $err);
    [$code, $out, $err] = runParallel(['a' => 'normal', 'b' => 'normal'], ['names' => ['b', 'a'], 'workers' => 2]);
    check($code === 0 && strpos($out, 'RUN   SUITE b (worker') < strpos($out, 'RUN   SUITE a (worker'), $out . $err);
    echo "PASS: Safari serial fallback, explicit incompatibility errors, explicit serial mode and requested scheduling order\n";

    if (PHP_OS_FAMILY !== 'Windows') {
        [$code, $out, $err] = runParallel(['a' => 'descendants']);
        check($code === 1 && str_contains($out, 'inactivity timeout') && is_file($root . '/descendant.pid'), $out . $err);
        foreach (['descendant', 'grandchild'] as $name) {
            $pid = (int) file_get_contents($root . '/' . $name . '.pid');
            check(!(WorkerProcess::snapshot()[$pid]['alive'] ?? false), 'Worker recovery leaked ' . $name . ' ' . $pid);
        }
        echo "PASS: inactivity recovery force-kills native descendants and grandchildren that ignore TERM\n";
        if (function_exists('pcntl_signal')) {
            [$code, $out, $err] = runParallel(['a' => 'descendants'], interrupt: true);
            check($code === 1 && str_contains($out, 'interrupted by signal'), $out . $err);
            foreach (['descendant', 'grandchild'] as $name) {
                $pid = (int) file_get_contents($root . '/' . $name . '.pid');
                check(!(WorkerProcess::snapshot()[$pid]['alive'] ?? false), 'Interruption leaked ' . $name . ' ' . $pid);
            }
            echo "PASS: parent interruption cleans up the native worker tree\n";
        }
    }

    $records = [];
    foreach ([1, 2] as $workers) {
        [$code, $out, $err, , $record] = runParallel(['a' => 'attribute-suite-retry', 'b' => 'attribute-case-error'], ['workers' => $workers]);
        check($code === 0 && $err === '', $out . $err);
        // Worker and serial stacks have different entry frames; timing and environment also differ.
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if (!is_array($value)) return $value;
            foreach (['generatedAt', 'startedAt', 'endedAt', 'duration', 'stack', 'environment'] as $key) unset($value[$key]);
            return array_map($normalize, $value);
        };
        $records[] = $normalize($record);
    }
    check($records[0] === $records[1], 'Serial and parallel canonical records differ.');
    [$code, $out, $err, , $record] = runParallel(['a' => 'step-timeout']);
    $attempt = $record['suites'][0]['attempts'][0];
    $step = $attempt['cases'][1]['attempts'][0]['steps'][0];
    check($code === 1 && array_column($attempt['cases'], 'status') === ['PASS', 'FAIL', 'SKIP']
        && $step['status'] === 'FAIL' && $step['endedAt'] !== null && $step['duration'] >= 0.5
        && $step['assertions'][0]['message'] === 'before timeout' && $step['assertions'][0]['class'] === 'AssertionFailure'
        && $step['error']['class'] === 'WorkerFailure' && str_contains($step['error']['message'], 'inactivity timeout')
        && $record['totals']['assertionsPassed'] === 2 && $record['totals']['assertionsFailed'] === 1,
        'In-flight step recovery lost timing, diagnostics or prior work: ' . $out . $err);
    echo "PASS: serial/parallel JSON parity and in-flight step recovery preserve the canonical record\n";

    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $client = stream_socket_client('tcp://' . stream_socket_get_name($listener, false));
    $server = stream_socket_accept($listener);
    stream_set_blocking($server, false);
    $channel = new Channel($server);
    fwrite($client, '{"type":"activity",');
    check(iterator_to_array($channel->receive()) === [], 'Partial frame emitted early.');
    fwrite($client, '"activity":"first"}' . "\n" . '{"type":"activity","activity":"second"}' . "\n" . 'broken' . "\n");
    $messages = [];
    try {
        do {
            $read = [$server];
            $write = $except = null;
            check(stream_select($read, $write, $except, 2) === 1, 'Malformed frame was not consumed.');
            foreach ($channel->receive() as $message) $messages[] = $message;
        } while (true);
    } catch (JsonException) {
        check(array_column($messages, 'activity') === ['first', 'second'], 'Malformed frame discarded earlier progress.');
    }
    $channel = new Channel($server);
    fwrite($client, '{"type":');
    fclose($client);
    try {
        do {
            $read = [$server];
            $write = $except = null;
            check(stream_select($read, $write, $except, 2) === 1, 'Truncated frame was not consumed.');
            foreach ($channel->receive() as $message) {}
        } while (!feof($server));
        throw new LogicException('Truncated frame accepted.');
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'incomplete message'), $error->getMessage());
    }
    $channel->close();
    fclose($listener);
    echo "PASS: fragmented frames, multiple frames, malformed data and truncated EOF preserve earlier messages\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        if ($entry->isDir()) rmdir($entry->getPathname());
        else unlink($entry->getPathname());
    }
    rmdir($root);
}
