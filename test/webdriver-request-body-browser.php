<?php

// Native request collection: php -d short_open_tag=1 test/webdriver-request-body-browser.php [chrome|firefox].
require __DIR__ . '/fixtures/test-runner.php';

use ThriveData\ThrivePHP\WebDriver\{NetworkDecision, WebDriver};

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function expectError(callable $read, string $text = 'no such network data'): void
{
    try { $read(); }
    catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), $text), $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected ' . $text . '.');
}

$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
check($listener !== false, $error);
$base = 'http://' . stream_socket_get_name($listener, false);
$pid = pcntl_fork();
check($pid !== -1, 'Could not fork HTTP fixture.');
if ($pid === 0) {
    $records = [];
    while ($client = @stream_socket_accept($listener, -1)) {
        stream_set_timeout($client, 3);
        $line = fgets($client);
        if ($line === false) { fclose($client); continue; }
        $headers = '';
        while (($header = fgets($client)) !== false && $header !== "\r\n") $headers .= $header;
        [$method, $path] = explode(' ', $line);
        preg_match('/Content-Length: (\d+)/i', $headers, $length);
        preg_match('/Content-Type: ([^\r\n]+)/i', $headers, $contentType);
        $body = '';
        while (strlen($body) < (int)($length[1] ?? 0)) {
            $part = fread($client, (int)$length[1] - strlen($body));
            check($part !== false && $part !== '', 'Incomplete fixture upload.');
            $body .= $part;
        }
        if (str_starts_with($path, '/case/')) $records[$path][] = ['method' => $method,
            'body' => base64_encode($body), 'contentType' => $contentType[1] ?? null];
        $status = 200;
        $extra = '';
        if (preg_match('#^/case/redirect(303|307|308)$#', $path, $redirect)) {
            $status = (int)$redirect[1];
            $extra = "Location: $base/case/final$redirect[1]\r\n";
        }
        if ($path === '/case/auth' && !str_contains($headers, 'Basic ' . base64_encode('native:secret'))) {
            $status = 401;
            $extra = "WWW-Authenticate: Basic realm=\"request-body\"\r\n";
        }
        // Only the PHP test reads this control endpoint; browser responses never echo uploaded bytes.
        $response = $path === '/records' ? json_encode($records) : ($path === '/public' ? '<title>Request bodies</title>' : 'received');
        fwrite($client, "HTTP/1.1 $status Fixture\r\nContent-Type: text/html\r\n" . $extra
            . 'Content-Length: ' . strlen($response) . "\r\nConnection: close\r\n\r\n" . $response);
        fclose($client);
    }
    exit;
}
fclose($listener);
$driver = null;
try {
    $name = $argv[1] ?? 'chrome';
    $records = fn () => json_decode(file_get_contents($base . '/records', context: stream_context_create(['http' => ['timeout' => 3]])), true, flags: JSON_THROW_ON_ERROR);
    foreach ([['request'], ['request', 'response']] as $types) {
        $driver = new WebDriver(browser: $name);
        $browser = $driver->browser(headless: true, bidi: true);
        $connection = $browser->bidi();
        $browser->navigate($base . '/public');
        $network = $browser->network()->startBodyCollection(4096, $types);
        $completed = $events = [];
        foreach (['beforeRequestSent', 'responseCompleted'] as $phase) {
            $connection->listen('network.' . $phase, function ($event) use ($phase, &$completed, &$events) {
                $path = parse_url($event['request']['url'], PHP_URL_PATH);
                $events[$path][] = [$phase, $event['request']['request'], $event['redirectCount']];
                if ($phase === 'responseCompleted') $completed[$path] = $event['request']['request'];
            });
        }
        $browser->subscribe(['network.beforeRequestSent', 'network.responseCompleted']);
        $fetch = function ($path, $kind = 'text', $body = "submitted ✓", $final = null) use ($browser, $connection, $base, &$completed) {
            $browser->script('let body=arguments[2];
                if(arguments[1]==="bytes") body=Uint8Array.from(atob(body), c=>c.charCodeAt(0));
                if(arguments[1]==="absent") body=undefined;
                if(arguments[1]==="form") body=new URLSearchParams([["message","✓ +"]]);
                if(arguments[1]==="multipart") {body=new FormData(); body.append("message","✓"); body.append("file",new Blob([new Uint8Array([0,255,1])]),"part.bin");}
                window.result=null;
                fetch(arguments[0],{method:"POST",body,headers:arguments[1]==="json"?{"Content-Type":"application/json"}:{}}).then(r=>r.text()).then(r=>window.result=r,e=>window.result={error:String(e)})',
                arguments: [$base . $path, $kind, $body]);
            $key = $final ?? $path;
            $browser->wait(function () use ($browser, $connection, &$completed, $key) {
                $connection->drain();
                return isset($completed[$key]) && $browser->script('return window.result !== null');
            });
            return $completed[$key];
        };
        $path = count($types) === 1 ? '/case/request-only' : '/case/mixed';
        $request = $fetch($path);
        check($browser->script('return window.result') === 'received'
            && base64_decode($records()[$path][0]['body'], true) === "submitted ✓", 'Baseline POST did not reach the server.');
        if (count($types) === 2) check($network->responseBody($request) === 'received', 'Mixed collector lost response data.');
        else {
            expectError(fn () => $network->responseBody($request));
            expectError(fn () => $network->disownBody($request));
        }
        try { $retained = $network->requestBody($request); }
        catch (RuntimeException $error) {
            check($name === 'firefox' && $error->getMessage() === 'WebDriver BiDi operation timed out.' && !$connection->active(), $error->getMessage());
            try { $driver->stop(); }
            catch (RuntimeException $cleanup) {
                check(str_contains($cleanup->getMessage(), 'WebDriver cleanup failed: WebDriver HTTP DELETE')
                    && str_contains($cleanup->getMessage(), 'timed out') && !$browser->active(), $cleanup->getMessage());
                echo "LIMITATION: Firefox HTTP deletion also timed out; owned runtime termination completed\n";
            }
            $network->stopBodyCollection();
            check(!$browser->active(), 'Timed-out request collection prevented runtime cleanup.');
            $driver = null;
            echo 'LIMITATION: Firefox accepted ' . json_encode($types) . ' and received the POST, but getData(request) timed out after responseCompleted; runtime cleanup verified' . PHP_EOL;
            continue;
        }
        check($retained === "submitted ✓" && $network->requestBody($request, true) === $retained, 'Baseline request bytes or repeated read differ.');
        expectError(fn () => $network->requestBody($request));
        if (count($types) === 1) {
            $network->stopBodyCollection();
            $browser->quit();
            check($retained === "submitted ✓", 'Eager body depended on session lifetime.');
            $driver->stop();
            $driver = null;
            echo "PASS: native $name request-only collection, repeated read, disown and cleanup\n";
            continue;
        }
        check($network->responseBody($request) === 'received', 'Request disown removed response data.');
        $payloads = [
            'text' => ['text', "submitted ✓"], 'json' => ['json', '{"message":"✓"}'],
            'form' => ['form', ''], 'binary' => ['bytes', base64_encode("\0\xffbytes")],
            'multipart' => ['multipart', ''], 'empty' => ['text', ''], 'absent' => ['absent', ''],
        ];
        foreach ($payloads as $label => [$kind, $body]) {
            $path = '/case/' . $label;
            $request = $fetch($path, $kind, $body);
            $observed = $records()[$path][0];
            $received = base64_decode($observed['body'], true);
            if ($kind === 'multipart') {
                check(str_starts_with($observed['contentType'], 'multipart/form-data; boundary=')
                    && str_contains($received, "\0\xff\1"), 'Multipart upload was not received intact.');
            } else {
                $expected = match ($kind) { 'bytes' => base64_decode($body, true), 'form' => 'message=%E2%9C%93+%2B', default => $body };
                check($received === $expected, 'Server received different payload bytes at ' . $path);
            }
            if ($kind === 'absent' || ($label === 'empty' && $name === 'firefox')) {
                expectError(fn () => $network->requestBody($request), $name === 'firefox' ? 'no such network data' : 'unknown error: No post data available for the request');
                echo "LIMITATION: $name did not collect the $label POST body\n";
                continue;
            }
            try { $body = $network->requestBody($request); }
            catch (RuntimeException $error) { throw new RuntimeException('Reading ' . $path . ': ' . $error->getMessage(), previous: $error); }
            if (in_array($kind, ['bytes', 'multipart'], true) && $name === 'chrome') {
                check($body === base64_encode($received), 'Unexpected Chrome binary request representation.');
                $raw = $browser->command('network.getData', ['request' => $request, 'dataType' => 'request']);
                check($raw['bytes'] === ['type' => 'string', 'value' => $body], 'Native binary representation changed.');
                echo "LIMITATION: Chrome returned base64 text tagged as string for $label; no second decoding applied\n";
            } else {
                check($body === $received, 'Collected request differs from server bytes at ' . $path);
            }
        }
        check($network->requests() === [], 'Body collection enabled metadata capture.');

        $request = $fetch('/case/disown-sole');
        check($network->requestBody($request) === "submitted ✓" && $network->responseBody($request) === 'received', 'Sole-owned release fixture was not collected.');
        check($network->disownBody($request) === $network, 'Response release was not fluent.');
        expectError(fn () => $network->responseBody($request));
        expectError(fn () => $network->disownBody($request));
        check($network->requestBody($request) === "submitted ✓", 'Response release removed request ownership.');
        check($network->disownBody($request, dataType: 'request') === $network, 'Request release was not fluent.');
        expectError(fn () => $network->requestBody($request));
        expectError(fn () => $network->disownBody($request, 'request'));
        expectError(fn () => $network->disownBody('not-a-request'));
        $later = $fetch('/case/after-disown');
        check($network->requestBody($later) === "submitted ✓" && $network->responseBody($later) === 'received', 'Standalone release stopped later collection.');
        echo "PASS: native $name standalone request/response release, sole-owner repeats and continued collection\n";

        $manual = $browser->command('network.addDataCollector', ['dataTypes' => $types, 'maxEncodedDataSize' => 4096])['collector'];
        $request = $fetch('/case/disown-shared');
        foreach (['response' => 'received', 'request' => "submitted ✓"] as $type => $expected) {
            $reader = $type . 'Body';
            check($network->$reader($request) === $expected, 'Shared item or other data type was lost before release.');
            $network->disownBody($request, $type);
            expectError(fn () => $network->$reader($request));
            expectError(fn () => $network->disownBody($request, $type));
            $result = $browser->command('network.getData', ['request' => $request, 'dataType' => $type, 'collector' => $manual]);
            check($result['bytes'] === ['type' => 'string', 'value' => $expected], 'Standalone release removed foreign ownership.');
        }
        check($connection->active() && $network->captureError() === null && $network->interceptionError() === null, 'Release errors corrupted transport or passive diagnostics.');
        echo "LIMITATION: $name rejects repeated standalone release even while a foreign collector retains the item; foreign data preserved\n";
        $request = $fetch('/case/shared');
        check($network->requestBody($request, true) === "submitted ✓" && $network->responseBody($request, true) === 'received', 'Mixed disown failed.');
        $network->stopBodyCollection()->startBodyCollection(128, $types);
        expectError(fn () => $network->requestBody($request));
        expectError(fn () => $network->responseBody($request));
        foreach ($types as $type) expectError(fn () => $browser->command('network.getData', ['request' => $completed['/case/text'], 'dataType' => $type]));
        foreach (['request' => "submitted ✓", 'response' => 'received'] as $type => $expected) {
            $result = $browser->command('network.getData', ['request' => $request, 'dataType' => $type, 'collector' => $manual]);
            check($result['bytes'] === ['type' => 'string', 'value' => $expected], 'Managed lifecycle released foreign data.');
        }
        $browser->command('network.removeDataCollector', ['collector' => $manual]);
        foreach (['ascii127' => str_repeat('a', 127), 'ascii128' => str_repeat('a', 128), 'ascii129' => str_repeat('a', 129),
            'utf128' => str_repeat('é', 64), 'utf130' => str_repeat('é', 65), 'astral256' => str_repeat('😀', 64),
            'astral260' => str_repeat('😀', 65), 'binary96' => str_repeat("\xff", 96),
            'binary97' => str_repeat("\xff", 97), 'binary128' => str_repeat("\xff", 128), 'binary129' => str_repeat("\xff", 129)] as $label => $body) {
            $request = $fetch('/case/' . $label, 'bytes', base64_encode($body));
            check(base64_decode($records()['/case/' . $label][0]['body'], true) === $body, 'Boundary upload changed.');
            $unavailable = $name === 'firefox' ? in_array($label, ['ascii129', 'astral260', 'binary97', 'binary128', 'binary129'], true) : strlen($body) > 128;
            if ($unavailable) expectError(fn () => $network->requestBody($request));
            else check($network->requestBody($request) === (str_starts_with($label, 'binary') && $name === 'chrome' ? base64_encode($body) : $body), 'Boundary retrieval changed bytes.');
        }
        if ($name === 'firefox') echo "LIMITATION: Firefox's boundary results followed UTF-16 string/base64 lengths rather than uploaded byte counts\n";
        else echo "PASS: native $name request limits matched uploaded byte counts for ASCII, UTF-8 and binary boundary fixtures\n";
        $network->stopBodyCollection()->startBodyCollection(4096, $types);
        $decisions = [];
        $network->intercept(function ($event) use (&$decisions) {
            $path = parse_url($event['request']['url'], PHP_URL_PATH);
            $decisions[] = $path;
            if ($path === '/case/mock') return NetworkDecision::respond(body: 'mocked');
            // Equal byte lengths isolate body identity from browser Content-Length override behavior.
            return NetworkDecision::continueRequest(body: $path === '/case/override' ? 'overridden!!!' : 'second-hop!!!');
        }, array_map(fn ($path) => ['type' => 'string', 'pattern' => $base . $path], ['/case/mock', '/case/override', '/case/final307', '/case/final308']));
        $network->intercept(fn () => NetworkDecision::provideCredentials('native', 'secret'), [['type' => 'string', 'pattern' => $base . '/case/auth']], phase: 'authRequired');
        foreach (['override', 'mock', 'redirect303', 'redirect307', 'redirect308', 'auth'] as $label) {
            $path = '/case/' . $label;
            $decisions = [];
            $final = str_starts_with($label, 'redirect') ? '/case/final' . substr($label, -3) : $path;
            $request = $fetch($path, final: $final);
            $observed = $records();
            check($browser->script('return window.result') === ($label === 'mock' ? 'mocked' : 'received'), 'Intercepted fetch failed.');
            if ($label === 'mock') check(!isset($observed[$path]) && $decisions === [$path], 'Mocked request reached the server or missed its handler.');
            else {
                $received = array_map(fn ($record) => base64_decode($record['body'], true), $observed[$path]);
                check($received === ($label === 'auth' ? ["submitted ✓", "submitted ✓"] : [$label === 'override' ? 'overridden!!!' : "submitted ✓"]), 'Unexpected bytes received at ' . $path);
            }
            if (str_starts_with($label, 'redirect')) {
                check($events[$path][0] === ['beforeRequestSent', $request, 0]
                    && $events[$final][0] === ['beforeRequestSent', $request, 1], 'Redirect request identity was not preserved.');
                $record = $observed[$final][0];
                check($record['method'] === ($label === 'redirect303' ? 'GET' : 'POST')
                    && base64_decode($record['body'], true) === ($label === 'redirect303' ? '' : ($name === 'firefox' ? 'second-hop!!!' : "submitted ✓")), 'Redirect upload semantics changed.');
                if ($label !== 'redirect303') {
                    check($decisions === [$final], 'Second-hop override handler was not invoked.');
                    if ($name === 'chrome') echo "LIMITATION: Chrome acknowledged the $label second-hop override but sent original bytes; hop attribution remains unproven\n";
                }
            }
            if ($label === 'redirect303' && $name === 'chrome') {
                expectError(fn () => $network->requestBody($request), 'unknown error: No post data available for the request');
                echo "LIMITATION: Chrome exposes no POST data after the 303 rewrite to GET\n";
            } else {
                $expected = $name === 'firefox' ? match ($label) {
                    'override' => 'overridden!!!', 'redirect307', 'redirect308' => 'second-hop!!!', default => "submitted ✓",
                } : "submitted ✓";
                check($network->requestBody($request) === $expected, 'Unexpected collected bytes for ' . $label);
                if ($label === 'override' && $name === 'chrome') echo "LIMITATION: Chrome collected original request bytes while the server received the override\n";
            }
        }
        $network->stopInterception();
        check($network->interceptionError() === null, 'Request probes caused interception failure.');
        $browser->quit();
        $network->stopBodyCollection();
        $driver->stop();
        $driver = null;
        echo "PASS: native $name mixed request/response collection and ownership\n";
    }
} finally {
    try { $driver?->stop(); }
    finally { posix_kill($pid, SIGTERM); pcntl_waitpid($pid, $status); }
}
