---
name: webdriver
description: Develop, review, and troubleshoot ThrivePHP's WebDriver inventory, local driver lifecycle, fluent browser API, and protocol tests.
---

# WebDriver

## When to use this guide

Apply this guide when working in `src/WebDriver/` or changing callers and tests
that depend on this module. Preserve the contracts below unless the requested
task explicitly changes them.

The module targets PHP 8.5 or later, uses the namespace
`ThriveData\ThrivePHP\WebDriver`, and follows the repository's existing PSR-4
autoloading. Use `<?php`, tabs, and the surrounding PHP syntax conventions.

## Responsibility map

| File | Responsibility |
| --- | --- |
| `WebDriver.php` | Public inventory entry point and owned local driver runtime: construction, session factory, ports, readiness, and cleanup. |
| `Discovery.php` | Local platform detection, executable candidates, version probes, and inventory states. |
| `DriverProcess.php` | Child-process execution, captured diagnostics, bounded waits, termination, and reaping. |
| `Browser.php` | One HTTP WebDriver session, optional BiDi transport, fluent facade, shared window identities, and actual context tracking. |
| `Window.php` | Window/frame operations, argument/reference conversion, cookies, and captures. |
| `Element.php` | Element interactions and observations in the element's captured context. |
| `ShadowRoot.php` | Searches and references bound to the host's captured context. |
| `ElementSet.php` | Ordered, fixed-membership search results. |
| `Screenshot.php`, `PDF.php` | Eager capture values independent of session lifetime. |

The shared WebSocket transport lives in `src/WebSocket.php`. Application
bootstrap, not this module, installs browsers and drivers.

## Bootstrap: local inventory only

`WebDriver::bootstrap()` returns a JSON-serializable `stdClass`. It is separate
from routine test startup and performs no downloads, installation, release
metadata requests, driver-service launches, or browser-session creation.

The report contains:

- `ready`: an overall boolean.
- `platform`: OS, architecture, and distribution where applicable.
- `browsers`: exactly one entry each for `chrome`, `chromium`, `firefox`,
  `safari`, and `safaripreview`, including absent and unsupported targets.
- Each browser entry contains `name`, `ready`, `path`, `version`, `reason`,
  `message`, and a nested `driver` with the same component fields.
- Unavailable or inapplicable paths, versions, reasons, and messages are null.

Component readiness has five possible values:

| Value | Meaning |
| --- | --- |
| `true` | Installed and identifiable through the platform-appropriate version check. |
| `"unprovisioned"` | Executable was not found. |
| `"unsupported"` | The target is unsupported on the detected platform. |
| `"notready"` | Executable was found, but a known execution failure prevents use. |
| `"unknown"` | A discovery or probe failure prevents determining its condition. |

Use strict comparisons: **one pair with both states `=== true` is sufficient
for overall readiness**. Nonempty state strings are truthy PHP values and must
never count as ready.

Discovery/probe failures belong in the report with a machine-readable reason
and a useful message. Programming errors propagate normally; do not blanket-catch
all `Throwable` instances and turn implementation bugs into `"unknown"`.

Readiness establishes local installation and identity. It does not certify
browser/driver compatibility, Safari automation authorization, display
availability, or successful session creation.

### Platform discovery

Support macOS, Debian and Alpine Linux, FreeBSD, and Windows. Inspect conventional
installation locations and `PATH`; do not recursively search the filesystem.
Report the actual selected executable path and version. Preserve the distinction
between a missing executable and an inaccessible discovery location.

- **macOS:** use application-bundle metadata where appropriate. Safari has no
  documented browser `--version` contract. Native SafariDriver is supplied by
  macOS; do not substitute an unrelated driver found on `PATH`. `safaripreview`
  discovers `Safari Technology Preview.app` in system/user Applications and uses
  only the `safaridriver` inside the selected app bundle.
- **Debian:** include distribution browser wrappers and Firefox ESR locations.
- **FreeBSD:** Chromium's conventional executable is `/usr/local/bin/chrome`.
  Native Google Chrome is unsupported; Chromium is a separate target.
- **Windows:** include conventional system/user application directories and
  drivers on `PATH`. Environment-variable names are case-insensitive. Browser
  file-version metadata avoids relying on GUI executable CLI behavior.
- **Safari/Preview/SafariDriver:** macOS-only. Enabling remote automation changes system
  configuration and is not an inventory operation.

Keep probes bounded and avoid opening a browser GUI. If an operation temporarily
replaces a PHP error handler, restore it in `finally`.

## Runtime and session ownership

Construction starts the driver service: `new WebDriver(...)`. There is no
separate `launch()` step. Runtime discovery is fresh and does not require a
previous bootstrap report.

- Automatic preference is **Chrome → Chromium → Firefox → Safari Technology Preview → Safari**.
- Honor an explicitly requested browser without substituting another browser.
- After choosing a ready pair, startup/session failure throws rather than
  silently changing browsers.
- The constructor waits for service readiness but does not create a session.
- Preferred ports are Safari/Preview `4445`, Chrome/Chromium `9515`, and Firefox `4444`.
  These are library defaults, not assumptions about executable-version defaults.
- An occupied preferred port emits an `error_log()` warning and selects another
  available port. An explicitly requested occupied port fails.
- Never adopt or stop an existing service discovered on a port.
- Use argument-vector process execution, local-only service binding, captured
  diagnostics, and bounded startup/termination waits. ChromeDriver's
  `--allowed-ips` switch enables remote binding even with a loopback allowlist;
  its default local binding is intentional.

`$driver->browser()` creates the sole active owned `Browser` session. Carry the
selected executable into browser capabilities, and map Chromium to protocol
browser name `chrome`, and `safaripreview` to `Safari Technology Preview`.
Safari variants select their browser through the paired driver and protocol name.
Preserve caller options without allowing them to replace the selected executable.

