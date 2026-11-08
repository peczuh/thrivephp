<?php

// Native body collection: php -d short_open_tag=1 test/webdriver-body-browser.php [chrome|firefox].
require __DIR__ . '/fixtures/test-runner.php';

use ThriveData\ThrivePHP\WebDriver\{NetworkDecision, WebDriver};

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function missing(callable $read): void
{
    try { $read(); }
    catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'no such network data'), $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected no such network data.');
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
        $body = match ($path) {
            '/public' => '<title>Body fixture</title>', '/binary', '/binary-text' => "\0\xffbytes",
            '/empty', '/nocontent' => '', '/large' => str_repeat('x', 1024),
            '/gzip' => gzencode(str_repeat('decoded', 1024)), '/redirect' => 'redirect hop',
            '/final' => 'final response', default => "hello ✓",
        };
        $status = $path === '/nocontent' ? 204 : ($path === '/redirect' ? 302 : 200);
        $extra = ($path === '/gzip' ? "Content-Encoding: gzip\r\n" : '')
            . ($path === '/redirect' ? "Location: $base/final\r\n" : '');
        if ($path === '/auth' && !str_contains($headers, 'Basic ' . base64_encode('native:secret'))) {
            $status = 401;
            $extra = "WWW-Authenticate: Basic realm=\"body\"\r\n";
        }
        if ($path === '/pending') usleep(300000);
        fwrite($client, "HTTP/1.1 $status Fixture\r\nContent-Type: " . ($path === '/binary' ? 'application/octet-stream' : 'text/html')
            . "\r\n" . $extra . 'Content-Length: ' . strlen($body) . "\r\nConnection: close\r\n\r\n");
        if ($path === '/pending') {
            fwrite($client, substr($body, 0, 1));
            usleep(800000);
            fwrite($client, substr($body, 1));
        } elseif (!str_starts_with($line, 'HEAD ')) fwrite($client, $body);
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
    $network = $browser->network();
    try { $network->startBodyCollection(128); }
    catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'unknown command') && !str_contains($error->getMessage(), 'unsupported operation')) throw $error;
        check($browser->bidi()->active() && $network->interceptionError() === null, 'Unsupported collection corrupted the session.');
        echo 'LIMITATION: native body collection not verified: ' . $error->getMessage() . PHP_EOL;
        return;
    }
    $started = $completed = [];
    foreach (['beforeRequestSent', 'responseCompleted'] as $phase) {
        $browser->bidi()->listen('network.' . $phase, function ($event) use ($phase, &$started, &$completed) {
            $path = parse_url($event['request']['url'], PHP_URL_PATH);
            if ($phase === 'beforeRequestSent') $started[$path] = $event['request']['request'];
            else $completed[$path] = $event['request']['request'];
        });
    }
    $browser->subscribe(['network.beforeRequestSent', 'network.responseCompleted']);
    $launch = function ($path, $method = 'GET') use ($browser, $base) {
        $browser->script('window.result=null; fetch(arguments[0], {method:arguments[1]}).then(r => r.arrayBuffer()).then(b => window.result=[...new Uint8Array(b)], e => window.result={error:String(e)})',
            arguments: [$base . $path, $method]);
    };
    $finish = function ($path) use ($browser, &$completed) {
        $browser->wait(function () use ($browser, &$completed, $path) {
            $browser->bidi()->drain();
            return isset($completed[$path]) && $browser->script('return window.result !== null');
        });
        return $completed[$path];
    };
    foreach (['/text', '/binary', '/binary-text', '/empty', '/nocontent', '/head', '/gzip', '/large', '/redirect'] as $path) {
        $launch($path, $path === '/head' ? 'HEAD' : 'GET');
        $request = $finish($path === '/redirect' ? '/final' : $path);
        if ($path === '/large' && $name === 'firefox') {
            missing(fn () => $network->responseBody($request));
            continue;
        }
        $expected = match ($path) {
            '/binary', '/binary-text' => "\0\xffbytes", '/empty', '/nocontent', '/head' => '',
            '/gzip' => str_repeat('decoded', 1024), '/large' => str_repeat('x', 1024),
            '/redirect' => 'final response', default => "hello ✓",
        };
        check($browser->script('return window.result') === array_values(unpack('C*', $expected)), 'Page did not receive the fixture bytes at ' . $path);
        $body = $network->responseBody($request);
        if ($path === '/binary-text' && $name === 'firefox') {
            check($body === "\0\xc3\xbfbytes", 'Unexpected Firefox text MIME conversion.');
            echo "LIMITATION: Firefox changes non-UTF-8 bytes labeled text/html; octet-stream preservation checked separately\n";
        } else check($body === $expected, 'Collected bytes differ at ' . $path);
        if ($path === '/large') echo "LIMITATION: $name returned 1024 encoded bytes despite maxEncodedDataSize=128\n";
        if ($path === '/redirect') check($started['/redirect'] === $request && $started['/final'] === $request, 'Redirect request identity changed.');
        check($network->responseBody($request, disown: true) === $body, 'Repeated read changed bytes.');
        missing(fn () => $network->responseBody($request));
    }
    check($network->requests() === [], 'Body collection enabled metadata capture.');
    missing(fn () => $network->responseBody('not-a-request'));

    // A separately owned collector must survive managed disown and stop/restart.
    $manual = $browser->command('network.addDataCollector', ['dataTypes' => ['response'], 'maxEncodedDataSize' => 128])['collector'];
    $launch('/shared');
    $request = $finish('/shared');
    $retained = $network->responseBody($request, true);
    $network->stopBodyCollection()->stopBodyCollection()->startBodyCollection(128);
    missing(fn () => $network->responseBody($request));
    $manualBody = $browser->command('network.getData', ['request' => $request, 'dataType' => 'response', 'collector' => $manual]);
    check($manualBody['bytes'] === ['type' => 'string', 'value' => $retained], 'Managed lifecycle removed foreign ownership.');
    $browser->command('network.removeDataCollector', ['collector' => $manual]);

    $network->startCapture();
    $decisions = [];
    $network->intercept(function ($event) use (&$decisions) {
        $path = parse_url($event['request']['url'], PHP_URL_PATH);
        $decisions[] = $path;
        return $path === '/replace' ? NetworkDecision::respond(body: 'replacement') : NetworkDecision::continueResponse();
    }, array_map(fn ($path) => ['type' => 'string', 'pattern' => $base . $path], ['/pending', '/continued', '/replace']), phase: 'responseStarted');
    $network->intercept(fn () => NetworkDecision::respond(body: 'mocked'), [['type' => 'string', 'pattern' => $base . '/mock']]);
    $network->intercept(fn () => NetworkDecision::provideCredentials('native', 'secret'), [['type' => 'string', 'pattern' => $base . '/auth']], phase: 'authRequired');
    $launch('/pending');
    $browser->wait(function () use ($browser, &$decisions) { $browser->bidi()->drain(); return in_array('/pending', $decisions, true); });
    check($browser->script('return window.result') === null, 'Pending fixture completed before its early read.');
    $early = null;
    try {
        $early = $network->responseBody($started['/pending']);
        if ($name === 'firefox' && $early === 'h') echo "LIMITATION: Firefox returned only the received prefix for a pending response body\n";
        else {
            check($early === "hello ✓", 'Pending read changed bytes: ' . bin2hex($early));
            echo "PASS: native $name pending response read\n";
        }
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'no such network data')
            || ($name === 'chrome' && str_contains($error->getMessage(), 'unknown error: No data found for resource with given identifier')), $error->getMessage());
        echo "LIMITATION: $name rejected a body read after responseStarted but before completion: " . $error->getMessage() . PHP_EOL;
    }
    $finish('/pending');
    try { check($network->responseBody($started['/pending']) === "hello ✓", 'Completed delayed response was not collected.'); }
    catch (RuntimeException $error) {
        check($name === 'firefox' && $early === 'h'
            && str_contains($error->getMessage(), 'unknown error: RangeError: source array is too long'), $error->getMessage());
        echo 'LIMITATION: Firefox failed the completed read after an early partial read: ' . $error->getMessage() . PHP_EOL;
    }
    foreach ($name === 'chrome' ? ['/continued', '/mock', '/auth', '/replace'] : ['/continued', '/mock', '/auth'] as $path) {
        $launch($path);
        $request = $finish($path);
        $expected = match ($path) { '/mock' => 'mocked', '/replace' => 'replacement', default => "hello ✓" };
        if ($path === '/auth' && $name === 'chrome') {
            missing(fn () => $network->responseBody($request));
            check($browser->script('return window.result') === array_values(unpack('C*', $expected)), 'Authenticated fetch failed.');
            echo "LIMITATION: Chrome did not retain the final body after the authentication challenge\n";
            continue;
        }
        $collected = $network->responseBody($request);
        check($browser->script('return window.result') === array_values(unpack('C*', $expected)), 'Page body differs at ' . $path);
        if ($path === '/replace' && $collected === "hello ✓") echo "LIMITATION: Chrome collected the original server body after response-stage replacement\n";
        else check($collected === $expected, 'Intercepted body differs at ' . $path . ': ' . bin2hex($collected));
    }
    $network->stopInterception();
    $hops = $network->requests();
    check($hops !== [] && !str_contains(json_encode($hops), 'replacement') && $network->interceptionError() === null, 'Body retrieval contaminated metadata or interception.');
    $network->stopCapture();
    $browser->quit();
    $network->stopBodyCollection();
    check($retained === "hello ✓" && $network->captureError() === null, 'Session cleanup lost eager data or failed capture.');
    echo "PASS: native $name collection, decoded gzip, empty/HEAD/204, redirects, disown, ownership, interception and cleanup\n";
} finally {
    try { $driver?->stop(); }
    finally { posix_kill($pid, SIGTERM); pcntl_waitpid($pid, $status); }
}
