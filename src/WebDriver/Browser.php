<?php
	namespace ThriveData\ThrivePHP\WebDriver;

	use ThriveData\ThrivePHP\WebSocket;

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
		private ?WebSocket $socket = null;
		/** BiDi events received while waiting for a command response, in arrival order. */
		private array $events = [];
		/** BiDi command IDs distinguish responses from unsolicited browser events. */
		private int $nextId = 0;
		/** Window objects are shared so frame selection and closure stay consistent. */
		private array $windows = [];
		private ?Window $currentWindow = null;
		/** Selected frame path; null means a failed switch left the context uncertain. */
		private ?array $currentFrames = [];
		/** Shared assertion recorder; null makes failed assertions throw. */
		private ?\Closure $assertion = null;

		/**
		 * Create a session immediately, connecting BiDi if requested. $endpoint is the
		 * driver's HTTP(S) base URL, optionally including a path such as /wd/hub.
		 * $capabilities supplies browser options, e.g. goog:chromeOptions for headless
		 * Chrome; $browser and $bidi determine browserName and webSocketUrl themselves.
		 * $timeout is positive seconds: an HTTP stream I/O timeout and a BiDi command/
		 * event wait limit. Failed BiDi setup attempts to delete the new HTTP session.
		 * $assert optionally receives (bool $condition, string $message) once per fluent
		 * assertion, including successes. Its return value is ignored; exceptions propagate.
		 * Without it, failed assertions throw RuntimeException. Assertions retry only with $wait.
		 * For a test recorder, pass assert: [$test, 'assert'].
		 */
		public function __construct(
			private string $endpoint,
			string $browser,
			bool $bidi = false,
			private float $timeout = 10,
			array $capabilities = [],
			?callable $assert = null,
		) {
			$this->assertion = $assert === null ? null : \Closure::fromCallable($assert);
			$this->deadline($timeout);
			$this->endpoint = self::baseUrl($endpoint);
			$capabilities['browserName'] = $browser;
			unset($capabilities['webSocketUrl']);
			if ($bidi):
				$capabilities['webSocketUrl'] = true;
			endif;
			$result = $this->request('POST', '/session', ['capabilities' => ['alwaysMatch' => $capabilities]]);
			if (!is_array($result) || !is_string($result['sessionId'] ?? null) || $result['sessionId'] === ''):
				throw new \RuntimeException('WebDriver did not return a session ID.');
			endif;
			$this->sessionId = $result['sessionId'];
			try {
				if ($bidi):
					$url = $result['capabilities']['webSocketUrl'] ?? null;
					if (!is_string($url) || $url === ''):
						throw new \RuntimeException('WebDriver did not provide the requested BiDi WebSocket URL.');
					endif;
					$this->socket = new WebSocket($url, timeout: $timeout);
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
			$this->window()->back();
			return $this;
		}

		/** Navigate one entry forward in the current window's history. */
		public function forward(): self
		{
			$this->window()->forward();
			return $this;
		}

		/** Reload the current window's page. */
		public function refresh(): self
		{
			$this->window()->refresh();
			return $this;
		}

		/**
		 * Find in the current window/frame. Presence is the default; $visible requires
		 * the first match to be displayed. $invert instead requires no matches, or no
		 * displayed matches when $visible is true. Positive success returns an Element;
		 * inverted success returns null. Unmet conditions throw, even with a recorder.
		 * $wait repeats the search every 100 ms. $timeout is positive, finite milliseconds,
		 * defaulting to the constructor timeout; ignored without $wait. In-flight commands
		 * retain their own timeouts. $assert reports one final result through the recorder.
		 * Invalid selectors and unrelated driver errors propagate without assertion reports.
		 * Returned elements retain this lookup for waiting assertions; see Window::find().
		 * $using defaults to 'css selector'; other W3C strategies are 'xpath', 'tag name',
		 * 'link text', and 'partial link text'. For scoped XPath, use a relative expression.
		 */
		public function find(string $selector, string $using = 'css selector', bool $visible = false, bool $wait = false, bool $invert = false, bool $assert = false, ?float $timeout = null): ?Element
		{
			return $this->window()->find($selector, $using, $visible, $wait, $invert, $assert, $timeout);
		}

		/** Return a snapshot of matching elements in document order, possibly empty. */
		public function findAll(string $selector, string $using = 'css selector'): ElementSet
		{
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
			$this->window()->alertAccept();
			return $this;
		}

		/** Dismiss the current JavaScript dialog, e.g. cancel a confirmation or prompt. */
		public function alertDismiss(): self
		{
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
			$this->window()->alertType($text);
			return $this;
		}

		/** Switch into a frame element or zero-based index in the current context. */
		public function switchFrame(Element|int $element): self
		{
			$this->window()->switchFrame($element);
			return $this;
		}

		/** Move up one frame level; has no effect when already at the top level. */
		public function parentFrame(): self
		{
			$this->window()->parentFrame();
			return $this;
		}

		/** Return to the current window's top-level document. */
		public function defaultFrame(): self
		{
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
			$this->window()->resize($width, $height);
			return $this;
		}

		/** Move the outer window to screen coordinates in CSS pixels. */
		public function move(int $x, int $y): self
		{
			$this->window()->move($x, $y);
			return $this;
		}

		/** Maximize the current window. */
		public function maximize(): self
		{
			$this->window()->maximize();
			return $this;
		}

		/** Minimize the current window. */
		public function minimize(): self
		{
			$this->window()->minimize();
			return $this;
		}

		/** Enter fullscreen in the current window. */
		public function fullscreen(): self
		{
			$this->window()->fullscreen();
			return $this;
		}

		/** Select a window/tab and reset its frame context to the top level. */
		public function switch(Window|string $window): self
		{
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
			return $this->window()->close();
		}

		/** Pause locally for nonnegative, finite seconds (including fractions), preserving the chain. */
		public function sleep(float $seconds): self
		{
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
		 * Poll $condition($this) every 100 ms until it returns a truthy value, then
		 * return that value. Only missing/stale element errors are retried; all other
		 * exceptions propagate. Re-find elements inside the callback to survive DOM changes.
		 * $timeout is positive, finite milliseconds; null uses the constructor timeout.
		 * Checked between callbacks: synchronous work retains its own HTTP/BiDi timeouts.
		 * Empty ElementSet objects are truthy: return first() or explicitly test count().
		 */
		public function wait(callable $condition, ?float $timeout = null): mixed
		{
			$deadline = $this->deadline($timeout === null ? $this->timeout : $timeout / 1000);
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
				usleep((int)(min(0.1, $remaining) * 1e6));
			} while (hrtime(true) / 1e9 < $deadline);
			throw new \RuntimeException('WebDriver wait timed out.', 0, $lastError);
		}

		/**
		 * Assert that the current window URL matches a delimited PHP regex. $wait polls;
		 * $timeout is positive milliseconds or null for the browser default, ignored without waiting.
		 * Return this browser. Invalid patterns throw; results use the constructor's
		 * assertion callback, or throw on failure when none was supplied.
		 */
		public function urlMatches(string $pattern, bool $wait = false, ?float $timeout = null): self
		{
			$this->window()->urlMatches($pattern, $wait, $timeout);
			return $this;
		}

		/** @internal Report one assertion result; callback return values do not control the chain. */
		public function reportAssertion(bool $condition, string $message): void
		{
			if ($this->assertion !== null):
				($this->assertion)($condition, $message);
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
		 * @internal Evaluate once or poll every 100 ms; return false on expiry. Errors propagate.
		 * The timeout is positive, finite milliseconds, or null for the constructor default.
		 * Ignore timeout when not waiting. Each call gets its own deadline, checked between commands.
		 */
		public function evaluate(callable $condition, bool $wait, ?float $timeout): bool
		{
			$deadline = $wait ? $this->deadline($timeout === null ? $this->timeout : $timeout / 1000) : null;
			do {
				if ($condition()):
					return true;
				endif;
				if (!$wait || ($remaining = $deadline - hrtime(true) / 1e9) <= 0):
					return false;
				endif;
				usleep((int)(min(0.1, $remaining) * 1e6));
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
			$result = $this->window()->cookie($name, $value, $domain, $path, $secure, $httpOnly, $sameSite, $expiry);
			return $value === null ? $result : $this;
		}

		/** Delete matching cookies by name in the active document's scope. */
		public function cookieDelete(string $name): self
		{
			$this->window()->cookieDelete($name);
			return $this;
		}

		/** Delete all cookies associated with the active document, not every browser domain. */
		public function cookieDeleteAll(): self
		{
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
			$this->window()->actions($sources);
			return $this;
		}

		/** Release held keys/buttons and clear the session's input-source state. */
		public function releaseActions(): self
		{
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
			$this->setTimeout('pageLoad', $seconds);
			return $this;
		}

		/** Set element-search retries in seconds; these also lengthen find() calls inside wait(). */
		public function implicitTimeout(float $seconds): self
		{
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
		public function script(string $script, bool $async = false, array $arguments = []): mixed
		{
			return $this->window()->script($script, $async, $arguments);
		}

		/** Set the browser's script execution timeout in seconds, rounded to whole milliseconds. */
		public function scriptTimeout(float $seconds): self
		{
			$this->setTimeout('script', $seconds);
			return $this;
		}

		/** Subscribe to BiDi event names, e.g. ['log.entryAdded'], and return the subscription ID. */
		public function subscribe(array $events): string
		{
			$result = $this->command('session.subscribe', ['events' => array_values($events)]);
			if (!is_string($result['subscription'] ?? null) || $result['subscription'] === ''):
				throw new \RuntimeException('WebDriver BiDi did not return a subscription ID.');
			endif;
			return $result['subscription'];
		}

		/** Cancel subscription IDs returned by subscribe(); events already queued remain available. */
		public function unsubscribe(array $subscriptions): self
		{
			$this->command('session.unsubscribe', ['subscriptions' => array_values($subscriptions)]);
			return $this;
		}

		/**
		 * Run one BiDi command and return its result object as an associative array.
		 * Only one command is outstanding at a time; intervening events are queued.
		 * Driver errors throw without dropping BiDi. Transport/protocol failures and
		 * receive timeouts discard BiDi; HTTP remains available for commands and quit().
		 */
		public function command(string $method, array $params = []): array
		{
			if ($this->socket === null):
				throw new \LogicException('No active WebDriver BiDi connection.');
			endif;
			$id = ++$this->nextId;
			// Empty parameters must be a JSON object, not an array.
			$json = json_encode(['id' => $id, 'method' => $method, 'params' => (object)$params], JSON_THROW_ON_ERROR);
			$deadline = $this->deadline($this->timeout);
			try {
				$this->socket->send($json);
				do {
					$message = $this->receiveMessage($deadline);
					if ($message['type'] === 'event'):
						$this->events[] = $message;
					endif;
				} while ($message['type'] === 'event');
				if (($message['id'] ?? null) !== $id):
					throw new \RuntimeException('WebDriver BiDi response has an unexpected command ID.');
				endif;
			} catch (\Throwable $error) {
				$this->socket = null;
				throw $error;
			}
			if ($message['type'] === 'error'):
				throw new \RuntimeException('WebDriver BiDi ' . $message['error'] . ': ' . $message['message']);
			endif;
			return $message['result'];
		}

		/**
		 * Return the next BiDi event envelope (type, method, params), queued or newly
		 * received. Subscribe first using subscribe(). $timeout overrides seconds
		 * for this wait; expiration throws and closes BiDi rather than returning null.
		 */
		public function event(?float $timeout = null): array
		{
			$deadline = $this->deadline($timeout ?? $this->timeout);
			if ($this->events):
				return array_shift($this->events);
			endif;
			$message = $this->receiveMessage($deadline);
			if ($message['type'] !== 'event'):
				$this->socket = null;
				throw new \RuntimeException('Expected a WebDriver BiDi event, received an unsolicited response.');
			endif;
			return $message;
		}

		/**
		 * Delete the remote session over HTTP, which also ends its BiDi connection.
		 * Always release the local socket and queued events. Safe to repeat after
		 * success; a failed HTTP deletion throws and retains the session ID for retry.
		 */
		public function quit(): self
		{
			if ($this->sessionId === null):
				return $this;
			endif;
			try {
				$this->request('DELETE', $this->sessionPath());
				$this->invalidateSession();
			} finally {
				$this->socket = null;
				$this->events = [];
			}
			return $this;
		}

		/** Whether this client still holds a session ID; this does not probe remote liveness. */
		public function active(): bool
		{
			return $this->sessionId !== null;
		}

		/** @internal Forget local session state after deletion or termination of its owned service. */
		public function invalidateSession(): void
		{
			$this->sessionId = null;
			$this->socket = null;
			$this->events = [];
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

		/** Decode one BiDi envelope under the caller's shared monotonic deadline. */
		private function receiveMessage(float $deadline): array
		{
			if ($this->socket === null):
				throw new \LogicException('No active WebDriver BiDi connection.');
			endif;
			try {
				$remaining = $deadline - hrtime(true) / 1e9;
				if ($remaining <= 0):
					throw new \RuntimeException('WebDriver BiDi operation timed out.');
				endif;
				$text = $this->socket->receive($remaining);
				if ($text === null):
					throw new \RuntimeException('WebDriver BiDi connection closed by the peer.');
				endif;
				$message = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
				$type = $message['type'] ?? null;
				if (!is_array($message)
					|| !in_array($type, ['event', 'success', 'error'], true)
					|| ($type === 'event' && (!is_string($message['method'] ?? null) || !is_array($message['params'] ?? null)))
					|| ($type === 'success' && !is_array($message['result'] ?? null))
					|| ($type === 'error' && (!is_string($message['error'] ?? null) || !is_string($message['message'] ?? null)))):
					throw new \RuntimeException('Invalid WebDriver BiDi message.');
				endif;
				return $message;
			} catch (\Throwable $error) {
				$this->socket = null;
				throw $error;
			}
		}

		/** Send classic WebDriver JSON; scripts may override the ordinary I/O timeout in seconds. */
		private function request(string $method, string $path, ?array $body = null, ?float $timeout = null): mixed
		{
			return self::http($this->endpoint, $method, $path, $body, $timeout ?? $this->timeout);
		}

		/** Shared HTTP transport for session commands and status(), which requires no session. */
		private static function http(string $endpoint, string $method, string $path, ?array $body, float $timeout): mixed
		{
			$context = stream_context_create(['http' => [
				'method' => $method,
				'header' => "Content-Type: application/json\r\nAccept: application/json\r\nConnection: close\r\n",
				'content' => $body === null ? '' : json_encode((object)$body, JSON_THROW_ON_ERROR),
				'ignore_errors' => true,
				'follow_location' => 0,
				'timeout' => $timeout,
			]]);
			$stream = @fopen($endpoint . $path, 'rb', false, $context);
			if ($stream === false):
				throw new \RuntimeException("WebDriver HTTP $method $path failed to connect or receive headers.");
			endif;
			try {
				$metadata = stream_get_meta_data($stream);
				$status = 0;
				$length = null;
				foreach ($metadata['wrapper_data'] as $header):
					if (preg_match('/^HTTP\/\S+ (\d{3})/', $header, $match)):
						$status = (int)$match[1];
						$length = null;
					elseif (preg_match('/^Content-Length:\s*(\d+)\s*$/i', $header, $match)):
						$length = (int)$match[1];
					endif;
				endforeach;

				// ChromeDriver may leave the socket open after sending the complete response.
				// Stop at Content-Length; otherwise let PHP handle chunked or EOF-delimited bodies.
				$response = stream_get_contents($stream, $length ?? -1);
				$metadata = stream_get_meta_data($stream);
			} finally {
				fclose($stream);
			}
			if ($response === false || $metadata['timed_out'] || ($length !== null && strlen($response) !== $length)):
				throw new \RuntimeException("WebDriver HTTP $method $path failed, timed out, or returned an incomplete body.");
			endif;
			try {
				$result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
			} catch (\JsonException $error) {
				throw new \RuntimeException("WebDriver HTTP $status returned invalid JSON.", 0, $error);
			}
			if (!is_array($result) || !array_key_exists('value', $result)):
				throw new \RuntimeException("WebDriver HTTP $status response is missing value.");
			endif;
			$value = $result['value'];
			if ($status < 200 || $status >= 300):
				throw new \RuntimeException("WebDriver HTTP $status " . ($value['error'] ?? 'request failed') . ': ' . ($value['message'] ?? 'No error message.'));
			endif;
			return $value;
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
