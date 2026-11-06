<?php

// Run with: php test/test-suite.php.
require __DIR__ . '/fixtures/test-runner.php';

use ThriveData\ThrivePHP\Test;
use ThriveData\ThrivePHP\Test\Suite;
use ThriveData\ThrivePHP\Test\SuiteConfig;
use ThriveData\ThrivePHP\Test\CaseName;
use ThriveData\ThrivePHP\Test\SuiteBefore;
use ThriveData\ThrivePHP\Test\SuiteAfter;
use ThriveData\ThrivePHP\Test\CaseBefore;
use ThriveData\ThrivePHP\Test\CaseAfter;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

trait ZuluHooks
{
    #[SuiteBefore]
    public function traitZulu(): void { $this->trace[] = 'trait zulu'; }
}

trait AlphaHooks
{
    #[SuiteBefore]
    public function traitAlpha(): void { $this->trace[] = 'trait alpha'; }
}

abstract class BaseSuite extends Suite
{
    public array $trace = [];

    #[SuiteBefore]
    public function baseStart(): void { $this->trace[] = 'base start'; }

    #[SuiteAfter]
    public function baseStop(): void { $this->trace[] = 'base stop'; }

    #[CaseBefore]
    public function basePrepare(): void { $this->trace[] = 'base prepare'; }

    #[CaseAfter]
    public function baseClean(): void { $this->trace[] = 'base clean'; }

    #[CaseName('Inherited case')]
    public function inherited(): void { $this->trace[] = 'base case'; $this->test->assert(true, 'base'); }

    #[CaseName('Removed by an unannotated override')]
    public function removed(): void { throw new RuntimeException('parent override ran'); }

    #[CaseName('Replaced by an annotated override')]
    public function replaced(): void { throw new RuntimeException('parent replacement ran'); }
}

$suite = null;
[$status, $output] = runChecks(['suite' => function (Test $test) use (&$suite) {
    return $suite = new class($test) extends BaseSuite {
        use ZuluHooks, AlphaHooks;

        #[SuiteBefore]
        public function zStart(): void { $this->trace[] = 'child start z'; }

        #[SuiteBefore]
        public function aStart(): void { $this->trace[] = 'child start a'; }

        #[SuiteAfter]
        public function childStop(): void { $this->trace[] = 'child stop'; }

        #[CaseBefore]
        public function childPrepare(): void { $this->trace[] = 'child prepare'; }

        #[CaseAfter]
        public function childClean(): void { $this->trace[] = 'child clean'; }

        public function removed(): void { throw new RuntimeException('unannotated override ran'); }

        #[CaseName('Child replacement')]
        public function replaced(): void { $this->trace[] = 'child replacement'; $this->test->assert(true, 'replacement'); }

        #[CaseName('First declared child case')]
        public function zCase(): void { $this->trace[] = 'child z'; $this->test->assert(Test::active() === $this->test, 'active'); }

        #[CaseName('Second declared child case')]
        public function aCase(): void { $this->trace[] = 'child a'; $this->test->assert(true, 'a'); }
    };
}]);
$expected = ['base start', 'child start z', 'child start a', 'trait alpha', 'trait zulu'];
foreach (['base case', 'child replacement', 'child z', 'child a'] as $case) {
    array_push($expected, 'base prepare', 'child prepare', $case, 'child clean', 'base clean');
}
array_push($expected, 'child stop', 'base stop');
check($status === 0 && $suite->trace === $expected, $output . json_encode($suite->trace));
check(str_contains($output, 'Cases: 4 passed, 0 failed, 0 skipped') && str_contains($output, 'Assertions: 4 passed, 0 failed'), $output);
check(!str_contains($output, 'Removed by') && Test::active() === null, $output);
echo "PASS: inherited hooks, source-ordered cases, same-file traits and overrides execute in their defined order\n";

