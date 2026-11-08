<?php

// Native cache policy: php -d short_open_tag=1 test/webdriver-cache-browser.php [chrome|firefox].
require __DIR__ . '/fixtures/test-runner.php';

use ThriveData\ThrivePHP\WebDriver\WebDriver;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function expectError(callable $action, string $text): void
{
    try { $action(); }
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
    $counts = [];
    while ($client = @stream_socket_accept($listener, -1)) {
        // Idle browser connections must expire before the parent's counter-read timeout.
        stream_set_timeout($client, 1);
        $line = fgets($client);
        if ($line === false) { fclose($client); continue; }
        while (($header = fgets($client)) !== false && $header !== "\r\n") {}
        $path = explode(' ', $line)[1];
        $cacheable = str_starts_with($path, '/resource/');
        if ($cacheable) $counts[$path] = ($counts[$path] ?? 0) + 1;
        $body = match ($path) {
            '/public' => '<title>Cache fixture</title><iframe src="/frame"></iframe>',
            '/frame' => '<title>Child frame</title>',
            '/counts' => json_encode($counts),
            default => (string)($counts[$path] ?? 0),
        };
        fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nCache-Control: " . ($cacheable ? 'public, max-age=3600' : 'no-store')
            . "\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
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
    $network = $browser->network();
    $a = $browser->window()->navigate($base . '/public');
    // Each pair warms its own URL, then repeats it. Server counts, not timings, prove reuse.
    $pair = function ($window, $label, bool $bypass) use ($base) {
        $path = '/resource/' . $label;
        $values = [];
        for ($i = 0; $i < 2; $i++) {
            $values[] = $window->script('const done=arguments[arguments.length-1];fetch(arguments[0]).then(r=>r.text()).then(done,e=>done({error:String(e)}))',
                async: true, arguments: [$base . $path]);
        }
        $counts = json_decode(file_get_contents($base . '/counts', context: stream_context_create(['http' => ['timeout' => 10]])), true, flags: JSON_THROW_ON_ERROR);
        check($values === ($bypass ? ['1', '2'] : ['1', '1']) && ($counts[$path] ?? 0) === ($bypass ? 2 : 1),
            'Cache result at ' . $label . ': ' . json_encode(['values' => $values, 'count' => $counts[$path] ?? 0]));
    };
    $pair($a, 'baseline-a', false);
    check($network->setCacheBehavior('bypass') === $network, 'Session cache policy was not fluent.');
    $pair($a, 'global-bypass-a', true);
    $b = $browser->open()->navigate($base . '/public');
    $pair($b, 'global-bypass-new-b', true);
    check($network->setCacheBehavior('default', contexts: [$a->handle()]) === $network, 'Scoped cache policy was not fluent.');
    $pair($a, 'scoped-default-a', false);
    $pair($b, 'still-bypass-b', true);
    $network->setCacheBehavior('bypass');
    $pair($a, 'reset-to-bypass-a', true);
    $network->setCacheBehavior('default');
    $pair($a, 'global-default-a', false);
    $pair($b, 'global-default-b', false);
    $network->setCacheBehavior('bypass', contexts: [$a->handle()]);
    $pair($a, 'scoped-bypass-a', true);
    $pair($b, 'still-default-b', false);
    $network->setCacheBehavior('default');
    $pair($a, 'reset-to-default-a', false);
    $pair($b, 'reset-to-default-b', false);
    echo "PASS: native $name baseline cache reuse, global bypass/default, scoped isolation in both directions, override reset and new-context inheritance\n";

    $tree = $browser->command('browsingContext.getTree', ['root' => $a->handle()]);
    $child = $tree['contexts'][0]['children'][0]['context'];
    expectError(fn () => $network->setCacheBehavior('bypass', ['not-a-context']), 'no such frame');
    expectError(fn () => $network->setCacheBehavior('bypass', [$child]), 'invalid argument');
    expectError(fn () => $network->setCacheBehavior('bypass', [12]), 'invalid argument');
    $pair($a, 'after-errors-a', false);
    check($browser->bidi()->active() && $network->requests() === [] && $network->captureError() === null
        && $network->interceptionError() === null, 'Cache policy errors corrupted transport or enabled capture/passive diagnostics.');
    echo "PASS: native $name unknown/child/non-string context errors preserved with healthy transport and continued caching\n";
} finally {
    try { $driver?->stop(); }
    finally { posix_kill($pid, SIGTERM); pcntl_waitpid($pid, $status); }
}