Headless belongs to the session factory and defaults to true. Safari and Preview reject
headless requests and require `headless: false`. Automatic selection prefers Chrome,
Chromium, then Firefox before either Safari variant.

`Browser::quit()` ends the session and leaves the driver service available for a
subsequent session. Closing the last window also ends that session. `active()`
tracks whether the client holds a session ID; it is not a remote liveness probe.

`$driver->stop()` attempts session deletion, then driver termination and reaping,
even when session cleanup fails. Explicit cleanup reports errors after attempting
cleanup; shutdown/destructor cleanup logs errors instead of throwing. Preserve
the process handle when termination fails so another `stop()` can retry.
Successful cleanup is repeatable and invalidates the owned client's session state.

Failure during remote session creation stops the owned service: a lost response
may have created a session whose ID the client never received. Direct
`new Browser($endpoint, ...)` connections remain available for externally managed
services and are caller-owned.

### Usage

```php
use ThriveData\ThrivePHP\WebDriver\Browser;
use ThriveData\ThrivePHP\WebDriver\WebDriver;

$inventory = WebDriver::bootstrap();

$driver = new WebDriver(browser: 'chrome');
try {
    $browser = $driver->browser(headless: true);
    $browser->navigate('data:text/html,<input id="name">');
    $browser->find('#name')->type('Hello')->key(Browser::TAB);
} finally {
    $driver->stop();
}
```

Import `Browser` when using constants such as `Browser::TAB`; importing
`WebDriver` does not import the session class.

## Fluent API and context contracts

- Commands return their receiver; queries return values or domain objects.
  Preserve documented exceptions such as `open()` returning a new `Window` and
  `Window::close()` returning its `Browser`.
- API replacements do not need backward-compatible aliases. Keep established
  names such as `tag()`, `pdf()`, `cookieDelete()`, `cookieDeleteAll()`,
  `alertAccept()`, `alertDismiss()`, and `alertType()`.
- Browser facade methods delegate to the current window while preserving the
  facade receiver for commands.
- Elements and shadow roots retain their original window and frame path.
  Restore that context before operating. Retrying element comparisons and native
  click/form-entry actions may re-find retained lookups and adopt a replacement
  after success. One-shot actions, observations, and original-node waits retain
  the captured identity; unrecoverable stale nodes and context failures propagate.
- Keep a window's logical frame selection separate from the driver's actual
  context cache. Top-level endpoints must not require restoring stale frames.
- Invalidate actual context after an uncertain frame-changing outcome.
  `defaultFrame()` explicitly resynchronizes the remote context.
- Classify endpoints using the protocol's actual context semantics. PDF printing
  and action release remain frame-dependent; back/forward explicitly select the
  top-level frame before navigation.
- `ElementSet` is an ordered snapshot of membership, not a live query. Empty
  collections are truthy objects: use `first()` or `count()` in waits.
- Script/action conversion preserves valid repeated references, checks reference
  ownership, and rejects recursive or excessively nested arguments with catchable
  errors. The documented traversal limit is 64 levels.
- `cookie($name)` reads a string; a supplied string value sets the cookie and
  returns the receiver. Empty strings and `'0'` must set, not read.
- Captures are eager: `data()` returns decoded bytes and `save()` returns the
  capture. They remain usable after session termination. Screenshots expose
  pixel dimensions; failed or incomplete writes throw.
- `pdf()` uses discrete named parameters; page dimensions and margins are in cm.
  `pageRanges` remains a list. `actions()` remains structured source/action lists.

## Change workflow

1. Inspect the affected implementation, callers, tests, and working-tree changes.
   Treat unfamiliar demo scripts and local changes as user work.
2. Identify which contract is changing and trace it through discovery, process
   startup, capabilities, protocol requests, ownership, and cleanup as applicable.
3. Keep documentation alongside the affected public methods. Explain units,
   defaults, accepted values, array shapes, return values, and meaningful failure
   behavior. Keep facade and implementation signatures/examples consistent.
4. Add focused regression coverage for behavioral changes, particularly context
   restoration, readiness states, process failures, and resource cleanup. Prefer
   observable protocol/process behavior over tests that mirror implementation.
5. Run the relevant checks and report what they actually verify.

## Verification

Run commands from the repository root:

```sh
php test/webdriver.php
php test/webdriver-runtime.php
```

- `test/webdriver.php` exercises HTTP/BiDi contracts using isolated protocol
  peers; its fixture uses `pcntl`.
- `test/webdriver-runtime.php` exercises inventory and lifecycle behavior using
  PHP child processes and `test/fixtures/webdriver-driver.php`, not real browsers.
- Run `php test/websocket.php` when changing the shared WebSocket transport or
  behavior that depends on it.
- Lint changed PHP files with `php -l` and check whitespace with
  `git diff --check`. Documentation-only changes do not require process tests.

Distinguish simulated platform-path coverage, native inventory, native driver
startup, and actual browser-session tests in the handoff. A passing fixture suite
on macOS does not establish native Windows, Linux, or FreeBSD execution. A ready
driver endpoint does not establish that a browser session can be created.

## Protocol references

- [WebDriver](https://www.w3.org/TR/webdriver2/)
- [WebDriver BiDi](https://www.w3.org/TR/webdriver-bidi/)
- [ChromeDriver](https://developer.chrome.com/docs/chromedriver)
- [GeckoDriver](https://firefox-source-docs.mozilla.org/testing/geckodriver/)
- [Safari WebDriver](https://developer.apple.com/documentation/webkit/testing-with-webdriver-in-safari)

Consult the relevant driver documentation/source when platform behavior is
uncertain. Neither an expected fixture exchange nor a remembered vendor default
is sufficient evidence for a protocol assumption.
