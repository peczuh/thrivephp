<?php

// Run with: php test/test-progress.php. Real runners and workers write to a controlled POSIX PTY.
if (PHP_OS_FAMILY === 'Windows' || !function_exists('pcntl_async_signals')) {
    echo "SKIP: progress footer check requires a POSIX PTY and PCNTL\n";
    exit(0);
}

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = sys_get_temp_dir() . '/thrive-progress-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$source = var_export(dirname(__DIR__) . '/src/', true);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
$text = "fixture\0" . str_repeat('original image', 1000);
$chunk = 'tEXt' . $text;
$png = substr($png, 0, -12) . pack('N', strlen($text)) . $chunk . pack('N', crc32($chunk)) . substr($png, -12);
$suite = <<<'PHP'
    <?php
    use ThriveData\ThrivePHP\Settings;
    use ThriveData\ThrivePHP\Test\{Before, Suite, Test};
    $attempt = $GLOBALS['suiteAttempts'][__FILE__] = ($GLOBALS['suiteAttempts'][__FILE__] ?? 0) + 1;
    return new #[Test('Progress suite', retry: 1)] class($test, $attempt) extends Suite {
        private int $attempt = 0;
        public function __construct($test, private int $suiteAttempt) { parent::__construct($test); }
        #[Before]
        public function capture(): void {
            $this->test->browserSession(['sessionId' => 'fixture', 'capabilities' => []],
                fn () => new \ThriveData\ThrivePHP\WebDriver\Screenshot(file_get_contents(Settings::get('fixture.image'))));
        }
        #[Test('Retry', retry: 1)]
        public function retry(): void {
            $mode = Settings::get('fixture.mode');
            if (str_starts_with($mode, 'interrupt')) {
                posix_kill(Settings::get('fixture.parent'), SIGINT);
                usleep(500000);
            }
            if (str_starts_with($mode, 'resize') && $this->attempt === 1) {
                // Let the parent return to its worker wait before the resize interrupts it.
                if ($mode === 'resize-parallel') usleep(50000);
                $process = proc_open(['stty', 'rows', '16', 'cols', '60'], [0 => STDOUT, 1 => STDOUT, 2 => STDERR], $pipes);
                if (proc_close($process) !== 0) throw new RuntimeException('Fixture resize failed.');
                if (function_exists('pcntl_signal')) posix_kill(Settings::get('fixture.parent'), SIGWINCH);
                echo "AFTER_RESIZE\n";
            }
            $this->test->assert(++$this->attempt === 2, 'Second case attempt passes');
        }
        #[Test('Final outcome')]
        public function outcome(): void {
            $this->test->verify(Settings::get('fixture.mode') === 'suite-retry' && $this->suiteAttempt === 2, 'Final suite attempt');
        }
        #[Test('Skipped', skip: true)]
        public function skipped(): void { throw new LogicException('Skipped body ran.'); }
    };
    PHP;
