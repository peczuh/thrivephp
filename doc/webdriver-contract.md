# Browser assertion, wait, and retry contract

This contract covers `Browser`, `Window`, `Element`, `ElementSet`, and `ShadowRoot`.
The Nightwatch comparison below uses the installed 3.12.1 assertion inventory.
Method names are PHP APIs, not Nightwatch aliases.

## Operation rules

| Operation | Default budget | Unmet condition | Assertions recorded |
| --- | --- | --- | --- |
| Immediate observation | None | Return the observation; driver errors throw | None |
| Retrying input action | `waitTimeout` | Throw on exhaustion | None |
| `find()` or original-node `Element::wait()` | `waitTimeout` | Throw | None |
| Lookup/wait with `assert: true` | `waitTimeout` | Record and throw | One final result |
| Lookup/wait with `verify: true` | `waitTimeout` | Record and continue under an active Test | One final result |
| Comparison assertion | `assertionTimeout` | Record and throw | One final result |
| Comparison with `verify: true` | `assertionTimeout` | Record and continue under an active Test | One final result |
| Browser/window callback `wait()` | `waitTimeout` | Throw on expiry | None |

Defaults are `timeout = 10` seconds for command I/O, `waitTimeout = 5000` ms,
`assertionTimeout = 5000` ms, and `pollInterval = 500` ms. All are positive and
finite. They can be supplied to `Browser`, `WebDriver::browser()`, or `SuiteConfig`.
A per-call `timeout:` overrides its operation's polling budget in milliseconds.
`wait: false` makes one attempt and ignores `timeout:`, including invalid values.
Original-node and callback `wait()` methods always wait and have no `wait` flag.

An attempt runs immediately. Further attempts start at the configured interval
after the previous attempt finishes, only while time remains. A successful
in-flight attempt may finish after the deadline. Command I/O, WebDriver implicit
lookup waits, page loads, and scripts retain their own timeouts. These are not
hard interrupts of PHP callbacks or remote commands.

Compound library actions share one polling deadline and use one-shot inner
operations. User callbacks retain control of their own work: use immediate
queries or `find(..., wait: false)` inside callback waits to avoid nesting an
independently waiting operation. No implicit timeout is set or changed by polling.

`verify: true` takes precedence over `assert: true` on lookups/waits. It affects
only that call. A failed verification fails the containing case or suite phase,
even though execution continues. Outside an active Test, failed assertions and
verifications both throw. Actions do not acquire verification semantics.

Invalid arguments, malformed patterns, lost responses, invalid sessions,
unrestorable contexts, and unrelated driver errors throw in every mode. Context
change/restoration failures retain the original exception as their cause; a stale
frame is not a retryable stale element, including inside callback waits.

## Identity, absence, and negation

* Observations use the captured node immediately. They never re-find it.
* Waiting comparisons repeat the retained lookup in its original window/frame,
  including recoverable ancestor and shadow-host lookups. Only success adopts a
  replacement. One-shot comparisons inspect the captured node.
* Script-returned and focused elements have no lookup. They can be observed
  repeatedly, but cannot recover from staleness.
* `Element::wait()` always observes its original node. Positive waits throw on
  staleness. Inverted presence waits succeed on staleness; inverted visibility
  waits succeed on hiddenness or staleness. Neither makes a stale node usable.
* `find(invert: true)` searches for no matches. With `visible: true`, it requires
  no displayed matches. Positive visibility checks the first match. Positive
  success returns an element; inverted success and failed verification return null.
* `Element::wait(verify: true)` returns its receiver after an unmet condition.
* `ElementSet` retains snapshot membership until a waiting count comparison
  succeeds. Failed verification leaves the snapshot intact. Members obtained from
  a search retain their original positional lookup, not a permanent node identity.
  Explicit snapshots without a lookup require `countEquals(..., wait: false)`.

All comparison methods accept `invert: true`. It negates the predicate on an
existing element. A missing element does not satisfy a negated text, attribute,
property, class, enabled-state, or selected-state comparison. Inverted visibility
is the deliberate exception: hidden or absent elements satisfy it. Use inverted
`find()` to assert selector absence. An attribute/property can itself be absent on
an existing node; its null value participates in that comparison normally.

Equality is strict. Contains checks are case-sensitive. `propertyContains()`
accepts a string substring or strict membership of every requested array member.
Arrays are supplied as arrays, not comma-separated strings. `propertyEquals()`
compares JSON-decoded PHP values, including arrays, without JavaScript coercion.
Regexes are delimited PHP patterns. Null does not match; `propertyMatches()` also
requires a string. Invalid patterns throw before polling, even when inverted.
`hasClass()` accepts a whitespace-separated string or a nonempty list of class
tokens, and requires all tokens. Its inversion means not all tokens are present.
`hasDescendants()` counts element descendants, excluding text and comments.

## Public method inventory

The following groups account for the public operations. Browser facades preserve
their browser receiver; window commands preserve their window unless specified.
Element assertions and actions preserve their element receiver.

