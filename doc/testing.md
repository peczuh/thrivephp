# Test suites

Requires PHP 8.5 or later. `ThriveData\ThrivePHP\Test` runs PHP files below `/test/checks` by default. Each
file receives the runner as `$test` and returns either a `Test\Suite` or an
existing callable check. A file's relative path, without `.php`, is its name.

```php
require __DIR__ . '/../vendor/autoload.php';

$test = new \ThriveData\ThrivePHP\Test(dirname(__DIR__));
$test->bootstrap();
exit($test->run());
```

Pass names to `run(['layouts', 'login'])` to select files. A nonzero return value
means at least one file failed. Callable checks continue to receive the runner
as their argument and report one result per file.

## Selecting files, cases, and tags

Pass one or more regex selectors to `run()`:

```php
$test->run(selectors: [
    'folderA/(folder1|folder2)!!/archive/::^case:!Deprecated$##^estimates#!^slow$',
    '/^login$::^validCredentials$',
]);
```

The grammar is:

```text
[files-include][!!files-exclude][::cases-include][:!cases-exclude][##tags-include][#!tags-exclude]
```

| Opening delimiter | Target |
| --- | --- |
| Initial text | Include file paths. Strip one leading `/` if present. |
| `!!` | Exclude file paths. |
| `::` | Include PHP case method names. |
| `:!` | Exclude PHP case method names. |
| `##` | Include cases with any matching tag. |
| `#!` | Exclude cases with any matching tag. |

File regexes match the complete path relative to the checks directory, without
`.php`, using `/` separators on every platform. The optional initial `/` is only
selector syntax, not an absolute-path indicator or anchor. There is no closing
slash delimiter; every subsequent slash is regex text. Case regexes match PHP
method names, independently of their `#[Test('description')]` text. Effective
tags combine the concrete suite's tags and each method's tags.

All components are optional and follow the order above. Within a selector, all
supplied conditions must hold. Selectors combine with OR, and overlapping
selectors execute each case once in its usual order. Exclusions are local to
their selector, so another selector may include a case that the first excludes.
To exclude `slow` across alternatives, include `#!^slow$` in each one.

Each delimiter opens its component once. The parser splits the first `#!`, then
the first `##` in the remaining prefix, and continues backward through `:!`,
`::`, and `!!`. Repeated delimiters and earlier delimiters inside a later
component remain regex text. For example, `file::case::variant##tag##suffix`
has file regex `file`, case regex `case::variant`, and tag regex `tag##suffix`.
A later component's opening delimiter inside an earlier regex is structural,
even inside a character class or `\Q...\E`. Encode literal delimiters there,
for example `\x23\x23` for `##`.

Components are raw PCRE regexes, without surrounding regex delimiters. Matching
is unanchored and case-sensitive unless the pattern says otherwise. Use `^` and
`$` for exact matching and inline options such as `(?i)` for case-insensitivity.

```php
$test->run(selectors: ['^folderA/']);          // All files below folderA.
$test->run(selectors: ['::^create']);          // Method names starting with create.
$test->run(selectors: ['##^tag#!^tag1$']);     // A tag starting with tag, but no tag1.
$test->run(selectors: ['#!^slow$']);          // Also permits untagged cases.
```

`selectors: []` imposes no restriction. An empty selector or explicitly empty
component is an error; omit a component or use `.*` deliberately. The runner
validates every regex before loading files and reports runtime regex errors
rather than treating them as nonmatches. A valid selection matching nothing
succeeds with zero checks. The existing `names` argument retains exact-file
selection and intersects with selectors when both are supplied.

File filtering happens before loading. Case/tag filtering requires loading the
file and constructing its suite, but happens before `configure()` or lifecycle
hooks, so a fully filtered suite starts no browser session. Filtered tests do
not contribute results or counts. Callable checks have no method name or tags;
positive case/tag filters exclude them, while negative-only filters permit them.
Tag declarations remain on `#[Test(..., tags: [...])]` and must be nonempty
strings. Run-level tag filtering uses selectors only.

## Execution and browser selection

```php
exit($test->run(
    names: ['layouts', 'login'],
    workers: 4,
    browser: 'firefox',
    headless: true,
));
```

`workers: null`, the default, uses up to two concurrent workers, capped by the
number of selected files. Each file runs in a fresh PHP process. Its cases and
hooks execute sequentially. `workers: 1` runs in the parent process, which also
supports checks that depend on parent-only globals or closures. Positive explicit
worker counts above one select parallel execution even for a single file.

