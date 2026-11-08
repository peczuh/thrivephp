# WebDriver BiDi and HTTP coordination

Classic WebDriver remains the browser API's baseline. Optional BiDi supplies
events and commands over a session-owned WebSocket. The dispatcher is shared by
logging, network observation/interception, and the planned browsing-context and script features.
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

## Network observation

```php
$browser = $driver->browser(bidi: true);
$network = $browser->network()->startCapture();
$browser->navigate('https://app.example.test');
$posts = $network->requests(fn (array $hop): bool => $hop['request']['method'] === 'POST');
$network->stopCapture();
$browser->quit();
$history = $network->requests();
$error = $network->captureError();
```

`Browser::network()` returns the same `Network` object for the session. Obtaining
it does not subscribe; `startCapture()` explicitly starts session-wide observation
of `network.beforeRequestSent`, `network.responseStarted`,
`network.responseCompleted`, and `network.fetchError`. Repeated starts do not
duplicate subscriptions. Capture is independent of console capture and raw
caller subscriptions. These methods do not create test steps or assertions.

`requests()` returns one value snapshot per observed redirect hop, in
first-observed order:

| Field | Value |
| --- | --- |
| `sessionId` | Original session ID, retained after session deletion |
| `requestId` | Protocol request ID, reused across redirect hops |
| `redirectCount` | Protocol redirect count identifying the hop |
| `request` | Latest observed protocol request data |
| `response` | Latest observed protocol response data, or `null` |
| `events` | Arrival-ordered event envelopes containing `type`, `method`, and unmodified `params` |

Earlier event snapshots remain available when later events change request or
response data. Headers remain ordered lists with duplicate names and protocol
byte values intact. Capture includes raw metadata such as URLs, headers, and
cookies. Request and response body contents are not collected by these events.
Body retrieval requires separate BiDi data-collection support.

Reads collect available input and never consume history or `Browser::event()`
entries. The optional predicate runs outside dispatch, after collection, and
receives each hop. A predicate exception propagates without becoming a capture
failure. Existing `Browser::wait()` can poll `requests()` for an expected event.

Stopping removes only this collector's subscription and listeners, including
events observed during the stop operation. Restarting appends to history;
matching request IDs and redirect counts continue the same hop. Capture may
start or stop mid-request and may have gaps between capture periods. Missing
events remain missing; neither a response nor a completion is fabricated.
The latest request/response fields summarize observed events, not a guarantee
that capture covered the whole request.

