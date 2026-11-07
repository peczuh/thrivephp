<?php

require_once dirname(__DIR__, 2) . '/src/Test.php';
require_once dirname(__DIR__, 2) . '/src/Test/AssertionFailure.php';
spl_autoload_register(static function (string $class): void {
	$prefix = 'ThriveData\\ThrivePHP\\';
	if (str_starts_with($class, $prefix)) {
		require dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	}
});

use ThriveData\ThrivePHP\Test;

define('PATH_ROOT', dirname(__DIR__, 2));

/** Isolate fixture paths and artifacts so fast repeated runs do not collide by timestamp. */
class FixtureTest extends Test
{
	public string $saved = '';
	public function __construct(private string $root, private string $cases = '/test/fixtures/test-checks')
	{
		parent::__construct($root);
	}
	protected function casePath(): string { return $this->root . $this->cases; }
	protected function resolveBrowser(?string $browser): ?string { return $browser ?? 'firefox'; }
	protected function savePath(): string
	{
		$this->saved = sys_get_temp_dir() . '/thrive-report-' . bin2hex(random_bytes(8));
		register_shutdown_function(static function (string $path): void {
			if (!is_dir($path)) return;
			foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
				$entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
			}
			rmdir($path);
		}, $this->saved);
		return $this->saved;
	}
}

/** Run real check files with supplied bodies, capturing the runner's report. Supports nested runs. */
function runChecks(array $checks, ?Test $test = null, ?string $browser = null, bool $headless = false,
	array $selectors = []): array
{
	$previous = $GLOBALS['runnerChecks'] ?? null;
	$GLOBALS['runnerChecks'] = $checks;
	$test ??= new FixtureTest(PATH_ROOT);
	ob_start();
	try {
		$status = $test->run(array_keys($checks), workers: 1, browser: $browser, headless: $headless,
			selectors: $selectors);
		$reports = $test instanceof FixtureTest ? glob($test->saved . '/*/report.json') : [];
		return [$status, ob_get_contents(), $reports ? json_decode(file_get_contents($reports[0]), true, flags: JSON_THROW_ON_ERROR) : null];
	} finally {
		ob_end_clean();
		$GLOBALS['runnerChecks'] = $previous;
	}
}
