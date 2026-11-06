<?php

// Run with: php test/webdriver-runtime.php. Uses PHP child processes, never real browsers.
require dirname(__DIR__) . '/src/WebSocket.php';
require __DIR__ . '/fixtures/test-runner.php';
spl_autoload_register(function ($class) {
	$prefix = 'ThriveData\\ThrivePHP\\WebDriver\\';
	if (str_starts_with($class, $prefix)) require dirname(__DIR__) . '/src/WebDriver/' . substr($class, strlen($prefix)) . '.php';
});

use ThriveData\ThrivePHP\WebDriver\Browser;
use ThriveData\ThrivePHP\WebDriver\Discovery;
use ThriveData\ThrivePHP\WebDriver\DriverProcess;
use ThriveData\ThrivePHP\WebDriver\WebDriver;
use ThriveData\ThrivePHP\Test;
use ThriveData\ThrivePHP\Test\SuiteBrowser;
use ThriveData\ThrivePHP\Test\SuiteConfig;
use ThriveData\ThrivePHP\Test\SuiteBefore;
use ThriveData\ThrivePHP\Test\SuiteAfter;
use ThriveData\ThrivePHP\Test\CaseName;

function check(bool $condition, string $message): void
{
	if (!$condition) throw new RuntimeException($message);
}

function raises(callable $action, string $message, string $class = RuntimeException::class): void
{
	try {
		$action();
	} catch (Throwable $error) {
		check($error instanceof $class && str_contains($error->getMessage(), $message), get_class($error) . ': ' . $error->getMessage());
		return;
	}
	throw new RuntimeException('Expected error: ' . $message);
}

/** Limit filesystem candidates to fixture paths while preserving real inventory aggregation. */
class FixtureDiscovery extends Discovery
{
	public array $paths = [];
	public array $results = [];
	public array $visited = [];

	public function __construct(array $platform = ['os' => 'macos', 'architecture' => 'arm64', 'distribution' => null])
	{
		parent::__construct($platform, []);
	}

	public function browser(string $name): stdClass
	{
		$this->visited[] = $name;
		return parent::browser($name);
	}

	protected function candidates(string $name): array
	{
		return $this->paths[$name] ?? [];
	}

	protected function probe(string $name, string $path, bool $driver): array
	{
		if (isset($this->results[$name])):
			if ($this->results[$name] instanceof Throwable) throw $this->results[$name];
			return $this->results[$name];
		endif;
		return parent::probe($name, $path, $driver);
	}
}

/** Use installed PHP as an executable candidate, and a real HTTP child as the driver service. */
class FixtureDriver extends WebDriver
{
	public static FixtureDiscovery $source;

	protected static function discovery(): Discovery
	{
		return self::$source;
	}

	protected function command(int $port): array
	{
		return [PHP_BINARY, __DIR__ . '/fixtures/webdriver-driver.php', ...array_slice(parent::command($port), 1)];
	}
}

/** Keep the production suite hooks while substituting the local protocol fixture service. */
abstract class FixtureBrowserSuite extends SuiteBrowser
{
	public ?DriverProcess $process = null;
	public ?SuiteConfig $settings = null;

	protected function createDriver(SuiteConfig $config): WebDriver
	{
		$this->settings = $config;
		$driver = new FixtureDriver(browser: $config->browser, startupTimeout: 1);
		$this->process = ownedProcess($driver);
		return $driver;
	}
}

class CandidateDiscovery extends Discovery
{
	public function paths(string $name): array
	{
		return $this->candidates($name);
	}
}

