# ThrivePHP

ThrivePHP is a PHP framework for web applications, command-line tools, and
automated testing. It includes HTTP routing, JSON settings, logging, PostgreSQL
access, a suite-based test runner, and browser automation through WebDriver and
optional WebDriver BiDi.

## Documentation

- [CLI](doc/cli.md): project discovery, test selection, and command-line options.
- [Settings](doc/settings.md): configuration loading, merging, and runtime overrides.
- [Testing](doc/testing.md): suites, assertions, lifecycle hooks, and reports.
- [WebDriver](doc/webdriver-contract.md): browser targets, conditions, and retries.
- [WebDriver BiDi](doc/webdriver-bidi.md): browser logs, network observation, and interception.

## Installation

Install ThrivePHP in your application with Composer:

```sh
composer require peczuh/thrivephp
```

Composer autoloads the `ThriveData\ThrivePHP\` namespace. Applications use the
installed package in `vendor/`; they do not need a checkout of this repository.

Requires PHP 8.5 or later and the PHP `curl` extension. Some source files use
short PHP opening tags, so enable `short_open_tag` in the PHP configuration used
by your application and CLI:

```ini
short_open_tag = On
```

For the CLI commands below, you may need to add `-d short_open_tag=1` after `php`
if short tags are not already enabled in your CLI configuration.

Enable the PHP `pgsql` extension when using the database components. Browser
automation requires a locally installed browser and its WebDriver executable;
ThrivePHP does not download or install them.

## Application bootstrap

For an application with a front controller at `public/index.php`:

```php
<?php

use ThriveData\ThrivePHP\Application;

require dirname(__DIR__) . '/vendor/autoload.php';

Application::init();
Application::start();
```

`Application::init()` determines the application root from Composer, loads
settings, and installs logging and error handlers. To set the root explicitly,
call `Application::setPathRoot($path)` before initialization.

`Application::start()` dispatches according to the PHP SAPI:

- Web requests use `Router`, which loads the application's `routes.php`.
- CLI invocations use `Commander`, which loads the application's `commands.php`.

The separate framework CLI described below runs tests and displays help. It
does not dispatch application commands from `commands.php`.
Registered application commands can call
[`CLI::dispatch()`](doc/cli.md#registered-application-commands) to run tests with
the same options after application initialization.

Configure your web server to send requests to the front controller. The current
request implementation expects `HTTP_HOST`, `REQUEST_METHOD`, `PATH_INFO`, and
`CONTENT_TYPE` in `$_SERVER`.

## Settings

Settings load from the application root in this order:

1. `settings.json`
2. `local/settings.json`

Missing or unreadable files are skipped during initialization. Files must contain
JSON objects; invalid JSON throws. Objects merge recursively, lists replace
completely, and later scalar or null values replace earlier values. Empty lists
clear lists; empty objects preserve existing object keys.

For example, an application's `settings.json` can contain:

```json
{
  "app": {
    "name": "My application"
  },
  "log": {
    "level": "info"
  }
}
```

After initialization, read values with dot-separated paths:

```php
use ThriveData\ThrivePHP\Settings;

$name = Settings::get('app.name');
$timezone = Settings::fetch('app.timezone') ?? 'UTC';

Settings::set('app.timezone', 'UTC');
```

`Settings::get()` throws when a key is missing or null. `Settings::fetch()` returns
`null` instead. `Settings::set()` replaces a value in memory without editing files.
The test runner passes effective settings to parallel workers.

See the [settings guide](doc/settings.md) for merge rules, typed overrides, and
snapshots.

## Running tests

With the settings above, create a suite in your application's
`test/cases/settings.php`:

```php
<?php

use ThriveData\ThrivePHP\Settings;
use ThriveData\ThrivePHP\Test\Suite;
use ThriveData\ThrivePHP\Test\Test;

return new class($test) extends Suite {
    #[Test('The application name is configured')]
    public function name(): void
    {
        $this->test->equal(Settings::get('app.name'), 'My application', assert: true);
    }
};
```

Run suites from the application root with the installed framework CLI:

```sh
php vendor/peczuh/thrivephp/bin/cli.php --root . test
php vendor/peczuh/thrivephp/bin/cli.php --root . test '^settings$::^name$'
```

`--root .` selects the current application explicitly. To use automatic project
discovery, add a top-level `"root": "."` to the application's `settings.json`.
Use `--help` for options without bootstrapping an application.

Suite and case selectors are regular expressions. Suite names are paths relative
to `test/cases`, without `.php`; case names are method names. Methods use `#[Test]`,
with `#[Before]` and `#[After]` for lifecycle hooks. Extend `SuiteBrowser` instead
of `Suite` for a managed browser session and browser assertions.