trait SharedCases
{
    #[CaseName('Original and alias execute once')]
    public function shared(): void { $this->trace[] = __FUNCTION__; $this->test->assert(true, 'shared'); }

    #[CaseName('Public alias of a protected method')]
    protected function hidden(): void { $this->trace[] = __FUNCTION__; $this->test->assert(true, 'hidden'); }

    #[CaseName('First same-line method')] public function lineFirst(): void { $this->test->assert(true, 'first'); } #[CaseName('Second same-line method')] public function lineSecond(): void { $this->test->assert(true, 'second'); }
}

trait NestedCases
{
    use SharedCases { shared as nestedAlias; hidden as public zHidden; hidden as public aHidden; }
}

trait FirstConflict
{
    #[CaseName('First trait implementation')]
    public function conflict(): void { $this->test->assert(true, 'first conflict'); }
}

trait SecondConflict
{
    #[CaseName('Second trait implementation')]
    public function conflict(): void { $this->test->assert(true, 'second conflict'); }
}

[$status, $output] = runChecks(['suite' => function (Test $test) {
    return new class($test) extends Suite {
        use NestedCases, FirstConflict, SecondConflict {
            FirstConflict::conflict insteadof SecondConflict;
            SecondConflict::conflict as secondConflict;
            NestedCases::nestedAlias as anotherAlias;
        }
        public array $trace = [];
    };
}]);
check($status === 0 && str_contains($output, 'Cases: 6 passed, 0 failed, 0 skipped'), $output);
check(substr_count($output, 'Original and alias execute once') === 1 && substr_count($output, 'Public alias of a protected method') === 1, $output);
check(str_contains($output, 'First trait implementation') && str_contains($output, 'Second trait implementation'), $output);
echo "PASS: nested traits, aliases, visibility changes, conflict resolution and distinct same-line methods\n";

trait CompactFirst { #[CaseName('compact first')] public function compact(): void {} } trait CompactSecond { #[CaseName('compact second')] public function compact(): void {} }

[$status, $output] = runChecks(['suite' => function (Test $test) {
    return new class($test) extends Suite {
        use CompactFirst, CompactSecond {
            CompactSecond::compact insteadof CompactFirst;
            CompactFirst::compact as firstCompact;
        }
    };
}]);
check($status === 1 && str_contains($output, 'Ambiguous trait method origin')
    && str_contains($output, 'CompactFirst::compact') && str_contains($output, 'CompactSecond::compact'), $output);
echo "PASS: ambiguous same-name trait declarations fail discovery instead of silently losing a case\n";

trait OverlapSource { #[CaseName('trait original')] public function same(): void {} } class OverlapConsumer extends Suite { use OverlapSource { same as alias; } #[CaseName('class override')] public function same(): void {} }

[$status, $output] = runChecks(['suite' => fn(Test $test) => new OverlapConsumer($test)]);
check($status === 1 && str_contains($output, 'consuming class and trait method share source lines'), $output);

trait HelperFirst { public function helper(): void {} } trait HelperSecond { public function helper(): void {} }

[$status, $output] = runChecks(['suite' => function (Test $test) {
    return new class($test) extends Suite {
        use HelperFirst, HelperSecond { HelperSecond::helper insteadof HelperFirst; }
        #[CaseName('Unannotated helpers are ignored')]
        public function body(): void { $this->helper(); $this->test->assert(true, 'helper is callable'); }
    };
}]);
check($status === 0, $output);
echo "PASS: overlapping annotated declarations are rejected while unannotated helpers remain ordinary PHP methods\n";

trait AliasedHooks
{
    #[SuiteBefore]
    public function originalHook(): void { $this->test->verify(false, 'original hook once'); }

