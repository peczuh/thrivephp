# Browser targets, conditions, and retry contract

This contract covers `Browser`, `Window`, `Element`, `ElementSet`, and `ShadowRoot`.
The Nightwatch comparison below uses the installed 3.12.1 assertion inventory.
Method names are PHP APIs, not Nightwatch aliases.

## Operation rules

| Operation | Default budget | Unmet condition | Assertions recorded |
| --- | --- | --- | --- |
| `find()` / `findAll()` | None | Describe a search without requiring a match | None |
| Element value reader / `resolve()` | `waitTimeout` for target acquisition | Throw if acquisition fails; read once after acquisition | None |
| Retrying input action | `waitTimeout` | Throw on exhaustion | None |
| Condition query | None by default; `assertionTimeout` with `wait: true` | Return false | None |
| Condition with `assert: true` | `assertionTimeout` | Record and throw | One final result |
| Condition with `assert: true, stop: false` | `assertionTimeout` | Record and continue under an active Test | One final result |
| Browser/window callback `wait()` | `waitTimeout` | Throw on expiry | None |

Defaults are `timeout = 10` seconds for command I/O, `waitTimeout = 5000` ms,
`assertionTimeout = 5000` ms, and `pollInterval = 500` ms. All are positive and
finite. They can be supplied to `Browser`, `WebDriver::browser()`, or `SuiteConfig`.
A per-call `timeout:` overrides its operation's polling budget in milliseconds.
`wait: false` makes one attempt and ignores `timeout:`, including invalid values.
Browser/window callback `wait()` always waits and has no `wait` flag.

Every DOM condition uses the same options after its domain arguments:

```php
textEquals(string $expected, bool $is = true, bool $assert = false,
    bool $stop = true, ?bool $wait = null, ?float $timeout = null): bool|self
```

Queries return a boolean. Assertion mode records one final result and returns the
receiver. `is: false` tests the opposite predicate in either mode. Null `wait`
means one-shot queries and waiting assertions; explicit true/false overrides this.

An attempt runs immediately. Further attempts start at the configured interval
after the previous attempt finishes, only while time remains. A successful
in-flight attempt may finish after the deadline. Command I/O, WebDriver implicit
lookup waits, page loads, and scripts retain their own timeouts. These are not
hard interrupts of PHP callbacks or remote commands.

Compound library actions share one polling deadline and use one-shot inner
operations. User callbacks retain control of their own work: use immediate
queries or `resolve(wait: false)` inside callback waits to avoid nesting an
independently waiting operation. No implicit timeout is set or changed by polling.

`stop: false` affects only assertion mismatches on that call. A failed assertion
still fails its case or suite phase even though execution continues. Outside an
active Test, failed assertions throw even with `stop: false`. Actions do not
acquire assertion semantics.

Invalid arguments, malformed patterns, lost responses, invalid sessions,
unrestorable contexts, and unrelated driver errors throw in every mode. Context
change/restoration failures retain the original exception as their cause; a stale
frame is not a retryable stale element, including inside callback waits.

## Identity, absence, and negation

* `find()` describes a selector, strategy, scope, window, captured frame path, and
  optional position. It does not search the DOM. Browser-level construction may
  need to discover the current window handle.
* Selector-backed elements resolve afresh on every operation, including one-shot
  queries and actions. Waiting controls retries, not identity. Nested searches
  resolve their parents and shadow hosts within the same operation budget.
* `resolve(bool $wait = true, ?float $timeout = null): self` returns a new exact-node
  element without changing the original search. No match throws. On an exact node,
  it returns that object without I/O or a freshness check.
* Script-returned nodes, `activeElement()`, traversal results, and resolved nodes
  have no selector. They always address that node and cannot find a replacement.
* Ordinary element readers acquire the target using `waitTimeout`, then read once.
  `id()` resolves a selector but returns an exact node's stored ID without I/O.
* `present()` and `visible()` are false on absence/staleness; `is: false` therefore
  succeeds. Other one-shot conditions throw for missing/stale targets. Waiting
  forms remain unsatisfied in either `is` direction while the target is missing.
* `findAll()` describes a search. `count()` queries current matches once; zero is
  valid. `countEquals()` can poll. `first()`/`get()` create positional targets.
  `all()` and iteration query once and return exact nodes from that snapshot.
