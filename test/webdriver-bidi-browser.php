<?php

// Run with php -d short_open_tag=1 test/webdriver-bidi-browser.php [chrome|firefox|safaripreview|safari].
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
    if (!in_array($name, ['safari', 'safaripreview'], true)) {
        $subscription = $browser->subscribe(['network.beforeRequestSent']);
        $intercept = $browser->command('network.addIntercept', ['phases' => ['beforeRequestSent']]);
        $handled = 0;
        $listener = $browser->bidi()->listen('network.beforeRequestSent', function (array $event) use (&$handled): ?array {
            if (!($event['isBlocked'] ?? false)) return null;
            $handled++;
            return ['method' => 'network.provideResponse', 'params' => [
                'request' => $event['request']['request'], 'statusCode' => 200,
                'headers' => [['name' => 'Content-Type', 'value' => ['type' => 'string', 'value' => 'text/html']]],
                'body' => ['type' => 'string', 'value' => '<!doctype html><title>Intercepted during HTTP</title>'],
            ]];
        });
        // No server listens here: navigation can complete only if BiDi supplies the response.
        if ($name === 'chrome') {
            // ChromeDriver serializes BiDi commands behind pending classic navigation.
            $context = $browser->command('browsingContext.getTree')['contexts'][0]['context'];
            $browser->command('browsingContext.navigate', ['context' => $context, 'url' => 'http://127.0.0.1:49151/thrive-bidi', 'wait' => 'complete']);
        } else {
            $browser->navigate('http://127.0.0.1:49151/thrive-bidi');
        }
        check($handled > 0 && $browser->title() === 'Intercepted during HTTP', 'BiDi did not service the pending classic navigation.');
        $browser->command('network.removeIntercept', ['intercept' => $intercept['intercept']]);
        $browser->bidi()->unlisten($listener);
        $browser->unsubscribe([$subscription]);
        echo "PASS: native $name response-required event during " . ($name === 'chrome' ? 'BiDi' : 'classic HTTP') . " navigation\n";
    }
    $connection = $browser->bidi();
    $browser->window()->close();
    check(!$browser->active(), 'Closing the last window did not end the session.');
    check(!$connection->active(), 'A retained dispatcher kept the ended session connection alive.');
    $browser->quit();
} finally {
    $driver->stop();
}
