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
cookies. Standard event fields do not include body contents, but browser extensions
can: Chrome emits `goog:postData` inside request metadata. Raw capture preserves
these fields. Explicit body reads do not add their results to captured events.
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
`responseStarted` and `authRequired` support the response and authentication
decisions below. Patterns
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
All phases share first-matching-rule precedence, removal, the stop deadline,
and retained error reporting.

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

## Response interception

Use `phase: 'responseStarted'` to decide after the server's response headers
arrive, before its body completes:

```php
$id = $network->intercept(
    fn (array $event): NetworkDecision => NetworkDecision::continueResponse(
        status: 201,
        headers: [['Content-Type', 'application/octet-stream'], ['X-Test', 'modified']],
    ),
    urlPatterns: [['type' => 'string', 'pattern' => 'https://app.example.test/result']],
    phase: 'responseStarted',
);
```

`continueResponse(?int $status = null, ?array $headers = null)` continues with
the original network body. A null field preserves the server value. A supplied
status must be 100 through 599. Supplied headers replace the header list; `[]`
requests an empty list. Headers use the same ordered `[name, value]` pairs and
byte encoding as request decisions. Browsers may retain or generate their own
transport headers, as noted below. HTTP bodyless semantics still apply, such as
HEAD responses; changing status does not guarantee page-visible body bytes for
statuses that forbid a body.

At this phase, `respond(status: ..., headers: ..., body: ...)` supplies a complete
replacement using the existing defaults of status 200, empty headers, and an
empty body. `fail()` rejects the request with a network error. Response-body
replacement is verified on Chrome; Firefox 147 rejects it, including an empty
body. The framework retains that rejection and attempts one fail recovery. It
does not substitute a request-stage mock or silently preserve the original body.

`continueRequest()` is valid only at `beforeRequestSent`, `continueResponse()`
only at `responseStarted`, and authentication decisions only at `authRequired`.
`respond()` and `fail()` are valid at both non-authentication phases. Wrong-phase
decisions become retained controller errors and use the actual pause's recovery.

Response rules use the same registration replay, first-matching-rule precedence,
and occurrence tokens. They subscribe lazily and independently of capture.
Removed response rules pass late owned pauses through with `continueResponse()`.
Handler failures and rejected recoverable decisions attempt at most one fail
recovery for the still-current occurrence; `no such request`, failed fail decisions,
and superseded occurrences do not trigger another recovery. Errors retain
`phase: 'responseStarted'` through the existing attempt and retry reporting.

Do not assume a fixed request/authentication/response event sequence. Redirects
and authentication can produce multiple pauses with the same request ID, even
the same redirect count. Older command replies cannot clear or recover newer
pauses. In the tested browsers, overlapping request-stage mocking and response
interception can produce an event marked blocked whose continuation nevertheless
fails with `no such request`; that error remains visible rather than being ignored.

Metadata capture keeps the original `responseStarted` event unchanged. Later
events may report different values after modification. No snapshot is rewritten
to claim the server supplied the modified response. To read collected bodies,
use the separate API below. Body-transforming callbacks remain separate work.

## Response body collection

Start collection before making the request. The controller owns one session-wide
body collector, independently of metadata capture and interception. It defaults
to collecting responses:

```php
$network = $browser->network()->startCapture()->startBodyCollection(1_048_576);
$url = 'https://app.example.test/result';
$browser->navigate($url);
$completed = fn () => $network->requests(fn (array $hop): bool =>
    $hop['request']['url'] === $url && array_any($hop['events'],
        fn (array $event): bool => $event['method'] === 'network.responseCompleted'));
$browser->wait(fn () => $completed() !== []);
$bytes = $network->responseBody($completed()[0]['requestId'], disown: true);
$network->stopBodyCollection();
```