* Explicit `new ElementSet(...)` snapshots keep fixed membership, nullable `first()`
  and out-of-range `get()` errors. Waiting count conditions require a search;
  use `wait: false` for explicit snapshots.
* Captured frame paths retain exact frame references. Replaced frames are context
  errors; ancestor element recovery does not rebuild the frame path.

`Element::wait()` is replaced by ordinary conditions. To wait for the original
node's disappearance, use `$target->resolve()->present(is: false, assert: true)`.
An attribute/property can itself be absent on an existing node; its null value
participates in comparisons normally.

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
| `find`, `findAll` | Describe target/collection searches; no DOM lookup, step, or assertion |
| `urlEquals`, `urlContains`, `urlMatches`, `titleEquals`, `titleContains`, `titleMatches` | Unified conditions on the top-level document; do not restore a stale selected frame |
| `wait` | Callback utility; returns the first truthy callback value. Retries missing/stale element errors, but not context errors. Other callback exceptions propagate |
| `url`, `title`, `source`, `rect`, `cookies`, `cookie` getter, `alertText`, `activeElement` | Immediate observations |
| `navigate`, `back`, `forward`, `refresh` | One navigation command; never replay a navigation after an uncertain result |
| `resize`, `move`, `maximize`, `minimize`, `fullscreen`, `switchFrame`, `parentFrame`, `defaultFrame`, window `activate` | One command/selection sequence; no polling |
| `alertAccept`, `alertDismiss`, `alertType`, `cookie` setter, `cookieDelete`, `cookieDeleteAll` | One operation; no polling |
| `script` | Acquire argument targets under one budget, then invoke once; execution uses its own script timeout |
| `actions`, `releaseActions`, `tap` | One native input/release sequence; no replay after partial input. `tap` presses a chord at current focus and releases it in reverse order |
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
| Element `resolve` | Acquire an exact node, leaving the target search unchanged |
| `present`, `visible`, `enabled`, `selected`, `active`, `valueEquals`, `valueContains`, `textEquals`, `textContains`, `textMatches`, `attributeEquals`, `attributeContains`, `attributeMatches`, `propertyEquals`, `propertyContains`, `propertyMatches`, `cssEquals`, `hasAttribute`, `hasClass`, `hasDescendants` | Unified element conditions; query by default, optional assertions, negation, continuation, and waiting |
| ElementSet `countEquals` | Unified count condition on current matches or fixed snapshot membership |
| `click`, `type`, `key`, `clear`, `value` setter, `check`, `uncheck`, `select` | Retrying input actions described below |
| `hover`, `doubleClick`, `rightClick`, `dragDrop`, `dragBy` | One pointer sequence, followed by release; do not repeat potentially partial input |
| `submit`, `inject`, `attribute` setter, `property` setter, `focus`, `open`, `close` | Acquire targets then invoke once; no replay of scripts or DOM changes. `open`/`close` invoke native dialog methods |
| `text`, `value` getter, `attribute` getter, `property` getter, `css`, `tag`, `rect`, `role`, `label`, `shadowRoot`, `firstChild`, `lastChild`, `nextSibling`, `previousSibling` | Acquire the target then observe once; no assertions |
| Element `screenshot`, `sleep` | Eager capture or local delay; no assertion |
| ElementSet `count`, `first`, `get`, `all`, `getIterator` | Search-backed or explicit snapshot behavior described above; empty sets are truthy objects |
| Constructors, `description`, element `window` | Local description/identity access; never resolve targets |
| `id`, `reference` | Resolve search-backed targets when a protocol reference is required |

Public methods marked `@internal` support these operations, not additional user
assertions: `Browser::evaluate`, `reportAssertion`, `matches`, `sessionRequest`,
`activate`, `invalidateSession`, `invalidateContext`, `rememberFrames`,
`closeWindow`; `Window::request`, `rememberFrames`, `markClosed`, `assertOpen`,
`decodeCapture`; element/shadow-root `fromReference`, `canRefresh`; shadow-root
`resolve`; `ElementSet::fromReferences`, `fromLookup`; and `Lookup` and `Step`.
`evaluate()` returns a final boolean without recording; `reportAssertion()`
records once or throws outside a Test. Other internal helpers do not introduce
an independent polling deadline.

## Input retry boundaries

