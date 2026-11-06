# Test suites

`ThriveData\ThrivePHP\Test` runs PHP files below `/test/checks` by default. Each
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

## Defining a suite

Suite files return an anonymous class. `Suite` supplies the constructor and the
public `$test` property; individual suites do not need their own constructors.

```php
use ThriveData\ThrivePHP\Test\Suite;
use ThriveData\ThrivePHP\Test\CaseName;
use ThriveData\ThrivePHP\Test\CaseBefore;

return new class($test) extends Suite {
    private array $items = [];

    #[CaseBefore]
    public function prepare(): void
    {
        $this->items = ['first'];
    }

    #[CaseName('The initial collection contains one item')]
    public function initialCount(): void
    {
        $this->test->assert(count($this->items) === 1, 'Expected one initial item.');
    }
};
```

Annotated methods must be public and callable without arguments. Unannotated
methods are helpers. `CaseName` supplies the report label independently of the
method's PHP name. The same suite object holds shared state throughout its run.

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

Case assertions include their before/after hooks. A case with no assertions and
no other failure fails with `No assertions ran.` Suite setup/teardown assertions
are counted separately from individual cases and can fail the suite even when
all cases pass. An empty suite fails with `No cases found.`

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
use ThriveData\ThrivePHP\Test\CaseName;

return new class($test) extends SuiteBrowser {
    protected function configure(SuiteConfig $config): void
    {
        $config->browser = 'safari';
    }

    #[SuiteBefore]
    public function openApplication(): void
    {
        $this->browser->navigate('https://example.test/');
    }

    #[CaseName('The page displays its greeting')]
    public function greeting(): void
    {
        $this->browser->find('#greeting', wait: true)
            ->textEquals('Hello', wait: true);
    }
};
```

`configure()` is optional. The browser-start hook creates a fresh `SuiteConfig`
and calls `configure()` once before starting the driver. Defaults are:

| Property | Default | Meaning |
| --- | --- | --- |
| `browser` | `'firefox'` | Browser name accepted by `WebDriver`. |
| `headless` | `false` | Headless session; Safari does not support this. |
| `timeout` | `10` | Seconds, passed to `WebDriver::browser()`. |
| `capabilities` | `[]` | Browser capabilities passed to the session factory. |

Declared properties enforce their PHP types. Assigning an unknown option throws
`InvalidArgumentException`, including on PHP 8.0. Configuration changes do not
affect other suites. No reference parameter is needed to change object properties.

Browser assertions automatically report to the current case or suite phase and
stop it on failure by default. Comparison methods accept `verify: true` to record
a failed result and continue, returning their usual receiver for chaining:

```php
$this->browser->find('#greeting', wait: true)
    ->textEquals('Hello', wait: true, timeout: 5000, verify: true);
$this->browser->findAll('.row')->countEquals(3, verify: true);
```

This option is available on `isVisible()`, `isNotVisible()`, `valueEquals()`,
`textEquals()`, `textContains()`, `attributeEquals()`, `attributeMatches()`,
`countEquals()`, and browser/window `urlMatches()`. It applies only to that call.
A failed verification still fails the case or suite phase overall. Without an
active `Test`, failed comparisons throw even with `verify: true`.

Explicit `wait: true` enables retrying before the final result is recorded once.
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
php test/webdriver.php
php test/webdriver-runtime.php
```

Browser protocol and lifecycle checks use local fixture services, not real browsers.