History has no automatic eviction and survives stop, session cleanup, and
capture failure. `captureError()` inspects failure state without network I/O.
HTTP error statuses and `network.fetchError` entries are diagnostic data, not
collector failures. A failed capture operation throws and records its failure;
after a lost connection, retained entries remain readable. A successful restart
clears the prior capture error, retaining history. Session cleanup performs final
collection before deletion, but events generated after that collection or during
remote shutdown are not guaranteed. Explicit BiDi is required; there is no
protocol fallback. Set `SuiteConfig::$browserNetwork = true` for automatic suite
capture and console/JSON/HTML reporting. Explicit capture started under an active
test also reports events, with each event attributed to the attempt, phase, hook,
and step where it was observed. See [network reporting](testing.md#browser-network-observation).

## Request interception and mocking

```php
use ThriveData\ThrivePHP\WebDriver\NetworkDecision;

$browser = $driver->browser(bidi: true);
$network = $browser->network();
$id = $network->intercept(
    fn (array $event): NetworkDecision => NetworkDecision::respond(
        status: 200,
        headers: [['Content-Type', 'application/json']],
        body: '{"available":true}',
    ),
    urlPatterns: [['type' => 'string', 'pattern' => 'https://app.example.test/api/availability']],
);
// Perform browser operations that make the request, using a tested navigation path below.
$network->removeIntercept($id);
$network->stopInterception();
```

`intercept(callable $handler, array $urlPatterns = [], string $phase = 'beforeRequestSent')`
returns the remote intercept ID. The default phase is `beforeRequestSent`;
`authRequired` supports the authentication decisions below. Patterns
are BiDi URL-pattern objects, not PHP regular expressions or shell globs; an empty
list matches all requests. The browser supplies matching intercept IDs. Among
active managed rules, the first registered matching rule handles the pause,
regardless of the order of IDs in the event. An explicit continue ends rule
evaluation. Foreign-only pauses are ignored. Manual controllers must not also
decide pauses owned by these managed rules.

The handler receives the event's `params`, including `request`, `redirectCount`,
and `intercepts`. It must return one of these values:

- `NetworkDecision::continueRequest()` passes through unchanged. Optional `url`,
  `method`, `headers`, and `body` replace the supplied fields. `null` leaves a field
  unchanged; `[]` supplies an empty header list and `''` supplies an empty body.
- `NetworkDecision::respond(int $status = 200, array $headers = [], string $body = '')`
  supplies a response. Status must be 100 through 599.
- `NetworkDecision::fail()` makes the browser fail the request. An intentional
  fail decision is not an interception error.

Headers are ordered `[name, value]` string pairs and preserve duplicate names.
UTF-8 header values use BiDi string values; other bytes use base64. Bodies are PHP
byte strings, encoded as BiDi base64 values. Supplying bodies does not enable
captured-body retrieval. Browser limitations on overrides still apply.

Decision values perform no I/O. The dispatcher sends the returned decision and
checks its reply. Handlers cannot call browser operations, including during
replay of events received before `addIntercept` returns its ID. Handlers run
synchronously; a nonreturning callback prevents same-thread servicing and cleanup.
There is no background event loop while application code is idle. Browser
operations and test checkpoints service events.

Capture and interception have separate subscriptions and lifecycles. Interception
alone does not populate `requests()` or enable raw metadata reporting. Stopping
capture does not stop interception. Removing a rule stops its handler immediately;
requests already decided retain that decision. A failed removal retains ownership
and passes subsequent matching pauses through without calling that handler.

`removeIntercept($id)` is repeatable for a removed managed ID. Unknown IDs throw.
`stopInterception()` removes all remaining rules and settles known decisions
under one shared command-timeout budget. Ordinary removal errors do not prevent
attempts to remove the other rules. Successful stop leaves the session usable.
Removed IDs and a passive responder remain until session cleanup because removing
an intercept does not release already-paused requests or fence late events.
Late owned pauses continue without invoking retired handlers. Session deletion
releases remote pauses directly; it does not need to remove each rule first.

Handler exceptions, invalid return values, and rejected decisions are retained in
`interceptionError(): ?NetworkError`, independently of `captureError()`. The first
original exception remains `getPrevious()`. `failures()` returns arrival-ordered
`{requestId, redirectCount, phase, error}` entries, with nullable correlation for
controller/transport failures. Recovery and cleanup errors append to this list.
For a handler failure or rejected continuation/response, the controller attempts
at most one fail recovery for the still-current pause. It does not retry after
`no such request`, a failed fail decision, or a newer pause of the same request.
Older replies cannot clear newer occurrences.

Managed errors do not escape the dispatcher or become capture failures. Active
test checkpoints report every retained failure once per attempt, including later
recovery failures and failures carried into retries. Explicit `stopInterception()`
throws the retained aggregate after cleanup, including on repeated calls. Errors
are not reset by stopping or adding another rule. Transport failures still throw
from the detecting operation. Deadline exhaustion during stop invalidates BiDi
rather than leaving ambiguous pending replies; HTTP session cleanup remains
available. Failed cleanup cannot guarantee that every remote pause was released.

## Authentication challenges

Register an `authRequired` rule to answer HTTP authentication challenges. This
example supplies credentials once per request and cancels a repeated challenge:

```php
$answered = [];
$id = $network->intercept(
    function (array $event) use (&$answered, $username, $password): NetworkDecision {
        $request = $event['request']['request'];
        if (isset($answered[$request])) return NetworkDecision::cancelAuth();
        $answered[$request] = true;
        return NetworkDecision::provideCredentials($username, $password);
    },
    urlPatterns: [['type' => 'string', 'pattern' => 'https://app.example.test/protected']],
    phase: 'authRequired',
);
```

Use `bidi: true` for a directly created session, or `$config->bidi = true` in a
browser suite. Authentication does not enable metadata capture. Each phase
subscribes lazily; request-only interception does not subscribe to auth events.
Both phases share first-matching-rule precedence, removal, the stop deadline,
and retained error reporting. Response-phase interception is not supported.

Authentication handlers return one of:

- `provideCredentials(string $username, string $password)` sends password
  credentials through `network.continueWithAuth`. Empty strings and `'0'` are
  preserved. The browser handles the authentication scheme.
- `defaultAuth()` resumes the browser's normal authentication handling. It may
  open a prompt, reject the request, or leave a navigation pending.
- `cancelAuth()` cancels the challenge. An intentional cancellation is not a
  controller error.

The handler receives raw event parameters. When supplied by the browser,
`response.authChallenges` lists `{scheme, realm}` entries; it is not an attempt
counter. Repeated challenges can reuse both the request ID and redirect count.
The controller calls the handler for each owned pause and does not retry
credentials automatically. Handlers choose whether to supply new credentials or
cancel, as in the bounded example above. Internal occurrence tokens protect a
newer challenge from an older command reply, including replies from the request
phase.

Using a request decision during authentication, or an authentication decision
during the request phase, records a contract failure. Authentication handler
exceptions, invalid returns, and rejected recoverable decisions attempt at most
one cancellation for the still-current pause. `network.failRequest` is not valid
at this phase. Rejected cancellations and `no such request` do not trigger another
recovery. The original and recovery failures retain `phase: 'authRequired'` and
use the existing attempt reporting, including with capture disabled and across
retries.

Removing an authentication rule stops its handler. Late owned authentication
pauses use **browser-default handling**, not cancellation. A rejected default
decision can attempt one cancellation and retains the rejection as an error.
Successful `stopInterception()` removes active rules and settles known decisions;
it does not promise that requests, authentication prompts, or navigations have
completed. Passive responders remain until session cleanup.

Credential decision parameters are not added to report fields or error metadata,
and settlement callbacks do not retain those parameters. This is not a redaction
guarantee for browser error text or user-thrown messages. Existing opt-in raw
capture can include authentication headers. The four-event capture list remains
unchanged; authentication challenge metadata is available to the handler and raw
event queue.

Native verification covers HTTP Basic on the versions below. Digest and proxy
authentication are not certified by these tests.

## Transport and dispatch

`WebSocket` retains partial frame headers, payloads, fragmented text, and outgoing
write offsets. `pump()` advances available I/O. `poll()` returns a complete text
message, `false` for incomplete/idle input, or `null` for normal peer closure.
Blocking send/receive/close remain available. Protocol failures and blocking
operation timeouts close the connection; an idle poll does not.

`BiDi` owns command IDs, pending responses, internal listeners, and the low-level
event queue. Replies can arrive out of order. Internal observers do not consume
`Browser::event()` entries. Each received event is observed once, even when a
caller later retrieves it from the raw queue. Retained logs, network hops, and the raw
event queue consume memory until their owner releases them; no entries are
silently dropped to enforce a retention cap.

The dispatcher processes bounded event batches so traffic does not prevent HTTP
progress or deadline checks. Available-input collection drains those batches
under one deadline. Internal listeners may return a `{method, params}` decision;
the dispatcher queues that protocol command and checks its reply. Browser calls
inside a listener are rejected. User code executes synchronously and blocks
servicing until it returns. Deadline checks cannot interrupt arbitrary PHP code.
Exceptions and failed internal response commands without a feature-specific
settler propagate and invalidate BiDi; classic HTTP cleanup remains available.
Managed network decisions attach a guarded settler that records rejection and
may return one recovery command. Transport and malformed-envelope failures remain
fatal. Feature-specific handling of pauses belongs to the corresponding controller.

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
`test/webdriver-network-browser.php [chrome|firefox]` uses a loopback HTTP fixture
to check redirect correlation, concurrent fetches, HTTP/fetch errors, independent
stop/restart, retained metadata, and last-window cleanup. Run it with
`php -d short_open_tag=1`; its HTTP fixture requires `pcntl`.
`test/webdriver-intercept-browser.php [chrome|firefox]` checks native mocking,
header and URL replacement, method/body replacement, failed fetches, session
reuse after stop, and suite errors with capture disabled. Use the same PHP option.
Protocol fixtures additionally cover registration races, overlapping rules,
retired ownership, out-of-order settlement,
recovery errors, shared cleanup deadlines, and reporting across retries.
`test/webdriver-auth-browser.php [chrome|firefox]`, with the same PHP option,
checks Basic credentials, cancellation, wrong credentials followed by correction
or cancellation, browser-default acknowledgement, request/auth rule coexistence,
capture independence, handler-error recovery, stop, and cleanup. Fresh sessions
prevent cached credentials from hiding challenges. Reuse is checked after
credential and cancellation paths, not after browser-default handling.

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
- Chrome 156 and Firefox 147 network observation reused a request ID across three
  redirect hops with redirect counts 0, 1, and 2. The fixture served
  `302`, `307`, `200`; earlier Chrome probes reported `302`, `302`, `200`, while
  the native observation test reported the served statuses on both browsers.
  The Chrome discrepancy was not reproduced on every run. Capture preserves
   browser-reported values without correction.
- Chrome 156 sent base64 request-header values literally in the native test.
  UTF-8 string header values worked in both tested browsers; arbitrary non-UTF-8
  header bytes are protocol-encoded but are not certified on Chrome 156.
  Firefox 147 applied header replacement alone but dropped it when the same
  continuation also replaced the URL. The native test verifies these replacements
  separately; ThrivePHP does not split one decision into multiple commands.
- Firefox 147 classic navigation hung after an intentional `failRequest`, and
  later HTTP commands timed out. The native failure test uses `fetch()` and checks
  both promise rejection and `network.fetchError`, then verifies continued session
  use. This does not establish failed-navigation support for that driver version.
- Chrome 156 and Firefox 147 both satisfied HTTP Basic with supplied credentials
  and reissued challenges after wrong credentials with the same request ID and
  redirect count. Both accepted correction or cancellation on the second challenge.
  Chrome omitted `response.authChallenges`; Firefox supplied the scheme and realm.
- Both browsers acknowledged `continueWithAuth(action: 'default')`. In navigation
  probes, Chrome reported `net::ERR_INVALID_AUTH_CREDENTIALS`; Firefox left even a
  `wait: 'none'` navigation command pending until the command timeout invalidated
  BiDi. Fetch-based probes separated the authentication acknowledgement from
  navigation, and both completed rule removal and session cleanup. The native
  authentication test checks that separation rather than claiming default auth
  completes a navigation or permits every subsequent browser operation.
- Safari Technology Preview 27.0 / driver 21626.1.6.19.1 exposed an experimental
  WebSocket with `safari:experimentalWebSocketUrl`, but its subscription response
  lacked the required subscription ID. Managed capture rejects that response;
  it cannot claim independent subscription ownership. A working socket alone
  does not establish log-capture support.
- Release Safari 26.6.2 did not return the requested BiDi WebSocket URL.

These observations apply to those versions, not all future versions or every
BiDi module. Native macOS results do not establish native Linux/Windows execution.
