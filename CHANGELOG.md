# Changelog

Releases are listed from newest to oldest. Version 0.9 covers all development
before the first `v1.0.0` tag; it is not a separate tagged release. Later entries
compare consecutive `vX.Y.Z` tags, not intermediate development commits.

Some 1.x minor releases contain breaking changes. When skipping versions, follow
each intervening migration section. Applications install ThrivePHP through
Composer; updating the package does not migrate application databases.

## 1.3.0

### Key features and changes

- Added the framework test runner with callable checks and attributed suites.
  `#[Test]` supplies descriptions, skips, tags, retries, and deadlines; `#[Before]`
  and `#[After]` provide suite-level and per-case lifecycle hooks.
- Added managed browser suites, isolated PHP workers, bounded parallel execution,
  worker recovery, and cleanup after partial failures. Browser suites skip
  remaining cases after a final case failure by default.
- Added regex selection by suite path, case method, and tags, including exclusions
  and multiple selectors. Suite paths distinguish matching filenames in different
  directories.
- Added strict `equal()` and recursive structural `same()` comparisons, stopping
  assertions, and continuing verifications.
- Added shared console, JSON, and HTML reporting with retry histories, source
  diagnostics, recorded browser steps, and configurable screenshots. Terminal
  output adds colors, linked screenshots, supported inline images, status filters,
  quiet mode, and an interactive progress footer.
- Added the project-aware framework CLI for tests and help, including root
  discovery, typed settings overrides, browser/worker selection, and explicit
  case/report paths. It does not dispatch application `commands.php` commands.
- Added recursive settings-object merging, typed in-memory overrides, settings
  snapshots, and propagation of effective settings to workers.
- Added run-level browser capabilities, browser preference lists, headed/headless
  selection, initial window dimensions, and inline screenshot display sizing.
- Added lazy browser targets, configurable target acquisition and assertion
  polling, and retries for transient input failures and replaced DOM nodes.
- Added browser conditions and assertions, value replacement, form submission,
  checkbox/radio actions, DOM traversal and mutation, script/HTML injection,
  keyboard chords, focus checks, dialog controls, and HTML snapshots.
- Added BiDi console/exception capture, network observations, managed request and
  response interception, authentication decisions, request/response body
  collection and release, and cache policy controls. Coordinated HTTP and BiDi
  dispatch allows event decisions while other commands are pending.
- Corrected worker-connection polling under application error handlers. Logging
  now respects the configured severity mask and does not treat remembered
  nonfatal warnings as fatal shutdown errors.

### Breaking changes

- The minimum PHP version increases from 8.0 to 8.5. Composer now requires
  `ext-curl`; WebDriver HTTP transport uses cURL instead of PHP HTTP streams.
- `Settings::$data` is private. Settings files must contain JSON objects, and
  nested objects merge recursively instead of replacing their whole top-level
  section. Lists still replace completely; explicit null and other falsy values
  remain overrides.
- `WebDriver::browser()` defaults to `headless: true` instead of false. Automatic
  browser preference changes to Chrome, Chromium, Firefox, Safari Technology
  Preview, then Safari. Safari variants require an explicit headed session.
- `Browser::wait()` and `Window::wait()` timeouts change from seconds to
  milliseconds. Their default budget is now `waitTimeout`, 5000 ms, rather than
  the command I/O timeout. Default polling changes from 100 ms to 500 ms.
- `find()` describes a selector-backed target without looking up the DOM.
  Subsequent operations resolve current matches, so a retained target can address
  a replacement node. A `try`/`catch` around `find()` alone no longer establishes
  presence or catches a missing-element lookup.
- `findAll()` returns a collection search rather than a fixed snapshot.
  `count()` queries current matches; `first()` and `get()` describe positional
  targets. A search-backed `first()` is not null just because nothing currently
  matches, and an out-of-range search position fails when resolved.
- `Element::displayed()` is removed in favor of `visible()`. The first parameter
  of `attribute()` and `property()` is renamed from `$name` to `$key`, affecting
  callers that use named arguments.
- Input actions and target-dependent value readers can now wait for acquisition
  or retry transient failures instead of always making one attempt. Command
  timeouts and polling budgets are separate settings.

### Migrating from 1.2.0

#### Runtime and settings

1. Upgrade the PHP runtimes used by the web application, CLI, and workers to 8.5
   or later, enable cURL, then update the package:

   ```sh
   composer require peczuh/thrivephp:1.3.0
   ```

   Short tags must still be enabled for files that use them. For CLI commands,
   you may need to add `-d short_open_tag=1` after `php` if the CLI configuration
   does not already enable them.

