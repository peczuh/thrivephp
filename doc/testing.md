# Test suites

Requires PHP 8.5 or later. `ThriveData\ThrivePHP\Test` runs PHP files below `<project root>/test/cases` by default. Each
file receives the runner as `$test` and returns either a `Test\Suite` or an
existing callable check. A file's relative path, without `.php`, is its name.

The [framework CLI](cli.md) runs these suites with positional selectors:

```sh
php vendor/peczuh/thrivephp/bin/cli.php --root . test 'folder1/suite1::caseA'
```

Omit selectors for a complete run. Omit `--root` when a discoverable
`settings.json` contains the project's top-level `root` path.

```php
require __DIR__ . '/../vendor/autoload.php';

$test = new \ThriveData\ThrivePHP\Test(dirname(__DIR__));
$test->bootstrap();
exit($test->run());
```

Pass names to `run(['layouts', 'login'])` to select files. A nonzero return value
means at least one file failed. Callable checks continue to receive the runner
as their argument and report one result per file.

## Paths and reports

Project/local files deep-merge through [Settings](settings.md). Apply runtime or
CLI overrides with `Settings::set()` after bootstrap and before `run()`.
Parallel workers receive the effective settings after their own bootstrap.

The constructor accepts the project root only. The former `$pathChecks` argument
has been removed. Paths resolve when `run()` starts, after application bootstrap:

| Setting | Default | Anchor for relative values |
| --- | --- | --- |
| `test.basepath` | `<project root>/test` | Project root |
| `test.casepath` | `<basepath>/cases` | Test base |
| `test.savepath` | `<basepath>/runs` | Test base |

A leading `/` means an absolute path. Subclasses used by fixtures may override
the protected `basePath()`, `casePath()`, and `savePath()` methods. The parent
resolves the run directory once and passes it to workers.

Every run produces live console output and both report files:

```text
<savepath>/YYYY-MM-DD_HH-MM-SS/
    report.json
    report.html
    screenshots/
        <suite>/
            a1__suite.png
            <case>/
                a1__a1__s2__fail.png
                a1__a2__case.png
```

The timestamp uses local time. There is no session suffix. An existing run
directory causes an exception before any suites execute; files are never overwritten
by a later run. Move or archive the entire run directory to retain its images.

The suite name is its path relative to the cases directory without `.php`, and
the case name is its method name.
For example, `folder/customer-login.php` and its `save()` method write to
`screenshots/folder/customer-login/save/`. Suite-level captures go directly in
`screenshots/folder/customer-login/`. Report metadata, file selectors and screenshot
directories use the same suite path. Filenames retain suite attempt,
case attempt, step ordinal when applicable, and capture point.

Each suite path component, case name and action name must contain 1 to 255 ASCII
letters, digits, underscores or hyphens, starting with a letter or underscore. Filesystem-reserved
names such as `CON`, `NUL`, `COM1` and `LPT1` are rejected, ignoring case.
Names are used literally, never sanitized or encoded. `customer login` is invalid;
it is not converted to `customer-login`. Descriptions in `#[Test('Customer login')]`
remain unrestricted display labels and do not determine screenshot paths.

Selected suite paths must be unique ignoring case. For example, `admin/login.php`
and `public/login.php` are distinct suites, while selecting both `admin/login.php`
and `Admin/Login.php` fails before any suite files or workers start. Invalid suite
path components are also run-level preparation errors. Discovered case names are
validated before suite configuration
or lifecycle hooks; PHP already treats method names case-insensitively. Action
names are validated before their callbacks execute. Repeated actions are allowed
because their step ordinals distinguish their captures.

Preparation failures, including invalid options, discovery, selectors and browser
selection, produce run-level diagnostics in all three outputs. Caught application
bootstrap failures use the same reporter even when Composer or short tags are
unavailable. If report-path settings cannot be loaded or resolved, reports use
`<project root>/test/runs`. A failure to create or write report storage is printed
to the console and remains an error; existing runs are never overwritten.
Worker bootstrap errors belong to the assigned suite in the parent's report.

### Console

Suite and case starts and results print live, labeled `SUITE` or `CASE`. Completed
cases and suites include duration; cases also include assertion counts.
Suite entries use `file: description`; case entries use
`file > method: description`. Matching names and descriptions appear only once.
The initial suite start identifies the file before its description is loaded.
Failed steps print as soon as they finish, labeled `STEP`, with their suite/case context,
phase, duration, supplied values, assertion messages and comparisons, author
locations with source excerpts, and screenshot paths. Failed assertions print
once as `AssertionFailure: message`. Unexpected exceptions also include stacks.
Set `test.console.steps` to `true` to print successful steps as they finish too.
Its default is `false`; a failing case or suite does not expose passing steps.
Each step's diagnostic block prints together, including with parallel workers.
Non-step execution errors print when received. Retry notices identify the failed
attempt as `attempt X/Y`, where Y is the configured maximum, including the initial
attempt. Case and suite retry contexts and results use the same numbering.
Completed steps are not replayed at retries or in final results.