### Browser and window

| Methods | Behavior |
| --- | --- |
| `find` | Retrying lookup, optional assertion or verification |
| `findAll` | Immediate membership snapshot; one recorded lookup step, no assertion |
| `urlEquals`, `urlContains`, `urlMatches`, `titleEquals`, `titleContains`, `titleMatches` | Retrying comparisons of the top-level document; do not restore a stale selected frame |
| `wait` | Callback utility; returns the first truthy callback value. Retries missing/stale element errors, but not context errors. Other callback exceptions propagate |
| `url`, `title`, `source`, `rect`, `cookies`, `cookie` getter, `alertText`, `activeElement` | Immediate observations |
| `navigate`, `back`, `forward`, `refresh` | One navigation command; never replay a navigation after an uncertain result |
| `resize`, `move`, `maximize`, `minimize`, `fullscreen`, `switchFrame`, `parentFrame`, `defaultFrame`, window `activate` | One command/selection sequence; no polling |
| `alertAccept`, `alertDismiss`, `alertType`, `cookie` setter, `cookieDelete`, `cookieDeleteAll` | One operation; no polling |
| `script` | One script invocation; synchronous or callback-completed execution uses its own script timeout |
| `actions`, `releaseActions` | One native action/release sequence; no replay after partial input |
| `screenshot`, `snapshot`, `pdf` | Immediate eager captures; capture objects remain usable after session cleanup. `snapshot` captures the selected HTML document/frame with script elements removed, UTF-8 encoding and a resolved base URL; resources remain external |
| `sleep` | Local delay in nonnegative finite seconds; no polling or assertion |
| Browser `windows`, `window`, `open`, `switch`, `close`; window `close` | Window discovery/selection/lifecycle, no polling. `open` returns a window; window `close` returns the browser |
| Browser `timeouts`, `pageLoadTimeout`, `implicitTimeout`, `scriptTimeout` | Read/set remote timeout settings; setters use seconds and do not change local polling budgets |
| Browser `subscribe`, `unsubscribe`, `command`, `event` | BiDi subscription/request/event operations with transport deadlines, not assertion polling |
| Browser `startLogCapture`, `stopLogCapture`, `logs`, `logCaptureError` | Session-wide BiDi console/exception diagnostics; non-destructive retrieval and retained entries after cleanup. See [BiDi transport and capture](webdriver-bidi.md) |
| Browser `__construct`, `status`, `quit`, `active`, `session`; window `__construct`, `browser`, `handle` | Session construction/status/cleanup or identity access; no assertion semantics |

### Elements, collections, and shadow roots

| Methods | Behavior |
| --- | --- |
| Element/shadow-root `find`, `findAll` | Same lookup contract, retaining their search scope |
| Element `wait` | Original-node wait, optional assertion/verification |
| `visible`, `valueEquals`, `valueContains`, `textEquals`, `textContains`, `textMatches`, `attributeEquals`, `attributeContains`, `attributeMatches`, `propertyEquals`, `propertyContains`, `propertyMatches`, `cssEquals`, `enabledEquals`, `selectedEquals`, `hasAttribute`, `hasClass`, `hasDescendants` | Retrying element comparisons; all support verification and inversion |
| ElementSet `countEquals` | Retrying collection comparison; supports verification/inversion and adopts membership only on success |
| `click`, `type`, `key`, `clear`, `value` setter, `check`, `uncheck`, `select` | Retrying input actions described below |
| `hover`, `doubleClick`, `rightClick`, `dragDrop`, `dragBy` | One pointer sequence, followed by release; do not repeat potentially partial input |
| `submit`, `inject`, `attribute` setter, `property` setter | One script operation; no replay of user code or DOM changes |
| `text`, `value` getter, `attribute` getter, `property` getter, `css`, `tag`, `rect`, `role`, `label`, `displayed`, `enabled`, `selected`, `shadowRoot`, `firstChild`, `lastChild`, `nextSibling`, `previousSibling` | Immediate observations, no assertions |
| Element `screenshot`, `sleep` | Eager capture or local delay; no assertion |
| ElementSet `count`, `first`, `get`, `all`, `getIterator` | Read the snapshot without remote calls; an empty set is a truthy PHP object |
| Constructors, `id`, `description`, element `window`, `reference` | Identity/reference access and validation; no polling |

Public methods marked `@internal` support these operations, not additional user
assertions: `Browser::evaluate`, `reportAssertion`, `matches`, `sessionRequest`,
`activate`, `invalidateSession`, `invalidateContext`, `rememberFrames`,
`closeWindow`; `Window::request`, `rememberFrames`, `markClosed`, `assertOpen`,
`decodeCapture`; element/shadow-root `fromReference`, `canRefresh`, `resolve`;
`ElementSet::fromReferences`; and the `Lookup` and `Step` classes.
`evaluate()` returns a final boolean without recording; `reportAssertion()`
records once or throws outside a Test. Other internal helpers do not introduce
an independent polling deadline.