```sh
php vendor/peczuh/thrivephp/bin/cli.php --root . test --browser chrome --workers 2
```

Headless execution defaults to true. Safari and Safari Technology Preview require
`--headed --workers 1`. Browserless suites do not require an installed browser.

Each run writes JSON and HTML reports under `work/test/<timestamp>/` by default,
with screenshots when enabled for browser suites. Failures return a nonzero exit
status. Console controls such as `--quiet` do not change saved reports, capture
policies, or exit status.

See the [CLI guide](doc/cli.md) for discovery, selectors, and overrides, and the
[testing guide](doc/testing.md) for lifecycle, assertions, retries, parallel
execution, browser configuration, and reporting.

## Browser automation

WebDriver can also be used without the test runner:

```php
<?php

use ThriveData\ThrivePHP\WebDriver\WebDriver;

require __DIR__ . '/vendor/autoload.php';

$driver = new WebDriver(browser: 'chrome');
try {
    $browser = $driver->browser(headless: true);
    $browser->navigate('data:text/html,<h1>Hello</h1>');
    $browser->find('h1')->textEquals('Hello', assert: true);
} finally {
    $driver->stop();
}
```

Supported browser targets are Chrome, Chromium, Firefox, Safari, and Safari
Technology Preview. Construction starts a local driver service; `browser()`
creates the session, and `stop()` cleans up both. Local inventory through
`WebDriver::bootstrap()` does not start services or certify session compatibility.

The [browser contract](doc/webdriver-contract.md) describes fluent operations,
target resolution, waiting, assertions, screenshots, HTML snapshots, and PDFs.
Optional BiDi provides console/exception capture, network observation,
interception, and body collection. Availability and limitations depend on the
browser; see the [BiDi guide](doc/webdriver-bidi.md).

## Components and reference

- [Application](src/Application.php): initialization and web or CLI dispatch.
- [Router](src/Router.php) and [Route](src/Route.php): regular-expression matching
  for request hosts, URLs, and HTTP methods.
- [Commander](src/Commander.php) and [Command](src/Command.php): command
  registration and dispatch.
- [CLI](src/CLI.php): framework command parsing, project discovery, and test dispatch.
- [Settings](src/Settings.php): recursive JSON configuration and typed overrides.
- [Log](src/Log.php): severity-based logging and PHP error handling.
- [DB](src/DB.php) and [Database](src/Database/): PostgreSQL connections and queries.
- [CURL](src/CURL.php): HTTP requests.
- [Test](src/Test.php) and [Test suites](src/Test/): assertions, lifecycle hooks,
  parallel execution, and console/JSON/HTML reporting.
- [WebDriver](src/WebDriver/) and [WebSocket](src/WebSocket.php): browser automation
  and WebSocket transport.

The guides in [`doc/`](doc/) describe current behavior. Older generated API pages
are available in [`doc/src/`](doc/src/index.html).

## Framework development

Work on the framework in this repository, separately from applications that
install it through Composer.

Run focused regression scripts from the repository root. For example:

```sh
php test/settings.php
php test/test-cli.php
php test/test-suite.php
php test/test-report.php
php test/test-parallel.php
php test/websocket.php
php test/webdriver.php
php test/webdriver-runtime.php
```

These scripts use isolated fixtures and PHP processes, not native browsers.
The WebSocket and WebDriver protocol checks require `pcntl` for local test peers.
Read each script's usage header for its prerequisites.

Native-browser checks are separate, for example:

```sh
php test/webdriver-contract-browser.php chrome
php test/test-report-browser.php chrome 2
```

They require an installed browser/driver pair. See the
[testing guide](doc/testing.md) for additional browser checks. Application suites
may modify application data; use isolated framework checks for framework changes.

## License

ThrivePHP is licensed under the [Mozilla Public License 2.0](LICENSE).
