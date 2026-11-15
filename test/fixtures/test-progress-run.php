<?php

require __DIR__ . '/test-runner.php';

use ThriveData\ThrivePHP\Settings;
use ThriveData\ThrivePHP\Test;

$root = $argv[1];
$mode = $argv[2];
if (stream_isatty(STDOUT)) {
    $process = proc_open(['stty', 'rows', '12', 'cols', '100'], [0 => STDOUT, 1 => STDOUT, 2 => STDERR], $pipes);
    if (proc_close($process) !== 0) throw new RuntimeException('Could not size fixture PTY.');
}
$signals = [];
if (function_exists('pcntl_signal')) {
    foreach ([SIGWINCH, SIGINT, SIGTERM] as $signal) {
        $signals[$signal] = static function (): void {};
        pcntl_signal($signal, $signals[$signal]);
    }
}
Settings::set('', ['fixture' => ['mode' => $mode, 'parent' => getmypid(), 'image' => $root . '/source.png'],
    'test' => ['screenshots' => ['cases' => 'off'], 'console' => ['screenshots' => ['columns' => 40]]]]);
if ($mode === 'no-screenshots') Settings::set('test.console.screenshots.enabled', false);
$test = new class($root, quiet: str_starts_with($mode, 'quiet')) extends Test {
    protected function resolveBrowser(?string $browser): ?string { return null; }
};
$status = $test->run(workers: (int)$argv[3], selectors: $mode === 'filtered' ? ['^missing$'] : []);
foreach ($signals as $signal => $handler) {
    if (pcntl_signal_get_handler($signal) !== $handler) throw new RuntimeException('Progress did not restore a signal handler.');
}
if (function_exists('pcntl_async_signals') && pcntl_async_signals()) throw new RuntimeException('Progress did not restore signal dispatch mode.');
exit($status);
