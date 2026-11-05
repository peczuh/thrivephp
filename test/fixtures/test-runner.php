<?php

require_once dirname(__DIR__, 2) . '/src/Test.php';
require_once dirname(__DIR__, 2) . '/src/Test/AssertionFailure.php';

use ThriveData\ThrivePHP\Test;

define('PATH_ROOT', dirname(__DIR__, 2));

/** Run real check files with supplied bodies, capturing the runner's report. Supports nested runs. */
function runChecks(array $checks, ?Test $test = null): array
{
	$previous = $GLOBALS['runnerChecks'] ?? null;
	$GLOBALS['runnerChecks'] = $checks;
	$test ??= new Test(PATH_ROOT, '/test/fixtures/test-checks');
	ob_start();
	try {
		$status = $test->run(array_keys($checks));
		return [$status, ob_get_contents()];
	} finally {
		ob_end_clean();
		$GLOBALS['runnerChecks'] = $previous;
	}
}