Actions resolve selector-backed targets before each attempt. Compound actions pin
one exact node for the attempt. Missing matches and recoverable stale targets are
retried within the same deadline; exact references cannot find replacements.
Repeated references to the same target object in one script/action command resolve
to the same node. Target acquisition completes before dispatch; scripts and input
sequences are never replayed to recover an acquisition failure.

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
| `visible`, `hidden` | `visible(assert: true)`, `visible(is: false, assert: true)` |
| `elementPresent`, `elementNotPresent` | `present(assert: true)`, `present(is: false, assert: true)` |
| `elementsCount` | `findAll(...)->countEquals(...)` |
| `enabled`, `selected` | `enabled(assert: true)`, `selected(assert: true)`; use `is: false` for disabled/unselected |
| `value`, `valueEquals`, `valueContains` | `valueEquals()`, `valueContains()` |
| `containsText`, `textContains`, `textEquals`, `textMatches` | `textContains()`, `textEquals()`, `textMatches()` |
| `attributeEquals`, `attributeContains`, `attributeMatches`, `hasAttribute` | Same comparison names on `Element` |
| `domPropertyEquals`, `domPropertyContains`, `domPropertyMatches` | `propertyEquals()`, `propertyContains()`, `propertyMatches()` |
| `cssProperty` | `cssEquals()` |
| `cssClassPresent`, `cssClassNotPresent`, `hasClass` | `hasClass()`, with `is: false` for negation |
| `hasDescendants` | `hasDescendants()` |
| `title`, `titleEquals`, `titleContains`, `titleMatches` | `titleEquals()`, `titleContains()`, `titleMatches()` on browser/window |
| `urlEquals`, `urlContains`, `urlMatches` | Same names on browser/window |
| `contains` | PHP `str_contains` or strict `in_array`, recorded with `Test::assert`/`verify` |
| `promisedValue` | Nightwatch's JavaScript promise/value adapter; PHP observations return synchronously. Record a computed condition with `Test::assert`/`verify` |

Use `assert: true` on the comparisons in this table. Nightwatch's `assert.not`
maps to `is: false, assert: true`; `verify` maps to `assert: true, stop: false`.
The fluent value assertions `equals`, `contains`, and `matches` use the relevant
text/property/attribute comparison above. Fluent `present` uses `present(assert: true)`.
A custom script assertion is a script result
recorded through `Test::assert`/`verify`; script execution itself is one-shot.
General PHP comparisons and custom messages remain on `Test::assert`/`verify`;
there are no `strictEqual`/`deepEqual` aliases or JavaScript command queue.

## Reporting and regression checks

Each outer action, callback wait, or asserted condition produces one step. Target
construction and boolean conditions produce no steps or assertions. Internal polls,
context recovery, descendant queries, and clear/type phases do not add steps.
Comparisons record once after success or exhaustion. Failure captures happen
before continuation or teardown, never for an intermediate failed poll.
Assertions retain the author location and expected/actual values. Negated
comparisons retain `invert: true` in report data, including visibility. Assertion
failures have no duplicate step error. Unexpected errors
retain their diagnostic and cause.

Run from the framework root:

```sh
php -d short_open_tag=1 test/webdriver.php
php -d short_open_tag=1 test/webdriver-runtime.php
php -d short_open_tag=1 test/test-report.php
php -d short_open_tag=1 test/test-parallel.php
php -d short_open_tag=1 test/webdriver-contract-browser.php chrome
php -d short_open_tag=1 test/webdriver-target-browser.php chrome
```

`test/fixtures/webdriver-assertions.php` supplies the complete element and document
comparison matrix to protocol and report checks. These cover one-shot and eventual
success, inversion, verification expiry, stopping failure, outside-Test failure,
invalid budgets, driver errors, fresh resolution, and final-only recording.
Additional protocol scenarios cover lazy construction, query silence, count snapshots, all lookup scopes, original
nodes, context failures, input replay boundaries, and compound deadlines. The
native test checks dynamic DOM replacement, shadow/frame recovery, disabled and
intercepted input, original-node disappearance, and continuing verification on
isolated `data:` pages. The target test also checks nested closed-shadow focus,
modifier flags on letter key events, Tab traversal, and native dialog lifecycle.
Both accept `firefox` as an alternative browser argument.
