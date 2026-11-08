# WebDriver BiDi and HTTP coordination

Classic WebDriver remains the browser API's baseline. Optional BiDi supplies
events and commands over a session-owned WebSocket. The dispatcher is shared by
logging and the planned network, browsing-context, and script features.
`Browser::bidi()` exposes the internal dispatcher for feature implementations;
its listener/decision API is internal, not the public network interception API.

## Console and exception capture

```php
$browser = $driver->browser(bidi: true);
$browser->startLogCapture();
$browser->navigate('https://example.test');
$entries = $browser->logs();
$exceptions = $browser->logs(type: 'javascript');
$browser->stopLogCapture();
$browser->quit();
$retained = $browser->logs();
```

Capture subscribes to `log.entryAdded` across the session. Starting twice does
not duplicate subscriptions. Stopping cancels only the collector's subscription;
restarting appends to the retained history. Reading never clears entries.
`logs()` optionally filters by `console` or `javascript`. A `console.error()` is
a console entry, not an unhandled JavaScript exception.

Entries preserve the protocol fields, including type, level, text, timestamp,
source, and any supplied method, arguments, or stack trace. Structured arguments
are BiDi remote values, not live PHP objects. Missing optional fields are normal.
Data is retained after stop, session deletion, or connection failure.
`logCaptureError()` exposes a collection failure separately from browser entries.
The operation detecting a transport failure throws; subsequent retrieval can
still read retained entries. No guarantee covers events generated after final
collection or during remote session shutdown.

These capture methods do not create test steps or assertions. To enable automatic
capture before a browser suite's child setup hooks:

```php
protected function configure(SuiteConfig $config): void
{
    $config->browserLogs = true;
}
```

Explicitly requested but unavailable capture fails setup. Browser-generated
messages are diagnostic and do not change outcomes. Collection failures are
reported as execution errors. Tests can assert against `logs('javascript')` when
they want exceptions to fail a test.

Console, JSON, and HTML use the same recorded entries. Attempt records contain
`browserLogs`, whose elements contain the protocol `entry`, `sessionId`,
`observedAt`, `phase`, `hook`, and nullable `stepId`. The containing suite/case
attempt supplies retry attribution. “Observed during” does not claim the active
operation caused an asynchronous event. Failed attempts retain their logs.

## Transport and dispatch

`WebSocket` retains partial frame headers, payloads, fragmented text, and outgoing
write offsets. `pump()` advances available I/O. `poll()` returns a complete text
message, `false` for incomplete/idle input, or `null` for normal peer closure.
Blocking send/receive/close remain available. Protocol failures and blocking
operation timeouts close the connection; an idle poll does not.

`BiDi` owns command IDs, pending responses, internal listeners, and the low-level
event queue. Replies can arrive out of order. Internal observers do not consume
`Browser::event()` entries. Each received event is observed once, even when a
caller later retrieves it from the raw queue. Both retained logs and the raw
event queue consume memory until their owner releases them; no entries are
silently dropped to enforce a retention cap.

The dispatcher processes bounded event batches so traffic does not prevent HTTP
progress or deadline checks. Available-input collection drains those batches
under one deadline. Internal listeners may return a `{method, params}` decision;
the dispatcher queues that protocol command and checks its reply. Browser calls
inside a listener are rejected. User code executes synchronously and blocks
servicing until it returns. Deadline checks cannot interrupt arbitrary PHP code.
Exceptions and failed internal response commands propagate and invalidate BiDi;
classic HTTP cleanup remains available. Feature-specific handling of paused
requests belongs to the corresponding network/prompt implementation.

`HttpRequest` uses cURL multi for HTTP framing, TLS, and partial transfers. It
advances BiDi while HTTP is pending, including before HTTP response headers arrive.
HTTP timeouts are total transfer budgets in seconds, including connection setup;
script calls retain their additional script-timeout allowance. Redirects are not
followed, TLS certificates are verified, and error bodies remain available for
WebDriver error decoding. Loopback driver endpoints bypass proxy environment
variables. This module requires PHP's `ext-curl`, also used by `src/CURL.php`.
It does not reuse that class's blocking constructor or application HTTP errors.

## PHP cURL limitations and scheduling

PHP 8.5's `curl_multi_select()` calls libcurl's `curl_multi_wait()` with no extra
descriptors. PHP cannot add this WebSocket's stream to that wait. Consequently a
WebSocket event cannot wake an in-progress cURL wait immediately.

While HTTP and BiDi are active, the coordinator caps each cURL wait at **10 ms**
and advances both transports between waits. This is a scheduling interval, not a
latency guarantee: OS scheduling, event processing, and synchronous handlers can
add delay. HTTP-only waits can be longer. This keeps browser event servicing
independent of completion of the HTTP command, at the cost of periodic wakeups.