## Input retry boundaries

Actions try the captured node first. They re-find a stale target only when its
retained lookup and ancestor scopes can recover it. A successful action adopts the
replacement. A missing/replaced ancestor is retried within the same deadline.

| Action | Additional retryable pre-input conditions |
| --- | --- |
| `click` | Click interception, element not interactable |
| `type`, `key` | Element not interactable |
| `clear` | Invalid element state, element not interactable |
| `value` setter | Clear's errors while clearing; type's errors while typing. Each replacement is cleared once; a rejected type does not clear the same node again |
| `check`, `uncheck` | Disabled input when a state change is needed, click interception/non-interactability. Re-check selection before clicking; an already satisfied state is a no-op |
| `select` | Disabled select/option, missing option, click interception/non-interactability. Re-check option selection before clicking |

Unsupported control types throw. Lost command responses, script errors, context
errors, and unrecognized WebDriver errors propagate. A successful native click
does not certify an application's downstream effect: assert that effect separately.
`check`/`uncheck` wait before dispatching to a disabled input because a native click
can otherwise report success without changing its state. They do not repeat a
successful click if application JavaScript cancels or reverses its effect.

## Nightwatch assertion mapping

Every non-internal file in Nightwatch 3.12.1's `lib/api/assertions` is accounted for:

| Nightwatch | ThrivePHP |
| --- | --- |
| `visible`, `hidden` | `visible()`, `visible(invert: true)` |
| `elementPresent`, `elementNotPresent` | `find(assert: true)`, `find(invert: true, assert: true)` |
| `elementsCount` | `findAll(...)->countEquals(...)` |
| `enabled`, `selected` | `enabledEquals()`, `selectedEquals()`; pass false for disabled/unselected |
| `value`, `valueEquals`, `valueContains` | `valueEquals()`, `valueContains()` |
| `containsText`, `textContains`, `textEquals`, `textMatches` | `textContains()`, `textEquals()`, `textMatches()` |
| `attributeEquals`, `attributeContains`, `attributeMatches`, `hasAttribute` | Same comparison names on `Element` |
| `domPropertyEquals`, `domPropertyContains`, `domPropertyMatches` | `propertyEquals()`, `propertyContains()`, `propertyMatches()` |
| `cssProperty` | `cssEquals()` |
| `cssClassPresent`, `cssClassNotPresent`, `hasClass` | `hasClass()`, with `invert: true` for negation |
| `hasDescendants` | `hasDescendants()` |
| `title`, `titleEquals`, `titleContains`, `titleMatches` | `titleEquals()`, `titleContains()`, `titleMatches()` on browser/window |
| `urlEquals`, `urlContains`, `urlMatches` | Same names on browser/window |
| `contains` | PHP `str_contains` or strict `in_array`, recorded with `Test::assert`/`verify` |
| `promisedValue` | Nightwatch's JavaScript promise/value adapter; PHP observations return synchronously. Record a computed condition with `Test::assert`/`verify` |

Nightwatch's `assert.not` maps to `invert: true`; `verify` maps to `verify: true`.
The fluent value assertions `equals`, `contains`, and `matches` use the relevant
text/property/attribute comparison above. Fluent `present` uses `find(assert: true)`
or original-node `wait(assert: true)`. A custom script assertion is a script result
recorded through `Test::assert`/`verify`; script execution itself is one-shot.
General PHP comparisons and custom messages remain on `Test::assert`/`verify`;
there are no `strictEqual`/`deepEqual` aliases or JavaScript command queue.

## Reporting and regression checks

Each outer action, lookup, wait, or comparison produces one step. Internal polls,
context recovery, descendant queries, and clear/type phases do not add steps.
Comparisons record once after success or exhaustion. Failure captures happen
before continuation or teardown, never for an intermediate failed poll.
Assertions retain the author location and expected/actual values. Negated
comparisons retain `invert: true`; visibility instead stores its negative boolean
expectation. Assertion failures have no duplicate step error. Unexpected errors
retain their diagnostic and cause.

Run from the framework root:

```sh
php -d short_open_tag=1 test/webdriver.php
php -d short_open_tag=1 test/webdriver-runtime.php
php -d short_open_tag=1 test/test-report.php
php -d short_open_tag=1 test/test-parallel.php
php -d short_open_tag=1 test/webdriver-contract-browser.php chrome
```

`test/fixtures/webdriver-assertions.php` supplies the complete element and document
comparison matrix to protocol and report checks. These cover one-shot and eventual
success, inversion, verification expiry, stopping failure, outside-Test failure,
invalid budgets, driver errors, replacement adoption, and final-only recording.
Additional protocol scenarios cover count snapshots, all lookup scopes, original
nodes, context failures, input replay boundaries, and compound deadlines. The
native test checks dynamic DOM replacement, shadow/frame recovery, disabled and
intercepted input, original-node disappearance, and continuing verification on
isolated `data:` pages. It accepts `firefox` as an alternative browser argument.