`startBodyCollection(int $maxEncodedDataSize, array $dataTypes = ['response']): self`
requires a positive per-item limit in bytes and calls `network.addDataCollector`,
using the protocol's default `blob` collector type. The selection must contain
`request`, `response`, or both; empty selections and other values are rejected.
Array keys are discarded and the browser's set conversion handles duplicates.
Starting while active throws, even with the same configuration. Stop and restart
to change the limit or selected types. Metadata capture is optional;
callers can also obtain protocol request IDs through raw network events.

For response data, the limit applies to each item's **encoded network size**, not total retention
or the returned PHP string's size. Compressed responses can expand beyond it.
The browser enforces the limit, and the tested Chrome version did not enforce it
for the oversized fixture below. It is not a client-side memory bound.

`responseBody(string $requestId, bool $disown = false): string` always identifies
the owned collector in `network.getData`. Reading without an active collector
throws. The result is an eager PHP byte string; BiDi `string` values pass through
and `base64` values use strict decoding. The specification requires decoded
response bytes, so ThrivePHP does not decompress them again or convert a text
charset. Browser-side conversions can still affect fidelity, as observed below.
Returned PHP strings remain usable after collection stops or the session ends.

Default reads retain remote ownership and permit repeated reads. `disown: true`
reads and releases only this collector's ownership of the item. That remote
release precedes local result validation, so malformed bytes can throw after the
item has already been released. Invalid result shapes and base64 throw without
independently invalidating an otherwise healthy BiDi connection.

`stopBodyCollection(): self` removes only the owned collector and releases its
ownership of both request and response items. Successful removal
clears ownership; failed removal throws and retains the ID for another explicit
call. Stopping while inactive is harmless. Session deletion releases remote
collectors, and local session cleanup forgets the ID. A successful add response
without a usable collector ID throws with unknown remote ownership; ordinary
session deletion is the cleanup backstop.