Failure screenshots render inline on a Ghostty or Rex TTY through the Kitty graphics
protocol. Screenshot paths on a TTY are OSC 8 hyperlinks to the original PNG's
absolute `file://` URL, with blue, underlined relative path labels. Redirected output stays plain
text. Successful screenshots appear as linked paths without inline images.
The final summary includes
environment information, totals, duration, and the output directory. Console
source, screenshot, and report paths within the project are displayed relative
to `environment.root`. JSON and HTML screenshot paths remain run-relative so
the report directory can be moved with its images.

Set `test.console.screenshots.columns` to a positive integer to choose the inline
display width in terminal columns. The terminal preserves the image's aspect
ratio. Omit the setting or use null for the existing natural-size display.
This changes only the Kitty placement width: the transmitted PNG, saved image
and recorded pixel dimensions stay unchanged.

### JSON and HTML

Both files use the same event-built record. `report.json` is pretty-printed with
stable key order. Schema version 1 contains `schemaVersion`, `generatedAt`,
`startedAt`, `endedAt`, `duration`, `status`, `environment`, `totals`, `errors`, and `suites`.
Durations are seconds. Timestamps include the local UTC offset.
The additive `errors` array holds run-level diagnostics with phase, class, message,
source location, stack and previous exception. Any run error sets `status` to
`FAIL` and returns a nonzero exit status without creating suite or assertion
failures. A run that fails before execution has empty `suites` and zero totals.
Unresolved environment fields remain null.

The hierarchy is suite, suite attempt, case, case attempt, step. Arrays retain
all retry attempts; totals count only final outcomes. IDs derive from the file
identity, attempt indexes, method, and step ordinal, independently of descriptions.
IDs retain readable file and method names without URL encoding, for example
`folder/one_case/a1/retry_body/a2/s1`.
Suite and case records store `maxAttempts` as one plus their configured retries;
each attempt stores its one-based `index`. HTML labels attempts as `Attempt X/Y`,
including when execution succeeds before using every allowed attempt.
Filtered suites are marked `FILTERED` and excluded from totals. Executed results
use `PASS`, `FAIL`, and `SKIP`. Each attempt has a flat step list tagged with
`suite-setup`, `case-setup`, `test`, `case-teardown`, or `suite-teardown`.

Steps contain the operation label, target, supplied values, status, timing,
location, assertions, error, and screenshots. Comparison assertions retain typed
`expected` and `actual` values. Plain assertions retain their boolean result and
message. Failed assertions add `class: "AssertionFailure"` and retain the author's
location, without a duplicate step error or instrumentation stack. Source locations
include a short excerpt. Unexpected errors contain their class, message, location,
stack and previous exception. Capture diagnostics do not change
the result. Partial worker records retain completed steps and mark interrupted
work as failed, with remaining cases skipped.

`environment.root` records the resolved absolute project root. Suite file paths,
source-location `file` fields, and stack-frame paths beneath that root are stored
relative to it; join the root and relative path to reconstruct the absolute path
without project configuration. Files outside that root retain absolute paths.
Source excerpts are still read from the original files before paths are shortened.

`environment.project` identifies the project in the HTML banner and browser title.
It resolves at run time from `run(title: 'Sunnyside')`, then `test.project`, then
the `name` in the project root's `composer.json`, using the first non-null value.
If none is available, it is null and the HTML title remains "Test report" without
a project prefix. Directory names are not used as project names.

Environment information includes PHP, OS, worker count, selected browser,
headless mode and `site.url`. Browser version and platform come from negotiated
session capabilities; each suite attempt also keeps its session ID and capabilities.
Runs without browser sessions have null browser version and platform.

`report.html` embeds its viewer code and the same record in
`<script id="test-report-data" type="application/json">`. Embedded JSON uses
`JSON_HEX_TAG`, preserving literal HTML in inputs without allowing it to terminate
the data element. The viewer provides dashboard totals, search and status filters,
expandable suites/cases/steps, retry history, failure details, and screenshot zoom.
It loads no remote assets. Screenshots use paths relative to the run folder.

### Steps and screenshots

Browser actions, asserted conditions, callback waits, `script()` and `sleep()`
record steps automatically. Target construction, boolean conditions and value
readers do not. Inputs are recorded without redaction.

```php
$this->browser->find('#name')->value('Jane');
// One value step. Target construction and internal clear/type calls add no steps.
```

The recorder suppresses nested operations across browser objects, including calls
inside wait callbacks. Each outer operation preserves its return value and errors.
Standalone WebDriver usage records nothing. Actions do not increment assertions;
conditions with `assert: true` record one final assertion.

| Setting | Default | Allowed values |
| --- | --- | --- |
| `test.screenshots.steps` | `failure` | `off`, `failure`, `always` |
| `test.screenshots.cases` | `always` | `off`, `failure`, `always` |
| `test.screenshots.suites` | `off` | `off`, `failure`, `always` |