2. Replace direct settings access with the public API:

   - Read `Settings::$data['app']['name']` with `Settings::get('app.name')` or
     `Settings::fetch('app.name')`.
   - Replace `Settings::$data['app']['name'] = $name` with
     `Settings::set('app.name', $name)`.
   - Replace `Settings::$data = []` with `Settings::set('', [])`.

   Use `Settings::export()` when saving a complete, independent settings snapshot.
   Restore it with `Settings::set('', $snapshot)`. Getters still return PHP arrays
   for JSON objects and lists.

3. Review local settings that previously replaced an entire configuration
   section. Omitted object keys now survive. Set individual keys to null when
   appropriate, use `[]` to clear a list, or replace a complete section through
   `Settings::set()` after bootstrap. An empty object does not clear an object.
   See [settings](doc/settings.md).

#### Browser selection and sessions

Select a browser explicitly when its identity matters. To retain a visible Safari
session:

```php
use ThriveData\ThrivePHP\WebDriver\WebDriver;

$driver = new WebDriver(browser: 'safari');
try {
    $browser = $driver->browser(headless: false);
} finally {
    $driver->stop();
}
```

For test-runner sessions, use `--browser safari --headed --workers 1`, or configure
equivalent settings. Direct `WebDriver` factories do not read test settings.

#### Waits, targets, and collections

Multiply existing callback-wait timeout values by 1000:

```php
$browser->wait(fn ($browser) => $browser->findAll('.row')->count() > 0, timeout: 5000);
```

This waits up to five seconds. Configure `waitTimeout`, `assertionTimeout`, and
`pollInterval` in milliseconds when creating a session or configuring a browser
suite. Command I/O `timeout`, `sleep()`, and remote timeout setters such as
`pageLoadTimeout()` still use seconds.

Replace old lookup assumptions explicitly:

| Intent | 1.3.0 API |
| --- | --- |
| Check whether a selector currently matches | `$browser->find('#item')->present()` |
| Wait and assert that it matches | `$browser->find('#item')->present(assert: true)` |
| Resolve one exact node without polling | `$browser->find('#item')->resolve(wait: false)` |
| Retain the original node instead of following replacements | `$browser->find('#item')->resolve()` |
| Read current collection membership | `$browser->findAll('.row')->count()` |
| Materialize exact nodes as a PHP array | `$browser->findAll('.row')->all()` |

For a fixed-membership `ElementSet`, construct one from the materialized nodes:

```php
use ThriveData\ThrivePHP\WebDriver\ElementSet;

$rows = new ElementSet(...$browser->findAll('.row')->all());
```

Replace callback conditions that merely return `find()` or a search-backed
`first()`. Those return target objects even without a matching node. Test
`present()`, `visible()`, or `count()` instead.

Update the renamed APIs:

| 1.2.0 call | 1.3.0 call |
| --- | --- |
| `$element->displayed()` | `$element->visible()` |
| `$element->attribute(name: 'title')` | `$element->attribute(key: 'title')` |
| `$element->property(name: 'value')` | `$element->property(key: 'value')` |

Conditions are boolean queries by default. Use `assert: true` to record an
assertion and return the receiver, `is: false` to negate, and `stop: false` to
continue after a failed assertion under an active test. Continuing still fails
the test; outside a test, failed assertions throw. Queries check once by default,
while assertions poll unless `wait: false` is supplied.

Where an action previously needed to fail immediately, use its `wait: false`
option, for example `$element->click(wait: false)`. See the
[browser contract](doc/webdriver-contract.md) for acquisition, retry boundaries,
and non-retrying operations.

#### Adopting the test runner and CLI

The framework test runner and CLI are new in this release; existing applications
do not have to adopt them to continue using the framework.

- Put suites in `test/cases` by default. Use `Suite` for PHP checks or
  `SuiteBrowser` for a managed browser session. Use the released `#[Test]`,
  `#[Before]`, and `#[After]` attributes; `case: true` selects per-case hooks.
- Run suites from the application root with:

  ```sh
  php vendor/peczuh/thrivephp/bin/cli.php --root . test
  ```

  Alternatively, add a top-level `"root": "."` to application `settings.json`
  for automatic discovery. Selectors use suite-relative paths and case method
  names, not descriptions.
- Use `--casepath` and `--savepath` for alternate suite and report locations.
  Explicit relative paths resolve against the project root. By default, runs
  write `report.json`, `report.html`, and enabled captures beneath
  `test/runs/<timestamp>/`.
- Put required initialization in application bootstrap or suite hooks. Parallel
  workers receive effective settings but do not inherit arbitrary parent-process
  globals or closures. Use one worker for checks that require parent-only state.