The [BiDi collection algorithms](https://www.w3.org/TR/webdriver-bidi/#network-collectors)
store only the final redirect response body, keyed by request ID and data type.
There is no historical per-hop body lookup. A captured redirect-hop snapshot does
not imply that its body is separately retrievable.

A present empty body can return `''`. A null body may have no collected item.
Oversized, uncollected, null-body, and disowned data can all produce
`no such network data`; the API preserves native errors and does not infer an
oversized diagnosis. Other errors, including `unavailable network data`,
`no such network collector`, and unsupported commands, also propagate normally.

The specification allows `getData` to wait for pending data. Such commands use
the existing timeout and continue servicing interception decisions. The installed
browsers have early-read limitations below. **Wait for `network.responseCompleted`
for routine reads.** All three methods reject calls from interception handlers,
including local validation and no-op paths.

Body operations create no implicit steps, asynchronous error accessor, local
cache, or automatic report attachment. Synchronous failures are ordinary test
execution errors. Body reads do not append `getData` results to `requests()` or
canonical console/JSON/HTML records. Raw event fields, including browser-supplied
body extensions such as `goog:postData`, are still preserved by existing capture.

### Native body findings

`php -d short_open_tag=1 test/webdriver-body-browser.php [chrome|firefox]` checks
the API on Chrome/ChromeDriver 156.0.8078.80 and Firefox 147.0.1/GeckoDriver 0.37.1
on macOS. These are observed version-specific results, not runtime version gates:

- Both collected UTF-8 text and binary `application/octet-stream` bytes exactly.
  Both returned empty strings for the tested empty 200, HEAD, and 204 responses.
- Both returned 7,168 decoded bytes from a gzip item under the 128-byte encoded
  limit, and returned the final redirect body using the shared request ID.
- Repeated reads, read-and-disown, explicit removal, restart, and isolation from
  a separately owned collector passed. Body collection worked without metadata
  capture, then alongside capture and managed interception.
- Firefox rejected the 1,024-byte uncompressed item under a 128-byte limit with
  `no such network data`. Chrome returned it. No client-side replacement limit
is imposed.
- With the binary fixture labeled `text/html`, Firefox returned a string in which
  byte `FF` became UTF-8 `C3 BF`; Chrome preserved the original bytes. Firefox's
  `application/octet-stream` result used base64 and preserved the bytes.
- After `responseStarted` but before a delayed body completed, Chrome rejected
  retrieval with `unknown error: No data found for resource with given identifier`.
  Its post-completion read succeeded. Firefox returned the received one-byte
  prefix; after completion, another read failed with
  `unknown error: RangeError: source array is too long`.
- Both collected request-stage mocked bodies and unchanged continued responses.
  Chrome collected the original server body after response-stage replacement,
  although the page received the replacement. Firefox's response-stage body
  replacement remains unsupported as documented above.
- Firefox collected the final response after the tested HTTP Basic challenge.
  Chrome returned `no such network data`, although the authenticated page fetch
  succeeded. This does not identify why Chrome lacked the item.

Native tests print these limitations separately from successful assertions.
Protocol fixtures verify malformed-result handling, removal retry, handler
reentry guards, synchronous reporting, and a pending `getData` reply interleaved
with a response-required decision. That fixture establishes dispatcher behavior;
it does not certify native pending-body support.

## Request body collection

Select `request` explicitly to collect submitted payloads. One collector can own
both data types:

```php
$network->startBodyCollection(
    maxEncodedDataSize: 1_048_576,
    dataTypes: ['request', 'response'],
);

// Submit a request, observe its completion, and obtain its protocol request ID.
$submitted = $network->requestBody($requestId, disown: true);
$received = $network->responseBody($requestId, disown: true);
$network->stopBodyCollection();
```

`requestBody(string $requestId, bool $disown = false): string` uses the owned
collector and `dataType: 'request'`. It shares `responseBody()`'s strict BytesValue
decoding, eager strings, command deadline, and no-reentry contract. A value tagged
`string` is returned literally, even if it resembles base64. The wrapper cannot
infer that a browser mislabeled bytes. There is no second decoding pass or request
replay to reconstruct missing data.

Request and response items have separate ownership. Reading and disowning one
does not release the other or another collector's item. Reading an unselected
type still sends the requested type and owned collector ID; the native error
passes through. A rejected request-only or mixed collector does not trigger a
response-only retry. An accepted collector alone does not establish successful
retrieval: a read can fail or time out, including after `responseCompleted`.

The lookup is by request ID and data type, not redirect count. Do not assume it
returns the first, final, or modified request body across redirects and retries.
Request-stage mocking may leave retrievable bytes even though nothing reached
the server. The observations below establish only the tested cases.

### Request-size semantics

The positive `maxEncodedDataSize` value is forwarded unchanged. It is a requested
per-item byte limit, not a total-retention or returned-string cap. The current
[collection algorithm](https://www.w3.org/TR/webdriver-bidi/#maybe-collect-network-data)
has an inconsistent request-size calculation: it takes a byte-sequence length
after [serialization](https://www.w3.org/TR/webdriver-bidi/#serialize-protocol-bytes)
has converted the bytes into a `{type, value}` map. Response collection supplies
encoded network size separately, so its compression rule does not resolve this
request-path ambiguity. ThrivePHP does not substitute a local sizing rule.

With a limit of 128, native request results were:

| Uploaded payload | Chrome 156 | Firefox 147 |
| --- | --- | --- |
| ASCII, 128 bytes | Retrieved | Retrieved |
| ASCII, 129 bytes | Missing | Missing |
| 65 copies of `é`, 130 UTF-8 bytes | Missing | Retrieved |
| 64 emoji, 256 UTF-8 bytes | Missing | Retrieved |
| 65 emoji, 260 UTF-8 bytes | Missing | Missing |
| Binary, 96 bytes, 128 base64 characters | Retrieved | Retrieved |
| Binary, 97 bytes, 132 base64 characters | Retrieved | Missing |
| Binary, 128 bytes | Retrieved | Missing |
| Binary, 129 bytes | Missing | Missing |

Missing cases returned `no such network data`. Chrome's boundaries matched
uploaded byte counts. Firefox's results followed the serialized value length,
counting UTF-16 code units for strings and base64 characters for binary in these
fixtures. Chrome's binary representation limitation below still applies to its
successful reads.

### Native request findings

Run `php -d short_open_tag=1 test/webdriver-request-body-browser.php [chrome|firefox]`.
The loopback fixture records received bytes independently; page responses do not
echo uploads. Verification used Chrome/ChromeDriver 156.0.8078.80 and Firefox
147.0.1/GeckoDriver 0.37.1 on macOS.

- Both retrieved UTF-8 text, JSON, and URL-encoded form payloads exactly, using
  request-only and mixed collectors. Repeated reads, independent request/response
  disowning, foreign collector ownership, stop/restart, and eager strings passed.
- Chrome returned base64 text tagged as `string` for the binary and multipart
  fixtures. For example, uploaded bytes `00 FF 62 79 74 65 73` became the literal
  string `AP9ieXRlcw==`. Firefox preserved these uploads byte-for-byte, including
  the generated multipart boundary and binary file contents.
- Chrome returned `''` for an explicitly empty POST body and
  `unknown error: No post data available for the request` for an absent body.
  Firefox returned `no such network data` for both tested cases.
- After a request-body override, Chrome returned the original bytes while the
  server received the replacement. Firefox returned the replacement bytes.
  Override fixtures use equal byte lengths to isolate body identity from
  Content-Length handling.
- After a 303 POST-to-GET redirect, Chrome reported no POST data; Firefox returned
  the original POST body. Both reused the request ID across redirect counts.
- For 307/308, Firefox sent and collected a distinct second-hop override. Chrome
  acknowledged that override but sent and collected the original bytes. Because
  its hop payloads remained identical, Chrome's collection source hop is unproven.
- Both returned the original request bytes for a mocked request that never
  reached the server, and collected the payload after a successful HTTP Basic
  challenge with two observed server requests.
- Initial Firefox runs accepted both collector configurations and completed the
  POST, but request retrieval exceeded the default 10-second command timeout even
  after `responseCompleted`. One run also timed out deleting the HTTP session;
  the existing owned-runtime termination path completed cleanup. Later fresh
  runtimes completed the tests above. Successful runs do not erase that observed
  intermittent limitation. Tests label a recognized timeout separately and do
  not claim the remaining request checks ran for that configuration.

The protocol suite verifies type selection and routing, mixed ownership,
malformed request results, handler reentry, pending command dispatch, timeout
cleanup, and synchronous report attribution. Native tests preserve the observed
errors and representation differences without a production fallback.

## Releasing collected bodies

Use `disownBody(string $requestId, string $dataType = 'response'): self` to release
an item without retrieving its bytes:

```php
// After the request completes, discard either item without transferring its body.
$network->disownBody($requestId);
$network->disownBody($requestId, dataType: 'request');
```

The method sends `network.disownData` with the owned collector ID. It requires
an active collector and accepts only `request` or `response`. It returns the
controller, leaves collection active for later requests, and preserves the other
data type and other collectors' ownership. Existing PHP strings remain usable.
The method performs no `getData` preflight, decoding, or implicit test step.
Like the readers, it rejects calls from event handlers and propagates synchronous
native errors without clearing collector ownership or recording a passive error.

Repeated release is not guaranteed to succeed. The
[specification](https://www.w3.org/TR/webdriver-bidi/#command-network-disownData)
returns `no such network data` when the global item is gone. Its ownership-removal
algorithm allows a no-op success when another collector retains the item but
this collector no longer owns it. The tested browsers reject that second case
too, as noted below. ThrivePHP neither suppresses repeated calls nor normalizes
their errors. A removed remote collector produces `no such network collector`.

Release targets **current ownership**, not cancellation or permanent exclusion
from collection. Pending data may not yet have owners; a successful release can
remove nothing, and later collection can assign ownership. Routine usage should
follow `network.responseCompleted`. The command does not promise that every
successful early release makes a later read fail.

### Native standalone-release findings

The completed-item checks are part of
`php -d short_open_tag=1 test/webdriver-request-body-browser.php [chrome|firefox]`.
Raw probes and acceptance tests used Chrome/ChromeDriver 156.0.8078.80 and Firefox
147.0.1/GeckoDriver 0.37.1 on macOS:

- Both released completed request and response items. Subsequent owned reads
  returned `no such network data`; the other data type remained readable.
- Separately owned data remained readable after release. Both browsers nevertheless
  rejected a second release with `no such network data` while the foreign owner
  retained the item, rather than taking the specification's no-op success path.
- Later requests were still collected. Missing request IDs and unselected types
  produced `no such network data`. Raw probes with removed collector IDs returned
  `no such network collector`. Ordinary errors left BiDi usable.
- Successful verification subscribed to `beforeRequestSent` and completion
  events. A Firefox raw probe without the `beforeRequestSent` subscription hit
  the previously observed request-body retrieval timeout before request release
  could be verified. The successful run with that subscription does not establish
  its necessity; `disownBody()` itself installs no subscriptions.

An optional bounded delayed-response probe also called release after
`responseStarted` while the page fetch was unfinished. Chrome acknowledged it;
the page fetch subsequently finished, but no `responseCompleted` event arrived
before the fixture's wait timeout, and a body read reported missing data. Firefox
rejected the early release because the item did not match the collector; a later
completed-body read failed with `RangeError: source array is too long`. These are
observations of that sequence, not proof of an internal clone state or a general
pending-release guarantee. Timing-sensitive pending behavior is not a native
acceptance requirement.

Protocol fixtures separately exercise both possible repeated-command results,
default-response and explicit-request routing, validation and reentry, fluent
returns, and retained collector ownership after rejection. They verify client
forwarding rather than simulating proof of browser storage behavior.

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
`test/webdriver-response-browser.php [chrome|firefox]`, with the same PHP option,
checks body-preserving continuation, status/header changes, response failure,
redirects, authentication coexistence, HEAD, original capture snapshots, and
stop/reuse. It verifies Chrome's empty/binary response replacement and explicitly
checks Firefox's unsupported-operation error and fail recovery. Known mocked
response continuation errors are reported as limitations, not treated as success.

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
- Chrome 156 and Firefox 147 continued real responses with byte-identical binary
  bodies after status/header modification. Chrome exposed only the supplied
  headers; Firefox retained `Content-Type` and `Content-Length` while removing
  the fixture's replaced `X-Origin` header. Both respected HEAD bodyless behavior
  and produced response pauses across redirect hops.
- Chrome 156 replaced response bodies with supplied empty and binary bodies.
  Firefox 147 rejected `provideResponse` at `responseStarted` with
  `unsupported operation: The "body" parameter is only supported for the
  beforeRequestSent phase at the moment`. Fail recovery released the pause and
  rejected the fetch while leaving BiDi usable.
- With request-stage mocking and response interception both enabled, both tested
  browsers emitted `responseStarted` with `isBlocked: true`, then rejected
  `continueResponse` with `no such request`; the mocked body still reached the page.
- For the authenticated fixture, Chrome's blocked-event sequence was request,
  authentication, then the 200 response. Firefox paused the initial 401 response,
  then authentication, then another request and the 200 response. Firefox reused
  the request ID and redirect count throughout. These observations do not impose
  a universal phase order on the controller.
- Safari Technology Preview 27.0 / driver 21626.1.6.19.1 exposed an experimental
  WebSocket with `safari:experimentalWebSocketUrl`, but its subscription response
  lacked the required subscription ID. Managed capture rejects that response;
  it cannot claim independent subscription ownership. A working socket alone
  does not establish log-capture support.
- Release Safari 26.6.2 did not return the requested BiDi WebSocket URL.

These observations apply to those versions, not all future versions or every
BiDi module. Native macOS results do not establish native Linux/Windows execution.
