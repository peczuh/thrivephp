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
class Application { public static function init() { $GLOBALS["applicationStarted"] = true; } }
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
        file_put_contents($root . '/checks/' . $name . '.php', '<?php $mode = ' . var_export($mode, true)
            . '; $name = ' . var_export($name, true) . '; return require '
            . var_export(__DIR__ . '/fixtures/test-parallel-suite.php', true) . ';');
    }
    $process = proc_open([PHP_BINARY, '-d', 'memory_limit=192M', '-d', 'short_open_tag=1',
        __DIR__ . '/fixtures/test-parallel-run.php', $root, json_encode($options, JSON_THROW_ON_ERROR)],
        [0 => ['null'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
        env_vars: $environment + ['TEST_CWD' => getcwd()] + getenv());
    foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
    $out = $err = '';
    $live = false;
    $until = hrtime(true) / 1e9 + 20;
    try {
        do {
            $out .= stream_get_contents($pipes[1]);
            $err .= stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if ($status['running'] && str_contains($out, 'PASS a > first') && !str_contains($out, 'PASS a > last')) $live = true;
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
        return [$status['exitcode'], $out, $err, $live];
    } finally {
        if (proc_get_status($process)['running']) proc_terminate($process);
        foreach ($pipes as $pipe) fclose($pipe);
        proc_close($process);
    }
}

try {
    [$code, $out, $err, $live] = runParallel(array_fill_keys(['a', 'b', 'c', 'd', 'e'], 'concurrent'));
    check($code === 0 && $err === '' && str_contains($out, 'Checks: 5 passed, 0 failed')
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
    check($code === 0 && $err === '' && str_contains($out, 'Checks: 80 passed, 0 failed')
        && str_contains($out, 'Assertions: 320 passed, 0 failed'), $out . $err);
    echo "PASS: fast-worker scheduling and exit/socket races retain every result across 80 suites\n";

    [$code, $out, $err] = runParallel(['a' => 'configuration'], ['workers' => 3, 'browser' => 'chromium', 'headless' => true]);
    check($code === 0 && $err === '', $out . $err);
    [$code, $out, $err] = runParallel(['a' => 'bootstrap'], environment: ['TEST_BOOTSTRAP' => '1']);
    check($code === 0 && $err === '', $out . $err);
    echo "PASS: startup/runtime ini values, quoted values, run options, environment, null stdin and application bootstrap\n";

    [$code, $out, $err] = runParallel(['a' => 'crash', 'b' => 'normal'], ['workers' => 2]);
    check($code === 1 && str_contains($out, 'PASS a > first') && str_contains($out, 'FAIL a > body')
        && str_contains($out, 'SKIP a > last') && str_contains($out, 'PASS b')
        && str_contains($out, 'Cases: 4 passed, 1 failed, 1 skipped')
        && str_contains($out, 'Assertions: 7 passed, 0 failed') && str_contains($out, 'Suite incomplete.'), $out . $err);
    [$code, $out, $err] = runParallel(['a' => 'mismatch']);
    check($code === 1 && str_contains($out, 'exit status 7 disagrees') && str_contains($out, 'Cases: 3 passed, 0 failed, 0 skipped'), $out . $err);
    [$code, $out, $err] = runParallel(['a' => 'config-error']);
    check($code === 1 && str_contains($out, 'configuration failed') && str_contains($out, 'Cases: 0 passed, 0 failed, 3 skipped')
        && !str_contains($out, 'protocol error'), $out . $err);
    echo "PASS: crash recovery retains completed work, skips remaining cases, checks exit status and reports configuration failures\n";

    [$code, $out, $err] = runParallel(['a' => 'timeout', 'b' => 'normal']);
    check($code === 1 && str_contains($out, 'inactivity timeout') && str_contains($out, 'case body: body')
        && str_contains($out, 'SKIP a > last') && str_contains($out, 'PASS b'), $out . $err);
    [$code, $out, $err] = runParallel(['a' => 'progress']);
    check($code === 1 && !str_contains($out, 'inactivity timeout') && str_contains($out, 'Case deadline exceeded')
        && str_contains($out, 'PASS a > last') && str_contains($out, 'Assertions: 12 passed, 0 failed'), $out . $err);
    echo "PASS: inactivity recovery, assertion progress resets, and post-body deadlines without interruption\n";

    [$code, $out, $err] = runParallel(['a' => 'large']);
    check($code === 1 && str_contains($out, 'LARGE-BEGIN' . str_repeat('x', 2 * 1024 * 1024) . 'LARGE-END')
        && str_contains($out, 'Assertions: 4 passed, 1 failed'), 'Large result lost or miscounted: ' . substr($out, -1000) . $err);
    [$code, $out, $err] = runParallel(['a' => 'diagnostics']);
    check($code === 0 && str_contains($out, 'OUT-BEGIN' . str_repeat('o', 1024 * 1024) . 'OUT-END')
        && $err === 'ERR-BEGIN' . str_repeat('e', 1024 * 1024) . 'ERR-END', 'Inherited diagnostics lost bytes.');
    echo "PASS: multi-megabyte TCP results and inherited stdout/stderr do not block or lose bytes\n";

    [$code, $out, $err] = runParallel(['a' => 'normal'], ['workers' => 2], ['TEST_BROWSER' => 'safari']);
    check($code === 1 && str_contains($out, 'does not support parallel') && !str_contains($out, 'RUN a'), $out . $err);
    [$code, $out, $err] = runParallel(['a' => 'normal'], environment: ['TEST_BROWSER' => 'safaripreview']);
    check($code === 0 && !str_contains($out, 'worker'), $out . $err);
    [$code, $out, $err] = runParallel(['a' => 'normal'], ['headless' => true], ['TEST_BROWSER' => 'safari']);
    check($code === 1 && str_contains($out, 'does not support headless') && !str_contains($out, 'RUN a'), $out . $err);
    [$code, $out, $err] = runParallel(['a' => 'normal'], ['workers' => 1]);
    check($code === 0 && !str_contains($out, 'worker'), $out . $err);
    [$code, $out, $err] = runParallel(['a' => 'normal'], ['workers' => 0]);
    check($code === 1 && str_contains($out, 'positive integer'), $out . $err);
    [$code, $out, $err] = runParallel(['a' => 'normal', 'b' => 'normal'], ['names' => ['b', 'a'], 'workers' => 2]);
    check($code === 0 && strpos($out, 'RUN b (worker') < strpos($out, 'RUN a (worker'), $out . $err);
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
