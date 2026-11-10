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

class ComparisonParent
{
	public function __construct(private mixed $parent) {}
}

class ComparisonValue extends ComparisonParent implements JsonSerializable
{
	public function __construct(private mixed $value, mixed $parent = null) { parent::__construct($parent); }
	public int $virtual { get { throw new LogicException('Comparison invoked a property hook.'); } }
	public function jsonSerialize(): mixed { throw new LogicException('Comparison invoked serialization.'); }
}

class ComparisonNullable
{
	public ?string $value;
}

$object = (object)['name' => 'Example'];
$uninitialized = new ComparisonNullable();
$initialized = new ComparisonNullable();
$initialized->value = null;
$date = new DateTimeImmutable('2026-01-01');
$internalChild = new class('2026-01-01') extends DateTimeImmutable {};
foreach ([
	[1, 1, true, true],
	[1, '1', false, false],
	[1, 1.0, false, false],
	[null, null, true, true],
	[false, 0, false, false],
	[NAN, NAN, false, false],
	[INF, INF, true, true],
	[[1, '2'], [1, '2'], true, true],
	[[1, 2], [2, 1], false, false],
	[['a' => 1, 'b' => null], ['b' => null, 'a' => 1], false, true],
	[['a' => null], ['b' => null], false, false],
	[[0 => 'a', 1 => 'b'], [1 => 'b', 0 => 'a'], false, false],
	[['rows' => [[1, '2']]], ['rows' => [[1, 2]]], false, false],
	[$object, $object, true, true],
	[$object, (object)['name' => 'Example'], false, true],
	[(object)['a' => 1, 'b' => [2]], (object)['b' => [2], 'a' => 1], false, true],
	[(object)['0' => 'a', '1' => 'b'], (object)['1' => 'b', '0' => 'a'], false, true],
	[(object)[], [], false, false],
	[(object)['a' => null], (object)[], false, false],
	[new ComparisonValue(['nested' => $object]), new ComparisonValue(['nested' => clone $object]), false, true],
	[new ComparisonValue(1), new ComparisonValue('1'), false, false],
	[new ComparisonValue(1, 'parent'), new ComparisonValue(1, 'different'), false, false],
	[new ComparisonValue(null), new ComparisonParent(null), false, false],
	[$uninitialized, $initialized, false, false],
	[$date, clone $date, false, false],
	[$date, $date, true, true],
	[$internalChild, clone $internalChild, false, false],
] as [$actual, $expected, $equal, $same]) {
	foreach (['equal' => $equal, 'same' => $same] as $method => $matches) {
		check($test->$method($actual, $expected) === $matches, $method . ' comparison semantics changed.');
		check($test->$method($actual, $expected, is: false) === !$matches, $method . ' negation changed.');
	}
}
$cycle = [];
$cycle['self'] = &$cycle;
$otherCycle = [];
$otherCycle['self'] = &$otherCycle;
try {
	$test->equal($cycle, $otherCycle);
	throw new LogicException('equal suppressed PHP recursive-array behavior.');
} catch (Error) {}
foreach ([[$cycle, $otherCycle], [(object)['child' => &$cycle], (object)['child' => &$otherCycle]]] as [$actual, $expected]) {
	try {
		$test->same($actual, $expected);
		throw new LogicException('same accepted a recursive structure.');
	} catch (InvalidArgumentException $error) {
		check(str_contains($error->getMessage(), 'maximum 64 levels'), $error->getMessage());
	}
}
echo "PASS: equal uses PHP identity; same compares typed lists, maps and stored object properties\n";

[$status, $output, $record] = runChecks(['check' => function (Test $test) {
	check($test->equal(1, 1) === true && $test->equal(1, '1') === false, 'equal query changed.');
	check($test->same((object)['x' => [1]], (object)['x' => [1]]) === true, 'same query changed.');
	check($test->same([1], ['1'], is: false, stop: false) === true, 'Negated query changed.');
}]);
check($status === 0 && $record['totals']['assertionsPassed'] === 0 && $record['totals']['assertionsFailed'] === 0
	&& $record['suites'][0]['attempts'][0]['steps'] === [], $output);
echo "PASS: value comparison queries create no steps or assertions\n";

foreach (['equal', 'same'] as $method) {
	$continued = false;
	[$status, $output, $record] = runChecks(['check' => function (Test $test) use ($method, &$continued) {
		check($test->$method(1, 1, assert: true, message: 'matching value') === $test, 'Assertion lost fluent return.');
		check($test->$method(1, '1', assert: true, stop: false, message: 'typed mismatch') === $test, 'Continuing comparison lost fluent return.');
		$test->$method(1, '1', is: false, assert: true, message: 'negated match');
		$test->$method(1, 1, is: false, assert: true, message: 'negated mismatch');
		$continued = true;
	}]);
	$steps = $record['suites'][0]['attempts'][0]['steps'];
	check($status === 1 && !$continued && $record['totals']['assertionsPassed'] === 2 && $record['totals']['assertionsFailed'] === 2
		&& count($steps) === 4 && array_column($steps, 'label') === array_fill(0, 4, $method), $output);
	foreach ($steps as $step) check(count($step['assertions']) === 1 && $step['location']['file'] === 'test/test.php', 'Comparison recorded twice or lost author location.');
	check($steps[1]['assertions'][0]['expected'] === '1' && $steps[1]['assertions'][0]['actual'] === 1
		&& $steps[1]['assertions'][0]['message'] === 'typed mismatch' && $steps[3]['assertions'][0]['invert'] === true, 'Comparison details were lost.');
}
echo "PASS: comparison assertions record once, retain values/messages, negate and stop or continue\n";

[$status, $output, $record] = runChecks(['check' => function (Test $test) {
	$resource = fopen('php://memory', 'r+');
	try {
		$test->equal(INF, 0, assert: true, stop: false);
		$test->same(['number' => NAN], ['number' => null], assert: true, stop: false);
		$test->equal($resource, null, assert: true, stop: false);
		$test->same(new ComparisonValue(1), new ComparisonValue(2), assert: true, stop: false);
		$test->recordStep('outer', null, [], fn () => $test->same([1], [1], assert: true));
	} finally {
		fclose($resource);
	}
	$test->equal($resource, null, assert: true, stop: false);
}]);
$steps = $record['suites'][0]['attempts'][0]['steps'];
check($status === 1 && count($steps) === 6 && $record['totals']['assertionsFailed'] === 5
	&& $record['totals']['assertionsPassed'] === 1, $output);
check($steps[0]['assertions'][0]['actual'] === ['type' => 'float', 'value' => 'INF']
	&& $steps[1]['assertions'][0]['actual']['number']['value'] === 'NAN'
	&& $steps[2]['assertions'][0]['actual'] === 'stream'
	&& $steps[3]['assertions'][0]['actual'] === ['class' => ComparisonValue::class]
	&& $steps[4]['label'] === 'outer' && $steps[5]['assertions'][0]['actual'] === 'resource (closed)',
	'Comparison serialization or nested recording changed.');
echo "PASS: comparisons preserve JSON-safe diagnostics and nested operation recording\n";