`SuiteBrowser` supplies the screenshot source; ordinary `Suite` does not create
browser captures. A failure capture happens before another operation proceeds,
including after a continuing verification failure. Successful case captures occur
after the body and before `After(case: true)`. Case teardown failures retain an additional
capture. Enabled suite captures occur after cases and before suite-level `After` hooks.
Skipped cases and suites capture nothing. Retried attempts retain their images.
An action failure and its case can reference the same PNG.

Workers write PNG bytes to disk and send paths and dimensions over TCP. Within
the suite/case directories, filenames join both retry indexes, step ordinal when
applicable, and capture point/outcome with `__`, for example
`screenshots/one_case/failure_body/a1__a1__s2__fail.png`. Suite-level captures omit
the case directory and case attempt, for example `screenshots/one_case/a1__suite.png`.
Capture failures produce a diagnostic in the report
without replacing the test's result.

Reporting regression checks are `php test/test-report.php`,
`php test/test-report-paths.php`, `php test/test-report-startup.php`, `php test/test-report-inline.php`, `php test/test-names.php`, and
`php test/test-parallel.php`. For installed-browser coverage, run
`php test/test-report-browser.php chrome 2` or
`php test/test-report-browser.php firefox 1`. These use isolated `data:` pages,
exercise intentional failures and retries, and print a retained artifact directory
after checking the final counts and PNG files.

### Browser console and exceptions

Set `$config->browserLogs = true` in a browser suite's `configure()` method to
request BiDi capture before setup. Entries appear in console output and the
JSON/HTML attempt histories. They are diagnostic; they do not fail assertions.
Unavailable requested capture fails setup, and collection failures are reported.

Use `$browser->logs()` to read retained entries, or
`$browser->logs(type: 'javascript')` for unhandled JavaScript errors.
Manual sessions use `bidi: true` and `startLogCapture()` / `stopLogCapture()`.
See [BiDi capture, browser support, and transport limitations](webdriver-bidi.md)
for lifecycle details, event attribution, cURL scheduling, and upstream PHP API
improvements that would benefit this implementation.

### Browser network observation

Set `$config->browserNetwork = true` to capture network metadata before browser
suite setup. It works independently of `$config->browserLogs`; either option
requests BiDi. Unavailable requested capture fails setup.

Console, JSON, and HTML report the same observation records. Each attempt's
`browserNetwork` array contains the protocol `event`, `sessionId`, `observedAt`,
`phase`, nullable `hook`, and nullable `stepId`. Events stay in the attempt where
they were observed, even when a request spans retries. HTTP error statuses and
`network.fetchError` are diagnostic; tests can assert against `requests()` when
they want those outcomes to fail.

Records include raw URLs, headers, cookies, duplicate header values, and protocol
byte values. Body contents are not collected. The HTML Network tab shows event
summaries and expandable raw records. Observation labels do not claim causation
or a complete request history.

Explicit `$browser->network()->startCapture()` under an active test also enables
reporting. Repeating it registers an already-running collector with that test
without another subscription. Stopping capture retains earlier report entries.
Capture failures fail each affected attempt, with one passive collection
diagnostic per collector per attempt. An operation and its checkpoint do not
duplicate the same failure; failed steps still retain their own error details.
A successful capture restart resumes collection without erasing earlier errors.

Run `php -d short_open_tag=1 test/test-browser-network.php chrome 2` or substitute
`firefox` and `1` for native serial/parallel coverage. The test also opens the
generated HTML report and checks the Network tab, raw disclosures, and inert
protocol text.

### Managed network interception errors

