<?php

require_once dirname(__DIR__, 2) . '/src/Test.php';
require_once dirname(__DIR__, 2) . '/src/Test/AssertionFailure.php';
spl_autoload_register(static function (string $class): void {
	$prefix = 'ThriveData\\ThrivePHP\\Test\\';
	if (str_starts_with($class, $prefix)) {
		require dirname(__DIR__, 2) . '/src/Test/' . substr($class, strlen($prefix)) . '.php';
	}
});

use ThriveData\ThrivePHP\Test;

define('PATH_ROOT', dirname(__DIR__, 2));

/** Run real check files with supplied bodies, capturing the runner's report. Supports nested runs. */
function runChecks(array $checks, ?Test $test = null, ?string $browser = null, bool $headless = false): array
{
	$previous = $GLOBALS['runnerChecks'] ?? null;
	$GLOBALS['runnerChecks'] = $checks;
	$test ??= new class(PATH_ROOT, '/test/fixtures/test-checks') extends Test {
		protected function resolveBrowser(?string $browser): ?string { return $browser ?? 'firefox'; }
	};
	ob_start();
	try {
		$status = $test->run(array_keys($checks), workers: 1, browser: $browser, headless: $headless);
		return [$status, ob_get_contents()];
	} finally {
		ob_end_clean();
		$GLOBALS['runnerChecks'] = $previous;
	}
}