- Review `skipRemainingOnFailure` when moving dependent browser cases into suites.
  Its default is true for `SuiteBrowser` and false for ordinary `Suite`. Retries
  do not undo application data changes.
- Enable BiDi explicitly for network interception or body collection. Automatic
  log/network capture also requests BiDi. Browser logs and HTTP error statuses
  are diagnostics, not automatic assertion failures; requested capture failures
  and managed interception errors remain execution errors.
- Treat saved reports and captures as application data. Supplied inputs and raw
  network metadata are not automatically redacted.

See [testing](doc/testing.md), [CLI usage](doc/cli.md), and
[BiDi behavior and browser limitations](doc/webdriver-bidi.md) for the complete
configuration and lifecycle contracts.

---

## 1.2.0

### Key features and changes

- Added an RFC 6455 text WebSocket client with `ws://` and verified `wss://`
  connections, bounded operation timeouts, ping/pong handling, and graceful close.
- Added classic HTTP WebDriver sessions and optional raw WebDriver BiDi commands,
  subscriptions, and events.
- Added local browser/driver discovery and owned driver-process startup and
  cleanup for Chrome, Chromium, Firefox, Safari, and Safari Technology Preview.
- Added fluent window/frame, element, shadow-root, cookie, alert, script, keyboard,
  and pointer operations.
- Added eager PNG screenshots and PDF captures that remain usable after session
  cleanup.

### Breaking changes

No existing 1.1.0 APIs were changed; this release adds browser and transport
components. The database namespace migration from 1.1.0 still applies to
applications upgrading directly from 1.0.0.

### Migrating from 1.1.0

1. Update the package:

   ```sh
   composer require peczuh/thrivephp:1.2.0
   ```

2. Existing applications that do not use the new components need no API changes.
3. To use local browser automation, install a browser and its matching WebDriver
   executable. ThrivePHP discovers installed tools; it does not provision them.
   `WebDriver::bootstrap()` reports inventory without launching a service.
4. Put owned-driver cleanup in `finally` with `$driver->stop()`. For a direct
   `Browser` connection to an externally managed driver, end the session with
   `$browser->quit()` instead.
5. Request BiDi explicitly with `bidi: true` when needed. Secure WebSocket
   connections require OpenSSL support.

In 1.2.0, automatic local selection prefers Safari Technology Preview, then Safari,
Chrome, Chromium, and Firefox. Owned sessions are headed by default. Both defaults
change in 1.3.0.

---

## 1.1.0

### Key features and changes

- Moved the database API into the `ThriveData\ThrivePHP\Database` namespace,
  removing the redundant `Database` prefix from class names.
- Updated the `DB` facade and `Session` callers to use the new names.
- Preserved the database operations and session authentication behavior while
  reorganizing the classes.

### Breaking changes

The old database class names were removed without compatibility aliases. Imports,
fully qualified names, type declarations, inheritance, exception catches, and
stored class-name strings must use the new namespace.

