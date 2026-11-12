<?php

// Run with: php test/test-report-inline.php. Uses a real PTY, without starting a browser.
if (PHP_OS_FAMILY === 'Windows') {
    echo "SKIP: inline screenshot transport check requires a POSIX PTY\n";
    exit(0);
}

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = sys_get_temp_dir() . '/thrive-inline-' . bin2hex(random_bytes(6)) . ' space # % café';
mkdir($root, 0700, true);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
// A valid ancillary chunk makes the original PNG span multiple transport packets.
$text = "fixture\0" . str_repeat('original PNG bytes', 1000);
$chunk = 'tEXt' . $text;
$png = substr($png, 0, -12) . pack('N', strlen($text)) . $chunk . pack('N', crc32($chunk)) . substr($png, -12);
try {
    foreach ([[true, 'ghostty', '80'], [true, 'ghostty', '40'], [true, 'ghostty', 'default'],
        [true, 'rex', '80'], [true, 'rex', '40'], [true, 'rex', 'default'],
        [false, 'ghostty', '80'], [false, 'rex', '80'], [true, 'xterm', '80']] as $index => [$tty, $terminal, $columns]) {
        $project = $root . '/' . $index;
        mkdir($project . '/test/cases', 0700, true);
        file_put_contents($project . '/source.png', $png);
        file_put_contents($project . '/test/cases/inline.php', <<<'PHP'
            <?php
            return function ($test) {
                $test->browserSession(['sessionId' => 'inline', 'capabilities' => ['browserName' => 'fixture']],
                    fn () => new \ThriveData\ThrivePHP\WebDriver\Screenshot(file_get_contents(dirname(__DIR__, 2) . '/source.png')));
                $test->assert(true, 'Successful screenshot path is also linked');
                $test->assert(false, 'Intentional failure for inline screenshot rendering');
            };
            PHP);
        $process = proc_open([PHP_BINARY, '-d', 'short_open_tag=1', __DIR__ . '/fixtures/test-report-inline.php', $project, $columns],
            [0 => ['null'], 1 => $tty ? ['pty'] : ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            env_vars: ['TERM_PROGRAM' => $terminal] + getenv());
        $out = @stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        check(proc_close($process) === 1 && $err === '', 'Fixture did not report its intentional failure: ' . $out . $err);
        $reports = glob($project . '/test/runs/*/report.json');
        check(count($reports) === 1, 'Report missing.');
        $record = json_decode(file_get_contents($reports[0]), true, flags: JSON_THROW_ON_ERROR);
        $steps = $record['suites'][0]['attempts'][0]['steps'];
        preg_match_all('/\x1b\]8;;([^\x1b]*)\x1b\\\\\x1b\[34;4m([^\x1b]*)\x1b\[39;24m\x1b\]8;;\x1b\\\\/', $out, $links, PREG_SET_ORDER);
        check(count($links) === ($tty ? 2 : 0), 'Screenshot links were missing or leaked into redirected output.');
        if (!$tty) check(!str_contains($out, "\033[34;4m") && !str_contains($out, "\033]8;"), 'Link styling leaked into redirected output.');
        foreach ($steps as $number => $step) {
            $image = $step['screenshots'][0];
            $path = realpath(dirname($reports[0]) . '/' . $image['path']);
            check($image['width'] === 1 && $image['height'] === 1 && file_get_contents($path) === $png,
                'Saved screenshot or recorded dimensions were changed.');
            if ($tty) {
                check(str_starts_with($links[$number][1], 'file:///')
                    && rawurldecode(parse_url($links[$number][1], PHP_URL_PATH)) === $path
                    && str_contains($links[$number][1], '%23') && str_contains($links[$number][1], '%25')
                    && !str_contains($links[$number][1], ' ') && !str_contains($links[$number][1], '#'),
                    'Screenshot URL was not an encoded absolute file URL.');
                check($links[$number][2] === substr($path, strlen(realpath($project)) + 1), 'Screenshot link lost its relative label.');
            }
        }
        preg_match_all('/\x1b_G([^;]*);([A-Za-z0-9+\/=]*)\x1b\\\\/', $out, $packets, PREG_SET_ORDER);
        if (!$tty || $terminal === 'xterm') {
            check($packets === [] && str_contains($out, 'Screenshot:'), 'Inline data escaped its terminal gate.');
            continue;
        }
        check(count($packets) > 1, 'Fixture did not exercise chunked inline output.');
        $payload = '';
        foreach ($packets as $number => $packet) {
            if ($number === 0) {
                check(str_contains($packet[1], 'a=T,f=100,t=d,q=2,') && !str_contains($packet[1], 'r='), 'Format or aspect-ratio settings changed.');
                check($columns === 'default' ? !str_contains($packet[1], 'c=') : str_contains($packet[1], 'c=' . $columns . ','),
                    'Configured terminal columns were not applied.');
            } else {
                check($packet[1] === 'm=' . ($number === count($packets) - 1 ? '0' : '1'), 'Placement metadata appeared in a continuation packet.');
            }
            $payload .= $packet[2];
        }
        check(base64_decode($payload, true) === $png, 'Inline image bytes were resized, cropped, or re-encoded.');
    }
    echo "PASS: terminal screenshot links target original files; column scaling preserves PNG bytes, aspect ratio and dimensions\n";
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
