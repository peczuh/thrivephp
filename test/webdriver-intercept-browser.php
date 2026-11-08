<?php

// Native request interception: php -d short_open_tag=1 test/webdriver-intercept-browser.php [chrome|firefox].
require __DIR__ . '/fixtures/test-runner.php';

use ThriveData\ThrivePHP\WebDriver\NetworkDecision;
use ThriveData\ThrivePHP\WebDriver\WebDriver;
use ThriveData\ThrivePHP\Test\{SuiteBrowser, SuiteConfig, Test};

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
        $title = $path === '/pass' && str_contains(strtolower($headers), 'x-native: yes') ? 'Continued' : 'Network ' . $path;
        if ($path === '/post') {
            preg_match('/^Content-Length:\s*(\d+)/mi', $headers, $length);
            $data = '';
            while (strlen($data) < (int)($length[1] ?? 0)) {
                $chunk = fread($client, (int)$length[1] - strlen($data));
                if ($chunk === false || $chunk === '') break;
                $data .= $chunk;
            }
            if (str_starts_with($line, 'POST ') && $data === "\0\xffnative") $title = 'Posted';
        }
        $body = '<!doctype html><title>' . $title . '</title>';
        fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
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
    echo 'SESSION ' . json_encode($browser->session()['capabilities']) . PHP_EOL;
    $browser->navigate($base . '/page');
    $context = $browser->command('browsingContext.getTree')['contexts'][0]['context'];
    // Chrome 156 hangs on classic navigation with BiDi interception; choose BiDi explicitly, never as a fallback.
    $navigate = $name === 'chrome'
        ? fn ($path) => $browser->command('browsingContext.navigate', ['context' => $context, 'url' => $base . $path, 'wait' => 'complete'])
        : fn ($path) => $browser->navigate($base . $path);
    $network = $browser->network()->startCapture();
    $network->intercept(static function ($event) use ($base): NetworkDecision {
        return match ($event['request']['url']) {
            $base . '/mock' => NetworkDecision::respond(headers: [['Content-Type', 'text/html']], body: '<!doctype html><title>Mocked</title>'),
            $base . '/pass' => NetworkDecision::continueRequest(headers: [['X-Native', 'yes']]),
            $base . '/rewrite' => NetworkDecision::continueRequest(url: $base . '/changed'),
            $base . '/post' => NetworkDecision::continueRequest(method: 'POST', body: "\0\xffnative"),
            default => NetworkDecision::fail(),
        };
    }, urlPatterns: array_map(fn ($path) => ['type' => 'string', 'pattern' => $base . $path], ['/mock', '/pass', '/rewrite', '/post', '/fail']));
    $navigate('/mock');
    check($browser->title() === 'Mocked', 'Mock response was not used.');
    $navigate('/pass');
    $title = $browser->title();
    check($title === 'Continued', 'Request header modification did not reach the server: ' . $title . '; ' . ($network->interceptionError()?->getMessage() ?? 'no interception error'));
    $navigate('/rewrite');
    check($browser->title() === 'Network /changed', 'Request URL modification did not reach the server.');
    $navigate('/post');
    check($browser->title() === 'Posted', 'Request method or byte body was not replaced.');
    $browser->script('window.failedRequest = null; fetch(arguments[0]).then(() => window.failedRequest = false, () => window.failedRequest = true)', arguments: [$base . '/fail']);
    $browser->wait(fn () => array_any($network->requests(), fn ($hop) => $hop['request']['url'] === $base . '/fail'
        && array_any($hop['events'], fn ($event) => $event['method'] === 'network.fetchError')), timeout: 3000);
    $browser->wait(fn () => $browser->script('return window.failedRequest === true'), timeout: 3000);
    check($network->captureError() === null && $network->interceptionError() === null, 'Intentional fail decision became an interceptor failure.');
    $network->stopCapture();
    $navigate('/mock');
    check($browser->title() === 'Mocked', 'Stopping capture stopped interception.');
    $network->stopInterception();
    $navigate('/mock');
    check($browser->title() === 'Network /mock', 'Stopped rule still mocked the request.');
    $browser->quit();
    check(count($network->requests()) >= 3 && $network->interceptionError() === null, 'Session cleanup lost observations or created interception errors.');
    $driver->stop();
    $driver = null;
    [$status, , $record] = runChecks(['suite' => function ($test) use ($base) {
        return new class($test, $base) extends SuiteBrowser {
            public function __construct($test, private string $base) { parent::__construct($test); }
            protected function configure(SuiteConfig $config): void { $config->bidi = true; }
            #[Test('Interception failure without capture')]
            public function body(): void {
                $this->browser->navigate($this->base . '/page');
                $this->browser->network()->intercept(fn () => throw new RuntimeException('native handler failure'),
                    urlPatterns: [['type' => 'string', 'pattern' => $this->base . '/suite-fail']]);
                $this->browser->script('window.failedRequest = false; fetch(arguments[0]).catch(() => window.failedRequest = true)',
                    arguments: [$this->base . '/suite-fail']);
                $this->browser->wait(fn () => $this->browser->script('return window.failedRequest === true'));
                $this->browser->network()->stopInterception();
            }
        };
    }], browser: $name, headless: true);
    $suite = $record['suites'][0]['attempts'][0];
    $case = $suite['cases'][0]['attempts'][0];
    check($status === 1 && count($case['errors']) === 1 && $case['errors'][0]['message'] === 'native handler failure'
        && count($suite['errors']) === 1 && $suite['browserNetwork'] === [] && $case['browserNetwork'] === [],
        'Native suite interception diagnostics were lost, duplicated or enabled metadata capture.');
    echo "PASS: native $name request mocking, modification, failure and stop/reuse\n";
    echo "PASS: native $name suite interception errors with capture disabled\n";
} finally {
    try { $driver?->stop(); }
    finally { posix_kill($pid, SIGTERM); pcntl_waitpid($pid, $status); }
}
