<?php

require __DIR__ . '/project/vendor/autoload.php';

use ThriveData\ThrivePHP\WebDriver\WebDriver;

$name = $argv[1];
$inventory = WebDriver::bootstrap();
$pair = $inventory->browsers->$name;
echo json_encode(['platform' => $inventory->platform, 'browser' => $pair], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
if ($inventory->platform->distribution !== 'alpine' || !$inventory->ready
    || $pair->ready !== true || $pair->driver->ready !== true) {
    throw new RuntimeException("Did not discover a ready $name pair on Alpine.");
}

$driver = new WebDriver(browser: $name);
$endpoint = parse_url($driver->endpoint());
try {
    // Only use this flag for the local pages in the network-isolated container.
    $options = $name === 'chromium' ? ['goog:chromeOptions' => ['args' => ['--no-sandbox']]] : [];
    $started = hrtime(true);
    $browser = $driver->browser(timeout: $name === 'firefox' ? 30 : 10, capabilities: $options);
    printf("Session creation took %.2fs\n", (hrtime(true) - $started) / 1e9);
    $browser->navigate('data:text/html,' . rawurlencode(
        "<title>Before</title><button onclick=\"document.title='After'\">Click</button>"
    ));
    $browser->titleEquals('Before', assert: true);
    $browser->find('button')->click();
    $browser->titleEquals('After', assert: true);
    $browser->quit();
    if ($browser->active()) throw new RuntimeException('Session still active after quit().');
} finally {
    $driver->stop();
}
$socket = @stream_socket_client('tcp://' . $endpoint['host'] . ':' . $endpoint['port'], timeout: 1);
if ($socket !== false) {
    fclose($socket);
    throw new RuntimeException('Driver still listening after stop().');
}
echo "PASS: $name session, page interaction, and driver shutdown", PHP_EOL;