The parent resolves the browser before starting parallel work. Automatic selection
uses the first ready browser/driver pair in this order: Safari Technology Preview,
Safari, Chrome, Chromium, Firefox. It does not skip Safari to obtain parallelism.
Safari and Preview make the default execution mode serial; explicitly requesting
more than one worker with either browser fails before any checks run.

`browser` and `headless` apply to all managed `SuiteBrowser` sessions in the run.
Explicit unavailable browsers fail; headless Safari/Preview fails without switching
browsers. Headless defaults to false. Checks that need no browser can run without
an installed pair. Managed browser setup then fails if no pair is available.
In explicit serial mode, automatic browser discovery waits until a managed suite
needs it. Suites needing a different browser must create and clean up that browser
themselves.

Workers use `PHP_BINARY`, the parent's ini-file loading and effective ini-setting
overrides, and inherit its environment and working directory. They do not take a
separately configured worker ini file. Configure extensions in the shared PHP ini
files. Workers load `<project root>/vendor/autoload.php`. If the parent called
`$test->bootstrap()`, each worker also repeats application initialization. Put
required application state in that initialization or suite hooks. Arbitrary parent
globals, closures, objects, and entry-script statements are not copied to workers.

Each worker has null stdin and inherits stdout/stderr. Incidental diagnostic
output may interleave. Structured progress and results use a dedicated loopback
TCP connection with newline-delimited JSON; they do not share the output streams.
The parent prints suite-labeled case results as they arrive, then combined totals.
Completion order can differ from the requested scheduling order.

## Timing and recovery

```php
#[Test('The report completes', deadline: 5)]
public function report(): void
{
    // The body completes before the runner checks its elapsed time.
    $this->test->assert($this->buildReport(), 'Report built');
}

protected function configure(SuiteConfig $config): void
{
    $config->idleTimeout = 120;
}
```

On a case method, `Test::$deadline` is nullable and defaults to no deadline. A positive
finite value sets a case-body limit in seconds, measured with a monotonic clock. Hooks
are excluded. The worker checks the elapsed time after the body returns, records
a case failure if it exceeded the limit, and continues cleanup and subsequent
cases. It does not interrupt a running body. Deadline failures do not add a failed
assertion to the assertion count.

`SuiteConfig::$idleTimeout` is also nullable with no timeout by default. In parallel
mode, a positive finite value limits seconds without structured worker progress.
Hook boundaries, case boundaries, and assertions reset it; incidental stdout/stderr
does not. A suite can exceed this duration overall while continuing to make
progress. This timeout starts when the worker reports its suite configuration,
so application loading and `configure()` are not covered. In-process serial runs
cannot enforce worker inactivity recovery. Browser command timeout remains a
separate setting.

Workers have a hardcoded 60-second connection timeout. On a crash, lost connection,
protocol failure, or inactivity timeout, the parent retains completed case results
and reported assertions, fails an active case, skips remaining discovered cases,
and marks the suite incomplete. Other suites continue. A final result must agree
with the worker's exit status. A crash before case discovery can only report a
failed file, because the parent does not know its cases.

Recovery attempts to terminate the whole worker tree and reap the worker, including
surviving descendants after ordinary completion. Unix workers create a separate
session when POSIX is available; the parent also tracks observed descendants and
escalates termination to a forced kill. With PCNTL available, parent interruption
uses the same cleanup path. Windows uses a PowerShell supervisor and a kill-on-close
Job Object. Cleanup is best-effort: detached Unix children that escape observation,
an uncatchable parent termination, or OS termination failures can leave processes
behind. Cleanup failures are reported.

## Defining a suite

Suite files return an anonymous class. `Suite` supplies the constructor and the
public `$test` property; individual suites do not need their own constructors.

```php
use ThriveData\ThrivePHP\Test\Suite;
use ThriveData\ThrivePHP\Test\Test;
use ThriveData\ThrivePHP\Test\CaseBefore;

return new class($test) extends Suite {
    private array $items = [];

    #[CaseBefore]
    public function prepare(): void
    {
        $this->items = ['first'];
    }

    #[Test('The initial collection contains one item')]
    public function initialCount(): void
    {
        $this->test->assert(count($this->items) === 1, 'Expected one initial item.');
    }
};
```

Annotated methods must be public and callable without arguments. Unannotated
methods are helpers. `Test` supplies the reporting description independently of the
method's PHP name. The same suite object holds shared state throughout its run.

### Suite and case metadata

`ThriveData\ThrivePHP\Test\Test` replaces the former `CaseName` attribute. It can
annotate a suite class as well as a case method:

```php
use ThriveData\ThrivePHP\Test\SuiteBrowser;
use ThriveData\ThrivePHP\Test\Test;

return new #[Test('Estimates', retry: 1, tags: ['estimates'])] class($test) extends SuiteBrowser {
    #[Test('Creates an estimate', deadline: 10, retry: 2, tags: ['smoke'])]
    public function createEstimate(): void
    {
        $this->browser->find('#create-estimate')->click();
    }

    #[Test('Exports an estimate', skip: true)]
    public function exportEstimate(): void {}
};
```

| Argument | Default | Meaning |
| --- | --- | --- |
| `description` | Required | Human-readable suite or case description used in reports. |
| `deadline` | `null` | Positive finite seconds, checked after execution. |
| `retry` | `0` | Nonnegative number of additional attempts after failure. |
| `tags` | `[]` | Tags used for case selection. |
| `skip` | `false` | Report the selected suite or case as skipped. |

Class metadata is optional and applies to the concrete suite declaration; it
is not inherited from base classes. Without it, the suite label is its file name
and the attribute defaults apply. Method metadata follows the existing method
inheritance and override rules. Descriptions do not change file or method selection.
If a file imports both the runner and attribute, alias the runner with
`use ThriveData\ThrivePHP\Test as TestRunner;`.

### Skips

A skipped case runs neither its body nor its case hooks. A skipped suite, or one
whose selected cases are all skipped, runs no configuration or lifecycle hooks.
Selected skipped cases report `SKIP` with `marked skip`; a wholly skipped suite
also reports `SKIP` and contributes to skipped checks. Skips do not fail the run
or trigger retries. Selector filtering applies before skipping, so filtered-out cases
are omitted rather than reported as skipped.

### Retries

`retry: 1` permits two attempts. Stop retrying after the first successful attempt.
A method-level retry repeats `CaseBefore`, the case body, and `CaseAfter`, retaining
the suite object and browser session. Each attempt gets its own case-body deadline.
Failed assertions and verifications, exceptions, hook failures, and exceeded
deadlines can all cause a retry. Case hooks must prepare the state needed by each
attempt; retries do not undo application changes.

A class-level retry reruns the whole suite, including configuration, suite hooks,
and every selected case. It does not supply a default retry count for cases.
Case retries finish before deciding whether a suite needs another attempt.
Cleanup finishes before the next attempt starts. The runner reloads the check
file to construct a fresh suite with fresh configuration and, for `SuiteBrowser`,
a fresh browser session. Use anonymous suite classes as above, or keep named
classes in autoloaded files so reloading the factory does not redeclare them.
Retries run in the same PHP process; globals and static state are not reset.
Worker crashes and inactivity termination are not retried.

A class-level deadline measures the whole suite attempt, including configuration,
all case attempts, and teardown, but excluding file loading and construction. It
is checked after teardown and does not interrupt execution. A method-level deadline
continues to measure only that case body's attempt, excluding its hooks.

Reports show `RETRY` with the next attempt number and the failed attempt's
diagnostics. Results after retries include their attempt number. Final check,
case, and assertion totals include only the final suite attempt and the final
attempt of each case within it. Earlier failures remain visible in the output
but do not make a later successful attempt fail the run.

## Hooks and ordering

The marker attributes are `SuiteBefore`, `SuiteAfter`, `CaseBefore`, and
`CaseAfter`, all in `ThriveData\ThrivePHP\Test`. Each phase may have multiple
annotated methods.

Execution order:

1. All `SuiteBefore` hooks.
2. For each case: `CaseBefore` hooks, the case body, then `CaseAfter` hooks.
3. All `SuiteAfter` hooks.

Before hooks and cases run base class first, then child class. After hooks run
child class first, then base class. Within each class, directly declared methods
run in source-line order, followed by trait methods grouped by their originating
trait's fully qualified name and then source line. Effective method names break
source-line ties. Methods within a class are not reversed for teardown.

Child overrides replace parent methods, and only the overriding method's
attributes apply. An unannotated override removes that method's registration.
Nested traits, `insteadof`, aliases, and visibility adaptations are supported.
Aliases of the same trait implementation register once: prefer its original name
when public, otherwise the alphabetically first public alias. Different trait
implementations remain separate, even when their method names match.

PHP reflection cannot distinguish every compact declaration. Discovery rejects
ambiguous origins, such as conflicting same-name trait methods on the same source
line or overlapping class/trait declaration lines. Put those declarations on
separate lines or use distinct method names. Renaming only a trait does not
necessarily remove the ambiguity. Unannotated helpers are not discovered.

## Assertions, failures, and cleanup

- `$this->test->assert($condition, $message)` records the result and throws on
  failure, stopping the current case body or hook.
- `$this->test->verify($condition, $message)` records the result and continues.
- A thrown suite-setup failure skips remaining setup hooks and all cases.
- A thrown case-setup failure skips remaining setup hooks and that case's body.
- Every applicable teardown hook is attempted independently, including after
  partial setup. A teardown failure does not suppress later teardown hooks.
