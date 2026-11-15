# Command line

The framework CLI lives at `vendor/peczuh/thrivephp/bin/cli.php`. It currently
supports test execution and help. Application commands from `commands.php` are
not dispatched yet.

`bin/cli.php` loads `src/CLI.php` and exits with the result of
`ThriveData\ThrivePHP\CLI::run($arguments, $directory)`. Arguments exclude the
script name; the directory is the launcher's `__DIR__`. Parsing, help, project
discovery, bootstrap and command dispatch live in that class. The launcher loads
the class directly so help remains available before Composer or project discovery.

```sh
php vendor/peczuh/thrivephp/bin/cli.php --help
php vendor/peczuh/thrivephp/bin/cli.php --root . test
```

## Project discovery

Without `--root`, discovery starts at the CLI's `__DIR__` and walks upward.
It selects the first `settings.json` containing a top-level `root` key:

```json
{
  "root": "."
}
```

`root` must be a nonempty string. A relative value resolves against the directory
containing that settings file. An absolute value is used directly. The CLI then
loads `<resolved root>/vendor/autoload.php` and bootstraps that project.

A file without a top-level `root` does not stop discovery. Invalid JSON or an
invalid `root` causes an error. A selected root that does not exist or has no
`vendor/autoload.php` also causes an error; discovery does not continue past it.

When traversal reaches the current working directory, it checks that directory
and throws if no qualifying settings file was found. It never searches above
CWD once encountered. If CWD is not on the ancestor chain, traversal continues
to the filesystem root and then throws if no match was found.

`--root <dir>` bypasses discovery entirely. Relative command-line roots resolve
against CWD. Existing projects without a `root` setting can use `--root .`.

PHP resolves symlinks for `__DIR__`. A project-level `cli.php` symlink to the
framework script therefore starts discovery inside the framework's `bin`
directory.

## Test selection

```sh
php cli.php test
php cli.php test 'folder1/suite1::caseA'
php cli.php test 'folder1/suite1::caseA' 'folder2/suite2::caseB'
```

No selectors means a complete run under the configured case root. Defaults are
`<root>/test` for `test.basepath` and `<test base>/cases` for `test.casepath`.
Existing skip rules still apply.

Use `--casepath <dir>` to select a different case root and `--savepath <dir>` to
select the parent directory for timestamped reports without editing settings.
Explicit relative case/save paths resolve against the project root; absolute
paths are used directly. This applies to configuration settings and `--set` too.
Omitted or null values default to `<test base>/cases` and `<test base>/runs`.
Each dedicated option overrides its project/local setting and `--set` value
regardless of argument order. Repeating an option uses its last value.

```sh
php cli.php test '^estimates/' --casepath samples/calibration --savepath calibration-reports
```

Positional selectors go unchanged to the [runner's selector engine](testing.md).
They use its regex syntax for files, cases, tags and exclusions. Multiple
selectors combine with OR, while the constraints inside each selector stay
associated. A matching case runs once even if several selectors match it.

`folder1/suite1::caseA` matches `folder1/suite1.php` under the case root and its
`caseA` method. Matching is regex-based, not exact-name lookup. Use
`'^folder1/suite1$::^caseA$'` when an exact match is required. Quote selectors
to prevent the shell from interpreting their punctuation.

## Options

Options may appear before or after the command and selectors. Values use separate
arguments, as in `--workers 2`. `--` ends option parsing.

| Option | Effect |
| --- | --- |
| `--root <dir>` | Select the project without discovery. |
| `--casepath <dir>` | Override `test.casepath`; relative paths use the project root. |
| `--savepath <dir>` | Override `test.savepath`; relative paths use the project root. |
| `--browser <name>` | Select one browser, overriding the `test.browser.driver` preference list. |
| `--headless` | Enable headless execution. |
| `--headed` | Show the browser window. |
| `--workers <count>` | Set a positive worker count; omission retains runner defaults. |
| `--title <text>` | Set the report title, overriding `test.project`. |
| `--run` / `--no-run` | Show or hide suite and case start announcements. |
| `--pass` / `--no-pass` | Show or hide passing results and their details. |
| `--fail` / `--no-fail` | Show or hide failures; execution errors remain visible. |
| `--skip` / `--no-skip` | Show or hide skipped results. |
| `--screenshots` / `--no-screenshots` | Show or hide console screenshot paths and inline images. |
| `--quiet` | Print only failure and error diagnostics, retaining interactive progress but omitting screenshots and summary. |
| `--set <path>=<json>` | Replace a setting after bootstrap; repeatable, in argument order. |
| `--help` | Show help without discovery or bootstrap. |

No command, or the `help` command, also displays help. Unknown commands and
options are errors.

Display switches override `test.console.run`, `pass`, `fail`, `skip`, and
`screenshots.enabled`, including values supplied through `--set`. All five default
to `true`. Repeating a display switch uses its last value. `--quiet` takes precedence
over all display switches regardless of argument order. Quiet retains the progress
bar on supported interactive terminals; non-TTY output disables the progress bar.
Successful quiet runs produce no runner output when redirected. Execution errors
remain visible even with `--no-fail`.

```sh
php cli.php test --no-run --no-pass --no-screenshots
php cli.php test --quiet
```

The individual display switches retain the final summary and progress footer.
Saved reports, screenshot capture policies and exit status are independent of
console display. See [console settings](testing.md#console) for configuration and
the distinction between successful-step detail and status filtering.

Without `--browser`, the runner uses the first ready browser/driver pair from
`test.browser.driver`, for example `["firefox", "chrome", "chromium"]`. If the
setting is absent, its built-in preference order applies. The list can also be
overridden with `--set 'test.browser.driver=["firefox","chrome"]'`.

Headless mode defaults to `true`. Set `test.browser.headless` to `false` in
project/local settings to use visible windows by default. Explicit `--headless`
or `--headed` overrides the setting; if both occur, the last flag wins. Safari
and Safari Preview require headed mode. Invalid non-boolean settings are reported
unless an explicit flag overrides them.

`--set` requires a nonempty dot-separated settings path and a valid JSON value.
Strings need JSON quotes; booleans, numbers, objects, lists and null retain
their types:

```sh
php cli.php test --browser firefox --headless --workers 2 \
  --set test.browser.capabilities.acceptInsecureCerts=true

php cli.php test --set 'test.project="Sunnyside development"'
php cli.php test --set 'test.browser.capabilities.goog:chromeOptions.args=["--disable-gpu"]'
```

The CLI parses arguments before discovery, bootstraps project/local settings,
then applies overrides with `Settings::set()` before calling `Test::run()`.
See [settings](settings.md) for merge semantics and worker propagation.

Help and successful test runs exit with status 0. Usage, discovery, bootstrap,
or test failures exit with status 1. Usage and discovery errors go to stderr;
test-run errors retain the runner's console, JSON and HTML reports.

## Verification

Run `php test/test-cli.php` from the framework repository. The checks use isolated
projects, the real test runner and PHP workers, and a fixture browser inventory.
They verify discovery, symlink behavior, selectors, typed overrides,
argument errors and exit status without launching a native browser.
