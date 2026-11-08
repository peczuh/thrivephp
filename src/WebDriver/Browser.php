<?php
	namespace ThriveData\ThrivePHP\WebDriver;

	use ThriveData\ThrivePHP\WebSocket;
	use ThriveData\ThrivePHP\Test;

	/**
	 * One browser session using classic HTTP WebDriver and optional WebDriver BiDi.
	 * The browser driver must already be running. PHP streams handle HTTP; WebSocket
	 * handles BiDi. Use WebDriver for local discovery and owned driver services;
	 * this class also connects directly to externally managed endpoints.
	 * Commands return their receiver for chaining; queries return values or objects.
	 * Window operations target the current window. open() returns and selects a new
	 * Window; operations on saved Window/Element objects select their owning context.
	 *
	 * Example (requires GeckoDriver listening on port 4444):
	 *
	 * ```php
	 * $driver = new \ThriveData\ThrivePHP\WebDriver\Browser('http://localhost:4444', 'firefox', bidi: true);
	 * try {
	 *     $driver->resize(1440, 900)->navigate('data:text/html,<title>Example</title><input id="name">');
	 *     $input = $driver->wait(function ($driver) {
	 *         $element = $driver->find('#name');
	 *         return $element->displayed() && $element->enabled() ? $element : false;
	 *     }, timeout: 10000);
	 *     $input->clear()->type('Hello')->key(\ThriveData\ThrivePHP\WebDriver\Browser::TAB);
	 *     $value = $driver->script('return arguments[0].value;', arguments: [$input]);
	 *     $title = $driver->title();
	 *     $contexts = $driver->command('browsingContext.getTree');
	 *     $inputs = $driver->findAll('input');
	 *     $png = $driver->screenshot()->data(); // Decoded PNG bytes.
	 * } finally {
	 *     $driver->quit();
	 * }
	 * ```
	 *
	 * Classic commands work with Safari, Chrome, and Firefox. BiDi must be requested
	 * explicitly and supported by the driver. Subscribe with subscribe(['log.entryAdded'])
	 * before retrieving console and JavaScript error events with event().
	 * Call quit() explicitly to end the remote session, including after exceptions.
	 *
	 * Protocols: https://www.w3.org/TR/webdriver/ and https://www.w3.org/TR/webdriver-bidi/
	 */
	class Browser
	{
		/** W3C private-use Unicode key codes for key() or type(); RELEASE ends modifiers. */
		public const RELEASE = "\u{E000}", BACKSPACE = "\u{E003}", TAB = "\u{E004}",
			RETURN = "\u{E006}", ENTER = "\u{E007}", SHIFT = "\u{E008}", CONTROL = "\u{E009}",
			ALT = "\u{E00A}", ESCAPE = "\u{E00C}", SPACE = "\u{E00D}", PAGE_UP = "\u{E00E}",
			PAGE_DOWN = "\u{E00F}", END = "\u{E010}", HOME = "\u{E011}", ARROW_LEFT = "\u{E012}",
			ARROW_UP = "\u{E013}", ARROW_RIGHT = "\u{E014}", ARROW_DOWN = "\u{E015}",
			INSERT = "\u{E016}", DELETE = "\u{E017}", META = "\u{E03D}";

		/** Remote session identifier; null before creation or after successful deletion. */
		private ?string $sessionId = null;
		private array $capabilities = [];
		private ?BiDi $connection = null;
		/** Collector ownership is separate from caller-created low-level subscriptions. */
		private ?string $logSubscription = null;
		private ?int $logListener = null;
		/** Protocol entries outlive the connection and are never consumed by logs(). */
		private array $logs = [];
		private ?\Throwable $logFailure = null;
		/** One network collector per session; retained records outlive session cleanup. */
		private ?Network $network = null;
		/** Window objects are shared so frame selection and closure stay consistent. */
		private array $windows = [];
		private ?Window $currentWindow = null;
		/** Selected frame path; null means a failed switch left the context uncertain. */
		private ?array $currentFrames = [];
		/** Polling budgets and interval in milliseconds, independent of command I/O timeouts. */
		private float $waitTimeout = 5000;
		private float $assertionTimeout = 5000;
		private float $pollInterval = 500;

		/**
		 * Create a session immediately, connecting BiDi if requested. $endpoint is the
		 * driver's HTTP(S) base URL, optionally including a path such as /wd/hub.
		 * $capabilities supplies browser options, e.g. goog:chromeOptions for headless
		 * Chrome; $browser and $bidi determine browserName and webSocketUrl themselves.
		 * $timeout is positive seconds: a total HTTP transfer budget and a BiDi command/
		 * event wait limit. Failed BiDi setup attempts to delete the new HTTP session.
		 * Assertions report automatically to the active Test and stop on failure by default.
		 * Comparison methods accept $verify to record a failed result and continue instead.
		 * Outside Test::run(), failures throw RuntimeException. Assertions retry by default;
		 * $wait=false checks once.
		 * $waitTimeout, $assertionTimeout and $pollInterval are positive, finite milliseconds.
		 * Per-call timeouts override the corresponding polling budget, not command I/O.
		 */
		public function __construct(
			private string $endpoint,
			string $browser,
			bool $bidi = false,
			private float $timeout = 10,
			array $capabilities = [],
			float $waitTimeout = 5000,
			float $assertionTimeout = 5000,
			float $pollInterval = 500,
		) {
			$this->deadline($timeout);
			foreach (['waitTimeout' => $waitTimeout, 'assertionTimeout' => $assertionTimeout, 'pollInterval' => $pollInterval] as $name => $value):
				if (!is_finite($value) || $value <= 0) throw new \InvalidArgumentException($name . ' must be positive and finite.');
				$this->$name = $value;
			endforeach;
			$this->endpoint = self::baseUrl($endpoint);
			$capabilities['browserName'] = $browser;
			unset($capabilities['webSocketUrl']);
			if ($bidi):
				$capabilities['webSocketUrl'] = true;
				if ($browser === 'Safari Technology Preview') $capabilities['safari:experimentalWebSocketUrl'] = true;
			endif;
			$result = $this->request('POST', '/session', ['capabilities' => ['alwaysMatch' => $capabilities]]);
			if (!is_array($result) || !is_string($result['sessionId'] ?? null) || $result['sessionId'] === ''):
				throw new \RuntimeException('WebDriver did not return a session ID.');
			endif;
			$this->sessionId = $result['sessionId'];
			$this->capabilities = $result['capabilities'] ?? [];
			try {
				if ($bidi):
					$url = $result['capabilities']['webSocketUrl'] ?? null;
					if (!is_string($url) || $url === ''):
						throw new \RuntimeException('WebDriver did not provide the requested BiDi WebSocket URL.');
					endif;
					$this->connection = new BiDi(new WebSocket($url, timeout: $timeout), $timeout);
				endif;
			} catch (\Throwable $error) {
				try {
					$this->quit();
				} catch (\Throwable $cleanup) {
					throw new \RuntimeException($error->getMessage() . ' Session cleanup also failed: ' . $cleanup->getMessage(), 0, $error);
				}
				throw $error;
			}
		}

		/**
		 * Query driver readiness without creating a session. Returns ready and message.
		 * Example: Browser::status('http://localhost:4444')['ready'].
		 */
		public static function status(string $endpoint, float $timeout = 10): array
		{
			if (!is_finite($timeout) || $timeout <= 0):
				throw new \InvalidArgumentException('WebDriver timeout must be positive and finite.');
			endif;
			return self::http(self::baseUrl($endpoint), 'GET', '/status', null, $timeout);
		}

		/** Navigate the current window, using the driver's configured page-load strategy. */
		public function navigate(string $url): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->window()->navigate($url);
			return $this;
		}

		/** Return the current top-level document URL, including any redirect destination. */
		public function url(): string
		{
			return $this->window()->url();
		}

		/** Navigate one entry backward in the current window's history. */
		public function back(): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->window()->back();
			return $this;
		}

		/** Navigate one entry forward in the current window's history. */
		public function forward(): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->window()->forward();
			return $this;
		}

		/** Reload the current window's page. */
		public function refresh(): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->window()->refresh();
			return $this;
		}

		/**
		 * Find in the current window/frame. Presence is the default; $visible requires
		 * the first match to be displayed. $invert instead requires no matches, or no
		 * displayed matches when $visible is true. Positive success returns an Element;
		 * inverted success returns null. Unmet conditions throw unless $verify records
		 * a failed assertion and returns null through an active Test.
		 * By default, repeat at pollInterval; $wait=false checks once. $timeout is
		 * positive, finite milliseconds, defaulting to waitTimeout; ignored
		 * without $wait. In-flight commands retain their own timeouts.
		 * $assert or $verify reports one final result through the recorder, never one per poll.
		 * $verify takes precedence over $assert; failures still throw outside an active Test.
		 * Invalid selectors and unrelated driver errors propagate without assertion reports.
		 * Returned elements retain this lookup for waiting assertions; see Window::find().
		 * $using defaults to 'css selector'; other W3C strategies are 'xpath', 'tag name',
		 * 'link text', and 'partial link text'. For scoped XPath, use a relative expression.
		 */
		public function find(string $selector, string $using = 'css selector', bool $visible = false, bool $wait = true, bool $invert = false, bool $assert = false, ?float $timeout = null, bool $verify = false): ?Element
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->window()->find($selector, $using, $visible, $wait, $invert, $assert, $timeout, $verify);
		}

		/** Return a snapshot of matching elements in document order, possibly empty. */
		public function findAll(string $selector, string $using = 'css selector'): ElementSet
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->window()->findAll($selector, $using);
		}

		/** Return the focused element in the current browsing context. */
		public function activeElement(): Element
		{
			return $this->window()->activeElement();
		}

		/** Accept the current JavaScript alert, confirmation, or prompt. */
		public function alertAccept(): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->window()->alertAccept();
			return $this;
		}

		/** Dismiss the current JavaScript dialog, e.g. cancel a confirmation or prompt. */
		public function alertDismiss(): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->window()->alertDismiss();
			return $this;
		}

		/** Read the current JavaScript dialog's message; throws if no dialog is open. */
		public function alertText(): string
		{
			return $this->window()->alertText();
		}

		/** Enter a response in the current JavaScript prompt without accepting it. */
		public function alertType(string $text): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->window()->alertType($text);
			return $this;
		}

		/** Switch into a frame element or zero-based index in the current context. */
		public function switchFrame(Element|int $element): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->window()->switchFrame($element);
			return $this;
		}

		/** Move up one frame level; has no effect when already at the top level. */
		public function parentFrame(): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->window()->parentFrame();
			return $this;
		}

		/** Return to the current window's top-level document. */
		public function defaultFrame(): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->window()->defaultFrame();
			return $this;
		}

		/** Return the session's windows/tabs without selecting them; order is unspecified. @return Window[] */
		public function windows(): array
		{
			return array_map(fn($handle) => $this->window($handle), $this->sessionRequest('GET', '/window/handles'));
		}

		/** Get the current window, or an object for a known handle without selecting it. */
		public function window(?string $handle = null): Window
		{
			$this->sessionPath();
			if ($handle === null):
				if ($this->currentWindow === null):
					$handle = $this->sessionRequest('GET', '/window');
					if (!is_string($handle) || $handle === ''):
						throw new \RuntimeException('WebDriver did not return a window handle.');
					endif;
					$this->currentWindow = $this->window($handle);
				endif;
				return $this->currentWindow;
			endif;
			if ($handle === ''):
				throw new \InvalidArgumentException('Window handle must not be empty.');
			endif;
			return $this->windows[$handle] ??= new Window($this, $handle);
		}

		/** Create and select a tab or window, returning its fluent Window object. */
		public function open(string $type = 'tab'): Window
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$result = $this->window()->request('POST', '/window/new', ['type' => $type], topLevel: true);
			if (!is_string($result['handle'] ?? null) || $result['handle'] === ''):
				throw new \RuntimeException('WebDriver did not return a window handle.');
			endif;
			return $this->window($result['handle'])->activate();
		}

		/** Return outer-window x, y, width, and height; dimensions include browser chrome. */
		public function rect(): array
		{
			return $this->window()->rect();
		}

		/** Resize the outer window in CSS pixels; rect() reads the actual dimensions. */
		public function resize(int $width, int $height): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->window()->resize($width, $height);
			return $this;
		}

		/** Move the outer window to screen coordinates in CSS pixels. */
		public function move(int $x, int $y): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->window()->move($x, $y);
			return $this;
		}

		/** Maximize the current window. */
		public function maximize(): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->window()->maximize();
			return $this;
		}

		/** Minimize the current window. */
		public function minimize(): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->window()->minimize();
			return $this;
		}

		/** Enter fullscreen in the current window. */
		public function fullscreen(): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->window()->fullscreen();
			return $this;
		}

		/** Select a window/tab and reset its frame context to the top level. */
		public function switch(Window|string $window): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$window = is_string($window) ? $this->window($window) : $window;
			$this->activate($window, []);
			return $this;
		}

		/**
		 * Close the current window and select the first remaining window (unspecified
		 * order). Closing the final window ends the session; only quit() remains usable.
		 */
		public function close(): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->window()->close();
		}

		/** Pause locally for nonnegative, finite seconds (including fractions), preserving the chain. */
		public function sleep(float $seconds): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			if (!is_finite($seconds) || $seconds < 0):
				throw new \InvalidArgumentException('Sleep duration must be finite and nonnegative.');
			endif;
			$deadline = hrtime(true) / 1e9 + $seconds;
			while (($remaining = $deadline - hrtime(true) / 1e9) > 0):
				usleep((int)ceil(min(1, $remaining) * 1e6));
			endwhile;
			return $this;
		}

		/**
		 * Poll $condition($this) at the configured interval until it returns a truthy value, then
		 * return that value. Only missing/stale element errors are retried; all other
		 * exceptions and context-restoration failures propagate. Re-find elements inside
		 * the callback to survive DOM changes.
		 * $timeout is positive, finite milliseconds; null uses waitTimeout.
		 * Checked between callbacks: synchronous work retains its own HTTP/BiDi timeouts.
		 * Empty ElementSet objects are truthy: return first() or explicitly test count().
		 */
		public function wait(callable $condition, ?float $timeout = null): mixed
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$deadline = $this->deadline(($timeout ?? $this->waitTimeout) / 1000);
			$lastError = null;
			do {
				try {
					$result = $condition($this);
					if ($result):
						return $result;
					endif;
				} catch (\RuntimeException $error) {
					// Match our HTTP error prefix, not text from the driver's free-form message.
					if (!preg_match('/^WebDriver HTTP [45][0-9]{2} (no such element|stale element reference): /', $error->getMessage())):
						throw $error;
					endif;
					$lastError = $error;
				}
				$remaining = $deadline - hrtime(true) / 1e9;
				if ($remaining <= 0):
					break;
				endif;
				usleep((int)(min($this->pollInterval / 1000, $remaining) * 1e6));
			} while (hrtime(true) / 1e9 < $deadline);
			throw new \RuntimeException('WebDriver wait timed out.', 0, $lastError);
		}

		/**
		 * Assert that the current window URL matches a delimited PHP regex.
		 * Poll by default; $wait=false checks once.
		 * $timeout is positive milliseconds or null for assertionTimeout, ignored without waiting.
		 * Return this browser. Invalid patterns throw. Results report to the active Test;
		 * $verify records mismatches and continues. Outside Test::run(), failures always throw.
		 * $invert negates the match. All URL/title comparisons share these polling and reporting rules.
		 */
		public function urlMatches(string $pattern, bool $wait = true, ?float $timeout = null, bool $verify = false, bool $invert = false): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->window()->urlMatches($pattern, $wait, $timeout, $verify, $invert);
			return $this;
		}

		/** Assert exact current URL equality; use urlMatches() polling, verification, and millisecond timeout semantics. */
		public function urlEquals(string $expected, bool $wait = true, ?float $timeout = null, bool $verify = false, bool $invert = false): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->window()->urlEquals($expected, $wait, $timeout, $verify, $invert);
			return $this;
		}

		/** Assert a case-sensitive URL substring, polling by default; return this browser. */
		public function urlContains(string $expected, bool $wait = true, ?float $timeout = null, bool $verify = false, bool $invert = false): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->window()->urlContains($expected, $wait, $timeout, $verify, $invert);
			return $this;
		}

		/** Assert the current top-level title; use urlMatches() polling, verification, and millisecond timeout semantics. */
		public function titleEquals(string $expected, bool $wait = true, ?float $timeout = null, bool $verify = false, bool $invert = false): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->window()->titleEquals($expected, $wait, $timeout, $verify, $invert);
			return $this;
		}

		/** Assert a case-sensitive title substring, polling by default; return this browser. */
		public function titleContains(string $expected, bool $wait = true, ?float $timeout = null, bool $verify = false, bool $invert = false): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->window()->titleContains($expected, $wait, $timeout, $verify, $invert);
			return $this;
		}

		/** Assert a title matches a delimited PHP regex; invalid patterns throw before polling. */
		public function titleMatches(string $pattern, bool $wait = true, ?float $timeout = null, bool $verify = false, bool $invert = false): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->window()->titleMatches($pattern, $wait, $timeout, $verify, $invert);
			return $this;
		}

		/** @internal Report once; $verify continues only when an active Test records the result. */
		public function reportAssertion(bool $condition, string $message, bool $verify = false, array $comparison = []): void
		{
			if (class_exists(Test::class) && ($test = Test::active()) !== null):
				if ($verify):
					$test->verify($condition, $message, $comparison);
				else:
					$test->assert($condition, $message, $comparison);
				endif;
			elseif (!$condition):
				throw new \RuntimeException('WebDriver assertion failed: ' . $message);
			endif;
		}

		/** @internal Match a delimited PHP regex; null never matches, and regex errors throw. */
		public static function matches(string $pattern, ?string $actual): bool
		{
			$matched = @preg_match($pattern, $actual ?? '');
			if ($matched === false):
				throw new \InvalidArgumentException('Could not evaluate assertion pattern ' . var_export($pattern, true) . ': ' . preg_last_error_msg());
			endif;
			return $actual !== null && $matched === 1;
		}

		/**
		 * @internal Evaluate once or poll at pollInterval; return false on expiry. Errors propagate.
		 * Timeout is positive, finite milliseconds; null uses waitTimeout or assertionTimeout.
		 * Ignore timeout when not waiting. Each call gets its own deadline, checked between commands.
		 */
		public function evaluate(callable $condition, bool $wait, ?float $timeout, bool $assertion = false): bool
		{
			$deadline = $wait ? $this->deadline(($timeout ?? ($assertion ? $this->assertionTimeout : $this->waitTimeout)) / 1000) : null;
			do {
				if ($condition()):
					return true;
				endif;
				if (!$wait || ($remaining = $deadline - hrtime(true) / 1e9) <= 0):
					return false;
				endif;
				usleep((int)(min($this->pollInterval / 1000, $remaining) * 1e6));
			} while (hrtime(true) / 1e9 < $deadline);
			return false;
		}

		/** Return the current window's document title. */
		public function title(): string
		{
			return $this->window()->title();
		}

		/** Return the current frame's serialized document DOM, not the original HTTP response. */
		public function source(): string
		{
			return $this->window()->source();
		}

		/** Return cookies associated with the active document, including HttpOnly cookies. */
		public function cookies(): array
		{
			return $this->window()->cookies();
		}

		/**
		 * Read or set a cookie for the active document. Navigate to the target website first;
		 * WebDriver rejects unrelated domains and cookie-averse pages such as data: URLs.
		 *
		 * Omit $value or pass null to return the named cookie's string value. Missing
		 * cookies throw the driver's error. Use cookies() to retrieve full cookie fields.
		 * Pass a string (including '' or '0') to add/replace the cookie and return this
		 * browser for chaining.
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
		 * $browser->navigate('https://example.com')->cookie(
		 *     name: 'theme',
		 *     value: 'dark',
		 *     secure: true,
		 *     sameSite: 'Lax',
		 *     expiry: time() + 86400,
		 * );
		 * $theme = $browser->cookie('theme'); // 'dark'
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
			if ($value !== null && ($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$result = $this->window()->cookie($name, $value, $domain, $path, $secure, $httpOnly, $sameSite, $expiry);
			return $value === null ? $result : $this;
		}

		/** Delete matching cookies by name in the active document's scope. */
		public function cookieDelete(string $name): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->window()->cookieDelete($name);
			return $this;
		}

		/** Delete all cookies associated with the active document, not every browser domain. */
		public function cookieDeleteAll(): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->window()->cookieDeleteAll();
			return $this;
		}

		/**
		 * Capture the current window's viewport immediately. Use ->data() for decoded
		 * PNG bytes or ->save('page.png') to write them. This is not a full-page guarantee.
		 */
		public function screenshot(): Screenshot
		{
			return $this->window()->screenshot();
		}

		/**
		 * Capture the current HTML document/frame immediately without changing the live DOM.
		 * Script elements are removed and a resolved base URL preserves resource references.
		 * Use ->data() for HTML or ->save('page.html'); the capture survives session cleanup.
		 * Resources, child-frame documents and shadow trees are not bundled. See Window::snapshot().
		 */
		public function snapshot(): Snapshot
		{
			return $this->window()->snapshot();
		}

		/**
		 * Capture a PDF of the current window/frame immediately using W3C print options.
		 * Use ->data() for decoded bytes or ->save('page.pdf') to write them.
		 * Support depends on the browser/driver; unsupported-command errors propagate.
		 *
		 * Optional named arguments:
		 * - $orientation: 'portrait' (default) or 'landscape'.
		 * - $scale: 0.1 to 2, default 1; $background: include background graphics, default false.
		 * - $pageWidth/$pageHeight: paper dimensions in cm, defaults 21.59 x 27.94;
		 *   minimum 2.54 / 72 cm each. Landscape swaps the dimensions.
		 * - $marginTop/$marginBottom/$marginLeft/$marginRight: nonnegative cm, default 1 each.
		 * - $pageRanges: list of one-based page integers or strings such as '3-5', '-2',
		 *   or '4-'; default [] includes every page.
		 * - $shrinkToFit: fit content to the paper width, default true.
		 *
		 * ```php
		 * $browser->pdf(
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
			return $this->window()->pdf(
				orientation: $orientation, scale: $scale, background: $background,
				pageWidth: $pageWidth, pageHeight: $pageHeight,
				marginTop: $marginTop, marginBottom: $marginBottom,
				marginLeft: $marginLeft, marginRight: $marginRight,
				pageRanges: $pageRanges, shrinkToFit: $shrinkToFit,
			);
		}

		/**
		 * Perform W3C keyboard, pointer, or wheel input sources, retaining held input state.
		 * Pass the source list (not an outer actions object); call releaseActions() when done.
		 * Source IDs let subsequent calls continue the same keyboard or pointer sequence.
		 * Each source is ['id' => string, 'type' => 'key'|'pointer'|'wheel'|'none',
		 * 'actions' => list of action arrays]. Sources run together in ticks by list index.
		 * Pointer sources optionally include parameters: ['pointerType' => 'mouse'|'pen'|'touch']
		 * (default mouse). Action types and fields:
		 * - pause: optional duration, default 0; valid on every source ('none' only pauses).
		 * - keyDown/keyUp: value is one Unicode character or a Browser key constant.
		 * - pointerMove: integer x/y, optional duration and origin ('viewport' by default,
		 *   'pointer', or an Element from the current window/frame).
		 * - pointerDown/pointerUp: integer button (0 left, 1 middle, 2 right).
		 * - scroll (wheel): integer x/y/deltaX/deltaY, optional duration and origin
		 *   ('viewport' by default or an Element from the current window/frame).
		 * Durations are nonnegative integer milliseconds; omitted move/scroll durations
		 * use the tick duration (the longest duration across sources in that tick).
		 * Coordinates/deltas are CSS pixels. Driver validation/support errors propagate. Cycles or nesting
		 * beyond 64 levels throw InvalidArgumentException. See also Window::actions().
		 *
		 * ```php
		 * $button = $browser->find('button');
		 * try {
		 *     $browser->actions([[
		 *         'type' => 'pointer', 'id' => 'mouse',
		 *         'parameters' => ['pointerType' => 'mouse'],
		 *         'actions' => [
		 *             ['type' => 'pointerMove', 'origin' => $button, 'x' => 0, 'y' => 0],
		 *             ['type' => 'pointerDown', 'button' => 0],
		 *             ['type' => 'pointerUp', 'button' => 0],
		 *         ],
		 *     ]]);
		 * } finally {
		 *     $browser->releaseActions();
		 * }
		 * ```
		 */
		public function actions(array $sources): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->window()->actions($sources);
			return $this;
		}

		/** Release held keys/buttons and clear the session's input-source state. */
		public function releaseActions(): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->window()->releaseActions();
			return $this;
		}

		/** Return browser script, pageLoad, and implicit timeouts in protocol milliseconds. */
		public function timeouts(): array
		{
			return $this->request('GET', $this->sessionPath() . '/timeouts');
		}

		/** Set the browser navigation timeout in seconds; the constructor's HTTP timeout still applies. */
		public function pageLoadTimeout(float $seconds): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->setTimeout('pageLoad', $seconds);
			return $this;
		}

		/** Set element-search retries in seconds; these also lengthen find() calls inside wait(). */
		public function implicitTimeout(float $seconds): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->setTimeout('implicit', $seconds);
			return $this;
		}

		/**
		 * Execute JavaScript in the current window/frame and return its JSON-decoded result.
		 * $arguments are positional JavaScript arguments and may contain Element objects
		 * in nested arrays. Returned DOM elements are converted to Element objects.
		 * Cyclic arguments or nesting beyond 64 levels throw InvalidArgumentException.
		 *
		 * PHP blocks until execution completes in either mode. With $async = true,
		 * WebDriver appends a completion callback as the final JavaScript argument.
		 * Call it with the result to complete the waiting PHP call. Returning from the
		 * JavaScript function without signaling completion does not finish the wait.
		 * A timeout or execution error throws an exception.
		 *
		 * Calling the callback signals completion of this invocation; other outstanding
		 * JavaScript requests or timers may still be running.
		 *
		 * With $inject, append an inline script containing the source (without script tags)
		 * and return null. Execution is subject to CSP; insertion does not guarantee success.
		 * Injection rejects $async=true and nonempty $arguments.
		 *
		 * ```php
		 * $driver->scriptTimeout(5);
		 * $result = $driver->script(
		 *     'const done = arguments[arguments.length - 1]; setTimeout(() => done(42), 100);',
		 *     async: true,
		 * );
		 * ```
		 * The script's browser timeout plus the constructor's I/O allowance is used for
		 * this HTTP request. If the browser timeout is disabled (null), only the finite
		 * constructor timeout applies; an indefinitely running script is not awaited forever.
		 */
		public function script(string $script, bool $async = false, array $arguments = [], bool $inject = false): mixed
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->window()->script($script, $async, $arguments, $inject);
		}

		/** Set the browser's script execution timeout in seconds, rounded to whole milliseconds. */
		public function scriptTimeout(float $seconds): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->setTimeout('script', $seconds);
			return $this;
		}

		/** Subscribe to BiDi event names, e.g. ['log.entryAdded'], and return the subscription ID. */
		public function subscribe(array $events): string
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$result = $this->command('session.subscribe', ['events' => array_values($events)]);
			if (!is_string($result['subscription'] ?? null) || $result['subscription'] === ''):
				throw new \RuntimeException('WebDriver BiDi did not return a subscription ID.');
			endif;
			return $result['subscription'];
		}

		/** Cancel subscription IDs returned by subscribe(); events already queued remain available. */
		public function unsubscribe(array $subscriptions): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->command('session.unsubscribe', ['subscriptions' => array_values($subscriptions)]);
			return $this;
		}

		/**
		 * Run one BiDi command and return its result object as an associative array.
		 * Intervening events are dispatched and queued; internal response commands may
		 * complete while this command is waiting, with replies routed by command ID.
		 * Driver errors throw without dropping BiDi. Transport/protocol failures and
		 * receive timeouts discard BiDi; HTTP remains available for commands and quit().
		 */
		public function command(string $method, array $params = []): array
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			return $this->bidi()->command($method, $params);
		}

		/** @internal Shared dispatcher for BiDi feature implementations; not a separate session. */
		public function bidi(): BiDi
		{
			if ($this->connection === null) throw new \LogicException('No active WebDriver BiDi connection.');
			$this->connection->assertCallable();
			return $this->connection;
		}

		/** Return this session's network controller. Requires BiDi; capture and interception start independently. */
		public function network(): Network
		{
			$this->connection?->assertCallable();
			return $this->network ??= new Network($this->bidi(), $this->sessionId);
		}

		/** Start session-wide console/exception capture. Requires bidi: true; repeated starts are harmless. */
		public function startLogCapture(): self
		{
			$connection = $this->bidi();
			if (!$connection->active()) throw new \LogicException('No active WebDriver BiDi connection.');
			if ($this->logSubscription !== null) return $this;
			$connection->drain();
			$this->logListener = $connection->listen('log.entryAdded', function (array $entry): void {
				$this->logs[] = $entry;
				\ThriveData\ThrivePHP\Test::active()?->browserLog($this->session(), $entry);
			});
			try {
				$result = $connection->command('session.subscribe', ['events' => ['log.entryAdded']]);
				if (!is_string($result['subscription'] ?? null) || $result['subscription'] === '') throw new \RuntimeException('WebDriver BiDi did not return a subscription ID.');
				$this->logSubscription = $result['subscription'];
				$this->logFailure = null;
				\ThriveData\ThrivePHP\Test::active()?->watchBrowserLogs($this);
			} catch (\Throwable $error) {
				$connection->unlisten($this->logListener);
				$this->logListener = null;
				throw $error;
			}
			return $this;
		}

		/** Stop this collector's subscription, retaining entries. Restarting appends to the history. */
		public function stopLogCapture(): self
		{
			$this->connection?->assertCallable();
			if ($this->logSubscription === null) return $this;
			try {
				$this->bidi()->command('session.unsubscribe', ['subscriptions' => [$this->logSubscription]]);
				$this->connection->drain();
			} catch (\Throwable $error) {
				$this->logFailure = $error;
				throw $error;
			} finally {
				$this->detachLogs();
			}
			return $this;
		}

		/**
		 * Collect available events and return retained protocol entries, optionally filtered by
		 * console/javascript type. Reading never clears entries; captured data survives quit().
		 */
		public function logs(?string $type = null): array
		{
			if ($type !== null && !in_array($type, ['console', 'javascript'], true)) throw new \InvalidArgumentException('Expected console or javascript log type.');
			$this->collectLogs();
			return $type === null ? $this->logs : array_values(array_filter($this->logs, fn ($entry) => ($entry['type'] ?? null) === $type));
		}

		/** @internal Collection checkpoints do not create steps or consume the low-level event queue. */
		public function collectLogs(): void
		{
			$this->connection?->assertCallable();
			$this->logCaptureError();
			if ($this->logSubscription !== null && $this->connection?->active()):
				try {
					$this->connection->drain();
				} catch (\Throwable $error) {
					$this->logFailure = $error;
					throw $error;
				}
			endif;
		}

		/** Capture failure is separate from browser messages; entries remain readable after failure. */
		public function logCaptureError(): ?\Throwable
		{
			if ($this->logSubscription !== null && $this->connection !== null && !$this->connection->active()):
				$this->logFailure ??= $this->connection->failure() ?? new \RuntimeException('Browser log collection lost its BiDi connection.');
			endif;
			return $this->logFailure;
		}

		private function detachLogs(): void
		{
			if ($this->logListener !== null) $this->connection?->unlisten($this->logListener);
			$this->logListener = null;
			$this->logSubscription = null;
		}

		/**
		 * Return the next BiDi event envelope (type, method, params), queued or newly
		 * received. Subscribe first using subscribe(). $timeout overrides seconds
		 * for this wait; expiration throws and closes BiDi rather than returning null.
		 */
		public function event(?float $timeout = null): array
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$this->deadline($timeout ?? $this->timeout);
			return $this->bidi()->event($timeout ?? $this->timeout);
		}

		/**
		 * Delete the remote session over HTTP, which also ends its BiDi connection.
		 * Always release the local socket and queued events. Safe to repeat after
		 * success; a failed HTTP deletion throws and retains the session ID for retry.
		 */
		public function quit(): self
		{
			if (($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			if ($this->sessionId === null):
				return $this;
			endif;
			try {
				try {
					try {
						$this->collectLogs();
					} finally {
						$this->network?->collect();
					}
				} finally {
					$this->request('DELETE', $this->sessionPath());
					$this->invalidateSession();
				}
			} finally {
				$this->detachLogs();
				$this->network?->disconnect();
				$this->connection?->disconnect();
				$this->connection = null;
			}
			return $this;
		}

		/** Whether this client still holds a session ID; this does not probe remote liveness. */
		public function active(): bool
		{
			return $this->sessionId !== null;
		}

		/** Session identity and negotiated capabilities, including browserVersion and platformName. */
		public function session(): array
		{
			return ['sessionId' => $this->sessionId, 'capabilities' => $this->capabilities];
		}

		/** @internal Forget local session state after deletion or termination of its owned service. */
		public function invalidateSession(): void
		{
			$this->sessionId = null;
			$this->detachLogs();
			$this->network?->disconnect();
			$this->connection?->disconnect();
			$this->connection = null;
			$this->currentWindow = null;
			$this->currentFrames = [];
			$this->windows = [];
		}

		/** @internal Send a session-relative command; extra timeout is added to the I/O allowance. */
		public function sessionRequest(string $method, string $path, ?array $body = null, float $extraTimeout = 0): mixed
		{
			return $this->request($method, $this->sessionPath() . $path, $body, $this->timeout + $extraTimeout);
		}

		/**
		 * @internal Select an object's owning context. Window switches reset frames, so
		 * replay the saved path only when needed. Failed switches invalidate the cache.
		 * Null selects only the window, preserving its logical frame path for later use.
		 */
		public function activate(Window $window, ?array $frames): void
		{
			$this->sessionPath();
			if ($window->browser() !== $this):
				throw new \InvalidArgumentException('Window belongs to another browser session.');
			endif;
			$window->assertOpen();
			try {
				if ($this->currentWindow !== $window):
					$this->sessionRequest('POST', '/window', ['handle' => $window->handle()]);
					$this->currentWindow = $window;
					$this->currentFrames = [];
				endif;
				if ($frames === null):
					return;
				endif;
				if ($this->currentFrames !== $frames):
					if ($this->currentFrames !== []):
						$this->sessionRequest('POST', '/frame', ['id' => null]);
					endif;
					$this->currentFrames = [];
					foreach ($frames as $frame):
						$this->sessionRequest('POST', '/frame', ['id' => $frame]);
						$this->currentFrames[] = $frame;
					endforeach;
				endif;
				$window->rememberFrames($frames);
			} catch (\Throwable $error) {
				$this->invalidateContext();
				// Callback waits must not retry stale/missing frame references as element conditions.
				if ($error instanceof \RuntimeException):
					throw new \RuntimeException('WebDriver context restoration failed: ' . $error->getMessage(), 0, $error);
				endif;
				throw $error;
			}
		}

		/** @internal Forget actual driver selection after a context-changing command fails. */
		public function invalidateContext(): void
		{
			$this->currentWindow = null;
			$this->currentFrames = null;
		}

		/** @internal Record a successful frame command or navigation. */
		public function rememberFrames(Window $window, array $frames): void
		{
			$this->currentWindow = $window;
			$this->currentFrames = $frames;
			$window->rememberFrames($frames);
		}

		/** @internal Close an owning window and invalidate its objects after success. */
		public function closeWindow(Window $window): self
		{
			$this->activate($window, null);
			$this->collectLogs();
			$this->network?->collect();
			$handles = $this->sessionRequest('DELETE', '/window');
			$window->markClosed();
			$this->currentWindow = null;
			$this->currentFrames = [];
			if ($handles === []):
				$this->invalidateSession();
			else:
				$this->switch($handles[0]);
			endif;
			return $this;
		}

		/** Build the session path without allowing opaque IDs to alter URL routing. */
		private function sessionPath(): string
		{
			if ($this->sessionId === null):
				throw new \LogicException('No active WebDriver session.');
			endif;
			return '/session/' . rawurlencode($this->sessionId);
		}

		/** Convert public seconds to protocol milliseconds, updating only the named timeout. */
		private function setTimeout(string $name, float $seconds): void
		{
			$milliseconds = round($seconds * 1000);
			if (!is_finite($milliseconds) || $seconds < 0 || $milliseconds >= PHP_INT_MAX):
				throw new \InvalidArgumentException(ucfirst($name) . ' timeout must be finite, nonnegative, and representable in milliseconds.');
			endif;
			$this->request('POST', $this->sessionPath() . '/timeouts', [$name => (int)$milliseconds]);
		}

		/** Normalize the HTTP(S) base used by both session construction and sessionless status(). */
		private static function baseUrl(string $endpoint): string
		{
			$parts = parse_url($endpoint);
			if (!$parts || !isset($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
				|| isset($parts['query']) || isset($parts['fragment'])):
				throw new \InvalidArgumentException('Expected an HTTP(S) driver base URL without query or fragment.');
			endif;
			return rtrim($endpoint, '/');
		}

		/** Send classic WebDriver JSON; scripts may override the ordinary I/O timeout in seconds. */
		private function request(string $method, string $path, ?array $body = null, ?float $timeout = null): mixed
		{
			$this->connection?->assertCallable();
			$deleting = $method === 'DELETE' && $path === '/session/' . rawurlencode($this->sessionId ?? '');
			$closingWindow = $method === 'DELETE' && str_ends_with($path, '/window');
			$value = HttpRequest::run($this->endpoint, $method, $path, $body, $timeout ?? $this->timeout,
				!$deleting && $this->connection?->active() ? function () use ($closingWindow): void {
					if (!$this->connection->active()) return;
					try {
						$this->connection->tick();
					} catch (\Throwable $error) {
						if ($closingWindow && $this->connection->peerClosed()) return;
						if ($this->logSubscription !== null) $this->logFailure = $error;
						throw $error;
					}
				} : null);
			if ($closingWindow && $value !== [] && $this->connection?->peerClosed()) throw $this->connection->failure();
			return $value;
		}

		/** Shared HTTP transport for session commands and status(), which requires no session. */
		private static function http(string $endpoint, string $method, string $path, ?array $body, float $timeout): mixed
		{
			return HttpRequest::run($endpoint, $method, $path, $body, $timeout);
		}

		/** Absolute seconds on the monotonic clock; incoming events cannot extend a wait. */
		private function deadline(float $timeout): float
		{
			if (!is_finite($timeout) || $timeout <= 0):
				throw new \InvalidArgumentException('WebDriver timeout must be positive and finite.');
			endif;
			return hrtime(true) / 1e9 + $timeout;
		}
	}
