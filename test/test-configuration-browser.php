<?php

// Run with: php test/test-configuration-browser.php [chrome|firefox]. Uses a temporary self-signed HTTPS origin.
require __DIR__ . '/fixtures/test-runner.php';

use ThriveData\ThrivePHP\Settings;
use ThriveData\ThrivePHP\Test;

$root = sys_get_temp_dir() . '/thrive-configuration-browser-' . bin2hex(random_bytes(6));
mkdir($root . '/test/cases', 0700, true);
mkdir($root . '/vendor');
$source = var_export(dirname(__DIR__) . '/src/', true);
file_put_contents($root . '/vendor/autoload.php', '<?php spl_autoload_register(static function ($class) {
    $prefix = "ThriveData\\\\ThrivePHP\\\\";
    if (str_starts_with($class, $prefix)) require ' . $source . ' . str_replace("\\\\", "/", substr($class, strlen($prefix))) . ".php";
});');
$server = proc_open([PHP_BINARY, __DIR__ . '/fixtures/test-tls-server.php', $root],
    [0 => ['null'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
try {
    stream_set_timeout($pipes[1], 10);
    $url = trim(fgets($pipes[1]) ?: '');
    if (!str_starts_with($url, 'https://127.0.0.1:')) throw new RuntimeException('HTTPS fixture did not start.');
    $suite = <<<'PHP'
        <?php
        use ThriveData\ThrivePHP\Settings;
        use ThriveData\ThrivePHP\Test\SuiteBrowser;
        use ThriveData\ThrivePHP\Test\SuiteConfig;
        use ThriveData\ThrivePHP\Test\Test;
        return new class($test, $mode) extends SuiteBrowser {
            public function __construct($test, private string $mode) { parent::__construct($test); }
            protected function configure(SuiteConfig $config): void {
                if ($this->mode === 'suite') $config->capabilities['acceptInsecureCerts'] = false;
            }
            #[Test('Certificate policy')]
            public function certificate(): void {
                $accept = $this->configuration()->capabilities['acceptInsecureCerts'];
                $this->test->assert($this->browser->session()['capabilities']['acceptInsecureCerts'] === $accept,
                    'Negotiated certificate policy matches configuration');
                $rejected = false;
                try {
                    $this->test->withoutSteps(fn () => $this->browser->navigate(Settings::get('fixture.url')));
                } catch (RuntimeException $error) {
                    if (!str_contains(strtolower($error->getMessage()), 'insecure certificate')) throw $error;
                    $rejected = true;
                }
                if ($accept) {
                    $this->test->assert(!$rejected, 'Untrusted certificate is accepted');
                    $this->browser->find('#ready')->textEquals('HTTPS loaded', assert: true);
                } else {
                    $this->test->assert($rejected || !$this->browser->find('#ready')->present(),
                        'Untrusted page is blocked when certificate acceptance is false');
                }
            }
        };
        PHP;
    foreach (['inherit', 'suite'] as $mode) {
        file_put_contents($root . '/test/cases/' . $mode . '.php', '<?php $mode = ' . var_export($mode, true) . '; ?>' . $suite);
    }
    foreach ([[], ['acceptInsecureCerts' => false]] as $index => $capabilities) {
        Settings::set('', ['fixture' => ['url' => $url], 'test' => [
            'savepath' => $root . '/runs-' . $index, 'browser' => ['capabilities' => ['acceptInsecureCerts' => true]],
            'screenshots' => ['steps' => 'off', 'cases' => 'off', 'suites' => 'off']]]);
        $test = new Test($root);
        if ($test->run(workers: 2, browser: $argv[1] ?? 'chrome', headless: true, capabilities: $capabilities) !== 0) {
            throw new RuntimeException('Browser certificate configuration failed.');
        }
    }
    echo 'PASS: native ' . ($argv[1] ?? 'chrome') . ' settings, run and suite TLS policies through parallel workers' . PHP_EOL;
} finally {
    proc_terminate($server);
    fclose($pipes[1]);
    proc_close($server);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
