<?php
	namespace ThriveData\ThrivePHP\WebDriver;

	/**
	 * One browser window/tab and its selected frame. Commands select this window
	 * automatically and return it for chaining. Elements retain the frame path from
	 * their lookup, even after this window selects another frame. Frame indices are
	 * positional; use frame Elements when a stable frame identity is required.
	 * Top-level queries and commands do not restore frames; later frame-dependent
	 * operations still restore the saved selection. Navigation resets that selection.
	 */
	class Window
	{
		/** W3C frame IDs, from the top-level document down to the selected frame. */
		private array $frames = [];
		private bool $closed = false;

		/** @internal Obtain window objects through Browser::window(), windows(), or open(). */
		public function __construct(private Browser $browser, private string $handle)
		{
		}

		public function browser(): Browser
		{
			return $this->browser;
		}

		/** The driver's opaque handle, for logging or protocol integration. */
		public function handle(): string
		{
			return $this->handle;
		}

		/** Select this window and restore its selected frame. */
		public function activate(): self
		{
			$this->browser->activate($this, $this->frames);
			return $this;
		}

		/** Close this window and return its browser, selecting a remaining window if any. */
		public function close(): Browser
		{
			return $this->browser->closeWindow($this);
		}

		/** Navigate the top-level document using the driver's configured page-load strategy. */
		public function navigate(string $url): self
		{
			$this->request('POST', '/url', ['url' => $url], topLevel: true);
			$this->browser->rememberFrames($this, []);
			return $this;
		}

		/** Return the top-level document URL, including any redirect destination. */
		public function url(): string
		{
			return $this->request('GET', '/url', topLevel: true);
		}

		/**
		 * Assert once that this window's top-level URL matches a delimited PHP regex.
		 * Return this window. Invalid patterns throw; results use Browser's assertion
		 * callback, or throw on failure when none was supplied.
		 */
		public function urlMatches(string $pattern): self
		{
			$this->browser->assertMatches($pattern, $this->url(), 'Window ' . var_export($this->handle, true) . ' URL');
			return $this;
		}

		/** Navigate one entry backward in this window's history. */
		public function back(): self
		{
			$this->request('POST', '/back', [], frames: []);
			$this->browser->rememberFrames($this, []);
			return $this;
		}

		/** Navigate one entry forward in this window's history. */
		public function forward(): self
		{
			$this->request('POST', '/forward', [], frames: []);
			$this->browser->rememberFrames($this, []);
			return $this;
		}

		/** Reload this window's page and return to its top-level document. */
		public function refresh(): self
		{
			$this->request('POST', '/refresh', [], topLevel: true);
			$this->browser->rememberFrames($this, []);
			return $this;
		}

		/**
		 * Find the first match in the selected frame. Strategies: css selector, xpath,
		 * tag name, link text, partial link text. Missing elements throw driver errors.
		 */
		public function find(string $selector, string $using = 'css selector'): Element
		{
			return Element::fromReference($this, $this->frames, $this->request('POST', '/element', [
				'using' => $using, 'value' => $selector,
			]));
		}

		/**
		 * Re-find in this window/frame every 100 ms until the first match exists, then return it.
		 * Uses find() strategies. $timeout is positive, finite milliseconds; null uses
		 * the browser constructor timeout. Throws on expiry; only missing/stale element
		 * errors are retried. As with wait(), in-flight commands retain their own timeouts.
		 */
		public function findWaitPresent(string $selector, string $using = 'css selector', ?float $timeout = null): Element
		{
			return $this->wait(fn() => $this->find($selector, $using), $timeout === null ? null : $timeout / 1000);
		}

		/**
		 * Re-find until the first match is displayed, then return it; later matches are ignored.
		 * Visibility does not guarantee clickability. Uses find() strategies and
		 * findWaitPresent() polling/error semantics. $timeout is positive, finite
		 * milliseconds; null uses the browser constructor timeout. Throws on expiry.
		 */
		public function findWaitVisible(string $selector, string $using = 'css selector', ?float $timeout = null): Element
		{
			return $this->wait(function () use ($selector, $using) {
				$element = $this->find($selector, $using);
				return $element->displayed() ? $element : false;
			}, $timeout === null ? null : $timeout / 1000);
		}

		/**
		 * Re-query until no matches remain, including replacements; return this window.
		 * An empty search succeeds immediately. Uses find() strategies and
		 * findWaitPresent() polling/error semantics. $timeout is positive, finite
		 * milliseconds; null uses the browser constructor timeout. Throws on expiry.
		 */
		public function waitNotPresent(string $selector, string $using = 'css selector', ?float $timeout = null): self
		{
			$this->wait(fn() => $this->findAll($selector, $using)->count() === 0, $timeout === null ? null : $timeout / 1000);
			return $this;
		}

		/**
		 * Re-query until no matches are displayed, including replacements; return this window.
		 * An empty search succeeds immediately. Uses find() strategies and
		 * findWaitPresent() polling/error semantics; a stale match triggers a fresh search.
		 * $timeout is positive, finite milliseconds; null uses the browser constructor
		 * timeout. Throws on expiry.
		 */
		public function waitNotVisible(string $selector, string $using = 'css selector', ?float $timeout = null): self
		{
			$this->wait(function () use ($selector, $using) {
				foreach ($this->findAll($selector, $using) as $element):
					if ($element->displayed()):
						return false;
					endif;
				endforeach;
				return true;
			}, $timeout === null ? null : $timeout / 1000);
			return $this;
		}

		/** Return a snapshot in document order, including an empty set when nothing matches. */
		public function findAll(string $selector, string $using = 'css selector'): ElementSet
		{
			$references = $this->request('POST', '/elements', ['using' => $using, 'value' => $selector]);
			return ElementSet::fromReferences($this, $this->frames, $references);
		}

		/** Return the focused element in the selected frame. */
		public function activeElement(): Element
		{
			return Element::fromReference($this, $this->frames, $this->request('GET', '/element/active'));
		}

		/** Switch into a frame element or a zero-based frame index in the selected frame. */
		public function switchFrame(Element|int $element): self
		{
			$id = $element instanceof Element ? $element->reference($this, $this->frames) : $element;
			$this->request('POST', '/frame', ['id' => $id]);
			$this->browser->rememberFrames($this, [...$this->frames, $id]);
			return $this;
		}

		/** Move up one frame level; has no effect at the top level. */
		public function parentFrame(): self
		{
			$this->request('POST', '/frame/parent', []);
			$frames = $this->frames;
			array_pop($frames);
			$this->browser->rememberFrames($this, $frames);
			return $this;
		}

		/** Return to the top-level document, including when a selected frame was removed. */
		public function defaultFrame(): self
		{
			$this->request('POST', '/frame', ['id' => null], topLevel: true);
			$this->browser->rememberFrames($this, []);
			return $this;
		}

		/** Return outer-window x, y, width, and height in CSS pixels, including browser chrome. */
		public function rect(): array
		{
			return $this->request('GET', '/window/rect', topLevel: true);
		}

		/** Resize the outer window; rect() reads the actual resulting dimensions. */
		public function resize(int $width, int $height): self
		{
			$this->request('POST', '/window/rect', ['width' => $width, 'height' => $height], topLevel: true);
			return $this;
		}

		/** Move the outer window to screen coordinates in CSS pixels. */
		public function move(int $x, int $y): self
		{
			$this->request('POST', '/window/rect', ['x' => $x, 'y' => $y], topLevel: true);
			return $this;
		}

		public function maximize(): self
		{
			$this->request('POST', '/window/maximize', [], topLevel: true);
			return $this;
		}

		public function minimize(): self
		{
			$this->request('POST', '/window/minimize', [], topLevel: true);
			return $this;
		}

		public function fullscreen(): self
		{
			$this->request('POST', '/window/fullscreen', [], topLevel: true);
			return $this;
		}

		/** Return the top-level document title. */
		public function title(): string
		{
			return $this->request('GET', '/title', topLevel: true);
		}

		/** Return the selected frame's serialized DOM, not the original HTTP response. */
		public function source(): string
		{
			return $this->request('GET', '/source');
		}

		/** Accept the current JavaScript alert, confirmation, or prompt. */
		public function alertAccept(): self
		{
			$this->request('POST', '/alert/accept', [], topLevel: true);
			return $this;
		}

		/** Dismiss the current JavaScript dialog. */
		public function alertDismiss(): self
		{
			$this->request('POST', '/alert/dismiss', [], topLevel: true);
			return $this;
		}

		/** Read the current JavaScript dialog's message; throws when none is open. */
		public function alertText(): string
		{
			return $this->request('GET', '/alert/text', topLevel: true);
		}

		/** Enter a prompt response without accepting the dialog. */
		public function alertType(string $text): self
		{
			$this->request('POST', '/alert/text', ['text' => $text], topLevel: true);
			return $this;
		}

		/** Return cookies associated with the active document, including HttpOnly cookies. */
		public function cookies(): array
		{
			return $this->request('GET', '/cookie');
		}

		/**
		 * Read or set a cookie for the active document. Navigate to the target website first;
		 * WebDriver rejects unrelated domains and cookie-averse pages such as data: URLs.
		 *
		 * Omit $value or pass null to return the named cookie's string value. Missing
		 * cookies throw the driver's error. Use cookies() to retrieve full cookie fields.
		 * Pass a string (including '' or '0') to add/replace the cookie and return this
		 * window for chaining.
		 *
		 * Optional arguments when setting a cookie (ignored when reading):
		 * - $domain: null uses the active document's domain.
		 * - $path: cookie URL path scope; defaults to '/'.
		 * - $secure: send only over secure connections; defaults to false.
		 * - $httpOnly: prevent JavaScript access; defaults to false.
		 * - $sameSite: 'Strict', 'Lax', or 'None'; null leaves the browser's default.
		 * - $expiry: expiration as Unix seconds, not milliseconds or a duration.
		 *   null creates a session cookie. Null optional arguments are omitted on the wire.
		 *
		 * ```php
		 * $window->navigate('https://example.com')->cookie(
		 *     name: 'theme',
		 *     value: 'dark',
		 *     secure: true,
		 *     sameSite: 'Lax',
		 *     expiry: time() + 86400,
		 * );
		 * $theme = $window->cookie('theme'); // 'dark'
		 * ```
		 *
		 * Invalid cookie fields or domains throw the driver's error.
		 */
		public function cookie(
			string $name,
			?string $value = null,
			?string $domain = null,
			string $path = '/',
			bool $secure = false,
			bool $httpOnly = false,
			?string $sameSite = null,
			?int $expiry = null,
		): string|self {
			if ($value === null):
				return $this->request('GET', '/cookie/' . rawurlencode($name))['value'];
			endif;
			$cookie = array_filter([
				'name' => $name, 'value' => $value, 'path' => $path, 'domain' => $domain,
				'secure' => $secure, 'httpOnly' => $httpOnly, 'sameSite' => $sameSite, 'expiry' => $expiry,
			], fn($value) => $value !== null);
			$this->request('POST', '/cookie', ['cookie' => (object)$cookie]);
			return $this;
		}

		/** Delete cookies matching this name in the active document's scope. */
		public function cookieDelete(string $name): self
		{
			$this->request('DELETE', '/cookie/' . rawurlencode($name));
			return $this;
		}

		/** Delete all cookies for the active document, not every browser domain. */
		public function cookieDeleteAll(): self
		{
			$this->request('DELETE', '/cookie');
			return $this;
		}

		/** Capture the viewport immediately; use ->data() or ->save('page.png'). Not a full-page guarantee. */
		public function screenshot(): Screenshot
		{
			return new Screenshot(self::decodeCapture($this->request('GET', '/screenshot', topLevel: true)));
		}

		/**
		 * Capture a PDF of the selected browsing context immediately. Use ->data() for
		 * decoded bytes or ->save('page.pdf') to write them. Browser/driver support varies;
		 * unsupported commands and invalid options propagate the driver's error.
		 *
		 * Optional named arguments use W3C print settings:
		 * - $orientation: 'portrait' (default) or 'landscape'.
		 * - $scale: 0.1 to 2, default 1.
		 * - $background: include background graphics, default false.
		 * - $pageWidth/$pageHeight: paper dimensions in cm, defaults 21.59 x 27.94.
		 *   Each must be at least 2.54 / 72 cm. Landscape swaps the dimensions.
		 * - $marginTop/$marginBottom/$marginLeft/$marginRight: nonnegative cm, default 1 each.
		 * - $pageRanges: list of one-based page integers or range strings, e.g. [1, '3-5'];
		 *   default [] prints all pages. Open ranges such as '-2' and '4-' are supported.
		 * - $shrinkToFit: fit content to the paper width, default true.
		 *
		 * ```php
		 * $window->pdf(
		 *     background: true,
		 *     pageWidth: 21, pageHeight: 29.7,
		 *     marginLeft: 0.5, marginRight: 0.5,
		 *     pageRanges: [1, '3-5'],
		 * )->save('page.pdf');
		 * ```
		 */
		public function pdf(
			string $orientation = 'portrait',
			float $scale = 1,
			bool $background = false,
			float $pageWidth = 21.59,
			float $pageHeight = 27.94,
			float $marginTop = 1,
			float $marginBottom = 1,
			float $marginLeft = 1,
			float $marginRight = 1,
			array $pageRanges = [],
			bool $shrinkToFit = true,
		): PDF {
			return new PDF(self::decodeCapture($this->request('POST', '/print', [
				'orientation' => $orientation, 'scale' => $scale, 'background' => $background,
				'page' => ['width' => $pageWidth, 'height' => $pageHeight],
				'margin' => ['top' => $marginTop, 'bottom' => $marginBottom, 'left' => $marginLeft, 'right' => $marginRight],
				'pageRanges' => array_values($pageRanges), 'shrinkToFit' => $shrinkToFit,
			])));
		}

		/**
		 * Execute JavaScript in this window/frame. Nested Element/ShadowRoot arguments
		 * and results are converted automatically. PHP blocks in either mode; async
		 * scripts must invoke the final callback argument. See Browser::script().
		 * Cyclic arguments or nesting beyond 64 levels throw InvalidArgumentException.
		 */
		public function script(string $script, bool $async = false, array $arguments = []): mixed
		{
			$arguments = $this->encode($arguments);
			// Script limits can exceed the ordinary HTTP timeout (30s vs 10s by default).
			$timeouts = $this->browser->timeouts();
			$result = $this->request('POST', $async ? '/execute/async' : '/execute/sync', [
				'script' => $script, 'args' => array_values($arguments),
			], extraTimeout: ($timeouts['script'] ?? 0) / 1000);
			return $this->decode($result);
		}

		/**
		 * Perform W3C input sequences in the selected frame. Pass a list of source arrays,
		 * not an outer ['actions' => ...] wrapper. Each source requires a string id, a type
		 * ('key', 'pointer', 'wheel', or 'none'), and an ordered actions list. IDs continue
		 * earlier sequences; held keys/buttons persist until released or releaseActions().
		 * Sources run together in ticks, pairing actions at the same list index.
		 *
		 * Action arrays require type plus these fields (durations are nonnegative integer
		 * milliseconds; coordinates and scroll deltas are integer CSS pixels):
		 * - Any source: ['type' => 'pause', 'duration' => 100]; duration defaults to 0.
		 * - key: keyDown/keyUp with value: a single Unicode character or Browser key constant.
		 * - pointer: pointerMove with x, y, optional duration, and origin
		 *   ('viewport' by default, 'pointer', or an Element in this window/frame);
		 *   pointerDown/pointerUp with button (0 left, 1 middle, 2 right).
		 *   Optional source parameters: ['pointerType' => 'mouse'|'pen'|'touch']; default mouse.
		 * - wheel: scroll with x, y, deltaX, deltaY, optional duration, and
		 *   origin ('viewport' by default or an Element in this window/frame).
		 * - none: pause only. Driver-specific support/validation errors propagate.
		 * Omitted move/scroll durations use the tick duration (the longest duration
		 * across sources in that tick).
		 * Cyclic sources or nesting beyond 64 levels throw InvalidArgumentException.
		 *
		 * ```php
		 * $button = $window->find('button');
		 * try {
		 *     $window->actions([[
		 *         'type' => 'pointer', 'id' => 'mouse',
		 *         'parameters' => ['pointerType' => 'mouse'],
		 *         'actions' => [
		 *             ['type' => 'pointerMove', 'origin' => $button, 'x' => 0, 'y' => 0, 'duration' => 100],
		 *             ['type' => 'pointerDown', 'button' => 0],
		 *             ['type' => 'pointerUp', 'button' => 0],
		 *         ],
		 *     ]]);
		 * } finally {
		 *     $window->releaseActions();
		 * }
		 * ```
		 */
		public function actions(array $sources): self
		{
			$this->request('POST', '/actions', ['actions' => array_values($this->encode($sources))]);
			return $this;
		}

		/** Release held keys/buttons and clear the session's input-source state. */
		public function releaseActions(): self
		{
			$this->request('DELETE', '/actions');
			return $this;
		}

		/** Pause locally for seconds (including fractions), returning this window without selecting it. */
		public function sleep(float $seconds): self
		{
			$this->browser->sleep($seconds);
			return $this;
		}

		/**
		 * Poll condition($this) with Browser::wait() semantics, reselecting the original
		 * frame each time. Return first() or test count() when waiting for an ElementSet:
		 * an empty collection object is still truthy in PHP.
		 */
		public function wait(callable $condition, ?float $timeout = null): mixed
		{
			$frames = $this->frames;
			return $this->browser->wait(function () use ($condition, $frames) {
				$this->browser->activate($this, $frames);
				return $condition($this);
			}, $timeout);
		}

		/**
		 * @internal Issue a command in this window or an element's captured frame path.
		 * Top-level endpoints need only the window selected; they leave logical frames intact.
		 */
		public function request(string $method, string $path, ?array $body = null, ?array $frames = null, float $extraTimeout = 0, bool $topLevel = false): mixed
		{
			$this->browser->activate($this, $topLevel ? null : ($frames ?? $this->frames));
			try {
				return $this->browser->sessionRequest($method, $path, $body, $extraTimeout);
			} catch (\Throwable $error) {
				// A lost response cannot tell us whether the remote context already changed.
				if ($method === 'POST' && in_array($path, ['/frame', '/frame/parent', '/url', '/back', '/forward', '/refresh'], true)):
					$this->browser->invalidateContext();
				endif;
				throw $error;
			}
		}

		/** @internal Record the selected frame without issuing a protocol command. */
		public function rememberFrames(array $frames): void
		{
			$this->frames = $frames;
		}

		/** @internal Invalidate all elements and shadow roots that belong to this window. */
		public function markClosed(): void
		{
			$this->closed = true;
		}

		/** @internal Reject use of a window successfully closed through this client. */
		public function assertOpen(): void
		{
			if ($this->closed):
				throw new \LogicException('WebDriver window is closed.');
			endif;
		}

		/** @internal Decode protocol base64 before constructing a capture object. */
		public static function decodeCapture(mixed $encoded): string
		{
			$bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
			if ($bytes === false):
				throw new \RuntimeException('WebDriver returned an invalid base64 capture.');
			endif;
			return $bytes;
		}

		/** Convert references, bounding traversal so cycles and excessive nesting throw safely. */
		private function encode(mixed $value, int $depth = 0): mixed
		{
			if ($depth > 64):
				throw new \InvalidArgumentException('Recursive or excessively nested WebDriver arguments (maximum 64 levels).');
			endif;
			if ($value instanceof Element || $value instanceof ShadowRoot):
				return $value->reference($this, $this->frames);
			endif;
			if (is_array($value)):
				return array_map(fn($item) => $this->encode($item, $depth + 1), $value);
			endif;
			if ($value instanceof \stdClass):
				return (object)array_map(fn($item) => $this->encode($item, $depth + 1), get_object_vars($value));
			endif;
			return $value;
		}

		/** Preserve JSON array structure while binding returned DOM references to this frame. */
		private function decode(mixed $value): mixed
		{
			if (!is_array($value)):
				return $value;
			endif;
			if (array_key_exists(Element::REFERENCE, $value)):
				return Element::fromReference($this, $this->frames, $value);
			endif;
			if (array_key_exists(ShadowRoot::REFERENCE, $value)):
				return ShadowRoot::fromReference($this, $this->frames, $value);
			endif;
			return array_map(fn($item) => $this->decode($item), $value);
		}
	}
