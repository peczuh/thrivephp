<?php

// Run with php -d short_open_tag=1 test/webdriver-network-browser.php [chrome|firefox]. Uses pcntl for the loopback HTTP fixture.
require __DIR__ . '/fixtures/test-runner.php';

use ThriveData\ThrivePHP\WebDriver\WebDriver;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
check($listener !== false, $error);
$base = 'http://' . stream_socket_get_name($listener, false);
$pid = pcntl_fork();
check($pid !== -1, 'Could not fork network fixture.');
if ($pid === 0) {
    while ($client = @stream_socket_accept($listener, -1)) {
        stream_set_timeout($client, 3);
        $first = fgets($client);
        if ($first === false) { fclose($client); continue; }
        while (($line = fgets($client)) !== false && $line !== "\r\n") {}
        $path = explode(' ', $first)[1];
        if ($path === '/drop') { fclose($client); continue; }
        [$status, $headers, $body] = match ($path) {
            '/start' => ['302 Found', "Location: /hop\r\n", ''],
            '/hop' => ['307 Temporary Redirect', "Location: /final\r\n", ''],
            '/final' => ['200 OK', "Content-Type: text/html\r\n", '<!doctype html><title>Network capture</title><script>fetch("/ok"); fetch("/missing"); fetch("/drop").catch(() => {});</script>'],
            '/missing' => ['404 Not Found', "Content-Type: text/plain\r\n", 'missing'],
            default => ['200 OK', "Content-Type: text/html\r\n", '<!doctype html><title>Network fixture</title>'],
        };
        fwrite($client, "HTTP/1.1 $status\r\n" . $headers . 'Content-Length: ' . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
        fclose($client);
    }
    exit;
}
fclose($listener);
$driver = null;
$name = $argv[1] ?? 'chrome';
try {
    $driver = new WebDriver(browser: $name);
    $browser = $driver->browser(headless: true, bidi: true);
    echo 'SESSION ' . json_encode($browser->session()['capabilities']) . PHP_EOL;
    $network = $browser->network()->startCapture();
    $network->startCapture();
    $browser->navigate($base . '/start');
    $browser->wait(function () use ($network, $base): bool {
        $hops = $network->requests();
        foreach (['/final' => 200, '/ok' => 200, '/missing' => 404] as $path => $status) {
            if (!array_any($hops, fn ($hop) => $hop['request']['url'] === $base . $path
                && ($hop['response']['status'] ?? null) === $status
                && array_any($hop['events'], fn ($event) => $event['method'] === 'network.responseCompleted'))) return false;
        }
        return array_any($hops, fn ($hop) => $hop['request']['url'] === $base . '/drop'
            && array_any($hop['events'], fn ($event) => $event['method'] === 'network.fetchError'));
    }, timeout: 5000);
    $redirects = $network->requests(fn ($hop) => in_array($hop['request']['url'], [$base . '/start', $base . '/hop', $base . '/final'], true));
    check(count($redirects) === 3 && count(array_unique(array_column($redirects, 'requestId'))) === 1
        && array_column($redirects, 'redirectCount') === [0, 1, 2], 'Redirect hops lost request identity or order.');
    $statuses = array_map(fn ($hop) => $hop['response']['status'], $redirects);
    check($statuses[0] === 302 && $statuses[2] === 200, 'Missing redirect or final response.');
    foreach ($redirects as $hop) {
        $completed = array_values(array_filter($hop['events'], fn ($event) => $event['method'] === 'network.responseCompleted'));
        check(count($completed) === 1 && $completed[0]['params']['response'] === $hop['response'], 'Capture changed or duplicated browser-reported response data.');
    }
    // Chrome 156 reported 302 for the fixture's second (307) hop; retain the reported value.
    echo 'REDIRECTS ' . json_encode(['served' => [302, 307, 200], 'observed' => $statuses]) . PHP_EOL;
    check($network->captureError() === null, 'HTTP 404 or fetch failure became a capture failure.');
    $network->stopCapture();
    $stopped = $network->requests();
    $browser->navigate($base . '/stopped');
    check($network->requests() === $stopped, 'Stopped capture continued recording.');
    $network->startCapture();
    $browser->navigate($base . '/restarted');
    $browser->wait(fn () => array_any($network->requests(), fn ($hop) => $hop['request']['url'] === $base . '/restarted'
        && array_any($hop['events'], fn ($event) => $event['method'] === 'network.responseCompleted')), timeout: 3000);
    $browser->window()->close();
    check(!$browser->active() && $network->captureError() === null, 'Last-window deletion became a capture failure.');
    $retained = $network->requests();
    check(array_slice($retained, 0, count($stopped)) === $stopped, 'Restart or session cleanup changed earlier history.');
    $browser->quit();
    check($browser->network() === $network && $network->requests() === $retained, 'Quit lost retained history or observer identity.');
    echo "PASS: native $name network observation, redirects, errors, stop/restart and cleanup\n";
} finally {
    try {
        $driver?->stop();
    } finally {
        posix_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $status);
    }
}