Both columns below are relative to `ThriveData\ThrivePHP\`:

| 1.0.0 class | 1.1.0 class |
| --- | --- |
| `DatabaseConnection` | `Database\Connection` |
| `DatabaseResult` | `Database\Result` |
| `DatabaseCondition` | `Database\Condition` |
| `DatabaseDateTime` | `Database\DateTime` |
| `DatabaseDateInterval` | `Database\DateInterval` |
| `DatabaseException` | `Database\Exception` |
| `DatabaseClientException` | `Database\ClientException` |
| `DatabaseServerException` | `Database\ServerException` |
| `DatabaseNoResult` | `Database\NoResult` |
| `DatabaseManyResults` | `Database\ManyResults` |
| `DatabaseDataException` | `Database\DataException` |
| `DatabaseDataTypeMismatch` | `Database\DataTypeMismatch` |
| `DatabaseNotNullViolation` | `Database\NotNullViolation` |
| `DatabaseUndefinedColumn` | `Database\UndefinedColumn` |
| `DatabaseUniqueViolation` | `Database\UniqueViolation` |
| `DatabaseCheckViolation` | `Database\CheckViolation` |
| `DatabaseExclusionViolation` | `Database\ExclusionViolation` |
| `DatabaseInvalidTextRepresentation` | `Database\InvalidTextRepresentation` |
| `DatabaseForeignKeyViolation` | `Database\ForeignKeyViolation` |

Database result conversion now returns the renamed date/time classes. Code that
checks their types or persists their class names must also be updated.

### Migrating from 1.0.0

1. Update references using the mapping above. For example, replace:

   ```php
   use ThriveData\ThrivePHP\DatabaseConnection;
   use ThriveData\ThrivePHP\DatabaseNoResult;
   ```

   with:

   ```php
   use ThriveData\ThrivePHP\Database\Connection;
   use ThriveData\ThrivePHP\Database\NoResult;
   ```

2. Update constructor calls, type declarations, and catches that use those imports.
   An import alias can retain a local identifier while targeting the new class:

   ```php
   use ThriveData\ThrivePHP\Database\NoResult as DatabaseNoResult;
   ```

3. Review cached or serialized framework objects and stored class-name strings
   before upgrading. Recreate or migrate them if they reference removed names.
4. Update the package and test query results and exception handling:

   ```sh
   composer require peczuh/thrivephp:1.1.0
   ```

Calls through `ThriveData\ThrivePHP\DB` keep the same facade API. This namespace
change does not require a database schema migration.

---

## 1.0.0

### Key features and changes

- Published the first version-tagged release of the framework accumulated during
  the 0.9 development period.
- Included the application, routing, request/response, settings, logging,
  PostgreSQL, session, and permissions APIs described in the 0.9 section.
- Declared PHP 8.0 or later in Composer.

### Breaking changes

The `v1.0.0` tag has the same source tree as its first parent. It introduces no
additional source changes relative to the final pre-release revision. Applications
using earlier 0.9 revisions may still need the pre-release migrations in the 0.9 section.

### Migrating from 0.9

1. Apply the 0.9 migration checklist if the application uses an older development
   revision.
2. Select the tagged package version in the application:

   ```sh
   composer require peczuh/thrivephp:1.0.0
   ```

3. Confirm that the application's web and CLI runtimes use PHP 8.0 or later and
   enable short opening tags where needed. Database and HTTP-client users also
   need the PostgreSQL and cURL extensions respectively.
4. Check routing, redirects, authentication, permission checks, and database
   exception handling against the application's existing behavior.

---

## 0.9

### Key features and changes

- Established the `ThriveData\ThrivePHP` framework with Composer autoloading.
  The package name became `peczuh/thrivephp`.
- Added application initialization, root-path discovery, web routing, and
  application command registration and dispatch.
- Added project and local JSON settings, dot-separated settings lookups, logging,
  PHP error handling, and contextual exceptions.
- Added the cURL HTTP client, including separate query-parameter arrays.
- Added PostgreSQL queries, inserts, updates, result conversion, and typed
  database exceptions. Split the database classes into separately autoloaded files.
- Added session authentication, role and permission checks, cached permissions,
  and database provisioning SQL.
- Introduced `Request` as the common source of HTTP request data. Added response
  helpers, including `Response::failure()`, and corrected redirects containing
  query strings.
- Added configurable authentication redirects, URL-encoded referrers, user email
  in session data, permission-check tracing, and application-start timing.

### Breaking changes during pre-release development

There is no single 0.9 snapshot. These changes happened within the pre-release
period and may affect applications that still use an older development revision:

- Framework loading became Composer-only. Application roots use the global
  `PATH_ROOT` constant rather than `Application::PATH_ROOT`.
- Request properties moved from `Router::$host`, `Router::$method`, and
  `Router::$url` to the corresponding properties on `Request`.
- `Response::redirect()` changed from accepting a format string and additional
  values to accepting one complete URL.
- The permission constant `ACL::create` became `ACL::insert`.
- Cached permission checks changed to read `$_SESSION['permissions']`, with
  superuser state in `$_SESSION['user']['superuser']`.
- Shared permission and session queries moved to database objects in the
  `public` schema, including `public.permissions_check()` and
  `public.users_sessions`. Some application-specific queries still reference
  `pz.users`; changing the PHP package alone does not supply those objects.

### Migrating older pre-release applications

1. Require `peczuh/thrivephp` in the application's Composer configuration and load
   the application's `vendor/autoload.php`. Replace manual framework-file includes
   and obsolete autoload setup.
2. Replace application-root constant references and request-property access:

   - Replace `Application::PATH_ROOT` with `PATH_ROOT`.
   - Replace `Router::$host` with `Request::$host`.
   - Replace `Router::$method` with `Request::$method`.
   - Replace `Router::$url` with `Request::$url`.
   - Replace `ACL::create` with `ACL::insert`.

3. Build redirect URLs before passing them to `Response::redirect()`. For example,
   replace `Response::redirect('/users/%s', $id)` with
   `Response::redirect(sprintf('/users/%s', $id))`.
4. Review login/session population code for the current permission keys. Rebuild
   cached session data when its structure changes.
5. Review the SQL objects used by `ACL` and `Session` against the application's
   schema, and supply any required application migrations. Do not treat the
   provisioning scripts as an automatic upgrade for an existing database.
