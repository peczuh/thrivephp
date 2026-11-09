<?php

require dirname(__DIR__, 2) . '/src/Test.php';

use ThriveData\ThrivePHP\Settings;
use ThriveData\ThrivePHP\Test;

$root = $argv[1];
$test = new class($root) extends Test {
    protected function resolveBrowser(?string $browser): ?string { return null; }
};
if ($argv[2] === '1') {
    $test->bootstrap();
} else {
    define('PATH_ROOT', $root);
    require $root . '/vendor/autoload.php';
    Settings::init();
}
Settings::set('runtime', ['marker' => 'runtime-only-marker', 'large' => str_repeat('x', 200000),
    'emptyObject' => (object)[], 'emptyList' => [], 'numericObject' => (object)['0' => ['keep' => true]]]);
Settings::set('test.browser.capabilities.acceptInsecureCerts', false);
exit($test->run(workers: (int)$argv[3], capabilities: ['pageLoadStrategy' => 'eager', 'example:options' => ['args' => ['run']]]));
