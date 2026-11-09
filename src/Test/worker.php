<?php

declare(strict_types=1);

// This entry must connect before loading application code, including Composer's platform guard.
spl_autoload_register(static function (string $class): void {
    $prefix = 'ThriveData\\ThrivePHP\\';
    if (str_starts_with($class, $prefix)) {
        $path = dirname(__DIR__) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

use ThriveData\ThrivePHP\Test;
use ThriveData\ThrivePHP\Test\Channel;

try {
    if (PHP_OS_FAMILY !== 'Windows' && function_exists('posix_setsid') && posix_setsid() < 0) {
        throw new RuntimeException('Could not create a worker process session.');
    }
    $stream = stream_socket_client($argv[1], $errno, $error, 60);
    if ($stream === false) {
        throw new RuntimeException('Could not connect to test parent: ' . $error);
    }
    $channel = new Channel($stream);
    $channel->send(['type' => 'hello', 'token' => $argv[2], 'pid' => getmypid()]);
    stream_set_timeout($stream, 60);
    $job = null;
    while ($job === null) {
        foreach ($channel->receive() as $message) {
            if ($message['type'] !== 'job' || !is_array($message['job'] ?? null)) {
                throw new RuntimeException('Expected a worker job.');
            }
            $job = $message['job'];
        }
        if ($job === null && (feof($stream) || stream_get_meta_data($stream)['timed_out'])) {
            throw new RuntimeException('Test parent did not supply a worker job.');
        }
    }
    $test = new Test($job['root']);
    $code = $test->runWorker($job, $channel);
    $channel->close();
    exit($code);
} catch (Throwable $error) {
    fwrite(STDERR, 'Test worker error: ' . get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
