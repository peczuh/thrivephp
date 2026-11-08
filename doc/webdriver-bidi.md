# WebDriver BiDi and HTTP coordination

Classic WebDriver remains the browser API's baseline. Optional BiDi supplies
events and commands over a session-owned WebSocket. The dispatcher supports the planned
logging, network, browsing-context, and script features.
`Browser::bidi()` exposes the internal dispatcher for feature implementations;
its listener/decision API is internal, not the public network interception API.

## Transport and dispatch

`WebSocket` retains partial frame headers, payloads, fragmented text, and outgoing
write offsets. `pump()` advances available I/O. `poll()` returns a complete text
message, `false` for incomplete/idle input, or `null` for normal peer closure.
Blocking send/receive/close remain available. Protocol failures and blocking
operation timeouts close the connection; an idle poll does not.

`BiDi` owns command IDs, pending responses, internal listeners, and the low-level
event queue. Replies can arrive out of order. Internal observers do not consume
`Browser::event()` entries. Each received event is observed once, even when a
caller later retrieves it from the raw queue. The raw event queue consumes
memory until its owner consumes or releases it; no entries are
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
the coordinator can replace bounded polling without changing feature APIs.

Sources:
- [PHP 8.5 cURL multi binding](https://github.com/php/php-src/blob/PHP-8.5/ext/curl/multi.c)
- [libcurl multi interface and blocking restrictions](https://curl.se/libcurl/c/libcurl-multi.html)
- [curl_multi_wait and extra descriptors](https://curl.se/libcurl/c/curl_multi_wait.html)
- [curl_multi_poll](https://curl.se/libcurl/c/curl_multi_poll.html)
- [WebDriver BiDi specification](https://www.w3.org/TR/webdriver-bidi/)

## Browser verification

Use `php -d short_open_tag=1 test/webdriver-bidi-browser.php chrome` or `firefox`
for native response-required dispatch and session-lifecycle checks.
`test/webdriver.php` independently exercises HTTP/BiDi coordination, out-of-order
replies, and reentry rejection.

Observed during implementation:

- Firefox 147.0.1 / GeckoDriver 0.37.1 completed interception while a classic HTTP navigation remained pending.
  Owned GeckoDriver services request an OS-allocated BiDi port instead of sharing
  the default 9222, which caused parallel sessions to connect to the wrong browser.
- Chrome 156.0.8078.80 mixed classic navigation
  and BiDi interception hung, consistent with
  [Selenium issue 17373](https://github.com/SeleniumHQ/selenium/issues/17373).
  Its native interception test therefore explicitly uses BiDi navigation.
  ThrivePHP does not silently replace classic navigation with BiDi.
  Last-window deletion can end its BiDi stream without a close frame. The client
  accepts EOF between messages only when the HTTP response confirms no windows
  remain; truncated messages and connection loss with remaining windows still fail.
- Safari Technology Preview 27.0 / driver 21626.1.6.19.1 exposed an experimental
  WebSocket with `safari:experimentalWebSocketUrl`, but its subscription response
  lacked the required subscription ID. A working socket alone does not establish
  support for every BiDi module.
- Release Safari 26.6.2 did not return the requested BiDi WebSocket URL.

These observations apply to those versions, not all future versions or every
BiDi module. Native macOS results do not establish native Linux/Windows execution.
