<?php

// Run with: php test/test.php.
require __DIR__ . '/fixtures/test-runner.php';

use ThriveData\ThrivePHP\Test;
use ThriveData\ThrivePHP\Test\AssertionFailure;

function check(bool $condition, string $message): void
{
	if (!$condition) throw new RuntimeException($message);
}

check(Test::active() === null, 'A recorder was active before a run.');
$test = new FixtureTest(PATH_ROOT);
$continued = false;
$cleaned = false;
$next = false;
[$status, $output] = runChecks([
	'check' => function (Test $test) use (&$continued, &$cleaned) {
		check(Test::active() === $test, 'The check received a different recorder.');
		try {
			check($test->assert(true, 'success') === true, 'Successful assert lost its return value.');
			$test->assert(false, 'stopping failure');
			$continued = true;
		} finally {
			$cleaned = true;
			check(Test::active() === $test, 'Cleanup lost its active recorder.');
		}
	},
	'next' => function (Test $test) use (&$next) {
		$next = true;
		$test->assert(Test::active() === $test, 'Subsequent check has the active recorder.');
	},
], $test);
check($status === 1 && !$continued && $cleaned && $next, 'Assert did not stop only its check and run cleanup.');
check(str_contains($output, 'stopping failure') && str_contains($output, 'PASS  SUITE next')
	&& str_contains($output, 'Suites: 1 passed, 1 failed, 0 skipped') && str_contains($output, 'Assertions: 2 passed, 1 failed'), $output);
check(Test::active() === null, 'An assertion failure leaked the active recorder.');
echo "PASS: assert stops its check, reports once, runs cleanup and proceeds to the next check\n";

[$status, $output] = runChecks(['check' => function (Test $test) {
	check($test->verify(false, 'first failure') === false, 'verify did not return false.');
	check($test->verify(true, 'success') === true, 'verify did not return true.');
	$test->verify(false, 'second failure');
	$test->assert(true, 'continued');
}], $test);
check($status === 1 && str_contains($output, 'first failure') && str_contains($output, 'second failure')
	&& str_contains($output, 'Suites: 0 passed, 1 failed, 0 skipped') && str_contains($output, 'Assertions: 2 passed, 2 failed'), $output);
echo "PASS: verify accumulates failures and continues; repeated runs reset counts\n";

foreach (['success', 'assert', 'exception', 'empty'] as $mode) {
	[$status, $output] = runChecks(['check' => function (Test $outer) use ($mode) {
		$inner = new FixtureTest(PATH_ROOT);
		[$status, $output] = runChecks(['check' => function (Test $current) use ($inner, $mode) {
			check($current === $inner && Test::active() === $inner, 'Nested run did not register itself.');
			if ($mode === 'exception') throw new RuntimeException('unexpected failure');
			if ($mode !== 'empty') $current->assert($mode === 'success', 'inner assertion');
		}], $inner);
		check($status === (in_array($mode, ['success', 'empty'], true) ? 0 : 1), $output);
		if ($mode === 'exception') check(str_contains($output, 'Execution error: RuntimeException: unexpected failure'), $output);
		if ($mode === 'empty') check(str_contains($output, 'PASS  SUITE check (No assertions)') && str_contains($output, 'Assertions: 0 passed, 0 failed'), $output);
		$outer->assert(Test::active() === $outer, 'Nested run restored the outer recorder.');
	}]);
	check($status === 0 && str_contains($output, 'Assertions: 1 passed, 0 failed'), $output);
	check(Test::active() === null, 'Nested run leaked the active recorder.');
}
echo "PASS: nested runs restore the prior recorder after success, assertion failure, exception and empty checks\n";

$handler = static function () { return false; };
set_error_handler($handler);
try {
	[$status, $output] = runChecks([
		'check' => function () { trigger_error('fixture warning', E_USER_WARNING); },
		'next' => function (Test $test) { $test->assert(true, 'after unexpected exception'); },
	]);
	check($status === 1 && str_contains($output, 'Execution error: ErrorException: fixture warning') && str_contains($output, 'PASS  SUITE next'), $output);
	$restored = set_error_handler($handler);
	restore_error_handler();
	check($restored === $handler && Test::active() === null, 'Exception did not restore handlers and recorder.');
} finally {
	restore_error_handler();
}
echo "PASS: unexpected exceptions remain execution errors and restore the error handler\n";

try {
	$test->assert(false, 'outside run');
	throw new LogicException('assert did not throw outside run.');
} catch (AssertionFailure $error) {
	check($error->getMessage() === 'outside run', 'Assertion exception lost the message.');
}
[$status, $output] = runChecks(['check' => function (Test $test) {
	try {
		$test->assert(false, 'recorded failure');
	} finally {
		throw new RuntimeException('cleanup failure');
	}
}]);
check($status === 1 && str_contains($output, 'recorded failure') && str_contains($output, 'Assertions: 0 passed, 1 failed')
	&& str_contains($output, 'Execution error: RuntimeException: cleanup failure'), $output);
echo "PASS: assertion exceptions carry diagnostics and cleanup errors remain visible\n";
