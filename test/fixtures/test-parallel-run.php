<?php

require $argv[1] . '/vendor/autoload.php';

use ThriveData\ThrivePHP\Test;

$test = new class($argv[1], '/checks') extends Test {
    protected function resolveBrowser(?string $browser): ?string
    {
        return $browser ?? (getenv('TEST_BROWSER') ?: 'firefox');
    }
};
ini_set('precision', '12');
ini_set('include_path', 'fixture="quoted";${HOME};C:\\fixture\\');
if (getenv('TEST_BOOTSTRAP')) $test->bootstrap();
else define('PATH_ROOT', $argv[1]);
$options = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
exit($test->run(...$options));