Call `$browser->network()->intercept(...)` under an active test to register a
managed request handler. The browser session must request BiDi explicitly, for
example through `$config->bidi = true` in a browser suite. Interception does not
enable metadata capture; `$config->browserNetwork` remains an independent option.
See [request interception and mocking](webdriver-bidi.md#request-interception-and-mocking)
for decisions, URL patterns, cleanup, and tested browser limitations.

Handler, decision, recovery, and cleanup failures become execution errors at test
checkpoints, even with metadata capture disabled. Reports retain each failure in
arrival order. A later recovery failure is reported even when an earlier checkpoint
already recorded its cause. Each retry reports retained interception failures for
that attempt; stopping interception does not erase them. The aggregate thrown by
`stopInterception()` does not duplicate failures already reported in the attempt.
Intentional `NetworkDecision::fail()` decisions remain successful controller
operations unless the browser rejects the decision itself.

Authentication rules use `phase: 'authRequired'` and return `provideCredentials()`,
`defaultAuth()`, or `cancelAuth()` decisions. They use the same checkpoint and retry
reporting; intentional cancellation is not a controller failure. Auth handler or
decision failures recover with cancellation rather than `failRequest`. Removing
a rule leaves late owned authentication pauses to browser-default handling.
See [authentication challenges](webdriver-bidi.md#authentication-challenges) for
bounded challenge handling and the tested browser limitations.

Response rules use `phase: 'responseStarted'` and return `continueResponse()`,
`respond()`, or `fail()`. Their failures use the same capture-independent reporting
and retry retention. A browser rejecting response-body replacement is an execution
error, even if recovery successfully releases the pause. See
[response interception](webdriver-bidi.md#response-interception) for supported
decisions and the native Chrome/Firefox findings.

Body collection is explicit through `startBodyCollection()`, `requestBody()`,
`responseBody()`, `disownBody()`, and `stopBodyCollection()`. The default collects responses;
pass `dataTypes: ['request', 'response']` to collect both. Retrieved bytes are
returned to the caller and are not attached to console/JSON/HTML reports.
Raw network capture still preserves browser event fields, which can include
payloads in extensions such as Chrome's `goog:postData`.
These methods create no implicit steps; an uncaught synchronous error follows
ordinary test execution reporting. See [body collection](webdriver-bidi.md#response-body-collection)
for ownership, response encoded-size limits, and native early-read limitations,
and [request collection](webdriver-bidi.md#request-body-collection) for request-size
and byte-identity findings.
Use [standalone release](webdriver-bidi.md#releasing-collected-bodies) to discard
an owned item without retrieving bytes; repeated releases can report native errors.

Cache policy is explicit through `$browser->network()->setCacheBehavior('bypass')`
or `'default'`, with an optional nonempty list of top-level context IDs. A
session-wide call clears per-context overrides. This method creates no implicit
step or passive diagnostic; uncaught errors follow ordinary test execution
reporting. See [network cache behavior](webdriver-bidi.md#network-cache-behavior)
for scope, restoration semantics, and native checks.

### HTML snapshots

`Browser::snapshot()` and `Window::snapshot()` capture the selected HTML document
or frame immediately and return a `WebDriver\Snapshot` object:

```php
$snapshot = $browser->snapshot();
$html = $snapshot->data();
$browser->quit();
$snapshot->save('page.html');
```

Like screenshot and PDF captures, the object remains usable after the session
ends. `save()` returns the same capture, overwrites the destination, and throws
on failed or incomplete writes. The parent directory must already exist.
Capturing does not add an action step or assertion to the test report.

The capture clones the live DOM without changing it, removes script elements,
declares UTF-8, and replaces base elements with the document's resolved base URL.
This preserves relative resource URLs, including document-relative paths and
existing `<base>` elements. The doctype and serialized markup are retained.
Images and stylesheets remain external references; child-frame documents, shadow
trees, JavaScript memory, canvas pixels and property-only form state are not
archived. Script removal is not HTML sanitization; inline event handlers remain.

Native checks: `php -d short_open_tag=1 test/webdriver-snapshot-browser.php chrome`.
The same test accepts `firefox`, `safaripreview`, or `safari`.

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

### Browser capabilities and TLS

Set browser capability defaults in project or local settings:

```json
{
  "test": {
    "browser": {
      "capabilities": {
        "acceptInsecureCerts": true
      }
    }
  }
}
```

Or supply run overrides:

```php
$test->run(
    browser: 'firefox',
    headless: true,
    capabilities: ['acceptInsecureCerts' => true],
);
```

Precedence is project settings, local settings, runtime `Settings::set()` calls,
explicit run capabilities, then suite `configure()` changes. File precedence
follows load order. Run capabilities replace matching top-level capability keys;
nested browser-option objects and argument lists are not merged at this layer.
An explicit `false` overrides `true`.

Each browser suite attempt receives its own copy before `configure()`:

```php
protected function configure(SuiteConfig $config): void
{
    $config->capabilities['acceptInsecureCerts'] = false;
    // Assigning $config->capabilities = [...] replaces all inherited capabilities.
}
```

Defaults propagate to workers and fresh retry attempts. Suite changes do not
modify the run's defaults or Settings. With no capability supplied, the driver
uses its existing default. `acceptInsecureCerts` controls certificates for pages
and requests made by the browser. It does not change PHP HTTP or WebDriver/BiDi
transport TLS verification. Negotiated capabilities remain in session reports.
Browser/executable selection restrictions and explicit BiDi selection still apply.

### Browser window size

Set `test.browser.window` to an object with integer `width` and `height` values.
Every managed browser is resized after session creation, before child suite setup
or navigation. These are outer-window CSS pixels, including browser chrome;
the captured page viewport can be smaller. Omit the setting or use null to leave
the driver's initial size unchanged. Suites can still resize the browser later.
The existing settings snapshot carries the size to parallel workers.

For a large browser window and a smaller terminal preview:

```json
{
  "test": {
    "browser": {
      "window": { "width": 1900, "height": 1280 }
    },
    "console": {
      "screenshots": { "columns": 80 }
    }
  }
}
```

These settings can also be overridden through CLI `--set`, independently of
browser capabilities. Terminal display scaling does not resample screenshot files.

### Browser availability

Set `test.browser.driver` to an ordered list of browser names for automatic
selection:

```json
{
  "test": {
    "browser": {
      "driver": ["firefox", "chrome", "chromium"]
    }
  }
}
```

The runner chooses the first entry with both browser and driver ready. The list
replaces the built-in order; it does not append to it. Omit the setting or use
null to retain `chrome`, `chromium`, `firefox`, `safaripreview`, `safari`.
Local settings replace the project's whole list. An empty list selects no browser.
Values must be a JSON array of supported browser names; invalid values produce
a configuration error when automatic selection is needed.

An explicit `run(browser: ...)` or CLI `--browser` overrides the list, including
names omitted from it. Workers use the parent's resolved selection. Session
startup failures do not cause another browser to be selected.

Headless mode defaults to `true`. `run(headless: null)` or an omitted argument
uses `test.browser.headless`, then the default. The setting accepts a JSON boolean
or null. Explicit `run(headless: true/false)` and CLI `--headless`/`--headed`
override settings. The effective boolean is recorded in reports and passed to
workers. For a visible browser by default:

```json
{ "test": { "browser": { "headless": false } } }
```

Explicit unavailable browsers fail; headless Safari/Preview fails without switching
browsers. Select `headless: false` or `--headed` for Safari variants. Checks that
need no browser can run without an installed pair; managed browser setup then
fails if no pair is available. In serial headed mode without an explicit browser,
automatic discovery waits until a browser is requested. Suites needing a different
browser must create and clean up that browser themselves. Direct
`WebDriver::browser()` calls also default to headless; pass `headless: false` for
a visible session. Direct factories do not read test settings.

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
Hook boundaries, case boundaries, assertions, and recorded step events reset it; incidental stdout/stderr
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
use ThriveData\ThrivePHP\Test\Before;

return new class($test) extends Suite {
    private array $items = [];

    #[Before(case: true)]
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
A method-level retry repeats `Before(case: true)`, the case body, and `After(case: true)`, retaining
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

The hook attributes are `Before` and `After`, in `ThriveData\ThrivePHP\Test`.
Both accept `case: false` by default, running once per suite attempt. Set
`case: true` to run before or after each case attempt, including retries.
Each phase may have multiple annotated methods.

```php
#[Before]                  // Suite setup; equivalent to Before(case: false).
public function setupSuite(): void {}

#[Before(case: true)]      // Setup for each case attempt.
public function setupCase(): void {}

#[After(case: true)]       // Cleanup for each case attempt.
public function cleanupCase(): void {}

#[After]                   // Suite cleanup; equivalent to After(case: false).
public function cleanupSuite(): void {}
```

Execution order:

1. All suite-level `Before` hooks.
2. For each case attempt: `Before(case: true)` hooks, the case body, then `After(case: true)` hooks.
3. All suite-level `After` hooks.

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
- With `skipRemainingOnFailure`, a final case failure skips later selected cases.
  It defaults to `true` for `SuiteBrowser` and `false` for ordinary `Suite`.
- Assertion failures are reported once. Unexpected exceptions and additional
  cleanup failures retain their own diagnostics.

Case assertions include their before/after hooks. A case or callable check with no
assertions passes if it completes without errors, reported as `PASS` with a
`(No assertions)` annotation. Exceptions, hook failures, and exceeded deadlines
still fail the case. Suite setup/teardown assertions
are counted separately from individual cases and can fail the suite even when
all cases pass. An empty suite fails with `No cases found.` unless explicitly skipped.

Case results appear as `PASS layouts > The initial collection contains one item`.
Each file also has an overall result. `Suites` counts file results; `Cases` counts
suite cases, including cases skipped after suite setup fails; `Assertions` counts
all recorded results, including hooks and legacy checks.

`skipRemainingOnFailure` takes effect after the case's retries and `After(case: true)`
hooks finish. Failed assertions, continuing verifications, exceptions in the body
or case hooks, and case deadline failures all count. A successful retry allows
later cases to run. Skipped cases run no case hooks and report
`SKIP ... (previous case failed)`; explicitly skipped cases keep `marked skip`,
and filtered-out cases remain excluded from totals. Suite teardown still runs,
and other suites continue. A whole-suite retry starts with fresh skip state.

Override the default in either suite type:

```php
protected function configure(SuiteConfig $config): void
{
    $config->skipRemainingOnFailure = false;
}
```

Put required cleanup in suite-level `After` hooks rather than a final case, since a case
can be skipped after an earlier failure.

### Comparing PHP values

`equal()` uses PHP's `===`. `same()` compares structures recursively. Both take
the actual value first and the expected value second:

```php
$test->equal($actual, $expected); // Silent boolean query.
$test->equal($actual, $expected, assert: true, message: 'Saved customer ID');
$test->same($actualRows, $expectedRows, assert: true, message: 'Quantities retain their part order');
$test->same($actualRows, $expectedRows, is: false, assert: true);
$test->equal($actual, $expected, assert: true, stop: false); // Record failure and continue.
```

Both signatures are:

```php
equal(mixed $actual, mixed $expected, bool $is = true, bool $assert = false,
    bool $stop = true, ?string $message = null): bool|self
same(mixed $actual, mixed $expected, bool $is = true, bool $assert = false,
    bool $stop = true, ?string $message = null): bool|self
```

Queries create no steps or assertions. Assertion mode returns the `Test` instance
and records one `equal` or `same` step, including the message and expected/actual
values. `is: false` negates the comparison. `stop: false` uses `verify()` semantics;
the failure still counts. Existing enclosing steps retain their assertions without
opening nested comparison steps. Supplied values are compared immediately; these
methods have no `wait` or `timeout` options.

| Values | `equal()` | `same()` |
| --- | --- | --- |
| Scalars and null | Strict PHP value/type equality | Strict PHP value/type equality |
| Lists | Strict keys, order, values and types | Compare each position recursively |
| Associative arrays | Key order must also match | Key order ignored; keys and recursively compared values must match |
| `stdClass` and user-defined objects | Same instance required | Same class and recursively matching stored properties |
| Other internal objects and their subclasses | Same instance required | Same instance required |

Lists and associative arrays are distinct structures. Arrays and objects are also
distinct, including `[]` versus `(object) []`. Object properties are unordered;
public, protected and private instance properties participate. Property getters,
virtual properties and serialization callbacks are not invoked. Uninitialized
properties remain distinct from initialized null properties. Internal objects
such as `DateTimeImmutable` and `Closure` retain identity semantics because stored
PHP properties do not describe all their state.

```php
$a = (object) ['name' => 'Example'];
$b = (object) ['name' => 'Example'];
$test->equal($a, $b); // false: different instances.
$test->same($a, $b);  // true: matching class and contents.
```

`same()` traversal beyond 64 levels throws `InvalidArgumentException`, including
distinct cyclic structures that require traversal. Identical object references
match immediately. `equal()` retains PHP's behavior, including its error when
comparing distinct recursive arrays.

Reports use the existing JSON-safe value descriptions. Non-finite numbers retain
tagged values; resources show their type; arbitrary class instances show their
class rather than private state. Thus two different instances may have identical
report descriptions even when `equal()` or `same()` fails. Continue using
`assert()` and `verify()` for arbitrary boolean predicates.

## Browser suites

Extend `SuiteBrowser` to receive a fresh browser session per suite. Its inherited
setup hook starts the driver and browser before child setup. Its teardown hook
stops them after child cleanup. `$this->browser` is available once startup succeeds
and remains available through child suite teardown. Keep resource creation in
hooks, not constructors, so the runner can report failures and arrange cleanup.

```php
use ThriveData\ThrivePHP\Test\SuiteBrowser;
use ThriveData\ThrivePHP\Test\SuiteConfig;
use ThriveData\ThrivePHP\Test\Before;
use ThriveData\ThrivePHP\Test\Test;

return new class($test) extends SuiteBrowser {
    protected function configure(SuiteConfig $config): void
    {
        $config->timeout = 15;
    }

    #[Before]
    public function openApplication(): void
    {
        $this->browser->navigate('https://example.test/');
    }

    #[Test('The page displays its greeting')]
    public function greeting(): void
    {
        $this->browser->find('#greeting')->textEquals('Hello', assert: true);
    }
};
```

`configure()` is optional on all suites. The runner creates a fresh `SuiteConfig`
and calls `configure()` once per suite attempt before suite hooks. Defaults are:

| Property | Default | Meaning |
| --- | --- | --- |
| `timeout` | `10` | Seconds for HTTP/BiDi commands, passed to `WebDriver::browser()`. |
| `waitTimeout` | `5000` | Milliseconds for target acquisition, callback waits, and action retries. |
| `assertionTimeout` | `5000` | Milliseconds for waiting conditions, in query or assertion mode. |
| `pollInterval` | `500` | Milliseconds between polling attempts. |
| `capabilities` | `[]` | Browser capabilities passed to the session factory. |
| `idleTimeout` | `null` | Seconds without worker progress; null disables it. |
| `skipRemainingOnFailure` | `true` for `SuiteBrowser`, otherwise `false` | Skip later cases after a case exhausts its retries and fails. |

Declared properties enforce their PHP types. Assigning an unknown option throws
`InvalidArgumentException`. Configuration changes do not
affect other suites. No reference parameter is needed to change object properties.

`waitTimeout`, `assertionTimeout`, and `pollInterval` must be positive and finite.
They are also named arguments on `WebDriver::browser()` and direct `Browser`
construction. A per-call `timeout:` overrides the applicable retry budget;
`wait: false` checks once and ignores the timeout. Conditions use
`assertionTimeout` whether returning a boolean or recording an assertion.
Each in-flight command retains
its own I/O timeout; polling deadlines are checked between attempts.

Conditions return booleans by default. Pass `assert: true` to report to the current
case or suite phase and return the receiver for chaining. Add `stop: false` to
record a failed result and continue:

```php
$this->browser->find('#greeting')
    ->textEquals('Hello', assert: true, stop: false, timeout: 5000);
$this->browser->findAll('.row')->countEquals(3, assert: true, stop: false);
```

This option is available on every comparison method. It applies only to that call.
A failed assertion still fails the case or suite phase overall. Without an
active `Test`, failed assertions throw even with `stop: false`.

Element conditions include `present`, `visible`, `active`, `valueEquals`, `valueContains`, `textEquals`,
`textContains`, `textMatches`, `attributeEquals`, `attributeContains`,
`attributeMatches`, `propertyEquals`, `propertyContains`, `propertyMatches`,
`cssEquals`, `enabled`, `selected`, `hasAttribute`, `hasClass`, and
`hasDescendants`. Collections provide `countEquals`. Browsers and windows provide
`urlEquals`, `urlContains`, `urlMatches`, `titleEquals`, `titleContains`, and
`titleMatches`. All accept `is: false` for negation. Their common options are
`is`, `assert`, `stop`, `wait`, and `timeout`, after any required domain arguments.

The [complete browser contract](webdriver-contract.md) lists every public operation,
its wait/retry and failure behavior, identity rules, and Nightwatch assertion mapping.

Null `wait` means a one-shot query or a waiting assertion. Explicit `wait: true`
or `wait: false` overrides either default. A waiting query returns false on expiry.
One budget covers lookup and evaluation. Direct `$this->test->verify(...)` remains
available for computed PHP conditions.

`find()` on a browser, window, element, or shadow root creates a selector-backed
target without a DOM search. Each operation resolves it afresh, so it follows
replacement nodes. Ordinary value readers wait for presence, then read once.
`resolve(wait: true, timeout: null)` returns an exact-node element and leaves the
original target unchanged. Script-returned nodes and traversal results are exact
nodes too; they cannot find replacements.

```php
$optional = $this->browser->find('#notice');
if ($optional->visible()) {
    $optional->textContains('Saved', assert: true);
}
$optional->visible(assert: true, stop: false, timeout: 1000);
$original = $optional->resolve();
$original->present(is: false, assert: true); // Wait for that exact node's removal.
```

`present()` and `visible()` are false for missing/stale nodes; their `is: false`
forms succeed on absence. Other one-shot conditions require a node and throw if it
is missing/stale. Waiting forms remain unsatisfied on absence in either direction.
Invalid selectors, session errors, and context-restoration errors always propagate.
`Element::wait()` has been replaced by conditions. Browser/window callback `wait()`
remains a throwing utility without assertion flags.

`findAll()` creates a collection search. `count()` queries current matches once;
`countEquals()` supports the same condition options. `first()`/`get()` create
positional targets, while `all()` and iteration materialize exact-node snapshots.
Explicit `new ElementSet(...)` snapshots retain fixed membership and require
`wait: false` for count assertions. An empty explicit snapshot's `first()` is null;
a search-backed `first()` always describes a target. Use `count()` to check emptiness.

Application cleanup must tolerate incomplete setup. `SuiteBrowser` keeps a
nullable driver reference so its own cleanup also works after configuration or
startup failure. Keep the inherited `startBrowser()` and `stopBrowser()` hooks
when overriding other suite behavior.

### Element actions and queries

`click()`, `type()`, `key()`, `clear()`, the `value()` setter, `check()`,
`uncheck()`, and `select()` retry by default. Pass `wait: false` for one attempt
or `timeout: 5000` for a five-second retry budget. Null timeout uses `waitTimeout`.
Polling uses `pollInterval`; in-flight commands retain their own
timeouts. Compound operations share one polling deadline.

Actions resolve selector-backed targets on every attempt, in the captured window
and frame. Compound actions pin one exact node per attempt. Exact references cannot
find replacements. Context restoration failures propagate immediately; replaced
frames are not automatically recovered.

Native clicks retry interception and non-interactability; text entry retries
non-interactability, and clearing also retries invalid element state. These
WebDriver errors precede input dispatch. Connection failures, lost responses,
navigation timeouts, and unrelated errors propagate without repeating input.
`value()` retries invalid element state only during clearing, not during typing.
It clears each replacement before typing and does not re-clear the same
node after a rejected typing attempt. Checkbox and select operations re-check
selection before every click. `check()` and `uncheck()` wait for enabled state when
a change is needed. `select()` also waits for disabled controls/options
and missing options. Expiry throws with the last failure as its previous exception.

Internal retries remain one reported step, with screenshots only for the final
outcome. They do not increment assertion counts. Script operations and compound
pointer sequences such as dragging and double-clicking still execute once.

`visible()` queries visibility. `visible(assert: true)` waits and asserts, returning
the element. `is: false` tests hiddenness or absence. `visible()` replaces the
separate `displayed()` reader. Visibility does not guarantee an unobstructed click.

```php
$element->visible(assert: true)->click();
$element->visible(is: false, assert: true, wait: false);
if ($element->visible()) {
    $element->click();
}

$element->property('value');                 // Get the live DOM value.
$element->value();                           // Read the live value; null also reads.
$element->value('example');                  // Clear then type, returning the element.
$element->value('');                         // Clear without typing.
$element->property('value', 'example');      // Set and return the element.
$element->property('checked', false);
$element->property('customData', null);      // Assign JavaScript null.
$element->attribute('name');                 // Get an HTML attribute or null.
$element->attribute('name', 'example');
$element->attribute('disabled', '');         // Set an empty attribute.
$element->attribute('disabled', null);       // Remove the attribute.
```

`value()` replaces the former `setValue()` method. Its getter is an observation;
its setter records one step and uses native clear/type commands, including for `'0'`.

Focus and keyboard operations can use the current focus without sending keys to
a new target:

```php
$input->focus()->active(assert: true);
$this->browser->tap(Browser::TAB);
$this->browser->tap(Browser::SHIFT, Browser::TAB);
$this->browser->tap(Browser::META, Browser::SHIFT, 'a');
```

Import `ThriveData\ThrivePHP\WebDriver\Browser` for key constants. `tap()` presses
keys in order and releases them in reverse, then clears input state. The letter's
keydown sees both modifiers held. This is a chord, not sequential typing.
`Element::type()`/`key()` target a particular element instead. `active()` checks
focus through enclosing shadow roots; a host also counts as active when a shadow
descendant has focus. A missing node does not satisfy `active(is: false)`.

Native dialogs use `open(bool $modal = true)` and `close(?string $returnValue = null)`:

```php
$dialog->open()->visible(assert: true);       // Native showModal().
$dialog->close('saved');                     // Native close('saved').
$dialog->open(modal: false);                 // Native show().
$dialog->close();                            // Preserve its existing returnValue.
```

These call the native dialog methods once, preserving browser focus and events.
They do not run application setup attached to an opening button; click that button
when testing its application behavior.

For `property()` and `attribute()`, supplying a second argument selects setter behavior, including explicit `null`.
Setters return the original element and dispatch no automatic `input` or `change`
events. Property values use the usual script argument/reference conversion.
Attribute getters retain WebDriver's boolean-attribute behavior of `'true'` or null.

`check()` supports checkbox and radio inputs; `uncheck()` supports only checkboxes.
They use native clicks only when the selection state needs to change, returning
the element. Unsupported elements and radio unchecking throw. `selected()` is the
immediate boolean query.

`submit()` calls native `requestSubmit()` once, without a submitter argument.
It uses the form itself, a control's actual form owner including external
`form="..."` associations, or the nearest ancestor form for other descendants.
It throws when no form applies. Validation and cancellable submit handlers apply;
validation blocking or cancellation does not itself throw or record an assertion.
It returns the original element. Use `click()` when a particular submit button's
name/value or submission overrides matter.

`firstChild()`, `lastChild()`, `nextSibling()`, and `previousSibling()` return an
`Element` or null. They skip text and comment nodes and retain the original
window/frame context. They do not wait or retain a lookup for re-finding.

### Script and HTML injection

Browser/window `script()` normally executes a WebDriver function body and returns
its decoded result. With `inject: true`, it instead appends an inline script
containing the supplied source and returns null:

```php
$browser->script('window.testMode = true;', inject: true);
$browser->window()->script('window.testMode = false;', inject: true);

$element->inject('<span>Appended</span>');
$element->inject('<span>Prepended</span>', where: 'afterbegin');
```

Script injection takes JavaScript source without `<script>` tags. It rejects
`async: true` and nonempty `arguments`. Execution follows the page's CSP rules;
successful insertion does not guarantee successful execution.

`Element::inject($html, $where = 'beforeend')` calls `insertAdjacentHTML()` once
and returns the original element. Positions are `beforebegin` and `afterend` for
siblings, or `afterbegin` and `beforeend` for children. DOM errors propagate.
Script markup inserted this way does not execute. Register handlers through
`script()` or use an existing delegated listener.

All element actions restore their captured browser/window/frame context. Stale
references, context failures, and driver errors propagate without action retries.

## Regression checks

Run from the ThrivePHP repository root:

```sh
php test/test.php
php test/test-suite.php
php test/test-attributes.php
php test/test-selectors.php
php test/test-parallel.php
php test/settings.php
php test/test-configuration.php
php test/test-cli.php
php test/webdriver.php
php test/webdriver-runtime.php
```

Browser protocol and lifecycle checks use local fixture services, not real browsers.
Parallel checks launch real PHP workers and exercise concurrency, live reports,
large results, inherited diagnostics, timeouts, crashes, and descendant recovery.
Passing these checks on one OS does not verify native execution on other platforms
or concurrent real-browser sessions.

For DOM behavior, run `php test/webdriver-dom.php chrome` or
`php test/webdriver-dom.php firefox` with that browser and driver installed.
These checks create a headless session with isolated documents and cover form
submission, setters, checkbox/radio events, injection, traversal, and captured contexts.

For certificate policies, run `php test/test-configuration-browser.php chrome`
and `php test/test-configuration-browser.php firefox`. These require PHP OpenSSL
and the selected browser/driver. They create a temporary self-signed HTTPS origin
and verify settings, run overrides, suite overrides, and negotiated capabilities
through two real browser workers.
