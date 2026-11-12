<?php

require __DIR__ . '/test-runner.php';

use ThriveData\ThrivePHP\Settings;
use ThriveData\ThrivePHP\Test;

Settings::set('', []);
Settings::set('test.screenshots.steps', 'always');
if ($argv[2] !== 'default') Settings::set('test.console.screenshots.columns', (int)$argv[2]);
exit((new Test($argv[1]))->run(workers: 1));
