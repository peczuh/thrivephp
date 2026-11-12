<?php

// Run with: php -d short_open_tag=1 test/log.php.
require __DIR__ . '/fixtures/test-runner.php';

use ThriveData\ThrivePHP\Log;
use ThriveData\ThrivePHP\ErrorException as FrameworkError;
use ThriveData\ThrivePHP\UserWarningException;

if (($argv[1] ?? null) === 'nonfatal-shutdown') {
    Log::init();
    error_reporting(E_ALL & ~E_USER_WARNING);
    trigger_error('Filtered shutdown warning', E_USER_WARNING);
    echo "REACHED_SHUTDOWN\n";
    exit(0);
}
if (($argv[1] ?? null) === 'fatal-shutdown') {
    register_shutdown_function(static function (): void {
        try {
            Log::shutdown();
        } catch (FrameworkError $error) {
            echo 'FATAL_RECORDED:' . $error->getSeverity() . PHP_EOL;
        }
    });
    undefined_function_for_log_shutdown_test();
}

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$reporting = error_reporting();
Log::init();
try {
    // Exercise PHP's actual @ mask; production acceptance no longer needs suppression.
    @trigger_error('Suppressed warning', E_USER_WARNING);
    check(error_get_last()['type'] === E_USER_WARNING, 'Suppressed warning did not reach PHP normally.');
    Log::shutdown();
    error_reporting(E_ALL & ~E_USER_WARNING);
    trigger_error('Filtered warning', E_USER_WARNING);
    Log::shutdown();
    error_reporting(E_ALL);
    try {
        trigger_error('Visible warning', E_USER_WARNING);
        throw new RuntimeException('An unsuppressed warning was ignored.');
    } catch (UserWarningException $error) {
        check($error->getMessage() === 'Visible warning' && $error->getSeverity() === E_USER_WARNING,
            'Unsuppressed warning diagnostics changed.');
    }
    error_clear_last();
    Log::shutdown();
} finally {
    restore_error_handler();
    restore_exception_handler();
    error_reporting($reporting);
    error_clear_last();
}
echo "PASS: logging respects suppression and severity masks while retaining unsuppressed warnings\n";

foreach (['nonfatal-shutdown', 'fatal-shutdown'] as $mode) {
    $process = proc_open([PHP_BINARY, '-d', 'short_open_tag=1', '-d', 'log_errors=0', '-d', 'display_errors=stderr', __FILE__, $mode],
        [0 => ['null'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($mode === 'nonfatal-shutdown') {
        check($code === 0 && $out === "REACHED_SHUTDOWN\n" && $err === '', 'Nonfatal shutdown failed: ' . $out . $err);
    } else {
        check($code !== 0 && str_contains($out, 'FATAL_RECORDED:' . E_ERROR)
            && str_contains($err, 'undefined_function_for_log_shutdown_test'), 'Fatal error handling was lost: ' . $out . $err);
    }
}
echo "PASS: real process shutdown ignores remembered warnings and retains fatal errors\n";
