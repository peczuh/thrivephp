<?php
	namespace ThriveData\ThrivePHP\WebDriver;

	/**
	 * @internal Local browser/driver inventory. No provisioning or release-metadata
	 * requests occur here. Version discovery establishes identity, not compatibility
	 * or the ability to create an automation session.
	 */
	class Discovery
	{
		/** Platform/environment snapshots keep candidate selection consistent within one inventory. */
		public function __construct(private array $platform, private array $environment)
		{
			$this->environment = array_change_key_case($environment, CASE_UPPER);
		}

		/** Detect the host; Linux support is specifically Debian, not an inferred binary ABI. */
		public static function local(): self
		{
			$environment = getenv();
			$os = match (PHP_OS_FAMILY) {
				'Darwin' => 'macos',
				'Windows' => 'windows',
				'Linux' => 'linux',
				'BSD' => php_uname('s') === 'FreeBSD' ? 'freebsd' : strtolower(php_uname('s')),
				default => strtolower(PHP_OS_FAMILY),
			};
			$architecture = strtolower($environment['PROCESSOR_ARCHITEW6432'] ?? $environment['PROCESSOR_ARCHITECTURE'] ?? php_uname('m'));
			$architecture = match ($architecture) {
				'amd64', 'x86_64' => 'x64',
				'aarch64', 'arm64' => 'arm64',
				'i386', 'i686', 'x86' => 'x86',
				default => $architecture,
			};
			$distribution = null;
			if ($os === 'linux'):
				// Missing/unreadable OS metadata is an inventory state, even with an application's error handler.
				set_error_handler(static fn() => true, E_WARNING);
				try {
					$release = file_get_contents('/etc/os-release');
				} finally {
					restore_error_handler();
				}
				if ($release !== false && preg_match('/^ID=["\x27]?([a-zA-Z0-9_-]+)/m', $release, $match)):
					$distribution = strtolower($match[1]);
				endif;
			endif;
			return new self(['os' => $os, 'architecture' => $architecture, 'distribution' => $distribution], $environment);
		}

		/** Always include all five browser types; only literal true in both components counts. */
		public function inventory(): \stdClass
		{
			$result = (object)['ready' => false, 'platform' => (object)$this->platform, 'browsers' => new \stdClass()];
			foreach (['chrome', 'chromium', 'firefox', 'safari', 'safaripreview'] as $name):
				$browser = $this->browser($name);
				$result->browsers->$name = $browser;
				$result->ready = $result->ready || ($browser->ready === true && $browser->driver->ready === true);
			endforeach;
			return $result;
		}

		/** Inspect one target independently, so runtime selection need not build the full inventory. */
		public function browser(string $name): \stdClass
		{
			$driver = match ($name) {
				'chrome', 'chromium' => 'chromedriver',
				'firefox' => 'geckodriver',
				'safari', 'safaripreview' => 'safaridriver',
				default => throw new \InvalidArgumentException('Unknown browser: ' . $name),
			};
			$os = $this->platform['os'];
			$state = true;
			$reason = null;
			$message = null;
			if ($os === 'linux' && $this->platform['distribution'] === null):
				$state = 'unknown';
				$reason = 'platform_unknown';
				$message = 'Could not identify the Linux distribution from /etc/os-release.';
			elseif (!in_array($os, ['macos', 'freebsd', 'linux', 'windows'], true)
				|| ($os === 'linux' && $this->platform['distribution'] !== 'debian')
				|| (in_array($name, ['safari', 'safaripreview'], true) && $os !== 'macos') || ($name === 'chrome' && $os === 'freebsd')):
				$state = 'unsupported';
				$reason = 'platform_unsupported';
				$message = "$name is not supported by this inventory on $os" . ($os === 'linux' ? '/' . $this->platform['distribution'] : '') . '.';
			endif;
			if ($state !== true):
				$result = $this->entry($name, $state, reason: $reason, message: $message);
				$result->driver = $this->entry($driver, $state, reason: $reason, message: $message);
				return $result;
			endif;
			$result = $this->component($name, false);
			// Preview must use the driver shipped in the selected app, including user installations.
			$driverPaths = $name === 'safaripreview' ? ($result->path === null ? [] : [dirname($result->path) . '/safaridriver']) : null;
			$result->driver = $this->component($driver, true, $driverPaths);
			return $result;
		}

		/** Stable JSON-compatible component shape, including fields unavailable during failure. */
		private function entry(string $name, bool|string $ready, ?string $path = null, ?string $version = null, ?string $reason = null, ?string $message = null): \stdClass
		{
			return (object)compact('name', 'ready', 'path', 'version', 'reason', 'message');
		}

		/** Select the first installed candidate, reporting broken installations; optional paths restrict pairing. */
		private function component(string $name, bool $driver, ?array $paths = null): \stdClass
		{
			foreach ($paths ?? $this->candidates($name) as $candidate):
				try {
					$file = $this->inspectPath($candidate);
				} catch (\RuntimeException $error) {
					return $this->entry($name, 'unknown', reason: 'discovery_failed', message: $error->getMessage());
				}
				if ($file === null):
					continue;
				endif;
				[$path, $executable] = $file;
				if (!$executable):
					return $this->entry($name, 'notready', $path, reason: 'not_executable', message: 'Execution permission is missing: ' . $path);
				endif;
				try {
					$probe = $this->probe($name, $path, $driver);
				} catch (\RuntimeException $error) {
					return $this->entry($name, 'unknown', $path, reason: 'probe_failed', message: $error->getMessage());
				}
				if ($probe['timedOut']):
					return $this->entry($name, 'unknown', $path, reason: 'probe_timeout', message: 'Version discovery timed out: ' . $path);
				endif;
				if ($probe['exitCode'] !== 0):
					return $this->entry($name, $probe['metadata'] ? 'unknown' : 'notready', $path,
						reason: 'version_failed', message: 'Version check failed: ' . trim($probe['output']));
				endif;
				if (!preg_match('/\b\d+(?:\.\d+)+(?:[a-zA-Z][a-zA-Z0-9]*)?\b/', $probe['output'], $match)):
					return $this->entry($name, 'unknown', $path, reason: 'version_unknown', message: 'Version response was not recognized: ' . trim($probe['output']));
				endif;
				return $this->entry($name, true, $path, $match[0]);
			endforeach;
			return $this->entry($name, 'unprovisioned', reason: 'executable_missing',
				message: $paths === null ? "$name was not found in conventional locations or PATH." : "$name was not found in the selected Safari Technology Preview app.");
		}

		/** Distinguish absent files from inaccessible paths, without emitting PHP filesystem warnings. */
		private function inspectPath(string $candidate): ?array
		{
			set_error_handler(static function ($severity, $message) {
				throw new \RuntimeException($message);
			}, E_WARNING);
			try {
				// Application provisioning may have changed a symlink since the last inventory.
				clearstatcache(true, $candidate);
				if (!is_file($candidate)):
					$directory = dirname($candidate);
					while (!is_dir($directory) && dirname($directory) !== $directory):
						$directory = dirname($directory);
					endwhile;
					if (($this->platform['os'] !== 'windows' && !is_executable($directory)) || !is_readable($directory)):
						throw new \RuntimeException('Cannot inspect directory: ' . $directory);
					endif;
					return null;
				endif;
				$path = realpath($candidate);
				if ($path === false):
					throw new \RuntimeException('Cannot resolve executable path: ' . $candidate);
				endif;
				return [$path, $this->platform['os'] === 'windows' || is_executable($path)];
			} finally {
				restore_error_handler();
			}
		}

		/** Use bundle/file metadata where executing a browser could open a GUI. */
		protected function probe(string $name, string $path, bool $driver): array
		{
			$metadata = false;
			if (!$driver && $this->platform['os'] === 'windows'):
				$metadata = true;
				$powershell = rtrim($this->environment['SYSTEMROOT'] ?? 'C:\\Windows', '\\/') . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
				$literal = "'" . str_replace("'", "''", $path) . "'";
				$command = [$powershell, '-NoLogo', '-NoProfile', '-NonInteractive', '-Command',
					"\$ErrorActionPreference = 'Stop'; [System.Diagnostics.FileVersionInfo]::GetVersionInfo($literal).ProductVersion"];
			elseif (!$driver && $this->platform['os'] === 'macos' && str_contains($path, '.app/Contents/MacOS/')):
				$metadata = true;
				$command = ['/usr/libexec/PlistBuddy', '-c', 'Print :CFBundleShortVersionString', dirname($path, 2) . '/Info.plist'];
			else:
				$command = [$path, '--version'];
			endif;
			return DriverProcess::run($command) + ['metadata' => $metadata];
		}

		/** Conventional install locations first, followed by explicit PATH entries; no disk traversal. */
		protected function candidates(string $name): array
		{
			$os = $this->platform['os'];
			$paths = [];
			$names = [];
			if ($os === 'macos'):
				$bundle = [
					'chrome' => 'Google Chrome.app/Contents/MacOS/Google Chrome',
					'chromium' => 'Chromium.app/Contents/MacOS/Chromium',
					'firefox' => 'Firefox.app/Contents/MacOS/firefox',
					'safari' => 'Safari.app/Contents/MacOS/Safari',
					'safaripreview' => 'Safari Technology Preview.app/Contents/MacOS/Safari Technology Preview',
				][$name] ?? null;
				if ($bundle !== null):
					$paths[] = '/Applications/' . $bundle;
					if (!empty($this->environment['HOME'])):
						$paths[] = $this->environment['HOME'] . '/Applications/' . $bundle;
					endif;
					if ($name === 'safaripreview'):
						return $paths;
					endif;
				elseif ($name === 'safaridriver'):
					return ['/usr/bin/safaridriver'];
				else:
					$paths = ['/opt/homebrew/bin/' . $name, '/usr/local/bin/' . $name];
				endif;
				$names = [$name === 'chrome' ? 'google-chrome' : $name];
			elseif ($os === 'windows'):
				$suffix = [
					'chrome' => '\\Google\\Chrome\\Application\\chrome.exe',
					'chromium' => '\\Chromium\\Application\\chrome.exe',
					'firefox' => '\\Mozilla Firefox\\firefox.exe',
				][$name] ?? null;
				if ($suffix !== null):
					foreach (['PROGRAMW6432', 'PROGRAMFILES', 'PROGRAMFILES(X86)', 'LOCALAPPDATA'] as $root):
						if (!empty($this->environment[$root])):
							$paths[] = rtrim($this->environment[$root], '\\/') . $suffix;
						endif;
					endforeach;
				endif;
				$names = [$name === 'chrome' ? 'chrome.exe' : $name . '.exe'];
			else:
				$names = match ($name) {
					'chrome' => ['google-chrome', 'google-chrome-stable'],
					'chromium' => $os === 'freebsd' ? ['chrome', 'chromium'] : ['chromium', 'chromium-browser'],
					'firefox' => ['firefox', 'firefox-esr'],
					default => [$name],
				};
				foreach ($os === 'freebsd' ? ['/usr/local/bin', '/usr/bin'] : ['/usr/bin', '/usr/local/bin'] as $directory):
					foreach ($names as $executable):
						$paths[] = $directory . '/' . $executable;
					endforeach;
				endforeach;
				if ($os === 'linux'):
					$extra = ['chrome' => '/opt/google/chrome/google-chrome', 'chromium' => '/usr/lib/chromium/chromium', 'firefox' => '/usr/lib/firefox-esr/firefox-esr'];
					if (isset($extra[$name])):
						$paths[] = $extra[$name];
					endif;
				endif;
			endif;
			foreach (explode($os === 'windows' ? ';' : ':', $this->environment['PATH'] ?? '') as $directory):
				if ($directory === ''):
					continue;
				endif;
				foreach ($names as $executable):
					$paths[] = rtrim($directory, '\\/') . ($os === 'windows' ? '\\' : '/') . $executable;
				endforeach;
			endforeach;
			return array_values(array_unique($paths));
		}
	}
