<?php

// Native HTTP Basic authentication: php -d short_open_tag=1 test/webdriver-auth-browser.php [chrome|firefox].
require __DIR__ . '/fixtures/test-runner.php';

use ThriveData\ThrivePHP\WebDriver\{NetworkDecision, WebDriver};

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
        $authorized = preg_match('/^Authorization: Basic ' . preg_quote(base64_encode('native:secret'), '/') . '\r?$/mi', $headers) === 1;
        $ok = $path === '/public' || $authorized;
        $body = '<title>' . ($ok ? 'Authorized' : 'Unauthorized') . '</title>';
        fwrite($client, 'HTTP/1.1 ' . ($ok ? '200 OK' : '401 Unauthorized') . "\r\n"
            . ($ok ? '' : 'WWW-Authenticate: Basic realm="' . trim($path, '/') . '"' . "\r\n")
            . "Content-Type: text/html\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
        fclose($client);
    }
    exit;
}
fclose($listener);
$driver = null;
try {
    $name = $argv[1] ?? 'chrome';
    $driver = new WebDriver(browser: $name);
    // Fresh sessions prevent a prior successful authentication from hiding the next challenge.
    foreach (['credentials', 'cancel', 'wrong-cancel', 'wrong-correct', 'default', 'handler-error'] as $mode) {
        $browser = $driver->browser(headless: true, bidi: true);
        $context = $browser->command('browsingContext.getTree')['contexts'][0]['context'];
        $browser->navigate($base . '/public');
        $network = $browser->network();
        $events = [];
        $requests = 0;
        $patterns = [['type' => 'string', 'pattern' => $base . '/' . $mode]];
        $network->intercept(function () use (&$requests) { ++$requests; return NetworkDecision::continueRequest(); }, $patterns);
        $network->intercept(function ($event) use ($mode, &$events) {
            $events[] = $event;
            return match ($mode) {
                'credentials' => NetworkDecision::provideCredentials('native', 'secret'),
                'cancel' => NetworkDecision::cancelAuth(),
                'wrong-cancel' => count($events) === 1 ? NetworkDecision::provideCredentials('native', 'wrong') : NetworkDecision::cancelAuth(),
                'wrong-correct' => NetworkDecision::provideCredentials('native', count($events) === 1 ? 'wrong' : 'secret'),
                'default' => NetworkDecision::defaultAuth(),
                'handler-error' => throw new RuntimeException('native auth handler failure'),
            };
        }, $patterns, phase: 'authRequired');
        if ($mode === 'credentials') $network->startCapture()->stopCapture();
        if ($mode === 'credentials') {
            // Explicit BiDi navigation avoids Chrome's mixed-protocol interception hang.
            $browser->command('browsingContext.navigate', ['context' => $context, 'url' => $base . '/' . $mode, 'wait' => 'complete']);
            check($browser->title() === 'Authorized', 'Credentials did not satisfy HTTP Basic authentication.');
        } else {
            $browser->script('window.authResult = null; fetch(arguments[0]).then(r => window.authResult = r.status, () => window.authResult = "rejected")',
                arguments: [$base . '/' . $mode]);
            if ($mode === 'default') {
                // Wait only for the challenge. stopInterception then waits for the auth acknowledgement,
                // independently of a prompt or the fetch completing under browser-default handling.
                $connection = $browser->bidi();
                $deadline = $connection->deadline();
                while ($events === []) { $connection->tick(); if ($events === []) $connection->wait($deadline); }
            } else {
                $expected = $mode === 'wrong-correct' ? 200 : 401;
                $browser->wait(fn () => $browser->script('return window.authResult') === $expected);
            }
        }
        check($requests >= 1 && count($events) === (str_starts_with($mode, 'wrong-') ? 2 : 1), 'Request/auth phase coordination or challenge count changed.');
        if (count($events) === 2) check($events[0]['request']['request'] === $events[1]['request']['request']
            && $events[0]['redirectCount'] === $events[1]['redirectCount'], 'Repeated challenge identity changed.');
        if ($mode === 'handler-error') {
            try { $network->stopInterception(); throw new LogicException('Expected retained auth failure.'); }
            catch (\ThriveData\ThrivePHP\WebDriver\NetworkError $error) {
                check(count($error->failures()) === 1 && $error->failures()[0]['phase'] === 'authRequired', 'Authentication recovery lost failure correlation.');
            }
        } else {
            $network->stopInterception()->stopInterception();
            check($network->interceptionError() === null, 'Authentication decision failed.');
        }
        check($network->requests() === [], 'Authentication enabled metadata capture.');
        if ($mode !== 'default') {
            $browser->command('browsingContext.navigate', ['context' => $context, 'url' => $base . '/public', 'wait' => 'complete']);
            check($browser->title() === 'Authorized', 'Session reuse after authentication failed.');
        }
        $browser->quit();
        echo "PASS: native $name authentication $mode, stop and cleanup\n";
    }
} finally {
    try { $driver?->stop(); }
    finally { posix_kill($pid, SIGTERM); pcntl_waitpid($pid, $status); }
}
