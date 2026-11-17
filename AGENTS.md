# ThrivePHP

## Scope and workflow

- This is the ThrivePHP framework source repository. Downstream projects install
  ThrivePHP through Composer; do not assume they have a checkout of this repository.
- Do not commit or push unless requested.
- Keep changes limited to the requested behavior and necessary supporting work.
  Follow nearby code rather than introducing new abstractions, dependencies,
  configuration options, fallbacks, or compatibility layers without a concrete
  requirement.
- Prefer clear public APIs over backward compatibility. When changing an API,
  update its callers, tests, and documentation together.
- Preserve existing user changes and untracked files. Keep independently useful
  cleanup separate from behavioral changes.

## Repository map

- `composer.json`: package requirements and PSR-4 autoloading.
- `src/Application.php`, `src/Router.php`, and `src/Commander.php`: application
  initialization and web/application-command dispatch.
- `bin/cli.php` and `src/CLI.php`: framework CLI launcher, project discovery,
  options, and test dispatch. This CLI does not dispatch `commands.php`.
- `src/Settings.php`: settings loading, typed overrides, and snapshots.
- `src/Test.php` and `src/Test/`: test execution, suites, workers, and reporting.
- `src/WebDriver/` and `src/WebSocket.php`: browser automation, driver lifecycle,
  HTTP/BiDi coordination, and WebSocket transport.
- `test/` and `test/fixtures/`: standalone framework regression scripts and
  isolated fixtures. Native-browser scripts are separate from protocol fixtures.
- `doc/*.md`: maintained behavior guides. Generated pages in `doc/src/` may lag
  behind the implementation.

## Code and documentation

- Target PHP 8.5 or later. Composer requires `ext-curl` and maps
  `ThriveData\ThrivePHP\` to `src/`. Database components also require `ext-pgsql`.
- Match the indentation and syntax of the surrounding file. Existing modules use
  different styles; do not reformat unaffected lines.
- Explain non-obvious constraints, ownership, and public contracts in comments.
- Read `src/WebDriver/AGENTS.md` when changing WebDriver or code that depends on
  its contracts. Consult `doc/webdriver-contract.md` for current target, condition,
  and retry semantics, and `doc/webdriver-bidi.md` for BiDi behavior and limitations.
- Use repository-relative Markdown links. Do not put machine-specific filesystem
  paths in repository documentation.
- Keep public behavior documented in the relevant files:
  - `README.md`: installation, requirements, and introductory examples.
  - `doc/cli.md`: command parsing, project discovery, options, and exit status.
  - `doc/settings.md`: settings loading, overrides, and merge behavior.
  - `doc/testing.md`: suites, lifecycle hooks, assertions, reporting, and execution.
  - `doc/webdriver-contract.md` and `doc/webdriver-bidi.md`: browser APIs and
    protocol behavior.

## Settings and CLI contracts

- Settings files contain JSON objects. Load project settings before local
  overrides. Objects merge recursively; lists replace completely. Preserve
  explicit null and other falsy overrides, and distinguish empty objects from lists.
- Apply runtime overrides with `Settings::set()`, not direct property access.
  `set()` replaces the selected value in memory without writing a settings file.
  Preserve object/list types in snapshots sent to workers.
- Keep the framework launcher thin. Parsing, help, discovery, and dispatch belong
  in `src/CLI.php`; help and usage errors must not require project bootstrap.
- CLI discovery starts at the launcher directory and selects a `settings.json`
  with a top-level `root`. Explicit `--root` paths resolve against CWD; marker
  paths resolve against the settings file's directory.

## Test runner conventions

- Keep methods annotated with `#[Test]`, `#[Before]`, or `#[After]` short.
  One to three words is usually enough. Put detailed case descriptions in
  `#[Test('...')]`, not in method names.
- Use `Before` and `After` for lifecycle hooks. Their default scope is the suite;
  `case: true` applies to each case attempt.
- Suite identity is the full path relative to the cases directory, without
  `.php`. Preserve directory components in selectors, report identities,
  screenshot paths, and progress labels. Matching filename stems in different
  directories are valid.
- Console display controls must not change recorded outcomes, saved reports,
  screenshot capture policy, or exit status.
- Quiet mode retains failure/error diagnostics and interactive progress.
  Non-TTY output disables the progress bar.
- Keep report output parent-owned during parallel execution. Workers send
  structured events; incidental worker stdout/stderr are separate.
- Keep `Suite` independent of browsers. `SuiteBrowser` owns a fresh browser session
  per suite attempt and cleans it up through lifecycle hooks.
- Browser logs and network observations are diagnostic data, not automatic test
  failures. Explicitly requested capture failures and managed interception errors
  remain execution errors. Do not imply reports redact supplied values or raw
  network metadata.
- Preserve assertions that expose application defects. Distinguish a stale test,
  an application failure, and a missing fixture or environment prerequisite.

## Verification

Run checks from this repository's root. Some framework files use short PHP tags;
you may need to add `-d short_open_tag=1` after `php` when the local PHP
configuration does not enable them.

Choose checks appropriate to the change:

| Area | Checks under `test/` |
| --- | --- |
| Assertions and lifecycle | `test.php`, `test-suite.php`, `test-attributes.php` |
| Discovery and selectors | `test-names.php`, `test-selectors.php` |
| Settings and CLI | `settings.php`, `test-configuration.php`, `test-cli.php` |
| Logging and PHP error handling | `log.php` |
| Reports and capture paths | `test-report.php`, `test-report-paths.php`, `test-report-startup.php` |
| Parallel execution and terminal output | `test-parallel.php`, `test-progress.php`, `test-report-inline.php` |
| Browser protocol and driver lifecycle | `webdriver.php`, `webdriver-runtime.php` |
| WebSocket transport | `websocket.php` |

For example:

```sh
php test/test-cli.php
php test/test-report.php
php test/test-progress.php
```

- Use the existing isolated fixtures for framework regressions. Protocol fixtures
  do not establish that a real browser works; use the relevant native-browser
  check when the change depends on browser behavior. `test/webdriver-dom.php`
  also launches a native browser despite lacking the `-browser.php` suffix.
- Check each script's usage header and `doc/testing.md` for browser arguments and
  prerequisites. WebSocket/protocol peers use PCNTL; progress and inline-image
  checks need a POSIX PTY, with PCNTL also required for progress. Native checks
  require an installed browser/driver pair; nothing provisions them automatically.
  Application suites can write application data; prefer isolated framework checks
  when verifying framework behavior.
- Exercise serial and parallel paths when changing reporting, selection,
  lifecycle, or worker communication.
- Run PHP lint on changed PHP files and `git diff --check`. Inspect the final diff
  for unrelated changes and unnecessary machinery. For documentation-only changes,
  check local links and example syntax; process/browser tests are not required.
- Report the checks actually run and any exact missing prerequisites. Do not
  claim untested browsers or operating systems passed.
