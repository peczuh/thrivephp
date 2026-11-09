# Settings

`ThriveData\ThrivePHP\Settings` stores settings in memory. Application bootstrap
calls `Settings::init()`, which loads `<project>/settings.json` followed by
`<project>/local/settings.json`. Later files override earlier values. Explicit
`init($first, $second, ...)` arguments use their supplied order instead.

## File merging

Settings files must contain a JSON object. `load($file, silent: false)` merges
another file into the current settings:

- Two objects merge recursively, preserving omitted keys.
- Lists replace completely, including replacement with `[]`.
- Scalars, explicit `null`, and differing types use the later value.
- `false`, `0`, and `""` are overrides, not missing values.

For example, a local file containing only
`{"test":{"browser":{"capabilities":{"acceptInsecureCerts":true}}}}`
overrides that capability while retaining the project's other test settings.
An empty object `{}` preserves an earlier object's keys; an empty list `[]`
replaces it. Numeric object keys remain object properties during merging.

`init()` silently skips missing or unreadable files. `load(..., silent: false)`
throws for them. Invalid JSON throws regardless of `silent`. Repeated `init()`
and `load()` calls merge into the current settings rather than resetting them.

## Reading and overriding values

```php
use ThriveData\ThrivePHP\Settings;

$path = Settings::fetch('test.basepath'); // Null when missing or explicitly null.
$path = Settings::get('test.basepath');   // Throws when missing or explicitly null.

Settings::set('test.browser.capabilities.acceptInsecureCerts', true);
Settings::set('test.browser.capabilities.goog:chromeOptions.args', ['--disable-gpu']);
Settings::set('test.browser.capabilities.goog:chromeOptions.args', []);
```

`set(string $path, mixed $value): void` replaces the value at a dot-separated
path. It creates missing intermediate objects and replaces scalar intermediates
with objects. It does not merge the supplied value or write a settings file.
Existing lists can be addressed by numeric index, as in `servers.0.host`.

Use typed PHP values. Associative arrays become objects; list arrays remain lists.
Use `(object) []` for an empty object, or an explicit object for numeric object
keys. `fetch()` and `get()` return arrays for both JSON objects and lists, as
before. Supplied `stdClass` objects and returned arrays are independent of internal storage.

The former public `Settings::$data` property is private. Replace direct writes
with `set()`. An empty path replaces the complete settings object:

```php
Settings::set('', ['test' => ['project' => 'Example']]);
Settings::set('', []); // Reset to an empty settings object.
```

The root must be an object, an associative array, or `[]` for a reset.
`Settings::export(): stdClass` returns an independent snapshot preserving JSON
object/list types. `Settings::set('', $snapshot)` restores it.

## CLI and test runners

A CLI should bootstrap the application, parse override arguments into typed PHP
values, apply them with `set()`, then start the test run. The runner reads current
settings without reloading the files. Explicit later calls to `load()` or `init()`
still follow normal later-value precedence.

Parallel test workers receive the parent's effective settings snapshot over the
worker connection, not in process arguments. Each worker restores it after its
own application bootstrap and before loading its suite. Thus runtime overrides
apply to test code in serial and parallel runs. They do not retroactively change
resources initialized during application bootstrap. Worker-local changes do not
propagate to the parent or other workers.
