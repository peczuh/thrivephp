<?php
	namespace ThriveData\ThrivePHP\WebDriver;

	/**
	 * A DOM element bound to its browser window and original frame path. Commands
	 * select that context automatically and return this element for fluent chaining.
	 * Actions and observations use the current reference. Assertions wait by default and re-find
	 * retained selectors, including ancestor scopes, and adopt the successful reference.
	 * Script/focus references have no lookup: they can be polled but staleness propagates.
	 * Original-node disappearance waits never re-find. visible(invert: true) also accepts a stale
	 * original reference when no lookup is being retried. Assertions return this element
	 * and report once to the active Test. Failed comparisons stop the case by default;
	 * $verify records the failure and continues. Outside Test::run(), failures always throw.
	 * Driver errors propagate in either mode. find() and wait() remain stopping prerequisites.
	 * Assertion $timeout is positive, finite milliseconds, null for the browser default,
	 * and ignored with $wait=false, which checks once. Polling is every 100 ms;
	 * commands retain their own timeouts.
	 */
	class Element
	{
		/** W3C JSON element-reference key, also used by pointer action origins. */
		public const REFERENCE = 'element-6066-11e4-a52e-4f735466cecf';

		/** @internal Elements are normally obtained through find(), findAll(), or script(). */
		public function __construct(private Window $window, private string $id, private array $frames = [], private ?Lookup $lookup = null)
		{
			if ($id === ''):
				throw new \InvalidArgumentException('Element ID must not be empty.');
			endif;
		}

		/** The opaque remote ID, for logging or protocol integration. */
		public function id(): string
		{
			return $this->id;
		}

		/** Author-facing selector; script-returned nodes may have no retained selector. */
		public function description(): string
		{
			return $this->lookup?->description() ?? 'Element (no retained selector)';
		}

		public function window(): Window
		{
			return $this->window;
		}

		/**
		 * Find a descendant using Browser::find() conditions, millisecond timeouts and reporting.
		 * Scoped XPath should be relative, e.g. .//input. Waiting defaults to true and
		 * re-finds this parent when it has a retained lookup; $wait=false checks once.
		 * Positive success returns a child; inverted success null.
		 */
		public function find(string $selector, string $using = 'css selector', bool $visible = false, bool $wait = true, bool $invert = false, bool $assert = false, ?float $timeout = null): ?self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return (new Lookup($this->window, $this->frames, $selector, $using, $this))->find($visible, $wait, $invert, $assert, $timeout);
		}

		/** Return matching descendants in document order, possibly an empty collection. */
		public function findAll(string $selector, string $using = 'css selector'): ElementSet
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return (new Lookup($this->window, $this->frames, $selector, $using, $this))->all(false);
		}

		/** Return the accessible shadow root; throws if the host has none. */
		public function shadowRoot(): ShadowRoot
		{
			return ShadowRoot::fromReference($this->window, $this->frames, $this->request('GET', '/shadow'), $this);
		}

		/** Pause locally for seconds (including fractions), preserving this element and its context. */
		public function sleep(float $seconds): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->window->sleep($seconds);
			return $this;
		}

		/** Native click; retry interception, non-interactability and recoverable stale nodes by default. $timeout is milliseconds. */
		public function click(bool $wait = true, ?float $timeout = null): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->action(__FUNCTION__, fn($element) => $element->request('POST', '/click', []), $wait, $timeout,
				['element click intercepted', 'element not interactable']);
		}

		/** Check a checkbox or radio, re-checking selection on retries. $wait=false tries once; $timeout is milliseconds. */
		public function check(bool $wait = true, ?float $timeout = null): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->action(__FUNCTION__, static function ($element) {
				if (strtolower($element->tag()) !== 'input' || !in_array($element->property('type'), ['checkbox', 'radio'], true)):
					throw new \InvalidArgumentException('check() requires a checkbox or radio input.');
				endif;
				if (!$element->selected()) $element->click(wait: false);
			}, $wait, $timeout, ['element click intercepted', 'element not interactable']);
		}

		/** Uncheck a checkbox, re-checking selection on retries; radios are unsupported. $timeout is milliseconds. */
		public function uncheck(bool $wait = true, ?float $timeout = null): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->action(__FUNCTION__, static function ($element) {
				if (strtolower($element->tag()) !== 'input' || $element->property('type') !== 'checkbox'):
					throw new \InvalidArgumentException('uncheck() requires a checkbox input.');
				endif;
				if ($element->selected()) $element->click(wait: false);
			}, $wait, $timeout, ['element click intercepted', 'element not interactable']);
		}

		/**
		 * Request submission of this form, a control's form owner, or an ancestor form.
		 * Honor validation and cancellable submit events; neither blocking nor cancellation
		 * is an error. Throw if no form applies. Execute once without a submitter argument;
		 * click a specific button when its name/value or submission overrides matter.
		 */
		public function submit(): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->script(<<<'JS'
				const element = arguments[0];
				const form = element instanceof HTMLFormElement ? element :
					('form' in element ? element.form : element.closest('form'));
				if (!form) throw new Error('No applicable form for element.');
				HTMLFormElement.prototype.requestSubmit.call(form);
				JS);
			return $this;
		}

		/**
		 * Insert HTML at beforebegin, afterbegin, beforeend (default), or afterend.
		 * Return this element. DOM insertion errors propagate; script markup does not execute.
		 */
		public function inject(string $html, string $where = 'beforeend'): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->script('arguments[0].insertAdjacentHTML(arguments[1], arguments[2]);', [$where, $html]);
			return $this;
		}

		/** Return the first element child, ignoring text/comments, or null; never wait or re-find. */
		public function firstChild(): ?Element
		{
			return $this->script('return arguments[0].firstElementChild;');
		}

		/** Return the last element child, ignoring text/comments, or null; never wait or re-find. */
		public function lastChild(): ?Element
		{
			return $this->script('return arguments[0].lastElementChild;');
		}

		/** Return the next element sibling, ignoring text/comments, or null; never wait or re-find. */
		public function nextSibling(): ?Element
		{
			return $this->script('return arguments[0].nextElementSibling;');
		}

		/** Return the previous element sibling, ignoring text/comments, or null; never wait or re-find. */
		public function previousSibling(): ?Element
		{
			return $this->script('return arguments[0].previousElementSibling;');
		}

		/** Append text, retrying non-interactability and recoverable stale nodes. $wait=false tries once; $timeout is milliseconds. */
		public function type(string $text, bool $wait = true, ?float $timeout = null): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->action(__FUNCTION__, fn($element) => $element->request('POST', '/value', ['text' => $text]),
				$wait, $timeout, ['element not interactable']);
		}

		/** Empty editable content, retrying invalid state, non-interactability and staleness. $timeout is milliseconds. */
		public function clear(bool $wait = true, ?float $timeout = null): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->action(__FUNCTION__, fn($element) => $element->request('POST', '/clear', []), $wait, $timeout,
				['invalid element state', 'element not interactable']);
		}

		/**
		 * Null reads immediately; a string clears then types and returns this element. Empty strings only clear.
		 * Setter retries share one timeout in milliseconds; each replacement is cleared before typing.
		 * $wait=false makes one attempt. Reads ignore $wait and $timeout.
		 */
		public function value(?string $value = null, bool $wait = true, ?float $timeout = null): string|self
		{
			if ($value === null) return $this->property('value');
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$cleared = null;
			return $this->action(__FUNCTION__, static function ($element) use ($value, &$cleared) {
				if ($cleared !== $element->id):
					$element->clear(wait: false);
					$cleared = $element->id;
				endif;
				if ($value !== '') $element->type($value, wait: false);
			}, $wait, $timeout, ['invalid element state', 'element not interactable']);
		}

		/** Send Browser::ENTER or Browser::CONTROL . 'a' . Browser::RELEASE, with type()'s retries and millisecond timeout. */
		public function key(string $key, bool $wait = true, ?float $timeout = null): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->type($key, $wait, $timeout);
		}

		/**
		 * Select the first option with the exact DOM value in a native HTML select.
		 * Multiple selections are retained; an already selected option is not toggled.
		 * Retry disabled selects/options, missing options, interception and recoverable stale nodes.
		 * Custom dropdowns need clicks. $wait=false tries once; $timeout is milliseconds.
		 */
		public function select(string $value, bool $wait = true, ?float $timeout = null): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->action(__FUNCTION__, static function ($element) use ($value) {
				if (strtolower($element->tag()) !== 'select'):
					throw new \InvalidArgumentException('select() requires a native HTML select element.');
				endif;
				if (!$element->enabled()) return 'Cannot select an option in a disabled select element.';
				foreach ($element->findAll('option') as $option):
					// DOM value handles omitted value attributes and avoids interpolating CSS literals.
					if ($option->property('value') === $value):
						if (!$option->enabled()) return 'Cannot select a disabled option.';
						if (!$option->selected()) $option->click(wait: false);
						return null;
					endif;
				endforeach;
				return 'No option matches the requested value: ' . $value;
			}, $wait, $timeout, ['element click intercepted', 'element not interactable']);
		}

		/** Move the mouse to the in-view center without clicking. */
		public function hover(): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->pointer(0, 0);
			return $this;
		}

		/** Perform two consecutive primary-button clicks at the in-view center. */
		public function doubleClick(): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->pointer(0, 2);
			return $this;
		}

		/** Perform a secondary-button click at the in-view center. */
		public function rightClick(): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->pointer(2, 1);
			return $this;
		}

		/**
		 * Drag between element centers in the same window/frame using native pointer
		 * input. This does not construct HTML5 DataTransfer data. Returns the source.
		 */
		public function dragDrop(Element $target): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->drag(['origin' => $target->reference($this->window, $this->frames), 'x' => 0, 'y' => 0]);
			return $this;
		}

		/** Drag from the in-view center by signed CSS-pixel offsets. */
		public function dragBy(int $x, int $y): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->drag(['origin' => 'pointer', 'x' => $x, 'y' => $y]);
			return $this;
		}

		/** Return WebDriver's rendered text, rather than raw textContent. */
		public function text(): string
		{
			return $this->request('GET', '/text');
		}

		/**
		 * Get an HTML attribute, or null; boolean attributes return 'true' or null.
		 * A supplied value sets the attribute (null removes it) and returns this element.
		 * An empty string sets an empty attribute. No events are dispatched automatically.
		 */
		public function attribute(string $key, ?string $value = null): string|self|null
		{
			if (func_num_args() > 1 && ($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			if (func_num_args() === 1):
				return $this->request('GET', '/attribute/' . rawurlencode($key));
			endif;
			$this->script(<<<'JS'
				if (arguments[2] === null) arguments[0].removeAttribute(arguments[1]);
				else arguments[0].setAttribute(arguments[1], arguments[2]);
				JS, [$key, $value]);
			return $this;
		}

		/**
		 * Get a live DOM property decoded from JSON, such as the current input value.
		 * A supplied value, including null or false, assigns the property and returns this
		 * element. No events are dispatched automatically. Script reference conversion applies.
		 */
		public function property(string $key, mixed $value = null): mixed
		{
			if (func_num_args() > 1 && ($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			if (func_num_args() === 1):
				return $this->request('GET', '/property/' . rawurlencode($key));
			endif;
			$this->script('arguments[0][arguments[1]] = arguments[2];', [$key, $value]);
			return $this;
		}

		/** Return a resolved CSS value, not the inline style declaration. */
		public function css(string $property): string
		{
			return $this->request('GET', '/css/' . rawurlencode($property));
		}

		public function tag(): string
		{
			return $this->request('GET', '/name');
		}

		/** Return x, y, width, height in CSS pixels, relative to the element's document. */
		public function rect(): array
		{
			return $this->request('GET', '/rect');
		}

		/** Return the computed accessibility role, including implicit HTML semantics. */
		public function role(): string
		{
			return $this->request('GET', '/computedrole');
		}

		/** Return the computed accessible name, which may differ from text() or an HTML label. */
		public function label(): string
		{
			return $this->request('GET', '/computedlabel');
		}

		/** Return displayedness; visibility does not guarantee an unobstructed click. */
		public function displayed(): bool
		{
			return $this->request('GET', '/displayed');
		}

		/**
		 * Wait for the original node's presence, or displayedness with $visible. $invert
		 * requires staleness for presence, or hiddenness/staleness for visibility. Never
		 * re-find replacements. Return this element on success; a stale reference stays unusable.
		 * Poll every 100 ms. $timeout is positive, finite milliseconds; null uses the browser
		 * constructor timeout. In-flight commands retain their own timeouts. $assert reports
		 * one final result. Expiry always throws. Staleness immediately fails positive
		 * conditions; context and unrelated driver errors always propagate.
		 */
		public function wait(bool $visible = false, bool $invert = false, bool $assert = false, ?float $timeout = null): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$browser = $this->window->browser();
			$actual = 'not observed';
			$passed = $browser->evaluate(function () use ($browser, $visible, $invert, &$actual) {
				// A stale frame during context restoration is not proof that this node is stale.
				$browser->activate($this->window, $this->frames);
				try {
					if ($visible):
						$condition = $this->displayed();
						$actual = $condition ? 'visible' : 'hidden';
					else:
						$this->tag();
						$condition = true;
						$actual = 'present';
					endif;
					return $invert ? !$condition : $condition;
				} catch (\RuntimeException $error) {
					if (!$invert || !preg_match('/^WebDriver HTTP [45][0-9]{2} stale element reference: /', $error->getMessage())):
						throw $error;
					endif;
					$actual = 'stale';
					return true;
				}
			}, true, $timeout);
			$expected = $invert ? ($visible ? 'hidden or stale' : 'stale') : ($visible ? 'visible' : 'present');
			$message = $this->description() . ': expected ' . $expected . ', got ' . $actual . '.';
			if ($assert):
				$browser->reportAssertion($passed, $message, comparison: ['expected' => $expected, 'actual' => $actual]);
			endif;
			if (!$passed):
				throw new \RuntimeException('WebDriver wait timed out. ' . $message);
			endif;
			return $this;
		}

		/**
		 * Assert displayedness, waiting by default and re-finding retained lookups.
		 * $invert accepts hiddenness, missing matches, or stale original nodes.
		 * Return this element for chaining; use displayed() for immediate boolean logic.
		 * Visibility does not guarantee clickability.
		 */
		public function visible(bool $invert = false, bool $wait = true, ?float $timeout = null, bool $verify = false): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->assertObservation(fn($element) => $element->displayed(), fn($actual) => $actual === !$invert,
				'visibility: expected ' . ($invert ? 'false or stale' : 'true'), $wait, $timeout, $verify, !$invert, $invert);
		}

		/** Assert strict equality with the live DOM value property, not the HTML attribute. Return this element. */
		public function valueEquals(string $expected, bool $wait = true, ?float $timeout = null, bool $verify = false): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->assertObservation(fn($element) => $element->property('value'), fn($actual) => $actual === $expected,
				'value: expected ' . var_export($expected, true), $wait, $timeout, $verify, $expected);
		}

		/** Assert strict equality with rendered text, waiting by default; return this element. */
		public function textEquals(string $expected, bool $wait = true, ?float $timeout = null, bool $verify = false): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->assertObservation(fn($element) => $element->text(), fn($actual) => $actual === $expected,
				'text: expected ' . var_export($expected, true), $wait, $timeout, $verify, $expected);
		}

		/** Assert a case-sensitive substring of rendered text, waiting by default; return this element. */
		public function textContains(string $expected, bool $wait = true, ?float $timeout = null, bool $verify = false): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->assertObservation(fn($element) => $element->text(), fn($actual) => str_contains($actual, $expected),
				'text: expected to contain ' . var_export($expected, true), $wait, $timeout, $verify, $expected);
		}

		/** Assert strict attribute equality, waiting by default; null expects absence. Return this element. */
		public function attributeEquals(string $name, ?string $expected, bool $wait = true, ?float $timeout = null, bool $verify = false): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->assertObservation(fn($element) => $element->attribute($name), fn($actual) => $actual === $expected,
				'attribute ' . var_export($name, true) . ': expected ' . var_export($expected, true), $wait, $timeout, $verify, $expected);
		}

		/**
		 * Assert an attribute matches a delimited PHP regex, waiting by default; absence never matches.
		 * Invalid patterns throw. Return this element.
		 */
		public function attributeMatches(string $name, string $pattern, bool $wait = true, ?float $timeout = null, bool $verify = false): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			// Validate before polling, even when no element can currently be found.
			Browser::matches($pattern, '');
			return $this->assertObservation(fn($element) => $element->attribute($name), fn($actual) => Browser::matches($pattern, $actual),
				'attribute ' . var_export($name, true) . ': expected a match for ' . var_export($pattern, true), $wait, $timeout, $verify, $pattern);
		}

		/** Return enabled state according to WebDriver's form-control rules. */
		public function enabled(): bool
		{
			return $this->request('GET', '/enabled');
		}

		/** Return selected state for options, checkboxes, and radio buttons. */
		public function selected(): bool
		{
			return $this->request('GET', '/selected');
		}

		/** Capture the visible rectangle immediately; use ->data() for PNG bytes or ->save('element.png'). */
		public function screenshot(): Screenshot
		{
			return new Screenshot(Window::decodeCapture($this->request('GET', '/screenshot')));
		}

		/**
		 * Return the W3C reference for low-level protocol integration. Internal callers
		 * supply a context to reject elements belonging to another session/window/frame.
		 */
		public function reference(?Window $window = null, ?array $frames = null): array
		{
			if (($window !== null && $window !== $this->window) || ($frames !== null && $frames !== $this->frames)):
				throw new \InvalidArgumentException('Element belongs to another browser session or browsing context.');
			endif;
			return [self::REFERENCE => $this->id];
		}

		/** @internal Validate references consistently for searches, focus, and script results. */
		public static function fromReference(Window $window, array $frames, mixed $reference, ?Lookup $lookup = null): self
		{
			$id = is_array($reference) ? ($reference[self::REFERENCE] ?? null) : null;
			if (!is_string($id) || $id === ''):
				throw new \RuntimeException('WebDriver did not return an element ID.');
			endif;
			return new self($window, $id, $frames, $lookup);
		}

		/** @internal Whether this node and all its search ancestors can be re-found. */
		public function canRefresh(): bool
		{
			return $this->lookup !== null && $this->lookup->canRefresh();
		}

		/** @internal Resolve a search ancestor without changing the original node's identity. */
		public function resolve(): ?self
		{
			return $this->lookup === null ? $this : $this->lookup->element();
		}

		/**
		 * Try the captured node first, then recover stale lookups within one polling deadline.
		 * Operations return null on success or a reason to wait for local state, such as a missing option.
		 * Only pre-input protocol errors are retryable; lost responses and context failures propagate.
		 */
		private function action(string $name, callable $operation, bool $wait, ?float $timeout, array $errors): self
		{
			$browser = $this->window->browser();
			$element = $this;
			$refresh = false;
			$lastError = null;
			$passed = $browser->evaluate(function () use ($browser, $operation, $wait, $errors, &$element, &$refresh, &$lastError) {
				// A stale captured frame is a context failure, not a reason to re-find an action target.
				$browser->activate($this->window, $this->frames);
				try {
					if ($refresh):
						$element = $this->lookup->element();
						if ($element === null):
							$lastError = new \RuntimeException('No matching element.');
							return false;
						endif;
						$refresh = false;
					endif;
					$reason = $operation($element);
					if ($reason !== null):
						$lastError = new \RuntimeException($reason);
						if (!$wait) throw $lastError;
						return false;
					endif;
					$this->id = $element->id;
					return true;
				} catch (\RuntimeException $error) {
					if (!$wait || !preg_match('/^WebDriver HTTP [45][0-9]{2} ([^:]+): /', $error->getMessage(), $match)) throw $error;
					if (in_array($match[1], ['stale element reference', 'no such element', 'detached shadow root', 'no such shadow root'], true)):
						if (!$this->canRefresh()) throw $error;
						$refresh = true;
					elseif (!in_array($match[1], $errors, true)):
						throw $error;
					endif;
					$lastError = $error;
					return false;
				}
			}, $wait, $timeout);
			if (!$passed):
				throw new \RuntimeException('WebDriver action timed out. ' . $this->description() . ' ' . $name . ': ' . $lastError->getMessage(), 0, $lastError);
			endif;
			return $this;
		}

		/** Poll observations and report once; only successful retries replace this element's reference. */
		private function assertObservation(callable $observe, callable $matches, string $expectation, bool $wait, ?float $timeout, bool $verify, mixed $expected, bool $absencePasses = false): self
		{
			$browser = $this->window->browser();
			$refresh = $wait && $this->lookup !== null;
			$actual = 'no observation';
			$value = null;
			$passed = $browser->evaluate(function () use ($browser, $observe, $matches, $refresh, $absencePasses, &$actual, &$value) {
				$value = null;
				$browser->activate($this->window, $this->frames);
				try {
					$element = $refresh ? $this->lookup->element() : $this;
					if ($element === null):
						$actual = 'no matching element';
						return $absencePasses;
					endif;
					$value = $observe($element);
					$actual = var_export($value, true);
					if (!$matches($value)):
						return false;
					endif;
					$this->id = $element->id;
					return true;
				} catch (\RuntimeException $error) {
					if (!$refresh && $absencePasses && preg_match('/^WebDriver HTTP [45][0-9]{2} stale element reference: /', $error->getMessage())):
						$actual = 'stale';
						return true;
					endif;
					if (!$refresh || !$this->lookup->retryable($error)):
						throw $error;
					endif;
					$actual = $error->getMessage();
					return $absencePasses && (bool)preg_match('/^WebDriver HTTP [45][0-9]{2} no such element: /', $actual);
				}
			}, $wait, $timeout);
			$browser->reportAssertion($passed, $this->description() . ' ' . $expectation . ', got ' . $actual . '.', $verify,
				['expected' => $expected, 'actual' => $value, 'observation' => $actual]);
			return $this;
		}

		/** Route an element-relative command through its captured context. */
		private function request(string $method, string $path, ?array $body = null): mixed
		{
			return $this->window->request($method, '/element/' . rawurlencode($this->id) . $path, $body, $this->frames);
		}

		/** Restore captured context before encoding arguments; the first argument is this element. */
		private function script(string $script, array $arguments = []): mixed
		{
			$this->window->browser()->activate($this->window, $this->frames);
			$operation = fn() => $this->window->script($script, arguments: [$this, ...$arguments]);
			$test = Step::recorder();
			return $test === null ? $operation() : $test->withoutSteps($operation);
		}

		/** Build hover or click actions at the in-view center. */
		private function pointer(int $button, int $clicks): void
		{
			$actions = [['type' => 'pointerMove', 'duration' => 0, 'origin' => $this->reference(), 'x' => 0, 'y' => 0]];
			for ($i = 0; $i < $clicks; $i++):
				$actions[] = ['type' => 'pointerDown', 'button' => $button];
				$actions[] = ['type' => 'pointerUp', 'button' => $button];
			endfor;
			$this->pointerActions($actions);
		}

		/** Move for 250 ms while holding the primary button, then release at the destination. */
		private function drag(array $destination): void
		{
			$this->pointerActions([
				['type' => 'pointerMove', 'duration' => 0, 'origin' => $this->reference(), 'x' => 0, 'y' => 0],
				['type' => 'pointerDown', 'button' => 0],
				['type' => 'pointerMove', 'duration' => 250] + $destination,
				['type' => 'pointerUp', 'button' => 0],
			]);
		}

		/** Release input even if the sequence fails partway; context errors send no input. */
		private function pointerActions(array $actions): void
		{
			$this->window->browser()->activate($this->window, $this->frames);
			try {
				$this->window->actions([[
					'type' => 'pointer', 'id' => 'mouse', 'parameters' => ['pointerType' => 'mouse'], 'actions' => $actions,
				]]);
			} finally {
				$this->window->releaseActions();
			}
		}
	}
