<?php
	namespace ThriveData\ThrivePHP\WebDriver;

	/**
	 * A selector-backed target or an exact DOM node in a captured window/frame.
	 * Searches resolve afresh per operation; resolve() freezes the resulting node.
	 * Ordinary readers wait for presence, then read once. Exact nodes never re-find.
	 * Conditions return bool by default. $is=false negates the predicate; $assert=true
	 * records one result and returns this element. $stop=false continues after a failed
	 * assertion under an active Test; outside a Test, assertion failures always throw.
	 * Null $wait checks queries once and polls assertions. Explicit $wait overrides this.
	 * Condition $timeout is positive, finite milliseconds, null for assertionTimeout,
	 * ignored without waiting. One budget covers lookup and evaluation at pollInterval.
	 * Commands retain their own timeouts. Context and unrelated driver errors propagate.
	 * Absence makes present()/visible() false. Other one-shot conditions require a node;
	 * waiting conditions remain unsatisfied while it is missing, even with $is=false.
	 */
	class Element
	{
		/** W3C JSON element-reference key, also used by pointer action origins. */
		public const REFERENCE = 'element-6066-11e4-a52e-4f735466cecf';

		/** @internal Elements are normally obtained through find(), findAll(), or script(). */
		public function __construct(private Window $window, private ?string $id, private array $frames = [], private ?Lookup $lookup = null)
		{
			if ($id === '' || ($id === null) === ($lookup === null)):
				throw new \InvalidArgumentException('An element requires either a nonempty node ID or a search.');
			endif;
		}

		/** Resolve a search and return its remote ID; exact nodes return their ID without a freshness check. */
		public function id(): string
		{
			return $this->lookup === null ? $this->id : $this->resolve()->id;
		}

		/** Author-facing selector; script-returned nodes may have no retained selector. */
		public function description(): string
		{
			return $this->lookup?->description() ?? 'Element ' . var_export($this->id, true);
		}

		public function window(): Window
		{
			return $this->window;
		}

		/** Describe a descendant search without looking up a node. Scoped XPath should be relative, e.g. .//input. */
		public function find(string $selector, string $using = 'css selector'): self
		{
			return (new Lookup($this->window, $this->frames, $selector, $using, $this))->target();
		}

		/** Describe a descendant collection; count()/all() search when called. */
		public function findAll(string $selector, string $using = 'css selector'): ElementSet
		{
			return (new Lookup($this->window, $this->frames, $selector, $using, $this))->collection();
		}

		/** Return the accessible shadow root; throws if the host has none. */
		public function shadowRoot(): ShadowRoot
		{
			return ShadowRoot::fromReference($this->window, $this->frames, $this->request('GET', '/shadow'), $this->lookup === null ? null : $this);
		}

		/** Wait for the target, then call its native focus() once. Non-focusable elements may remain unfocused. */
		public function focus(): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->script('arguments[0].focus();');
			return $this;
		}

		/**
		 * Check focus through the node's root and each enclosing shadow host. A host is
		 * active when its shadow descendant has focus, matching DOM activeElement semantics.
		 * Like other node-requiring conditions, a missing/stale one-shot target throws;
		 * waiting forms remain unsatisfied. Use present(is: false) for removal.
		 */
		public function active(bool $is = true, bool $assert = false, bool $stop = true, ?bool $wait = null, ?float $timeout = null): bool|self
		{
			if ($assert && ($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->condition(fn($element) => $element->script(<<<'JS'
				let node = arguments[0];
				let root = node.getRootNode();
				if (root.activeElement !== node) return false;
				while (root.host) {
					node = root.host;
					root = node.getRootNode();
					if (root.activeElement !== node) return false;
				}
				return true;
				JS), fn($actual) => $actual, 'active: expected true', true, $is, $assert, $stop, $wait, $timeout);
		}

		/** Open a native dialog once, using showModal() by default or show() with $modal=false. */
		public function open(bool $modal = true): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->script('HTMLDialogElement.prototype[arguments[1] ? "showModal" : "show"].call(arguments[0]);', [$modal]);
			return $this;
		}

		/** Close a native dialog once. Null omits the return value; an empty string explicitly sets it. */
		public function close(?string $returnValue = null): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->script(<<<'JS'
				if (arguments[1] === null) HTMLDialogElement.prototype.close.call(arguments[0]);
				else HTMLDialogElement.prototype.close.call(arguments[0], arguments[1]);
				JS, [$returnValue]);
			return $this;
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

		/**
		 * Check a checkbox or radio, re-checking selection on retries and waiting for enabled
		 * state when a change is needed. $wait=false tries once; $timeout is milliseconds.
		 */
		public function check(bool $wait = true, ?float $timeout = null): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->action(__FUNCTION__, static function ($element) {
				if (strtolower($element->tag()) !== 'input' || !in_array($element->property('type'), ['checkbox', 'radio'], true)):
					throw new \InvalidArgumentException('check() requires a checkbox or radio input.');
				endif;
				if (!$element->selected()):
					if (!$element->enabled()) return 'Cannot check a disabled input.';
					$element->click(wait: false);
				endif;
			}, $wait, $timeout, ['element click intercepted', 'element not interactable']);
		}

		/**
		 * Uncheck a checkbox, re-checking selection on retries and waiting for enabled state
		 * when a change is needed. Radios are unsupported. $timeout is milliseconds.
		 */
		public function uncheck(bool $wait = true, ?float $timeout = null): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->action(__FUNCTION__, static function ($element) {
				if (strtolower($element->tag()) !== 'input' || $element->property('type') !== 'checkbox'):
					throw new \InvalidArgumentException('uncheck() requires a checkbox input.');
				endif;
				if ($element->selected()):
					if (!$element->enabled()) return 'Cannot uncheck a disabled input.';
					$element->click(wait: false);
				endif;
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

		/** Acquire the target, then return its first element child as an exact node, or null. Ignore text/comments. */
		public function firstChild(): ?Element
		{
			return $this->script('return arguments[0].firstElementChild;');
		}

		/** Acquire the target, then return its last element child as an exact node, or null. Ignore text/comments. */
		public function lastChild(): ?Element
		{
			return $this->script('return arguments[0].lastElementChild;');
		}

		/** Acquire the target, then return its next element sibling as an exact node, or null. Ignore text/comments. */
		public function nextSibling(): ?Element
		{
			return $this->script('return arguments[0].nextElementSibling;');
		}

		/** Acquire the target, then return its previous element sibling as an exact node, or null. Ignore text/comments. */
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
		 * Null acquires the target and reads once; a string clears then types and returns this element. Empty strings only clear.
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
					try {
						$element->clear(wait: false);
					} catch (\RuntimeException $error) {
						// Invalid state is a pre-input rejection of clear, not a retryable typing error.
						if (!preg_match('/^WebDriver HTTP [45][0-9]{2} invalid element state: /', $error->getMessage())) throw $error;
						return $error;
					}
					$cleared = $element->id;
				endif;
				if ($value !== '') $element->type($value, wait: false);
			}, $wait, $timeout, ['element not interactable']);
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
			$target->assertContext($this->window, $this->frames);
			$this->drag(['origin' => $target, 'x' => 0, 'y' => 0]);
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

		/** Check node presence; missing or stale nodes are absent. */
		public function present(bool $is = true, bool $assert = false, bool $stop = true, ?bool $wait = null, ?float $timeout = null): bool|self
		{
			if ($assert && ($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->condition(function ($element) { $element->tag(); return true; }, fn($actual) => $actual,
				'presence: expected true', true, $is, $assert, $stop, $wait, $timeout, absence: true);
		}

		/** Check displayedness; missing or stale nodes are invisible. Visibility does not guarantee clickability. */
		public function visible(bool $is = true, bool $assert = false, bool $stop = true, ?bool $wait = null, ?float $timeout = null): bool|self
		{
			if ($assert && ($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->condition(fn($element) => $element->request('GET', '/displayed'), fn($actual) => $actual,
				'visibility: expected true', true, $is, $assert, $stop, $wait, $timeout, absence: true);
		}

		/** Compare the live DOM value property, not the HTML attribute, using strict equality. */
		public function valueEquals(string $expected, bool $is = true, bool $assert = false, bool $stop = true, ?bool $wait = null, ?float $timeout = null): bool|self
		{
			if ($assert && ($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->condition(fn($element) => $element->property('value'), fn($actual) => $actual === $expected,
				'value: expected ' . var_export($expected, true), $expected, $is, $assert, $stop, $wait, $timeout);
		}

		/** Compare rendered text using strict equality. */
		public function textEquals(string $expected, bool $is = true, bool $assert = false, bool $stop = true, ?bool $wait = null, ?float $timeout = null): bool|self
		{
			if ($assert && ($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->condition(fn($element) => $element->text(), fn($actual) => $actual === $expected,
				'text: expected ' . var_export($expected, true), $expected, $is, $assert, $stop, $wait, $timeout);
		}

		/** Check a case-sensitive substring of rendered text. */
		public function textContains(string $expected, bool $is = true, bool $assert = false, bool $stop = true, ?bool $wait = null, ?float $timeout = null): bool|self
		{
			if ($assert && ($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->condition(fn($element) => $element->text(), fn($actual) => str_contains($actual, $expected),
				'text: expected to contain ' . var_export($expected, true), $expected, $is, $assert, $stop, $wait, $timeout);
		}

		/** Compare an attribute using strict equality; null expects an absent attribute on an existing node. */
		public function attributeEquals(string $name, ?string $expected, bool $is = true, bool $assert = false, bool $stop = true, ?bool $wait = null, ?float $timeout = null): bool|self
		{
			if ($assert && ($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->condition(fn($element) => $element->attribute($name), fn($actual) => $actual === $expected,
				'attribute ' . var_export($name, true) . ': expected ' . var_export($expected, true), $expected, $is, $assert, $stop, $wait, $timeout);
		}

		/**
		 * Check an attribute against a delimited PHP regex; absence never matches.
		 * Invalid patterns throw before polling.
		 */
		public function attributeMatches(string $name, string $pattern, bool $is = true, bool $assert = false, bool $stop = true, ?bool $wait = null, ?float $timeout = null): bool|self
		{
			if ($assert && ($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			// Validate before polling, even when no element can currently be found.
			Browser::matches($pattern, '');
			return $this->condition(fn($element) => $element->attribute($name), fn($actual) => Browser::matches($pattern, $actual),
				'attribute ' . var_export($name, true) . ': expected a match for ' . var_export($pattern, true), $pattern, $is, $assert, $stop, $wait, $timeout);
		}

		/** Check a case-sensitive substring of the live value. */
		public function valueContains(string $expected, bool $is = true, bool $assert = false, bool $stop = true, ?bool $wait = null, ?float $timeout = null): bool|self
		{
			if ($assert && ($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->condition(fn($element) => $element->property('value'), fn($actual) => is_string($actual) && str_contains($actual, $expected),
				'value: expected to contain ' . var_export($expected, true), $expected, $is, $assert, $stop, $wait, $timeout);
		}

		/** Check rendered text against a delimited PHP regex; invalid patterns throw before polling. */
		public function textMatches(string $pattern, bool $is = true, bool $assert = false, bool $stop = true, ?bool $wait = null, ?float $timeout = null): bool|self
		{
			if ($assert && ($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			Browser::matches($pattern, '');
			return $this->condition(fn($element) => $element->text(), fn($actual) => Browser::matches($pattern, $actual),
				'text: expected a match for ' . var_export($pattern, true), $pattern, $is, $assert, $stop, $wait, $timeout);
		}

		/** Check a case-sensitive attribute substring; a missing attribute does not contain it. */
		public function attributeContains(string $name, string $expected, bool $is = true, bool $assert = false, bool $stop = true, ?bool $wait = null, ?float $timeout = null): bool|self
		{
			if ($assert && ($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->condition(fn($element) => $element->attribute($name), fn($actual) => $actual !== null && str_contains($actual, $expected),
				'attribute ' . var_export($name, true) . ': expected to contain ' . var_export($expected, true), $expected, $is, $assert, $stop, $wait, $timeout);
		}

		/** Compare a JSON-decoded DOM property without string/number or boolean coercion. */
		public function propertyEquals(string $name, mixed $expected, bool $is = true, bool $assert = false, bool $stop = true, ?bool $wait = null, ?float $timeout = null): bool|self
		{
			if ($assert && ($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->condition(fn($element) => $element->property($name), fn($actual) => $actual === $expected,
				'property ' . var_export($name, true) . ': expected ' . var_export($expected, true), $expected, $is, $assert, $stop, $wait, $timeout);
		}

		/** Check a string substring or every requested array member using strict membership; other types do not match. */
		public function propertyContains(string $name, string|array $expected, bool $is = true, bool $assert = false, bool $stop = true, ?bool $wait = null, ?float $timeout = null): bool|self
		{
			if ($assert && ($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->condition(fn($element) => $element->property($name), static function ($actual) use ($expected) {
				if (is_string($actual) && is_string($expected)) return str_contains($actual, $expected);
				if (!is_array($actual)) return false;
				foreach ((array)$expected as $value):
					if (!in_array($value, $actual, true)) return false;
				endforeach;
				return true;
			}, 'property ' . var_export($name, true) . ': expected to contain ' . var_export($expected, true),
				$expected, $is, $assert, $stop, $wait, $timeout);
		}

		/** Check a string DOM property against a delimited PHP regex; null and non-strings do not match. */
		public function propertyMatches(string $name, string $pattern, bool $is = true, bool $assert = false, bool $stop = true, ?bool $wait = null, ?float $timeout = null): bool|self
		{
			if ($assert && ($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			Browser::matches($pattern, '');
			return $this->condition(fn($element) => $element->property($name), fn($actual) => is_string($actual) && Browser::matches($pattern, $actual),
				'property ' . var_export($name, true) . ': expected a match for ' . var_export($pattern, true), $pattern, $is, $assert, $stop, $wait, $timeout);
		}

		/** Compare resolved CSS using the driver's serialization of colors, lengths, and other values. */
		public function cssEquals(string $name, string $expected, bool $is = true, bool $assert = false, bool $stop = true, ?bool $wait = null, ?float $timeout = null): bool|self
		{
			if ($assert && ($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->condition(fn($element) => $element->css($name), fn($actual) => $actual === $expected,
				'CSS ' . var_export($name, true) . ': expected ' . var_export($expected, true), $expected, $is, $assert, $stop, $wait, $timeout);
		}

		/** Check enabled state according to WebDriver's form-control rules. */
		public function enabled(bool $is = true, bool $assert = false, bool $stop = true, ?bool $wait = null, ?float $timeout = null): bool|self
		{
			if ($assert && ($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->condition(fn($element) => $element->request('GET', '/enabled'), fn($actual) => $actual,
				'enabled: expected true', true, $is, $assert, $stop, $wait, $timeout);
		}

		/** Check selected state for options, checkboxes, and radio buttons. */
		public function selected(bool $is = true, bool $assert = false, bool $stop = true, ?bool $wait = null, ?float $timeout = null): bool|self
		{
			if ($assert && ($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->condition(fn($element) => $element->request('GET', '/selected'), fn($actual) => $actual,
				'selected: expected true', true, $is, $assert, $stop, $wait, $timeout);
		}

		/** Check attribute presence, including empty and boolean attributes, on an existing node. */
		public function hasAttribute(string $name, bool $is = true, bool $assert = false, bool $stop = true, ?bool $wait = null, ?float $timeout = null): bool|self
		{
			if ($assert && ($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->condition(fn($element) => $element->attribute($name), fn($actual) => $actual !== null,
				'attribute ' . var_export($name, true) . ': expected present', 'present', $is, $assert, $stop, $wait, $timeout);
		}

		/** Check all CSS class tokens, supplied as a whitespace-separated string or list; $is=false means not all are present. */
		public function hasClass(string|array $expected, bool $is = true, bool $assert = false, bool $stop = true, ?bool $wait = null, ?float $timeout = null): bool|self
		{
			if ($assert && ($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$classes = is_string($expected) ? preg_split('/[\x09\x0A\x0C\x0D\x20]+/', $expected, -1, PREG_SPLIT_NO_EMPTY) : $expected;
			if ($classes === []) throw new \InvalidArgumentException('Expected at least one CSS class token.');
			foreach ($classes as $class):
				if (!is_string($class) || $class === '' || preg_match('/[\x09\x0A\x0C\x0D\x20]/', $class)):
					throw new \InvalidArgumentException('CSS class tokens must be nonempty strings without whitespace.');
				endif;
			endforeach;
			return $this->condition(fn($element) => $element->attribute('class'), static function ($actual) use ($classes) {
				$actual = preg_split('/[\x09\x0A\x0C\x0D\x20]+/', $actual ?? '', -1, PREG_SPLIT_NO_EMPTY);
				return array_diff($classes, $actual) === [];
			}, 'classes: expected ' . var_export($expected, true), $expected, $is, $assert, $stop, $wait, $timeout);
		}

		/** Check for at least one descendant element; text and comments do not count. */
		public function hasDescendants(bool $is = true, bool $assert = false, bool $stop = true, ?bool $wait = null, ?float $timeout = null): bool|self
		{
			if ($assert && ($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->condition(fn($element) => $element->findAll('*')->count(), fn($actual) => $actual > 0,
				'descendants: expected at least one', '> 0', $is, $assert, $stop, $wait, $timeout);
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
		public function reference(?Window $window = null, ?array $frames = null, bool $wait = true): array
		{
			$this->assertContext($window, $frames);
			return [self::REFERENCE => $this->resolve($wait)->id];
		}

		/** Reject cross-context references before target acquisition or input cleanup. */
		private function assertContext(?Window $window, ?array $frames): void
		{
			if (($window !== null && $window !== $this->window) || ($frames !== null && $frames !== $this->frames)):
				throw new \InvalidArgumentException('Element belongs to another browser session or browsing context.');
			endif;
		}

		/** @internal Validate references consistently for searches, focus, and script results. */
		public static function fromReference(Window $window, array $frames, mixed $reference): self
		{
			$id = is_array($reference) ? ($reference[self::REFERENCE] ?? null) : null;
			if (!is_string($id) || $id === ''):
				throw new \RuntimeException('WebDriver did not return an element ID.');
			endif;
			return new self($window, $id, $frames);
		}

		/** @internal Whether this node and all its search ancestors can be re-found. */
		public function canRefresh(): bool
		{
			return $this->lookup !== null && $this->lookup->canRefresh();
		}

		/** Return an exact node, waiting for presence by default. Timeout is milliseconds, null for waitTimeout. */
		public function resolve(bool $wait = true, ?float $timeout = null): self
		{
			if ($this->lookup === null) return $this;
			$element = null;
			$error = null;
			$passed = $this->window->browser()->evaluate(function () use ($wait, &$element, &$error) {
				$this->lookup->activate();
				try {
					$element = $this->lookup->element();
					return true;
				} catch (\RuntimeException $failure) {
					if (!$wait || !$this->lookup->retryable($failure)) throw $failure;
					$error = $failure;
					return false;
				}
			}, $wait, $timeout);
			if (!$passed) throw new \RuntimeException('WebDriver wait timed out. ' . $this->description(), 0, $error);
			return $element;
		}

		/**
		 * Resolve each attempt within one polling deadline; compound actions pin that attempt's exact node.
		 * Operations return null on success, or a reason/exception to retry a known pre-input condition.
		 * Only pre-input protocol errors are retryable; lost responses and context failures propagate.
		 */
		private function action(string $name, callable $operation, bool $wait, ?float $timeout, array $errors): self
		{
			$browser = $this->window->browser();
			$lastError = null;
			$passed = $browser->evaluate(function () use ($browser, $operation, $wait, $errors, &$lastError) {
				// A stale captured frame is a context failure, not a reason to re-find an action target.
				$browser->activate($this->window, $this->frames);
				try {
					$element = $this->resolve(wait: false);
					$reason = $operation($element);
					if ($reason !== null):
						$lastError = $reason instanceof \RuntimeException ? $reason : new \RuntimeException($reason);
						if (!$wait) throw $lastError;
						return false;
					endif;
					return true;
				} catch (\RuntimeException $error) {
					if (!$wait || !preg_match('/^WebDriver HTTP [45][0-9]{2} ([^:]+): /', $error->getMessage(), $match)) throw $error;
					if (in_array($match[1], ['stale element reference', 'no such element', 'detached shadow root', 'no such shadow root'], true)):
						if ($this->lookup === null || !$this->lookup->retryable($error)):
							throw $error;
						endif;
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

		/** Resolve once per attempt; only assertion mode records the final comparison. */
		private function condition(callable $observe, callable $matches, string $expectation, mixed $expected, bool $is, bool $assert, bool $stop, ?bool $wait, ?float $timeout, bool $absence = false): bool|self
		{
			$browser = $this->window->browser();
			$wait ??= $assert;
			$actual = 'no observation';
			$value = null;
			$passed = $browser->evaluate(function () use ($browser, $observe, $matches, $wait, $absence, $is, &$actual, &$value) {
				$value = null;
				// Failed frame restoration cannot establish that the target is absent.
				$browser->activate($this->window, $this->frames);
				try {
					$element = $this->resolve(wait: false);
					$value = $observe($element);
					$actual = var_export($value, true);
					return (bool)$matches($value) === $is;
				} catch (\RuntimeException $error) {
					$missing = (bool)preg_match('/^WebDriver HTTP [45][0-9]{2} (no such element|stale element reference|detached shadow root|no such shadow root): /', $error->getMessage());
					if (!$missing || (!$absence && !$wait)) throw $error;
					$actual = $error->getMessage();
					if ($absence) $value = false;
					return $absence && !$is;
				}
			}, $wait, $timeout, assertion: true);
			if (!$assert) return $passed;
			$browser->reportAssertion($passed, $this->description() . ' ' . (!$is ? 'not (' . $expectation . ')' : $expectation) . ', got ' . $actual . '.', !$stop,
				['expected' => $expected, 'actual' => $value, 'observation' => $actual] + (!$is ? ['invert' => true] : []));
			return $this;
		}

		/** Route an element-relative command through its captured context. */
		private function request(string $method, string $path, ?array $body = null): mixed
		{
			return $this->window->request($method, '/element/' . rawurlencode($this->resolve()->id) . $path, $body, $this->frames);
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
			$actions = [['type' => 'pointerMove', 'duration' => 0, 'origin' => $this, 'x' => 0, 'y' => 0]];
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
				['type' => 'pointerMove', 'duration' => 0, 'origin' => $this, 'x' => 0, 'y' => 0],
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
