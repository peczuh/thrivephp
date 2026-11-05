<?php
	namespace ThriveData\ThrivePHP\WebDriver;

	/**
	 * A DOM element bound to its browser window and original frame path. Commands
	 * select that context automatically and return this element for fluent chaining.
	 * References are never silently re-found: stale-element errors propagate except
	 * when completing waitNotPresent() or waitNotVisible().
	 */
	class Element
	{
		/** W3C JSON element-reference key, also used by pointer action origins. */
		public const REFERENCE = 'element-6066-11e4-a52e-4f735466cecf';

		/** @internal Elements are normally obtained through find(), findAll(), or script(). */
		public function __construct(private Window $window, private string $id, private array $frames = [])
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

		public function window(): Window
		{
			return $this->window;
		}

		/** Find a descendant. Scoped XPath expressions should be relative, e.g. .//input. */
		public function find(string $selector, string $using = 'css selector'): self
		{
			return self::fromReference($this->window, $this->frames, $this->request('POST', '/element', [
				'using' => $using, 'value' => $selector,
			]));
		}

		/** Return matching descendants in document order, possibly an empty collection. */
		public function findAll(string $selector, string $using = 'css selector'): ElementSet
		{
			$references = $this->request('POST', '/elements', ['using' => $using, 'value' => $selector]);
			return new ElementSet(...array_map(fn($reference) => self::fromReference($this->window, $this->frames, $reference), $references));
		}

		/** Return the accessible shadow root; throws if the host has none. */
		public function shadowRoot(): ShadowRoot
		{
			return ShadowRoot::fromReference($this->window, $this->frames, $this->request('GET', '/shadow'));
		}

		/** Pause locally for seconds (including fractions), preserving this element and its context. */
		public function sleep(float $seconds): self
		{
			$this->window->sleep($seconds);
			return $this;
		}

		/** Click using native WebDriver interaction. */
		public function click(): self
		{
			$this->request('POST', '/click', []);
			return $this;
		}

		/** Send text without clearing existing input. */
		public function type(string $text): self
		{
			$this->request('POST', '/value', ['text' => $text]);
			return $this;
		}

		/** Empty an editable input, textarea, or contenteditable element. */
		public function clear(): self
		{
			$this->request('POST', '/clear', []);
			return $this;
		}

		/** Send Browser::ENTER or a sequence such as Browser::CONTROL . 'a' . Browser::RELEASE. */
		public function key(string $key): self
		{
			return $this->type($key);
		}

		/**
		 * Select the first option with the exact DOM value in a native HTML select.
		 * Multiple selections are retained; an already selected option is not toggled.
		 * Custom dropdowns need clicks. Disabled selects/options throw.
		 */
		public function select(string $value): self
		{
			if (strtolower($this->tag()) !== 'select'):
				throw new \InvalidArgumentException('select() requires a native HTML select element.');
			endif;
			if (!$this->enabled()):
				throw new \RuntimeException('Cannot select an option in a disabled select element.');
			endif;
			foreach ($this->findAll('option') as $option):
				// DOM value handles omitted value attributes and avoids interpolating CSS literals.
				if ($option->property('value') === $value):
					if (!$option->enabled()):
						throw new \RuntimeException('Cannot select a disabled option.');
					endif;
					if (!$option->selected()):
						$option->click();
					endif;
					return $this;
				endif;
			endforeach;
			throw new \RuntimeException('No option matches the requested value: ' . $value);
		}

		/** Move the mouse to the in-view center without clicking. */
		public function hover(): self
		{
			$this->pointer(0, 0);
			return $this;
		}

		/** Perform two consecutive primary-button clicks at the in-view center. */
		public function doubleClick(): self
		{
			$this->pointer(0, 2);
			return $this;
		}

		/** Perform a secondary-button click at the in-view center. */
		public function rightClick(): self
		{
			$this->pointer(2, 1);
			return $this;
		}

		/**
		 * Drag between element centers in the same window/frame using native pointer
		 * input. This does not construct HTML5 DataTransfer data. Returns the source.
		 */
		public function dragDrop(Element $target): self
		{
			$this->drag(['origin' => $target->reference($this->window, $this->frames), 'x' => 0, 'y' => 0]);
			return $this;
		}

		/** Drag from the in-view center by signed CSS-pixel offsets. */
		public function dragBy(int $x, int $y): self
		{
			$this->drag(['origin' => 'pointer', 'x' => $x, 'y' => $y]);
			return $this;
		}

		/** Return WebDriver's rendered text, rather than raw textContent. */
		public function text(): string
		{
			return $this->request('GET', '/text');
		}

		/** Return an HTML attribute, or null; boolean attributes return 'true' or null. */
		public function attribute(string $name): ?string
		{
			return $this->request('GET', '/attribute/' . rawurlencode($name));
		}

		/** Return a live DOM property, e.g. the current input value, decoded from JSON. */
		public function property(string $name): mixed
		{
			return $this->request('GET', '/property/' . rawurlencode($name));
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
		 * Poll the original node every 100 ms until stale, then return this same (unusable)
		 * element, without finding replacements. $timeout is positive, finite milliseconds;
		 * null uses the browser constructor timeout. Throws on expiry; other errors follow
		 * Browser::wait() semantics. In-flight commands retain their own timeouts.
		 */
		public function waitNotPresent(?float $timeout = null): self
		{
			$browser = $this->window->browser();
			$browser->wait(function () use ($browser) {
				// A stale frame during context restoration is not proof that this node is stale.
				$browser->activate($this->window, $this->frames);
				try {
					$this->tag();
					return false;
				} catch (\RuntimeException $error) {
					if (!preg_match('/^WebDriver HTTP [45][0-9]{2} stale element reference: /', $error->getMessage())):
						throw $error;
					endif;
					return true;
				}
			}, $timeout === null ? null : $timeout / 1000);
			return $this;
		}

		/**
		 * Poll the original node until hidden or stale; replacements are not re-found.
		 * Return this same element; a stale reference remains unusable. Uses waitNotPresent()
		 * polling/error semantics. $timeout is positive, finite milliseconds; null uses
		 * the browser constructor timeout. Throws on expiry.
		 */
		public function waitNotVisible(?float $timeout = null): self
		{
			$browser = $this->window->browser();
			$browser->wait(function () use ($browser) {
				$browser->activate($this->window, $this->frames);
				try {
					return !$this->displayed();
				} catch (\RuntimeException $error) {
					if (!preg_match('/^WebDriver HTTP [45][0-9]{2} stale element reference: /', $error->getMessage())):
						throw $error;
					endif;
					return true;
				}
			}, $timeout === null ? null : $timeout / 1000);
			return $this;
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
		public static function fromReference(Window $window, array $frames, mixed $reference): self
		{
			$id = is_array($reference) ? ($reference[self::REFERENCE] ?? null) : null;
			if (!is_string($id) || $id === ''):
				throw new \RuntimeException('WebDriver did not return an element ID.');
			endif;
			return new self($window, $id, $frames);
		}

		/** Route an element-relative command through its captured context. */
		private function request(string $method, string $path, ?array $body = null): mixed
		{
			return $this->window->request($method, '/element/' . rawurlencode($this->id) . $path, $body, $this->frames);
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
