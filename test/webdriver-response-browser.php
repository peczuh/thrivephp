<?php

// Native response interception: php -d short_open_tag=1 test/webdriver-response-browser.php [chrome|firefox].
require __DIR__ . '/fixtures/test-runner.php';

use ThriveData\ThrivePHP\WebDriver\{NetworkDecision, NetworkError, WebDriver};

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
check($listener !== false, $error);
$base = 'http://' . stream_socket_get_name($listener, false);
$pid = pcntl_fork();
check($pid !== -1, 'Could not fork HTTP fixture.');
if ($pid === 0) {
    while ($client = @stream_socket_accept($listener, -1)) {
        stream_set_timeout($client, 3);
        $line = fgets($client);
        if ($line === false) { fclose($client); continue; }
        $headers = '';
        while (($header = fgets($client)) !== false && $header !== "\r\n") $headers .= $header;
        $path = explode(' ', $line)[1];
        $status = 200;
        $extra = '';
        if ($path === '/redirect') { $status = 302; $extra = 'Location: ' . $base . "/target\r\n"; }
        if ($path === '/auth' && !str_contains($headers, 'Basic ' . base64_encode('native:secret'))) {
            $status = 401; $extra = "WWW-Authenticate: Basic realm=\"response\"\r\n";
        }
        $body = $path === '/public' ? '<title>Response fixture</title>' : "\0\xffserver";
        fwrite($client, 'HTTP/1.1 ' . $status . " Fixture\r\n" . $extra . 'Content-Type: '
            . ($path === '/public' ? 'text/html' : 'application/octet-stream')
            . "\r\nX-Origin: original\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n"
            . (str_starts_with($line, 'HEAD ') ? '' : $body));
        fclose($client);
    }
    exit;
}
fclose($listener);
$driver = null;
try {
    $name = $argv[1] ?? 'chrome';
    $driver = new WebDriver(browser: $name);
    $browser = $driver->browser(headless: true, bidi: true);
    $browser->navigate($base . '/public');
    $network = $browser->network()->startCapture();
    $paths = ['/original', '/modify', '/empty-headers', '/replace', '/empty', '/fail', '/redirect', '/target', '/auth', '/mock', '/head'];
    $patterns = array_map(fn ($path) => ['type' => 'string', 'pattern' => $base . $path], $paths);
    $events = [];
    foreach (['beforeRequestSent', 'responseStarted', 'authRequired'] as $phase) {
        $network->intercept(function ($event) use ($phase, &$events) {
            $path = parse_url($event['request']['url'], PHP_URL_PATH);
            $events[] = [$phase, $path, $event['request']['request'], $event['redirectCount'], $event['response']['status'] ?? null];
            if ($phase === 'authRequired') return NetworkDecision::provideCredentials('native', 'secret');
            if ($phase === 'beforeRequestSent') return $path === '/mock'
                ? NetworkDecision::respond(body: 'mock') : NetworkDecision::continueRequest();
            return match ($path) {
                '/modify' => NetworkDecision::continueResponse(201, [['X-Changed', 'yes']]),
                '/empty-headers' => NetworkDecision::continueResponse(headers: []),
                '/replace' => NetworkDecision::respond(body: "\xff\0replacement"),
                '/empty' => NetworkDecision::respond(),
                '/fail' => NetworkDecision::fail(),
                default => NetworkDecision::continueResponse(),
            };
        }, $patterns, phase: $phase);
    }
    $fetch = function ($path, $method = 'GET') use ($browser, $base) {
        $browser->script('window.result = null; fetch(arguments[0], {method: arguments[1]}).then(async r => ({status:r.status, headers:[...r.headers], body:[...new Uint8Array(await r.arrayBuffer())]})).then(r => window.result = r, e => window.result = {error:String(e)})',
            arguments: [$base . $path, $method]);
        $browser->wait(fn () => $browser->script('return window.result !== null'));
        return $browser->script('return window.result');
    };
    foreach (array_diff($paths, ['/target']) as $path) {
        $events = [];
        $before = count($network->interceptionError()?->failures() ?? []);
        $result = $fetch($path, $path === '/head' ? 'HEAD' : 'GET');
        $network->collect();
        $failures = array_slice($network->interceptionError()?->failures() ?? [], $before);
        check(array_any($events, fn ($event) => $event[0] === 'responseStarted'), 'No blocked response event for ' . $path);
        if ($name === 'firefox' && in_array($path, ['/replace', '/empty'], true) && $failures !== []) {
            check(count($failures) === 1 && str_contains($failures[0]['error']->getMessage(), 'unsupported operation')
                && str_contains($failures[0]['error']->getMessage(), '"body" parameter') && isset($result['error']), 'Unexpected response replacement failure.');
            echo "LIMITATION: Firefox rejects response-stage body replacement at $path; fail recovery verified\n";
        } else {
            if ($path === '/mock' && $failures !== []) {
                check(count($failures) === 1 && str_contains($failures[0]['error']->getMessage(), 'no such request'), 'Unexpected mocked-response continuation failure.');
                echo "LIMITATION: $name rejects continuation of its blocked mocked-response event; retained error verified\n";
            } else check($failures === [], 'Unexpected interception error at ' . $path);
            if ($path === '/fail') check(isset($result['error']), 'Fail decision did not reject fetch.');
            else {
                $body = match ($path) { '/replace' => "\xff\0replacement", '/empty', '/head' => '', '/mock' => 'mock', default => "\0\xffserver" };
                check($result['body'] === array_values(unpack('C*', $body)) && $result['status'] === ($path === '/modify' ? 201 : 200), 'Response status or bytes changed unexpectedly at ' . $path);
                $headers = array_column($result['headers'], 1, 0);
                if ($path === '/modify') check(($headers['x-changed'] ?? null) === 'yes' && !isset($headers['x-origin']), 'Header replacement failed.');
                if ($path === '/empty-headers') check(!isset($headers['x-origin']), 'Empty header replacement preserved a server header.');
                if ($path === '/original') check(($headers['x-origin'] ?? null) === 'original', 'Unchanged continuation lost server headers.');
            }
        }
        if ($path === '/redirect') {
            $responses = array_values(array_filter($events, fn ($event) => $event[0] === 'responseStarted'));
            check(array_column($responses, 3) === [0, 1] && $responses[0][2] === $responses[1][2], 'Redirect response occurrences were not preserved.');
        }
        if ($path === '/auth') {
            check(array_any($events, fn ($event) => $event[0] === 'authRequired'), 'Authentication did not coexist with response interception.');
            echo 'AUTH ORDER ' . json_encode($events) . PHP_EOL;
        }
        check($browser->bidi()->active(), 'A managed response error invalidated BiDi.');
    }
    $original = $network->requests(fn ($hop) => $hop['request']['url'] === $base . '/modify');
    $started = array_values(array_filter($original[0]['events'], fn ($event) => $event['method'] === 'network.responseStarted'))[0];
    check($started['params']['response']['status'] === 200
        && array_any($started['params']['response']['headers'], fn ($header) => strtolower($header['name']) === 'x-origin'), 'Capture rewrote the original response snapshot.');
    $retained = $network->interceptionError();
    try { $network->stopInterception(); check($retained === null, 'Stop did not throw its retained error.'); }
    catch (NetworkError $error) { check($error === $retained, 'Stop replaced retained response errors.'); }
    $result = $fetch('/modify');
    check($result['status'] === 200 && array_column($result['headers'], 1, 0)['x-origin'] === 'original', 'Stopped response handler still modified the response.');
    $browser->quit();
    check($network->captureError() === null, 'Managed failures contaminated metadata capture.');
    echo "PASS: native $name response continuation, modification, failure, redirects, auth, HEAD, capture and stop/reuse\n";
    if ($name === 'chrome') echo "PASS: native Chrome empty and binary response-body replacement\n";
} finally {
    try { $driver?->stop(); }
    finally { posix_kill($pid, SIGTERM); pcntl_waitpid($pid, $status); }
}