try {
    $scenarios = [['single', 1, true], ['parallel', 2, true], ['resize', 1, true], ['resize-parallel', 2, true],
        ['suite-retry', 1, true], ['redirected', 1, false], ['filtered', 1, true], ['no-signals', 1, true],
        ['quiet', 1, true], ['quiet-parallel', 2, true], ['quiet-redirected', 1, false], ['no-screenshots', 1, true]];
    if (function_exists('pcntl_signal') && function_exists('posix_kill')) {
        $scenarios[] = ['interrupt-serial', 1, true];
        $scenarios[] = ['interrupt-parallel', 2, true];
    }
    foreach ($scenarios as [$mode, $workers, $tty]) {
        $project = $root . '/' . $mode;
        mkdir($project . '/test/cases', 0700, true);
        mkdir($project . '/vendor');
        file_put_contents($project . '/source.png', $png);
        file_put_contents($project . '/vendor/autoload.php', '<?php spl_autoload_register(static function ($class) {
            $prefix = "ThriveData\\\\ThrivePHP\\\\";
            if (str_starts_with($class, $prefix)) require ' . $source . ' . str_replace("\\\\", "/", substr($class, strlen($prefix))) . ".php";
        });');
        if (in_array($mode, ['parallel', 'quiet-parallel'], true)) {
            foreach (['estimates', 'projects'] as $folder) {
                mkdir($project . '/test/cases/' . $folder);
                file_put_contents($project . '/test/cases/' . $folder . '/create.php', $suite);
            }
        } else {
            file_put_contents($project . '/test/cases/one.php', $suite);
        }
        $process = proc_open([PHP_BINARY, '-d', 'short_open_tag=1',
            ...($mode === 'no-signals' ? ['-d', 'disable_functions=pcntl_async_signals'] : []),
            __DIR__ . '/fixtures/test-progress-run.php', $project, $mode, (string)$workers],
            [0 => ['null'], 1 => $tty ? ['pty'] : ['pipe', 'w'], 2 => ['file', $project . '/stderr', 'w']], $pipes,
            env_vars: ['TERM' => 'xterm-ghostty', 'TERM_PROGRAM' => 'rex'] + getenv());
        $out = @stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($process);
        $err = file_get_contents($project . '/stderr');
        $expected = in_array($mode, ['suite-retry', 'filtered'], true) ? 0 : ($mode === 'interrupt-serial' ? 130 : 1);
        check($code === $expected && $err === '', $mode . ': ' . $out . $err);
        if (str_starts_with($mode, 'quiet')) {
            check(str_contains($out, 'FAIL') && !str_contains($out, "\033_G")
                && !str_contains($out, 'Screenshot:') && !str_contains($out, 'Suites:'), 'Quiet printed images or a summary: ' . $out);
        }
        if (!$tty || in_array($mode, ['filtered', 'no-signals'], true)) {
            check(!str_contains($out, "\033[1;") && !str_contains($out, "\0337"), 'Footer appeared without an active terminal run.');
            echo "PASS: $mode output has no progress controls\n";
            continue;
        }
        check(str_contains($out, "\033[1;11r") && str_contains($out, "\033[r"), 'Footer did not reserve and restore the scrolling region.');
        check(!str_contains($out, "\033[?1049h"), 'Footer discarded normal terminal scrollback.');
        preg_match_all('/\x1b7\x1b\[(\d+);1H\x1b\[2K([^\x1b]+)\x1b8/', $out, $frames, PREG_SET_ORDER);
        check($frames !== [], 'No progress frames were drawn.');
        if (in_array($mode, ['parallel', 'quiet-parallel'], true)) {
            $labels = implode("\n", array_column($frames, 2));
            check(str_contains($labels, 'estimates/create') && str_contains($labels, 'projects/create'), 'Progress omitted suite directories.');
        }
        foreach ($frames as $frame) {
            $columns = preg_match_all('/./us', $frame[2]);
            check($columns !== false && $columns < ((int)$frame[1] === 16 ? 60 : 100), 'Footer contains invalid UTF-8 or could wrap onto another row.');
        }
        if (str_starts_with($mode, 'interrupt')) {
            echo "PASS: $mode restores the terminal\n";
            continue;
        }
        $last = end($frames)[2];
        $counts = in_array($mode, ['parallel', 'quiet-parallel'], true) ? '2/2 suites | PASS 2 FAIL 2 SKIP 2'
            : ($mode === 'suite-retry' ? '3/3 cases | PASS 2 FAIL 0 SKIP 1' : '3/3 cases | PASS 1 FAIL 1 SKIP 1');
        check(str_contains($last, $counts), 'Progress counts accumulated retries or missed final results: ' . $last);
        if (str_starts_with($mode, 'quiet')) {
            check(str_ends_with($out, "\033[r\0338"), 'Quiet did not restore the scrolling region.');
            echo "PASS: $mode retains progress and failures without images or a summary\n";
            continue;
        }
        check(strrpos($out, "\033[r") < strpos($out, "\nSuites:"), 'Summary printed inside the footer region.');
        if ($mode === 'no-screenshots') {
            check(!str_contains($out, "\033_G") && !str_contains($out, 'Screenshot:'), 'Disabled screenshots reached the terminal.');
            echo "PASS: screenshot display can be disabled while retaining progress\n";
            continue;
        }
        check(str_contains($out, "\033_Ga=T,f=100"), 'Inline screenshots disappeared with the footer.');
        preg_match_all('/\x1b_G([^;]*);([A-Za-z0-9+\/=]*)\x1b\\\\/', $out, $packets, PREG_SET_ORDER);
        $payload = '';
        foreach ($packets as $packet) {
            $payload .= $packet[2];
            if (str_contains($packet[1], 'm=0')) {
                check(base64_decode($payload, true) === $png, 'Progress altered the inline PNG data.');
                $payload = '';
            }
        }
        check($payload === '', 'Progress interrupted a chunked image transfer.');
        if (str_starts_with($mode, 'resize')) {
            check(!str_contains($out, 'Could not monitor worker connections.'), 'Resize aborted the worker wait: ' . $out);
            check(str_contains($out, "\033[1;15r") && (int)end($frames)[1] === 16, 'Footer did not follow the resized terminal.');
            if ($mode === 'resize' && function_exists('pcntl_signal')) check(strpos($out, "\033[1;15r") < strpos($out, "AFTER_RESIZE\r\n"), 'Idle resize waited for another runner event.');
        }
        echo "PASS: $mode progress, retries, screenshots and terminal cleanup\n";
    }
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
