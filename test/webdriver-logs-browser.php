<?php

// Run with php -d short_open_tag=1 test/webdriver-logs-browser.php [chrome|firefox|safaripreview|safari].
require __DIR__ . '/fixtures/test-runner.php';

use ThriveData\ThrivePHP\WebDriver\WebDriver;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$name = $argv[1] ?? 'chrome';
$driver = new WebDriver(browser: $name);
try {
    $browser = $driver->browser(headless: !in_array($name, ['safari', 'safaripreview'], true), bidi: true);
    echo 'SESSION ' . json_encode($browser->session()['capabilities']) . PHP_EOL;
    $browser->startLogCapture();
    $browser->navigate('data:text/html,' . rawurlencode('<!doctype html><title>BiDi capture</title><script>console.log("native-console", {answer: 42}); setTimeout(() => { throw new Error("native-exception") }, 0)</script>'));
    $deadline = microtime(true) + 3;
    do {
        $browser->command('browsingContext.getTree');
        $entries = $browser->logs();
    } while ((!array_any($entries, fn ($entry) => str_contains($entry['text'] ?? '', 'native-exception'))) && microtime(true) < $deadline);
    check(array_any($browser->logs('console'), fn ($entry) => str_contains($entry['text'] ?? '', 'native-console')), 'Missing console entry.');
    check(array_any($browser->logs('javascript'), fn ($entry) => str_contains($entry['text'] ?? '', 'native-exception')), 'Missing unhandled exception.');
    echo 'LOGS ' . json_encode($entries, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    $before = count($entries);
    $browser->stopLogCapture();
    check(count($browser->logs()) >= $before, 'Stopping capture lost entries.');
    echo "PASS: native $name console and exception capture\n";
    $browser->window()->close();
    check(!$browser->active(), 'Closing the last window did not end the session.');
    $browser->quit();
    check(count($browser->logs()) >= $before, 'Session deletion lost captured entries.');
} finally {
    $driver->stop();
}
