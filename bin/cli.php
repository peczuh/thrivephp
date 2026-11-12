#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/CLI.php';

exit(\ThriveData\ThrivePHP\CLI::run(array_slice($argv, 1), __DIR__));