A local five-run-per-setting transport experiment on PHP 8.5.12/libcurl 8.23.0
withheld HTTP headers until a separate stream's event received a continuation:

| Maximum wait | Observed event latency |
| --- | --- |
| 1 ms | 0.07–1.18 ms |
| 10 ms | 6.85–7.63 ms |
| 50 ms | 47.17–47.63 ms |

These measurements demonstrate scheduling cost, not native-browser throughput
or portable latency bounds. The native tests separately exercise real BiDi.

When libcurl has no descriptors, `curl_multi_wait()` can return immediately. The
coordinator avoids spinning by waiting for a bounded interval in that state.
Multi-stack success is not transfer success: each completion's cURL result is
checked before decoding its WebDriver response.

DNS can still block on builds without c-ares or the threaded resolver. The tested
build reports asynchronous DNS. Owned drivers use numeric loopback addresses;
the framework does not promise nonblocking hostname resolution on every build.

## Upstream ext-curl improvements

These are proposed improvements to PHP's binding, not APIs ThrivePHP assumes
already exist:

1. **Expose extra streams in a multi wait/poll operation.** Libcurl accepts extra
   descriptors; PHP needs a supported mapping from socket streams to those
   descriptors and returned readiness. The API must account for TLS-buffered
   data and stream lifetime. This would allow one wait to wake for HTTP or BiDi,
   removing the 10 ms polling compromise.
2. **Expose socket-action and timer integration.** `curl_multi_socket_action()`,
   `CURLMOPT_SOCKETFUNCTION`, and `CURLMOPT_TIMERFUNCTION`, with a usable PHP socket
   representation, would let a coordinator use its own readiness loop. Exposing
   constant names alone would not be sufficient.
3. **Expose `curl_multi_poll()` semantics.** Unlike `curl_multi_wait()`, it can
   wait even without descriptors. This would remove the no-descriptor spin
   workaround. Without extra-stream support, it would not solve the shared-wait
   problem by itself.

No FFI, custom extension, background process, or raw-descriptor workaround is
required by the implementation. If PHP provides a suitable shared wait API,
the coordinator can replace bounded polling without changing capture APIs.

Sources:
- [PHP 8.5 cURL multi binding](https://github.com/php/php-src/blob/PHP-8.5/ext/curl/multi.c)
- [libcurl multi interface and blocking restrictions](https://curl.se/libcurl/c/libcurl-multi.html)
- [curl_multi_wait and extra descriptors](https://curl.se/libcurl/c/curl_multi_wait.html)
- [curl_multi_poll](https://curl.se/libcurl/c/curl_multi_poll.html)
- [WebDriver BiDi specification](https://www.w3.org/TR/webdriver-bidi/)

## Browser verification

Use `php -d short_open_tag=1 test/webdriver-bidi-browser.php chrome` or `firefox`
for native response-required dispatch and session-lifecycle checks.
`test/webdriver-logs-browser.php [chrome|firefox|safaripreview|safari]` checks native
console and exception capture. Protocol fixtures in
`test/webdriver.php` independently exercise HTTP/BiDi coordination, out-of-order
replies, subscription ownership, reentry rejection, and report attribution.
`test/test-browser-logs.php [chrome|firefox] [1|2]` checks real suite hooks, case
retries, and serial/parallel reports.

Observed during implementation:

- Firefox 147.0.1 / GeckoDriver 0.37.1 delivered logs and exceptions and completed
  interception while a classic HTTP navigation remained pending.
  Owned GeckoDriver services request an OS-allocated BiDi port instead of sharing
  the default 9222, which caused parallel sessions to connect to the wrong browser.
- Chrome 156.0.8078.80 delivered logs and exceptions. Mixed classic navigation
  and BiDi interception hung, consistent with
  [Selenium issue 17373](https://github.com/SeleniumHQ/selenium/issues/17373).
  Its native interception test therefore explicitly uses BiDi navigation.
  ThrivePHP does not silently replace classic navigation with BiDi.
  Last-window deletion can end its BiDi stream without a close frame. The client
  accepts EOF between messages only when the HTTP response confirms no windows
  remain; truncated messages and connection loss with remaining windows still fail.
- Safari Technology Preview 27.0 / driver 21626.1.6.19.1 exposed an experimental
  WebSocket with `safari:experimentalWebSocketUrl`, but its subscription response
  lacked the required subscription ID. Managed capture rejects that response;
  it cannot claim independent subscription ownership. A working socket alone
  does not establish log-capture support.
- Release Safari 26.6.2 did not return the requested BiDi WebSocket URL.

These observations apply to those versions, not all future versions or every
BiDi module. Native macOS results do not establish native Linux/Windows execution.