function installation(array $browsers): FixtureDiscovery
{
	global $temporary;
	$discovery = new FixtureDiscovery();
	foreach ($browsers as $browser):
		$driver = ['safari' => 'safaridriver', 'safaripreview' => 'safaridriver', 'firefox' => 'geckodriver', 'chrome' => 'chromedriver', 'chromium' => 'chromedriver'][$browser];
		$discovery->paths[$browser] = [PHP_BINARY];
		$discovery->paths[$driver] = [PHP_BINARY];
		if ($browser === 'safaripreview'):
			// Real sibling files exercise paired discovery; version probes and service execution use fixtures.
			foreach (['Safari Technology Preview', 'safaridriver'] as $executable):
				file_put_contents($temporary . '/' . $executable, 'fixture');
				chmod($temporary . '/' . $executable, 0700);
			endforeach;
			$discovery->paths[$browser] = [$temporary . '/Safari Technology Preview'];
		endif;
		foreach ([$browser, $driver] as $name):
			$discovery->results[$name] = ['exitCode' => 0, 'output' => "$name 123.4.5", 'timedOut' => false, 'metadata' => false];
		endforeach;
	endforeach;
	return $discovery;
}

function events(string $path): array
{
	return is_file($path) ? array_map(fn($line) => json_decode($line, true), file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
}

function ownedProcess(WebDriver $driver): DriverProcess
{
	$property = new ReflectionProperty(WebDriver::class, 'process');
	if (PHP_VERSION_ID < 80100) $property->setAccessible(true);
	return $property->getValue($driver);
}

/** Simulate an OS termination failure once, while retaining a real running fixture process. */
class RetryProcess extends DriverProcess
{
	private bool $fail = true;
	public function __construct(private DriverProcess $inner) {}
	public function running(): bool { return $this->inner->running(); }
	public function stop(float $timeout = 3): void
	{
		if ($this->fail):
			$this->fail = false;
			throw new RuntimeException('fixture termination failed');
		endif;
		$this->inner->stop($timeout);
	}
}

// A subprocess verifies PHP shutdown, independently of explicit stop() or local destruction.
if (($argv[1] ?? '') === '--shutdown-child'):
	FixtureDriver::$source = installation(['firefox']);
	$driver = new FixtureDriver(browser: 'firefox');
	$driver->browser();
	echo $driver->endpoint() . "\n";
	exit(0);
endif;

$temporary = sys_get_temp_dir() . '/webdriver-runtime-' . bin2hex(random_bytes(8));
check(mkdir($temporary, 0700), 'Could not create fixture directory.');
$log = $temporary . '/requests.jsonl';
$warnings = $temporary . '/warnings.log';
$oldErrorLog = ini_get('error_log');
$oldMode = getenv('WEBDRIVER_FIXTURE_MODE');
$oldLog = getenv('WEBDRIVER_FIXTURE_LOG');
ini_set('error_log', $warnings);
putenv('WEBDRIVER_FIXTURE_LOG=' . $log);

function scenario(string $name, callable $action): void
{
	global $log;
	putenv('WEBDRIVER_FIXTURE_MODE=normal');
	file_put_contents($log, '');
	FixtureDriver::$source = installation(['firefox']);
	$action();
	echo "PASS: $name\n";
}

try {
	scenario('inventory includes all targets and requires a complete pair', function () {
		FixtureDriver::$source = new FixtureDiscovery();
		$inventory = FixtureDriver::bootstrap();
		check($inventory instanceof stdClass && $inventory->ready === false, 'Empty inventory is ready.');
		check(array_keys((array)$inventory->browsers) === ['chrome', 'chromium', 'firefox', 'safari', 'safaripreview'], 'Inventory targets changed.');
		FixtureDriver::$source->paths['firefox'] = [PHP_BINARY];
		$inventory = FixtureDriver::bootstrap();
		check($inventory->browsers->firefox->ready === true && $inventory->ready === false, 'A missing driver counted as ready.');
		FixtureDriver::$source->paths['geckodriver'] = [PHP_BINARY];
		$inventory = FixtureDriver::bootstrap();
		check($inventory->ready === true && $inventory->browsers->firefox->driver->ready === true, 'One ready pair did not suffice.');
		check(json_decode(json_encode($inventory, JSON_THROW_ON_ERROR))->ready === true, 'Inventory did not serialize as JSON.');
	});

	scenario('inventory distinguishes unsupported, unknown and failed probes', function () {
		$freebsd = new FixtureDiscovery(['os' => 'freebsd', 'architecture' => 'x64', 'distribution' => null]);
		check($freebsd->browser('chrome')->ready === 'unsupported', 'Google Chrome was supported on FreeBSD.');
		check($freebsd->browser('chromium')->ready === 'unprovisioned', 'FreeBSD Chromium should be discoverable.');
		check($freebsd->browser('safari')->driver->ready === 'unsupported', 'Safari driver was supported on FreeBSD.');
		foreach (['freebsd', 'linux', 'windows'] as $os):
			$discovery = new FixtureDiscovery(['os' => $os, 'architecture' => 'x64', 'distribution' => $os === 'linux' ? 'debian' : null]);
			$preview = $discovery->browser('safaripreview');
			check($preview->ready === 'unsupported' && $preview->driver->ready === 'unsupported', 'Preview was supported outside macOS.');
		endforeach;
		$linux = new FixtureDiscovery(['os' => 'linux', 'architecture' => 'x64', 'distribution' => null]);
		check($linux->browser('firefox')->ready === 'unknown', 'Unknown distribution was treated as missing software.');
		$discovery = installation(['firefox']);
		foreach ([
			['exitCode' => 1, 'output' => 'missing library', 'timedOut' => false, 'metadata' => false, 'state' => 'notready'],
			['exitCode' => 1, 'output' => 'metadata inaccessible', 'timedOut' => false, 'metadata' => true, 'state' => 'unknown'],
			['exitCode' => 0, 'output' => 'unexpected output', 'timedOut' => false, 'metadata' => false, 'state' => 'unknown'],
			['exitCode' => null, 'output' => '', 'timedOut' => true, 'metadata' => false, 'state' => 'unknown'],
		] as $probe):
			$discovery->results['geckodriver'] = $probe;
			$inventory = $discovery->inventory();
			check($inventory->ready === false && $inventory->browsers->firefox->driver->ready === $probe['state'], 'Probe state was misclassified.');
		endforeach;
		$discovery->results['geckodriver'] = new RuntimeException('process inspection failed');
		check($discovery->browser('firefox')->driver->reason === 'probe_failed', 'Operational failure escaped discovery.');
		$discovery->results['geckodriver'] = new LogicException('implementation bug');
		raises(fn() => $discovery->inventory(), 'implementation bug', LogicException::class);
	});

	scenario('platform candidates include native package names and case-insensitive Windows PATH', function () {
		$bsd = new CandidateDiscovery(['os' => 'freebsd'], ['PATH' => '/custom/bin']);
		check(in_array('/usr/local/bin/chrome', $bsd->paths('chromium'), true), 'Missing native FreeBSD Chromium path.');
		$linux = new CandidateDiscovery(['os' => 'linux'], []);
		check(in_array('/usr/bin/firefox-esr', $linux->paths('firefox'), true), 'Missing Debian ESR path.');
		$windows = new CandidateDiscovery(['os' => 'windows'], ['ProgramFiles' => 'C:\\Program Files', 'Path' => 'D:\\Drivers;E:\\Tools']);
		check(in_array('C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', $windows->paths('chrome'), true), 'Missing Windows Chrome path.');
		check(in_array('D:\\Drivers\\geckodriver.exe', $windows->paths('geckodriver'), true), 'Windows Path spelling was ignored.');
		$mac = new CandidateDiscovery(['os' => 'macos'], ['HOME' => '/Users/test', 'PATH' => '/custom/bin']);
		check($mac->paths('safaridriver') === ['/usr/bin/safaridriver'], 'Safari was paired with an unrelated driver.');
		check($mac->paths('safaripreview') === [
			'/Applications/Safari Technology Preview.app/Contents/MacOS/Safari Technology Preview',
			'/Users/test/Applications/Safari Technology Preview.app/Contents/MacOS/Safari Technology Preview',
		], 'Preview application candidates differ.');
		check(in_array('/Users/test/Applications/Firefox.app/Contents/MacOS/firefox', $mac->paths('firefox'), true), 'User Applications path missing.');
	});

	if (PHP_OS_FAMILY !== 'Windows'):
		scenario('filesystem inspection distinguishes missing execution permission and inaccessible directories', function () use ($temporary) {
			$binary = $temporary . '/not-executable';
			file_put_contents($binary, 'fixture');
			chmod($binary, 0600);
			$discovery = new FixtureDiscovery();
			$discovery->paths['firefox'] = [$binary];
			check($discovery->browser('firefox')->ready === 'notready', 'Non-executable file was not distinguished from a missing file.');
			$directory = $temporary . '/restricted';
			mkdir($directory, 0700);
			chmod($directory, 0000);
			try {
				clearstatcache();
				if (!is_executable($directory)):
					$discovery->paths['firefox'] = [$directory . '/nested/firefox'];
					check($discovery->browser('firefox')->ready === 'unknown', 'Inaccessible discovery path was treated as unprovisioned.');
				endif;
			} finally {
				chmod($directory, 0700);
				rmdir($directory);
			}
		});
	endif;

	scenario('process probes capture exits and bound timeouts without pipe deadlock', function () {
		$result = DriverProcess::run([PHP_BINARY, '-r', 'fwrite(STDERR, str_repeat("x", 100000)); exit(7);']);
		check($result['exitCode'] === 7 && strlen($result['output']) === 65536 && !$result['timedOut'], 'Exit code/output handling failed.');
		$start = microtime(true);
		$result = DriverProcess::run([PHP_BINARY, '-r', 'usleep(3000000);'], 0.05);
		check($result['timedOut'] && microtime(true) - $start < 2, 'Probe did not stop at its deadline.');
	});

	scenario('Preview uses only its paired driver and requires both components', function () use ($temporary) {
		$discovery = installation(['safaripreview', 'safari']);
		$preview = $discovery->browser('safaripreview');
		check($preview->ready === true && $preview->driver->path === realpath($temporary . '/safaridriver'), 'Preview did not select its sibling driver.');
		unlink($temporary . '/safaridriver');
		$preview = $discovery->browser('safaripreview');
		check($preview->driver->ready === 'unprovisioned' && $preview->driver->path === null, 'Preview substituted a different driver.');
		FixtureDriver::$source = $discovery;
		$driver = new FixtureDriver();
		try {
			check($driver->name() === 'safari', 'Incomplete Preview pair prevented Safari fallback.');
		} finally {
			$driver->stop();
		}
		raises(fn() => new FixtureDriver(browser: 'safaripreview'), 'safaridriver was not found in the selected Safari Technology Preview app');
	});

	scenario('automatic selection uses Preview first and does not create a session at construction', function () use ($log) {
		FixtureDriver::$source = installation(['safaripreview', 'safari', 'chrome', 'chromium', 'firefox']);
		$driver = new FixtureDriver();
		try {
			check($driver->name() === 'safaripreview' && FixtureDriver::$source->visited === ['safaripreview'], 'Preference order or targeted discovery changed.');
			check(count(array_filter(events($log), fn($event) => ($event['path'] ?? '') === '/session')) === 0, 'Constructor created a session.');
			raises(fn() => $driver->browser(headless: true), 'Safari does not support headless', InvalidArgumentException::class);
		} finally {
			$driver->stop();
		}
	});

	scenario('automatic selection continues through Safari, Chrome, Chromium and Firefox only for unready pairs', function () {
		foreach ([['safari', 'chrome', 'chromium', 'firefox'], ['chrome', 'chromium', 'firefox'], ['chromium', 'firefox'], ['firefox']] as $available):
			FixtureDriver::$source = installation($available);
			$driver = new FixtureDriver();
			try {
				check($driver->name() === $available[0], 'Automatic preference order changed.');
			} finally {
				$driver->stop();
			}
		endforeach;
		FixtureDriver::$source = new FixtureDiscovery();
		raises(fn() => new FixtureDriver(), 'No ready browser/driver pair');
		raises(fn() => new FixtureDriver(browser: 'firefox'), 'firefox was not found');
		raises(fn() => new FixtureDriver(browser: 'unknown'), 'Unknown browser', InvalidArgumentException::class);
	});

	scenario('explicit Preview and Safari selection preserve protocol identity and options', function () use ($log) {
		foreach (['safaripreview' => 'Safari Technology Preview', 'safari' => 'safari'] as $name => $protocol):
			file_put_contents($log, '');
			FixtureDriver::$source = installation(['safaripreview', 'safari']);
			$driver = new FixtureDriver(browser: $name);
			try {
				check($driver->name() === $name && FixtureDriver::$source->visited === [$name], 'Explicit selection was substituted.');
				raises(fn() => $driver->browser(headless: true), 'Safari does not support headless', InvalidArgumentException::class);
				$driver->browser(capabilities: ['safari:automaticInspection' => true, 'browserName' => 'chrome']);
			} finally {
				$driver->stop();
			}
			$request = array_values(array_filter(events($log), fn($event) => ($event['path'] ?? '') === '/session'))[0];
			$capabilities = $request['body']['capabilities']['alwaysMatch'];
			check($capabilities['browserName'] === $protocol && $capabilities['safari:automaticInspection'] === true, 'Safari identity or options were lost.');
			check(!isset($capabilities['goog:chromeOptions']), 'Safari received Chrome options.');
		endforeach;
	});

	scenario('owned sessions preserve options, enforce single ownership and allow sequential reuse', function () use ($log) {
		$driver = new FixtureDriver(browser: 'firefox');
		$process = ownedProcess($driver);
		try {
			$first = $driver->browser(headless: true, capabilities: ['moz:firefoxOptions' => ['args' => ['-private'], 'prefs' => ['fixture' => true]]]);
			check($first->active(), 'New session was not active.');
			raises(fn() => $driver->browser(), 'already owns an active', LogicException::class);
			check($first->title() === 'Fixture', 'Owned session could not issue commands.');
			$first->quit();
			check(!$first->active() && $process->running(), 'Quit stopped the service or retained the session.');
			$second = $driver->browser();
			$second->close();
			check(!$second->active(), 'Closing the last window did not end the session.');
			$third = $driver->browser();
		} finally {
			$driver->stop();
		}
		check(!$third->active() && !$process->running() && $driver->stop() === $driver, 'Owned cleanup was not complete/idempotent.');
		$requests = array_values(array_filter(events($log), fn($event) => ($event['path'] ?? '') === '/session'));
		$options = $requests[0]['body']['capabilities']['alwaysMatch']['moz:firefoxOptions'];
		check($options['binary'] === realpath(PHP_BINARY) && $options['args'] === ['-private', '-headless'] && $options['prefs'] === ['fixture' => true], 'Browser options were lost.');
		raises(fn() => $driver->browser(), 'not running', LogicException::class);
	});

	scenario('session factory reports to the active Test across sessions without retaining a recorder', function () {
		FixtureDriver::$source = installation(['firefox']);
		$driver = new FixtureDriver(browser: 'firefox');
		try {
			$first = $driver->browser();
			$continued = false;
			[$status, $output] = runChecks(['check' => function () use ($first, &$continued) {
				check($first->urlMatches('~/fixture$~') === $first, 'Successful assertion lost its receiver.');
				$first->urlMatches('~/missing$~');
				$continued = true;
			}]);
			check($status === 1 && !$continued && str_contains($output, 'Assertions: 1 passed, 1 failed') && !str_contains($output, 'Execution error'), $output);
			raises(fn() => $first->urlMatches('~/missing$~'), 'WebDriver assertion failed');
			$first->quit();
			$second = $driver->browser();
			[$status, $output] = runChecks(['check' => function () use ($second) {
				$second->urlMatches('~/fixture$~');
			}]);
			check($status === 0 && str_contains($output, 'Assertions: 1 passed, 0 failed'), $output);
			raises(fn() => $second->urlMatches('~/missing$~'), 'WebDriver assertion failed');
			check(Test::active() === null, 'Factory assertion leaked a recorder.');
		} finally {
			$driver->stop();
		}
	});

	scenario('SuiteBrowser configures, shares and cleans up a fresh session per suite', function () use ($log) {
		FixtureDriver::$source = installation(['firefox', 'safari']);
		$suites = [];
		[$status, $output] = runChecks([
			'suite' => function (Test $test) use (&$suites) {
				return $suites[] = new class($test) extends FixtureBrowserSuite {
					public int $configured = 0;
					public array $trace = [];
					private ?Browser $original = null;

					protected function configure(SuiteConfig $config): void
					{
						$this->configured++;
						$config->browser = 'safari';
						$config->timeout = 0.5;
						$config->capabilities = ['acceptInsecureCerts' => false];
					}

					#[SuiteBefore]
					public function login(): void
					{
						$this->original = $this->browser;
						$this->browser->urlMatches('~/fixture$~');
						$this->trace[] = 'login';
					}

					#[CaseName('first browser case')]
					public function first(): void
					{
						$this->test->assert($this->browser === $this->original, 'Shared session.');
						$this->browser->urlMatches('~/fixture$~');
						$this->trace[] = 'first';
					}

					#[CaseName('second browser case')]
					public function second(): void
					{
						$this->test->assert($this->browser === $this->original, 'Same session in the next case.');
						$this->trace[] = 'second';
					}

					#[SuiteAfter]
					public function logout(): void
					{
						$this->browser->urlMatches('~/fixture$~');
						$this->trace[] = 'logout';
					}
				};
			},
			'suite-next' => function (Test $test) use (&$suites) {
				check(!$suites[0]->process->running(), 'Previous suite still owns a driver.');
				return $suites[] = new class($test) extends FixtureBrowserSuite {
					#[CaseName('default browser configuration')]
					public function defaults(): void { $this->browser->urlMatches('~/fixture$~'); }
				};
			},
		]);
		check($status === 0 && str_contains($output, 'Cases: 3 passed, 0 failed, 0 skipped') && str_contains($output, 'Assertions: 6 passed, 0 failed'), $output);
		check($suites[0]->configured === 1 && $suites[0]->trace === ['login', 'first', 'second', 'logout'], 'Suite setup or shared state changed.');
		check($suites[1]->settings->browser === 'firefox' && $suites[1]->settings->capabilities === [] && $suites[1]->settings->timeout === 10.0, 'Configuration leaked into the next suite.');
		foreach ($suites as $suite):
			check(!$suite->process->running() && !$suite->browser->active(), 'Suite did not stop its browser/driver.');
		endforeach;
		$requests = array_values(array_filter(events($log), fn($event) => ($event['path'] ?? '') === '/session'));
		check(count($requests) === 2 && $requests[0]['body']['capabilities']['alwaysMatch']['browserName'] === 'safari' && $requests[1]['body']['capabilities']['alwaysMatch']['browserName'] === 'firefox', 'Suite browser selection was not forwarded.');
		check($requests[0]['body']['capabilities']['alwaysMatch']['acceptInsecureCerts'] === false, 'Suite capabilities were not forwarded.');
	});

	foreach (['config', 'exit', 'session-error', 'case', 'cleanup', 'delete-error'] as $failure):
		scenario("SuiteBrowser cleans up after $failure failure", function () use ($failure, $log) {
			if (in_array($failure, ['exit', 'session-error', 'delete-error'], true)) putenv('WEBDRIVER_FIXTURE_MODE=' . $failure);
			$suite = null;
			[$status, $output] = runChecks([
				'suite' => function (Test $test) use (&$suite, $failure) {
					$suite = new class($test) extends FixtureBrowserSuite {
						public string $failure;
						public array $trace = [];

						protected function configure(SuiteConfig $config): void
						{
							if ($this->failure === 'config') $config->browswer = 'safari';
							$config->headless = true;
						}

						#[CaseName('browser assertion')]
						public function body(): void
						{
							$this->trace[] = 'case';
							$this->browser->urlMatches($this->failure === 'case' ? '~/missing$~' : '~/fixture$~');
							$this->trace[] = 'continued';
						}

						#[SuiteAfter]
						public function cleanup(): void
						{
							$this->trace[] = 'cleanup';
							if ($this->failure === 'cleanup') throw new RuntimeException('application cleanup failed');
						}
					};
					$suite->failure = $failure;
					return $suite;
				},
				'next' => function (Test $test) { $test->assert(true, 'next file runs'); },
			]);
			check($status === 1 && str_contains($output, 'PASS next') && in_array('cleanup', $suite->trace, true), $output);
			check($suite->process === null || !$suite->process->running(), 'Failure leaked a driver.');
			check(!isset($suite->browser) || !$suite->browser->active(), 'Failure leaked a browser session.');
			if (in_array($failure, ['config', 'exit', 'session-error'], true)):
				check(str_contains($output, 'Cases: 0 passed, 0 failed, 1 skipped') && $suite->trace === ['cleanup'], $output);
			else:
				check(count(array_filter(events($log), fn($event) => ($event['method'] ?? '') === 'DELETE')) === 1, 'Browser cleanup was skipped or repeated.');
			endif;
			if ($failure === 'case') check($suite->trace === ['case', 'cleanup'] && !str_contains($output, 'Execution error'), $output);
			check(Test::active() === null, 'Browser suite leaked the active recorder.');
		});
	endforeach;

	scenario('Chromium uses chrome protocol name and the selected binary', function () use ($log) {
		FixtureDriver::$source = installation(['chromium']);
		$driver = new FixtureDriver(browser: 'chromium');
		try {
			$driver->browser(headless: true);
		} finally {
			$driver->stop();
		}
		$request = array_values(array_filter(events($log), fn($event) => ($event['path'] ?? '') === '/session'))[0];
		$capabilities = $request['body']['capabilities']['alwaysMatch'];
		check($capabilities['browserName'] === 'chrome' && $capabilities['goog:chromeOptions']['binary'] === realpath(PHP_BINARY), 'Chromium identity was not carried through.');
		check($capabilities['goog:chromeOptions']['args'] === ['--headless'], 'Chromium headless flag missing.');
	});

	scenario('occupied preferred port warns and leaves the existing listener intact', function () use ($warnings) {
		$listener = @stream_socket_server('tcp://127.0.0.1:4444', $errno, $error);
		check($listener !== false, 'Fixture needs free preferred port 4444: ' . $error);
		try {
			$driver = new FixtureDriver(browser: 'firefox');
			try {
				check(parse_url($driver->endpoint(), PHP_URL_PORT) !== 4444, 'Occupied service was adopted.');
				check(str_contains(file_get_contents($warnings), 'preferred port 4444'), 'Port collision was not logged.');
				raises(fn() => new FixtureDriver(browser: 'firefox', port: 4444), 'Cannot bind WebDriver port 4444');
			} finally {
				$driver->stop();
			}
			check(is_resource($listener), 'Existing listener was closed.');
		} finally {
			fclose($listener);
		}
	});

	scenario('port fallback and startup polling preserve application warning handlers', function () {
		$listener = stream_socket_server('tcp://127.0.0.1:4444');
		$handler = static function ($severity, $message) { throw new ErrorException($message, 0, $severity); };
		set_error_handler($handler);
		try {
			$driver = new FixtureDriver(browser: 'firefox');
			try {
				$previous = set_error_handler($handler);
				restore_error_handler();
				check($previous === $handler, 'Driver setup replaced the application error handler.');
			} finally {
				$driver->stop();
			}
		} finally {
			restore_error_handler();
			fclose($listener);
		}
	});

	scenario('startup exit and readiness timeout clean up without selecting another browser', function () use ($log) {
		FixtureDriver::$source = installation(['safaripreview', 'safari', 'firefox']);
		putenv('WEBDRIVER_FIXTURE_MODE=exit');
		raises(fn() => new FixtureDriver(startupTimeout: 1), 'fixture startup failed');
		check(FixtureDriver::$source->visited === ['safaripreview'], 'Failed Preview startup fell back to another browser.');
		putenv('WEBDRIVER_FIXTURE_MODE=notready');
		$start = microtime(true);
		raises(fn() => new FixtureDriver(browser: 'firefox', startupTimeout: 0.2), 'Timed out waiting for driver readiness');
		check(microtime(true) - $start < 2, 'Startup timeout was not bounded.');
	});

	foreach (['session-error', 'session-drop'] as $mode):
		scenario("$mode stops the service after failed session creation", function () use ($mode) {
			putenv('WEBDRIVER_FIXTURE_MODE=' . $mode);
			$driver = new FixtureDriver(browser: 'firefox');
			$process = ownedProcess($driver);
			try {
				raises(fn() => $driver->browser(timeout: 0.5), $mode === 'session-error' ? 'fixture session refused' : 'failed to connect or receive headers');
				check(!$process->running(), 'Failed creation leaked its driver.');
			} finally {
				$driver->stop();
			}
		});
	endforeach;

	scenario('session deletion failure still reaps driver and invalidates local session', function () {
		putenv('WEBDRIVER_FIXTURE_MODE=delete-error');
		$driver = new FixtureDriver(browser: 'firefox');
		$process = ownedProcess($driver);
		$browser = $driver->browser();
		raises(fn() => $driver->stop(), 'fixture session deletion failed');
		check(!$process->running() && !$browser->active(), 'Failed HTTP cleanup left owned state active.');
		$driver->stop();
	});

	scenario('failed termination retains ownership and can be retried', function () {
		$driver = new FixtureDriver(browser: 'firefox');
		$inner = ownedProcess($driver);
		$wrapper = new RetryProcess($inner);
		$property = new ReflectionProperty(WebDriver::class, 'process');
		if (PHP_VERSION_ID < 80100) $property->setAccessible(true);
		$property->setValue($driver, $wrapper);
		$browser = $driver->browser();
		try {
			raises(fn() => $driver->stop(), 'fixture termination failed');
			check(ownedProcess($driver) === $wrapper && $inner->running(), 'Failed termination discarded its live process.');
			check(!$browser->active(), 'Session deletion was not attempted before termination.');
		} finally {
			$driver->stop();
		}
		check(!$inner->running(), 'Second cleanup failed to reap the retained child.');
	});

	scenario('destruction cleans up owned session and process', function () {
		$driver = new FixtureDriver(browser: 'firefox');
		$process = ownedProcess($driver);
		$browser = $driver->browser();
		unset($driver);
		check(!$browser->active() && !$process->running(), 'Runtime was retained by its shutdown callback.');
	});

	scenario('process termination escalates when graceful termination is ignored', function () {
		putenv('WEBDRIVER_FIXTURE_MODE=ignore-term');
		$driver = new FixtureDriver(browser: 'firefox', shutdownTimeout: 0.3);
		$process = ownedProcess($driver);
		$driver->stop();
		check(!$process->running(), 'Termination did not reap the child.');
	});

	foreach (['normal', 'delete-error'] as $mode):
		scenario("PHP shutdown cleans up and logs without throwing: $mode", function () use ($mode, $log, $warnings) {
			putenv('WEBDRIVER_FIXTURE_MODE=' . $mode);
			$result = DriverProcess::run([PHP_BINARY, '-d', 'error_log=' . $warnings, __FILE__, '--shutdown-child'], 5);
			check($result['exitCode'] === 0 && !$result['timedOut'], 'Shutdown cleanup changed the child exit: ' . $result['output']);
			$endpoint = trim($result['output']);
			$port = parse_url($endpoint, PHP_URL_PORT);
			check(is_int($port), 'Shutdown child did not report its service endpoint.');
			$connection = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.2);
			if (is_resource($connection)) fclose($connection);
			check($connection === false, 'Service outlived PHP shutdown.');
			check(count(array_filter(events($log), fn($event) => ($event['method'] ?? '') === 'DELETE')) === 1, 'Shutdown did not attempt session deletion.');
			if ($mode === 'delete-error') check(str_contains(file_get_contents($warnings), 'fixture session deletion failed'), 'Shutdown cleanup failure was not logged.');
		});
	endforeach;
} finally {
	ini_set('error_log', $oldErrorLog);
	putenv($oldMode === false ? 'WEBDRIVER_FIXTURE_MODE' : 'WEBDRIVER_FIXTURE_MODE=' . $oldMode);
	putenv($oldLog === false ? 'WEBDRIVER_FIXTURE_LOG' : 'WEBDRIVER_FIXTURE_LOG=' . $oldLog);
	foreach (scandir($temporary) as $file):
		if ($file !== '.' && $file !== '..') unlink($temporary . '/' . $file);
	endforeach;
	rmdir($temporary);
}