- A case failure does not prevent later cases from running.
- Assertion failures are reported once. Unexpected exceptions and additional
  cleanup failures retain their own diagnostics.

Case assertions include their before/after hooks. A case or callable check with no
assertions passes if it completes without errors, reported as `PASS` with a
`(No assertions)` annotation. Exceptions, hook failures, and exceeded deadlines
still fail the case. Suite setup/teardown assertions
are counted separately from individual cases and can fail the suite even when
all cases pass. An empty suite fails with `No cases found.` unless explicitly skipped.

Case results appear as `PASS layouts > The initial collection contains one item`.
Each file also has an overall result. `Checks` counts file results; `Cases` counts
suite cases, including cases skipped after suite setup fails; `Assertions` counts
all recorded results, including hooks and legacy checks.

## Browser suites

Extend `SuiteBrowser` to receive a fresh browser session per suite. Its inherited
setup hook starts the driver and browser before child setup. Its teardown hook
stops them after child cleanup. `$this->browser` is available once startup succeeds
and remains available through child suite teardown. Keep resource creation in
hooks, not constructors, so the runner can report failures and arrange cleanup.

```php
use ThriveData\ThrivePHP\Test\SuiteBrowser;
use ThriveData\ThrivePHP\Test\SuiteConfig;
use ThriveData\ThrivePHP\Test\SuiteBefore;
use ThriveData\ThrivePHP\Test\Test;

return new class($test) extends SuiteBrowser {
    protected function configure(SuiteConfig $config): void
    {
        $config->timeout = 15;
    }

    #[SuiteBefore]
    public function openApplication(): void
    {
        $this->browser->navigate('https://example.test/');
    }

    #[Test('The page displays its greeting')]
    public function greeting(): void
    {
        $this->browser->find('#greeting', wait: true)
            ->textEquals('Hello', wait: true);
    }
};
```

`configure()` is optional on all suites. The runner creates a fresh `SuiteConfig`
and calls `configure()` once per suite attempt before suite hooks. Defaults are:

| Property | Default | Meaning |
| --- | --- | --- |
| `timeout` | `10` | Seconds, passed to `WebDriver::browser()`. |
| `capabilities` | `[]` | Browser capabilities passed to the session factory. |
| `idleTimeout` | `null` | Seconds without worker progress; null disables it. |

Declared properties enforce their PHP types. Assigning an unknown option throws
`InvalidArgumentException`. Configuration changes do not
affect other suites. No reference parameter is needed to change object properties.

Browser assertions automatically report to the current case or suite phase and
stop it on failure by default. Comparison methods accept `verify: true` to record
a failed result and continue, returning their usual receiver for chaining:

```php
$this->browser->find('#greeting')
    ->textEquals('Hello', timeout: 5000, verify: true);
$this->browser->findAll('.row')->countEquals(3, verify: true);
```

This option is available on `isVisible()`, `isNotVisible()`, `valueEquals()`,
`textEquals()`, `textContains()`, `attributeEquals()`, `attributeMatches()`,
`countEquals()`, and browser/window `urlMatches()`. It applies only to that call.
A failed verification still fails the case or suite phase overall. Without an
active `Test`, failed comparisons throw even with `verify: true`.

Lookup and comparison methods that expose `wait` default to `true`, retrying before
the final result is recorded once. Pass `wait: false` for a single check. `countEquals()`
refreshes retained searches by default; explicit `ElementSet` snapshots without
a retained search require `wait: false`.
Explicit wait timeouts are milliseconds, distinct from the browser configuration's
seconds. Invalid patterns and driver errors still propagate; `find()` and
`Element::wait()` remain stopping prerequisites. Direct `$this->test->verify(...)`
is also available for boolean conditions.

Application cleanup must tolerate incomplete setup. `SuiteBrowser` keeps a
nullable driver reference so its own cleanup also works after configuration or
startup failure. Keep the inherited `startBrowser()` and `stopBrowser()` hooks
when overriding other suite behavior.

## Regression checks

Run from the ThrivePHP repository root:

```sh
php test/test.php
php test/test-suite.php
php test/test-attributes.php
php test/test-selectors.php
php test/test-parallel.php
php test/webdriver.php
php test/webdriver-runtime.php
```

Browser protocol and lifecycle checks use local fixture services, not real browsers.
Parallel checks launch real PHP workers and exercise concurrency, live reports,
large results, inherited diagnostics, timeouts, crashes, and descendant recovery.
Passing these checks on one OS does not verify native execution on other platforms
or concurrent real-browser sessions.
