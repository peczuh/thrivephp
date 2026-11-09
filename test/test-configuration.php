<?php

// Run with: php test/test-configuration.php. Uses real PHP workers without native browsers.
$root = sys_get_temp_dir() . '/thrive-configuration-' . bin2hex(random_bytes(6));
mkdir($root . '/vendor', 0700, true);
mkdir($root . '/test/cases', 0700, true);
mkdir($root . '/local');
$source = var_export(dirname(__DIR__) . '/src/', true);
file_put_contents($root . '/vendor/autoload.php', '<?php
namespace ThriveData\ThrivePHP;
class Application { public static function init() { Settings::init(); } }
spl_autoload_register(static function ($class) {
    $prefix = "ThriveData\\\\ThrivePHP\\\\";
    if (str_starts_with($class, $prefix)) require ' . $source . ' . str_replace("\\\\", "/", substr($class, strlen($prefix))) . ".php";
});');
foreach (['inherit', 'replace'] as $name) {
    file_put_contents($root . '/test/cases/' . $name . '.php', '<?php $name = ' . var_export($name, true)
        . '; return require ' . var_export(__DIR__ . '/fixtures/test-configuration-suite.php', true) . ';');
}
try {
    foreach ([false, true] as $bootstrap) foreach ([1, 2] as $workers) {
        $save = $root . '/runs-' . (int)$bootstrap . '-' . $workers;
        file_put_contents($root . '/settings.json', json_encode(['test' => ['savepath' => $save,
            'browser' => ['capabilities' => ['acceptInsecureCerts' => false, 'pageLoadStrategy' => 'normal',
                'example:options' => ['args' => ['project'], 'projectOnly' => true]]]]], JSON_THROW_ON_ERROR));
        file_put_contents($root . '/local/settings.json', '{"test":{"browser":{"capabilities":{"acceptInsecureCerts":true}}}}');
        $process = proc_open([PHP_BINARY, '-d', 'short_open_tag=1', __DIR__ . '/fixtures/test-configuration-run.php',
            $root, (string)(int)$bootstrap, (string)$workers], [0 => ['null'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($process);
        if ($code !== 0 || substr_count($out, 'RETRY SUITE') !== 2) throw new RuntimeException($out);
        echo 'PASS: settings and capability precedence, retry isolation, bootstrap=' . (int)$bootstrap . ', workers=' . $workers . PHP_EOL;
    }
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
