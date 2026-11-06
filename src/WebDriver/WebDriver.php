<?php
	namespace ThriveData\ThrivePHP\WebDriver;

	/**
	 * Local dependency inventory and an owned WebDriver service for test scaffolding.
	 * bootstrap() only inspects installed software. Construction starts a driver;
	 * browser() creates one session at a time, and stop() cleans up both resources.
	 * Application bootstrap is responsible for installing browsers and drivers.
	 *
	 * ```php
	 * $inventory = WebDriver::bootstrap(); // JSON-compatible stdClass, no provisioning.
	 * $driver = new WebDriver(browser: 'firefox');
	 * try {
	 *     $browser = $driver->browser(headless: true);
	 *     $browser->navigate('https://example.com');
	 * } finally {
	 *     $driver->stop();
	 * }
	 * ```
	 */
	class WebDriver
	{
		/** Automatic selection stops at the first locally ready browser/driver pair. */
		private const PREFERENCE = ['safaripreview', 'safari', 'chrome', 'chromium', 'firefox'];
		private ?DriverProcess $process = null;
		private ?Browser $session = null;
		/** Selected installation, independent of any earlier bootstrap report. */
		private \stdClass $installation;
		private string $endpoint;

		/**
		 * Start a local service for chrome, chromium, firefox, safari, or safaripreview.
		 * Null selects Safari Technology Preview, Safari, Chrome, Chromium, then Firefox;
		 * a failure after selection never changes
		 * browser. Discovery uses local executables, not bootstrap reports or downloads.
		 *
		 * $port: null prefers Safari/Preview 4445, Chrome/Chromium 9515, or Firefox 4444; an
		 * occupied preferred port logs a warning and selects a free port. An explicitly
		 * requested port (1–65535) is mandatory. Services bind to loopback only.
		 * $startupTimeout: positive seconds to start the service after discovery.
		 * $shutdownTimeout: positive seconds to terminate its process; HTTP session
		 * cleanup uses that session's own command timeout. No browser session starts here.
		 */
		public function __construct(?string $browser = null, ?int $port = null, float $startupTimeout = 10, private float $shutdownTimeout = 3)
		{
			foreach ([$startupTimeout, $shutdownTimeout] as $timeout):
				if (!is_finite($timeout) || $timeout <= 0):
					throw new \InvalidArgumentException('Driver startup and shutdown timeouts must be positive and finite.');
				endif;
			endforeach;
			if ($port !== null && ($port < 1 || $port > 65535)):
				throw new \InvalidArgumentException('Driver port must be between 1 and 65535.');
			endif;
			if ($browser !== null && !in_array($browser, self::PREFERENCE, true)):
				throw new \InvalidArgumentException('Unknown browser: ' . $browser);
			endif;
			$discovery = static::discovery();
			$failures = [];
			foreach ($browser === null ? self::PREFERENCE : [$browser] as $name):
				$installation = $discovery->browser($name);
				if ($installation->ready === true && $installation->driver->ready === true):
					$this->installation = $installation;
					break;
				endif;
				$failures[] = $name . ': ' . ($installation->ready === true ? $installation->driver->message : $installation->message);
			endforeach;
			if (!isset($this->installation)):
				throw new \RuntimeException('No ready browser/driver pair. ' . implode(' ', $failures));
			endif;

			// A weak reference avoids keeping every stopped runtime alive until PHP exits.
			$reference = \WeakReference::create($this);
			register_shutdown_function(static function () use ($reference) {
				$reference->get()?->shutdown();
			});
			$preferred = $port ?? match ($this->installation->name) {
				'safari', 'safaripreview' => 4445, 'firefox' => 4444, default => 9515,
			};
			$deadline = hrtime(true) / 1e9 + $startupTimeout;
			for ($attempt = 0; $attempt < 3; $attempt++):
				[$selected, $reservation] = $this->reserve($attempt === 0 ? $preferred : 0, $port !== null);
				$this->endpoint = 'http://127.0.0.1:' . $selected;
				// The executable must bind its own socket; retry implicit-port bind races below.
				fclose($reservation);
				try {
					$this->process = new DriverProcess($this->command($selected));
					$this->awaitReady($deadline);
					return;
				} catch (\Throwable $error) {
					$output = trim($this->process?->output() ?? '');
					$collision = preg_match('/address.*in use|EADDRINUSE|bind.*(?:failed|error)|port.*(?:in use|already|not available)/i', $output);
					try {
						$this->stop();
					} catch (\Throwable $cleanup) {
						throw new \RuntimeException($error->getMessage() . ' Cleanup also failed: ' . $cleanup->getMessage(), 0, $error);
					}
					if ($port === null && $collision && $attempt < 2 && hrtime(true) / 1e9 < $deadline):
						error_log('WebDriver warning: port ' . $selected . ' became unavailable during startup; selecting another port.');
						continue;
					endif;
					throw new \RuntimeException('Could not start ' . $this->installation->name . ' driver: ' . $error->getMessage()
						. ($output === '' ? '' : ' Driver output: ' . $output), 0, $error);
				}
			endfor;
		}

		/**
		 * Inspect macOS, Debian Linux, FreeBSD, or Windows without installing software,
		 * fetching release metadata, starting services, or creating browser sessions.
		 * Search conventional locations and PATH, with one entry per browser type.
		 *
		 * Returns {ready: bool, platform: {os, architecture, distribution}, browsers:
		 * {chrome, chromium, firefox, safari, safaripreview}}. Each browser has name, ready, path,
		 * version, reason, message, and a nested driver with the same component fields.
		 * Paths/versions/reasons/messages are null when unavailable or inapplicable.
		 *
		 * Component ready is true, 'unprovisioned' (missing), 'unsupported' (platform),
		 * 'notready' (known execution failure), or 'unknown' (discovery/probe failure).
		 * Overall ready requires at least one pair whose two states are literally true.
		 * A successful version/metadata check does not certify compatibility, Safari
		 * authorization, display availability, or session creation. Discovery failures
		 * are data; programming errors propagate. Serialize with json_encode().
		 */
		public static function bootstrap(): \stdClass
		{
			return static::discovery()->inventory();
		}

		/** The selected browser type, including 'chromium' rather than its protocol alias 'chrome'. */
		public function name(): string
		{
			return $this->installation->name;
		}

		/** Loopback service URL. Sessions made directly with this endpoint are caller-owned. */
		public function endpoint(): string
		{
			return $this->endpoint;
		}

		/**
		 * Create the sole active owned session. After quit() or closing its last window,
		 * another session can be created. $headless adds the browser's headless flag;
		 * Safari and Preview reject it. Null selection prefers Preview, so headless tests
		 * should explicitly select Chrome, Chromium, or Firefox in the constructor.
		 *
		 * $timeout is positive seconds for Browser's command I/O, not service startup.
		 * $bidi requests BiDi support. $capabilities accepts browser-specific options;
		 * arguments/preferences are retained, but the detected binary cannot be replaced.
		 * Failure during session creation stops the owned service: a lost response may
		 * have left a remote session that this client cannot identify for deletion.
		 * Assertions report automatically to the active Test and stop on failure by default.
		 * Comparison methods accept $verify to record a failed result and continue instead.
		 * Outside Test::run(), failures throw. Assertions retry only with $wait.
		 */
		public function browser(bool $headless = false, bool $bidi = false, float $timeout = 10, array $capabilities = []): Browser
		{
			if ($this->process === null || !$this->process->running()):
				throw new \LogicException('WebDriver service is not running. Construct a new WebDriver to restart it.');
			endif;
			if ($this->session?->active()):
				throw new \LogicException('This WebDriver already owns an active browser session; quit it before creating another.');
			endif;
			if (!is_finite($timeout) || $timeout <= 0):
				throw new \InvalidArgumentException('Browser timeout must be positive and finite.');
			endif;
			$name = $this->installation->name;
			if (in_array($name, ['safari', 'safaripreview'], true)):
				if ($headless):
					throw new \InvalidArgumentException('Safari does not support headless browser sessions.');
				endif;
			else:
				$key = $name === 'firefox' ? 'moz:firefoxOptions' : 'goog:chromeOptions';
				$options = $capabilities[$key] ?? [];
				if (!is_array($options)):
					throw new \InvalidArgumentException($key . ' must be an associative array.');
				endif;
				if (isset($options['binary']) && $options['binary'] !== $this->installation->path):
					throw new \InvalidArgumentException('Capabilities cannot replace the selected browser executable.');
				endif;
				$options['binary'] = $this->installation->path;
				if ($headless):
					if (isset($options['args']) && !is_array($options['args'])):
						throw new \InvalidArgumentException($key . '.args must be a list of browser arguments.');
					endif;
					$options['args'] = [...array_values($options['args'] ?? []), $name === 'firefox' ? '-headless' : '--headless'];
				endif;
				$capabilities[$key] = $options;
			endif;
			$protocolName = match ($name) {
				'chromium' => 'chrome',
				'safaripreview' => 'Safari Technology Preview',
				default => $name,
			};
			try {
				return $this->session = new Browser($this->endpoint, $protocolName, $bidi, $timeout, $capabilities);
			} catch (\Throwable $error) {
				try {
					$this->stop();
				} catch (\Throwable $cleanup) {
					throw new \RuntimeException($error->getMessage() . ' Driver cleanup also failed: ' . $cleanup->getMessage(), 0, $error);
				}
				throw $error;
			}
		}

		/**
		 * Quit the owned session, then terminate and reap the owned driver even if HTTP
		 * cleanup fails. Explicit calls throw after attempting cleanup; shutdown logs
		 * errors instead. Failed termination keeps the process handle for retry.
		 * Repeated calls after successful termination are harmless. Returns this runtime.
		 */
		public function stop(): self
		{
			$errors = [];
			try {
				if ($this->session?->active() && $this->process?->running()):
					$this->session->quit();
				endif;
			} catch (\Throwable $error) {
				$errors[] = $error;
			}
			try {
				$this->process?->stop($this->shutdownTimeout);
				$this->process = null;
				$this->session?->invalidateSession();
				$this->session = null;
			} catch (\Throwable $error) {
				$errors[] = $error;
			}
			if ($errors):
				throw new \RuntimeException('WebDriver cleanup failed: ' . implode(' ', array_map(fn($error) => $error->getMessage(), $errors)), 0, $errors[0]);
			endif;
			return $this;
		}

		/** @internal Local discovery is shared by inventory and runtime selection, without cached reports. */
		protected static function discovery(): Discovery
		{
			return Discovery::local();
		}

		/** Build the driver's CLI. Chrome and Safari bind locally by default; Gecko needs --host. */
		protected function command(int $port): array
		{
			$binary = $this->installation->driver->path;
			return match ($this->installation->name) {
				// Chrome's --allowed-ips switch enables remote binding even for a loopback allowlist.
				'chrome', 'chromium' => [$binary, '--port=' . $port],
				'firefox' => [$binary, '--host', '127.0.0.1', '--port', (string)$port],
				'safari', 'safaripreview' => [$binary, '--port', (string)$port],
			};
		}

		/** Reserve briefly to detect conflicts; the child must bind again once the reservation closes. */
		private function reserve(int $port, bool $mandatory): array
		{
			set_error_handler(static fn() => true, E_WARNING);
			try {
				$socket = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $message);
			} finally {
				restore_error_handler();
			}
			if ($socket === false):
				if ($mandatory || $port === 0):
					throw new \RuntimeException('Cannot bind WebDriver port ' . $port . ': ' . $message);
				endif;
				error_log('WebDriver warning: preferred port ' . $port . ' is occupied or unavailable; selecting another port.');
				return $this->reserve(0, true);
			endif;
			$address = stream_socket_get_name($socket, false);
			return [(int)substr($address, strrpos($address, ':') + 1), $socket];
		}

		/** Wait for this child to stay alive and report W3C readiness, retaining the last HTTP failure. */
		private function awaitReady(float $deadline): void
		{
			$lastError = null;
			while (($remaining = $deadline - hrtime(true) / 1e9) > 0):
				if (!$this->process->running()):
					throw new \RuntimeException('Driver exited before becoming ready.', 0, $lastError);
				endif;
				try {
					// Connection refusals are normal while starting, including with an application error handler.
					set_error_handler(static fn() => true, E_WARNING);
					try {
						$status = Browser::status($this->endpoint, min(0.25, $remaining));
					} finally {
						restore_error_handler();
					}
					if (($status['ready'] ?? false) === true && $this->process->running()):
						return;
					endif;
				} catch (\RuntimeException $error) {
					$lastError = $error;
				}
				$remaining = $deadline - hrtime(true) / 1e9;
				if ($remaining > 0):
					usleep((int)(min(0.05, $remaining) * 1e6));
				endif;
			endwhile;
			throw new \RuntimeException('Timed out waiting for driver readiness.' . ($lastError ? ' ' . $lastError->getMessage() : ''), 0, $lastError);
		}

		/** Used by both destruction and PHP shutdown; logging must not replace an application's error. */
		private function shutdown(): void
		{
			try {
				$this->stop();
			} catch (\Throwable $error) {
				error_log('WebDriver warning: ' . $error->getMessage());
			}
		}

		public function __destruct()
		{
			$this->shutdown();
		}
	}
