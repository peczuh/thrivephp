# Command line

The framework CLI lives at `vendor/peczuh/thrivephp/bin/cli.php`. It currently
supports test execution and help. Application commands from `commands.php` are
not dispatched yet.

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
directory. A hard link has no target path to resolve; discovery starts in the
directory of the hard link used to invoke PHP.

## Test selection

```sh
php cli.php test
php cli.php test 'folder1/suite1::caseA'
php cli.php test 'folder1/suite1::caseA' 'folder2/suite2::caseB'
```

No selectors means a complete run under the configured case root. Defaults are
`<root>/test` for `test.basepath` and `<test base>/cases` for `test.casepath`.
Existing skip rules still apply.

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
| `--browser <name>` | Pass the browser selection to the runner. |
| `--headless` | Enable headless execution. |
| `--workers <count>` | Set a positive worker count; omission retains runner defaults. |
| `--title <text>` | Set the report title, overriding `test.project`. |
| `--set <path>=<json>` | Replace a setting after bootstrap; repeatable, in argument order. |
| `--help` | Show help without discovery or bootstrap. |

No command, or the `help` command, also displays help. Unknown commands and
options are errors.

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
They verify discovery, symlink/hard-link behavior, selectors, typed overrides,
argument errors and exit status without launching a native browser.