    #[SuiteBefore]
    protected function protectedHook(): void { $this->test->verify(false, 'public alias once'); }
}

[$status, $output] = runChecks(['suite' => function (Test $test) {
    return new class($test) extends Suite {
        use AliasedHooks {
            originalHook as aOriginalAlias;
            protectedHook as public zPublicAlias;
            protectedHook as public aPublicAlias;
        }

        #[CaseName('Case after verifying setup')]
        public function body(): void { $this->test->assert(true, 'case ran'); }
    };
}]);
check($status === 1 && str_contains($output, 'PASS suite > Case after verifying setup')
    && str_contains($output, 'SuiteBefore originalHook: original hook once')
    && str_contains($output, 'SuiteBefore aPublicAlias: public alias once')
    && str_contains($output, 'Assertions: 1 passed, 2 failed'), $output);
echo "PASS: alias deduplication prefers an eligible original, otherwise the first public alias by name\n";

foreach (['suite setup', 'case setup', 'case body', 'case cleanup', 'suite cleanup', 'verify', 'empty'] as $mode) {
    $suite = null;
    [$status, $output] = runChecks([
        'suite' => function (Test $test) use (&$suite, $mode) {
            $suite = new class($test) extends Suite {
                public string $mode;
                public array $trace = [];
                private int $case = 0;

                #[SuiteBefore]
                public function setup(): void
                {
                    $this->trace[] = 'suite setup';
                    if ($this->mode === 'suite setup') $this->test->assert(false, 'setup failed');
                }

                #[SuiteBefore]
                public function laterSetup(): void { $this->trace[] = 'later setup'; }

                #[CaseBefore]
                public function prepare(): void
                {
                    $this->trace[] = 'prepare ' . ++$this->case;
                    if ($this->mode === 'case setup' && $this->case === 1) throw new RuntimeException('prepare failed');
                }

                #[CaseAfter]
                public function clean(): void
                {
                    $this->trace[] = 'clean ' . $this->case;
                    if ($this->mode === 'case cleanup' && $this->case === 1) $this->test->assert(false, 'cleanup failed');
                }

                #[CaseAfter]
                public function laterClean(): void { $this->trace[] = 'later clean ' . $this->case; }

                #[CaseName('First case')]
                public function first(): void
                {
                    $this->trace[] = 'first';
                    if ($this->mode === 'empty') return;
                    if ($this->mode === 'case body') $this->test->assert(false, 'body failed');
                    if ($this->mode === 'verify') $this->test->verify(false, 'verification failed');
                    $this->test->assert(true, 'first passed');
                    $this->trace[] = 'continued';
                }

                #[CaseName('Second case')]
                public function second(): void { $this->trace[] = 'second'; $this->test->assert(true, 'second passed'); }

                #[SuiteAfter]
                public function stop(): void
                {
                    $this->trace[] = 'suite cleanup';
                    if ($this->mode === 'suite cleanup') throw new RuntimeException('stop failed');
                }

                #[SuiteAfter]
                public function laterStop(): void { $this->trace[] = 'later stop'; }
            };
            $suite->mode = $mode;
            return $suite;
        },
        'next' => function (Test $test) { $test->assert(true, 'legacy check still runs'); },
    ]);
    check($status === 1 && str_contains($output, 'PASS next') && Test::active() === null, $output);
    check(array_slice($suite->trace, -2) === ['suite cleanup', 'later stop'], json_encode($suite->trace));
    if ($mode === 'suite setup') {
        check($suite->trace === ['suite setup', 'suite cleanup', 'later stop'], json_encode($suite->trace));
        check(str_contains($output, 'Cases: 0 passed, 0 failed, 2 skipped') && substr_count($output, 'setup failed') === 3, $output);
    } else {
        check(in_array('second', $suite->trace, true) && in_array('later clean 1', $suite->trace, true), $output);
        check(str_contains($output, $mode === 'suite cleanup' ? 'Cases: 2 passed, 0 failed, 0 skipped' : 'Cases: 1 passed, 1 failed, 0 skipped'), $output);
    }
    if ($mode === 'case setup') check(!in_array('first', $suite->trace, true), $output);
    if ($mode === 'case body') check(!in_array('continued', $suite->trace, true) && substr_count($output, 'body failed') === 1, $output);
    if ($mode === 'verify') check(in_array('continued', $suite->trace, true) && str_contains($output, 'Assertions: 3 passed, 1 failed'), $output);
    if ($mode === 'empty') check(str_contains($output, 'No assertions ran.'), $output);
}
echo "PASS: stopping failures, continuing verification, skipped cases, independent cleanup and mixed legacy checks\n";

[$status, $output] = runChecks(['suite' => function (Test $test) {
    return new class($test) extends Suite {
        #[SuiteBefore]
        public function start(): void { $this->test->verify(false, 'suite verification'); }

        #[CaseBefore]
        public function prepare(): void { $this->test->assert(true, 'case hook assertion'); }

        #[CaseName('Case uses its hook assertion')]
        public function body(): void {}

        #[CaseAfter]
        public function clean(): void { $this->test->assert(false, 'case cleanup assertion'); }

        #[CaseAfter]
        public function laterClean(): void { throw new RuntimeException('additional case cleanup error'); }

        #[SuiteAfter]
        public function stop(): void { $this->test->assert(false, 'suite cleanup assertion'); }

        #[SuiteAfter]
        public function laterStop(): void { $this->test->assert(true, 'later suite cleanup'); }
    };
}]);
check($status === 1 && str_contains($output, 'Assertions: 2 passed, 3 failed'), $output);
foreach (['suite verification', 'case cleanup assertion', 'additional case cleanup error', 'suite cleanup assertion'] as $message) {
    check(substr_count($output, $message) === 1, $output);
}
check(str_contains($output, 'SuiteBefore start:') && str_contains($output, 'SuiteAfter stop:') && str_contains($output, 'CaseAfter clean:'), $output);
echo "PASS: suite and case hook assertions are attributed separately and cleanup errors accumulate\n";

[$status, $output] = runChecks(['suite' => function (Test $test) {
    return new class($test) extends Suite {
        #[CaseName('Nested suite restores its caller')]
        public function nested(): void
        {
            $outer = $this->test;
            [$status, $output] = runChecks(['suite' => function (Test $test) {
                return new class($test) extends Suite {
                    #[CaseName('Inner failure')]
                    public function fail(): void { $this->test->assert(false, 'inner failure'); }
                };
            }]);
            $this->test->assert($status === 1 && str_contains($output, 'Assertions: 0 passed, 1 failed'), 'Inner suite reported its failure.');
            $this->test->assert(Test::active() === $outer, 'Outer recorder restored.');
        }
    };
}]);
check($status === 0 && str_contains($output, 'Assertions: 2 passed, 0 failed') && Test::active() === null, $output);
echo "PASS: nested suite runs restore the active recorder and isolate assertion totals\n";

[$status, $output] = runChecks(['suite' => function (Test $test) {
    return new class($test) extends Suite {};
}]);
check($status === 1 && str_contains($output, 'No cases found.'), $output);
[$status, $output] = runChecks(['suite' => function () { throw new RuntimeException('suite load failed'); }]);
check($status === 1 && str_contains($output, 'Execution error: RuntimeException: suite load failed') && Test::active() === null, $output);
echo "PASS: empty suites and loading failures produce failing reports without leaking a recorder\n";

$config = new SuiteConfig();
check($config->browser === 'firefox' && !$config->headless && $config->timeout === 10.0 && $config->capabilities === [], 'Incorrect defaults.');
$config->browser = 'safari';
check((new SuiteConfig())->browser === 'firefox', 'Configuration leaked between suites.');
try {
    $config->browswer = 'safari';
    throw new LogicException('Unknown config property was accepted.');
} catch (InvalidArgumentException $error) {
    check(str_contains($error->getMessage(), 'browswer'), $error->getMessage());
}
echo "PASS: SuiteConfig supplies fresh typed defaults and rejects misspelled settings\n";
