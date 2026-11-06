<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP;

/** Runs callable checks and attributed suites, recording assertions in their current case or phase. */
class Test
{
    private static ?self $active = null;
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];
    private string $pathRoot;
    private string $pathChecks;
    private bool $bootstrapped = false;
    private ?string $selectedBrowser = null;
    private bool $browserResolved = false;
    private bool $headless = false;
    /** OR alternatives, matched against file paths first and case methods/tags after discovery. */
    private array $selectors = [];
    /** Worker events stay separate from incidental stdout/stderr. Null is the serial path. */
    private ?Test\Channel $channel = null;
    private ?Test\Report $report = null;
    private string $currentCheck = '';

    /** Set the project root and its relative checks directory; bootstrap defines PATH_ROOT. */
    public function __construct(string $pathRoot, string $pathChecks = '/test/checks')
    {
        $this->pathRoot = $pathRoot;
        $this->pathChecks = $pathChecks;
    }

    /** Initialize the application without command dispatch; exit with status 1 on failure. */
    public function bootstrap(): void
    {
        if (!(bool) ini_get('short_open_tag')) {
            fwrite(STDERR, "ERROR Enable short_open_tag; application and framework files use short tags.\n");
            exit(1);
        }

        // Catch Composer's platform guard and bootstrap warnings before application logging is installed.
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            define('PATH_ROOT', $this->pathRoot);
            require_once(PATH_ROOT . '/vendor/autoload.php');
            \ThriveData\ThrivePHP\Application::init();
            $this->bootstrapped = true;
        } catch (\Throwable $error) {
            error_clear_last();
            fwrite(STDERR, 'ERROR Application initialization failed (' . get_class($error)
                . "). Check PHP requirements, Composer dependencies, and settings JSON.\n");
            exit(1);
        }
    }

    /** The recorder for the currently executing check, or null outside run(). */
    public static function active(): ?self
    {
        return self::$active;
    }

    /** Record a result; failure throws Test\AssertionFailure to stop the current case or hook. */
    public function assert(bool $condition, string $message): bool
    {
        $this->verify($condition, $message);
        if (!$condition) {
            throw new Test\AssertionFailure($message);
        }

        return $condition;
    }

    /** Record a result and continue, returning the condition for prerequisite guards. */
    public function verify(bool $condition, string $message): bool
    {
        if ($condition) {
            $this->passed++;
        } else {
            $this->failed++;
            $this->failures[] = $message;
        }
        $this->event(['type' => 'assertion', 'passed' => $condition]);

        return $condition;
    }

    /** Return a required application setting by dot-separated key; missing values throw. */
    public function settings(string $key): mixed
    {
        return \ThriveData\ThrivePHP\Settings::get($key);
    }

    /**
     * Execute an argument vector without a shell. Throw on startup failure or timeout.
     * Capture at most 1 MiB per stream; callers must not print credential-bearing output.
     * The timeout is in seconds, plus up to 0.2 seconds before killing the direct child.
     *
     * @param non-empty-list<string> $arguments Executable followed by unquoted arguments.
     * @return array{code: int, stdout: string, stderr: string}
     */
    public function command(array $arguments, int $timeout = 10): array
    {
        if (!$arguments || $timeout < 1) {
            throw new \InvalidArgumentException('Command and a positive timeout are required.');
        }

        $process = @proc_open($arguments, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start command.');
        }

        $output = [1 => '', 2 => ''];
        $code = -1;
        $deadline = microtime(true) + $timeout;

        try {
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            do {
                $bytes = 0;
                foreach ([1, 2] as $stream) {
                    // Keep draining excess output without allocating an unbounded buffer.
                    $chunk = fread($pipes[$stream], 65536);
                    $bytes += strlen($chunk);
                    $output[$stream] .= substr($chunk, 0, 1048576 - strlen($output[$stream]));
                }

                $status = proc_get_status($process);
                if ($status['exitcode'] >= 0) {
                    $code = $status['exitcode'];
                }
                if (!$status['running'] && $bytes === 0) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException("Command timed out after $timeout seconds.");
                }
                usleep(1000);
            } while (true);
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
                $grace = microtime(true) + 0.2;
                while (proc_get_status($process)['running'] && microtime(true) < $grace) {
                    usleep(10000);
                }
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, 9);
                }
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closed = proc_close($process);
        }

        return ['code' => $code >= 0 ? $code : $closed, 'stdout' => $output[1], 'stderr' => $output[2]];
    }

    /**
     * Fetch HTTP(S) with verified TLS, no redirects, and a 2 MiB response limit.
     * Transport failures throw sanitized errors; checks assert the expected HTTP status.
     *
     * @param array<int, mixed> $options Supports CURLOPT_HTTPHEADER, CURLOPT_CUSTOMREQUEST,
     *     CURLOPT_POST, CURLOPT_POSTFIELDS, CURLOPT_HTTPAUTH, and CURLOPT_USERPWD only.
     *     Transport limits, response capture, and TLS verification cannot be overridden.
     * @return array{code: int, body: string}
     */
    public function http(string $url, array $options = [], int $timeout = 10): array
    {
        if ($timeout < 1 || !extension_loaded('curl')) {
            throw new \RuntimeException('HTTP requires curl and a positive timeout.');
        }
        $supported = array_flip([
            CURLOPT_HTTPHEADER, CURLOPT_CUSTOMREQUEST, CURLOPT_POST,
            CURLOPT_POSTFIELDS, CURLOPT_HTTPAUTH, CURLOPT_USERPWD,
        ]);
        if (array_diff_key($options, $supported)) {
            throw new \InvalidArgumentException('Unsupported HTTP request option.');
        }

        $curl = curl_init();
        $body = '';
        try {
            $limits = [
                CURLOPT_URL => $url,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HEADER => false,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 2097152) {
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ];
            if (defined('CURLOPT_PROTOCOLS_STR')) {
                $limits[CURLOPT_PROTOCOLS_STR] = 'http,https';
            } else {
                $limits[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            }
            curl_setopt_array($curl, $limits + $options);
            $ok = curl_exec($curl);
            $errno = curl_errno($curl);
            $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        } catch (\Throwable $error) {
            throw new \RuntimeException('HTTP request setup or execution failed.');
        } finally {
            // CurlHandle releases its resources on destruction; curl_close is deprecated in PHP 8.5.
            unset($curl);
        }

        if ($ok === false) {
            throw new \RuntimeException("HTTP transport failed (cURL $errno); check connectivity, TLS, and response size.");
        }

        return ['code' => $code, 'body' => $body];
    }

    /**
     * Run checks and print results. Return 0 only when every selected check passes.
     * Files receive $test in their loading scope and return a Test\Suite or a callable.
     * Legacy callables accept this Test; their return values are ignored.
     * Register this instance as the process-wide active recorder during each check,
     * restoring the previous recorder afterward. Assertion failures stop the current
     * callable check, case body, or hook. Suite cleanup always runs; later cases follow
     * skipRemainingOnFailure, which defaults to true for browser suites and false otherwise.
     *
     * Null workers prefers two parallel processes, falling back to serial for Safari.
     * Explicit workers > 1 requires a parallel-capable selected browser; 1 runs in-process.
     * Browser and headless apply to every managed SuiteBrowser in this run.
     * @param list<string> $names Paths relative to checks/, without .php; empty selects all.
     * @param list<string> $selectors Regex selectors: files!!exclude::cases:!exclude##tags#!exclude.
     * Empty selects all; each alternative combines its supplied conditions with AND.
     */
    public function run(array $names = [], ?int $workers = null, ?string $browser = null, bool $headless = false,
        array $selectors = []): int
    {
        $directory = $this->pathRoot . '/' . trim($this->pathChecks, '/') . '/';
        $files = [];

        try {
            if ($workers !== null && $workers < 1) {
                throw new \InvalidArgumentException('Workers must be a positive integer, or null.');
            }
            $this->selectors = [];
            foreach ($selectors as $selector) {
                if (!is_string($selector)) {
                    throw new \InvalidArgumentException('Test selectors must be strings.');
                }
                $this->selectors[] = new Test\Selector($selector);
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $path = $file->getPathname();
                    $name = substr($path, strlen($directory), -4);
                    if (DIRECTORY_SEPARATOR === '\\') {
                        $name = str_replace('\\', '/', $name);
                    }
                    $files[$name] = $path;
                }
            }

            ksort($files, SORT_STRING);
            $names = $names ? array_values(array_unique($names)) : array_keys($files);

            if (!$names && !$this->selectors) {
                throw new \RuntimeException('No checks found.');
            }

            foreach ($names as $name) {
                if (!isset($files[$name])) {
                    throw new \RuntimeException("Unknown check: $name");
                }
            }
            if ($this->selectors) {
                $names = array_values(array_filter($names, fn ($name) => array_any($this->selectors,
                    fn ($selector) => $selector->file($name))));
                if (!$names) {
                    return (new Test\Report())->summary();
                }
            }
            $this->headless = $headless;
            $this->browserResolved = false;
            $this->selectedBrowser = null;
            if ($workers !== 1 || $browser !== null || $headless) {
                $this->selectedBrowser = $this->resolveBrowser($browser);
                $this->browserResolved = true;
                $this->validateHeadless();
            }
            if (in_array($this->selectedBrowser, ['safari', 'safaripreview'], true)) {
                if ($workers !== null && $workers > 1) {
                    throw new \RuntimeException('The selected browser ' . $this->selectedBrowser . ' does not support parallel sessions.');
                }
                $workers = 1;
            }
        } catch (\Throwable $error) {
            echo 'ERROR ' . $error->getMessage() . PHP_EOL;
            return 1;
        }

        if ($workers !== 1) {
            $selected = [];
            foreach ($names as $name) {
                $selected[$name] = $files[$name];
            }
            return (new Test\Parallel())->run($selected, min($workers ?? 2, count($names)), [
                'root' => realpath($this->pathRoot), 'checks' => $this->pathChecks,
                'browser' => $this->selectedBrowser, 'headless' => $headless, 'bootstrap' => $this->bootstrapped,
                'selectors' => $selectors,
            ]);
        }
        $this->report = new Test\Report();
        try {
            foreach ($names as $name) {
                $this->report->result($name, $this->executeFile($name, $files[$name]));
            }
            return $this->report->summary();
        } finally {
            $this->report = null;
        }
    }

    /** Managed suites use the concrete run-level selection, never a new automatic choice in a worker. */
    public function browser(): string
    {
        if (!$this->browserResolved) {
            $this->selectedBrowser = $this->resolveBrowser(null);
            $this->browserResolved = true;
        }
        $this->validateHeadless();
        return $this->selectedBrowser ?? throw new \RuntimeException('No ready browser/driver pair.');
    }

    public function headless(): bool
    {
        return $this->headless;
    }

    /** No ready pair is allowed for non-browser checks; managed browser setup then reports the failure. */
    protected function resolveBrowser(?string $browser): ?string
    {
        $preference = WebDriver\WebDriver::PREFERENCE;
        if ($browser !== null && !in_array($browser, $preference, true)) {
            throw new \InvalidArgumentException('Unknown browser: ' . $browser);
        }
        $inventory = WebDriver\WebDriver::bootstrap();
        foreach ($browser === null ? $preference : [$browser] as $name) {
            $entry = $inventory->browsers->$name;
            if ($entry->ready === true && $entry->driver->ready === true) {
                return $name;
            }
        }
        if ($browser !== null) {
            throw new \RuntimeException('No ready browser/driver pair for ' . $browser . '.');
        }
        return null;
    }

    private function validateHeadless(): void
    {
        if ($this->headless && in_array($this->selectedBrowser, ['safari', 'safaripreview'], true)) {
            throw new \InvalidArgumentException('Safari does not support headless browser sessions.');
        }
    }

    /** @internal The worker entry owns application bootstrap and sends exactly one terminal result. */
    public function runWorker(array $job, Test\Channel $channel): int
    {
        $this->channel = $channel;
        $this->selectedBrowser = $job['browser'];
        $this->browserResolved = true;
        $this->headless = $job['headless'];
        $this->selectors = array_map(fn ($selector) => new Test\Selector($selector), $job['selectors']);
        $this->event(['type' => 'activity', 'activity' => 'application bootstrap started']);
        if ($job['bootstrap']) {
            $this->bootstrap();
        } else {
            define('PATH_ROOT', $this->pathRoot);
            require_once PATH_ROOT . '/vendor/autoload.php';
        }
        $this->event(['type' => 'activity', 'activity' => 'application bootstrap finished']);
        $result = $this->executeFile($job['name'], $job['file']);
        $this->event(['type' => 'result', 'result' => $result]);
        return $result['checksFailed'] ? 1 : 0;
    }

    /** Load a check factory per suite attempt; only the final attempt contributes to totals. */
    private function executeFile(string $name, string $path): array
    {
        $this->currentCheck = $name;
        $started = hrtime(true);
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        $previous = self::$active;
        self::$active = $this;
        try {
            $attempt = 0;
            $retry = 0;
            do {
                $attempt++;
                $this->passed = $this->failed = 0;
                $this->failures = [];
                $casesPassed = $casesFailed = $casesSkipped = 0;
                $hasSuites = $skipped = $filtered = false;
                try {
                    $selectors = array_values(array_filter($this->selectors, fn ($selector) => $selector->file($name)));
                    $this->event(['type' => 'activity', 'activity' => 'loading suite']);
                    // Reload the factory for each attempt so constructors and file-level initialization
                    // produce a fresh suite, including managed browser ownership and configuration.
                    // The closure keeps variables declared by the check file out of the runner's scope.
                    $check = (static fn (string $path, self $test) => require($path))($path, $this);
                    if ($check instanceof Test\Suite) {
                        $hasSuites = true;
                        $attributes = (new \ReflectionObject($check))->getAttributes(Test\Test::class);
                        $metadata = $attributes ? $attributes[0]->newInstance() : new Test\Test($name);
                        $this->currentCheck = $metadata->description;
                        $retry = $metadata->retry;
                        $suiteStarted = hrtime(true);
                        [$casesPassed, $casesFailed, $casesSkipped, $skipped, $filtered] = $this->runSuite($check, $metadata, $selectors);
                        $duration = (hrtime(true) - $suiteStarted) / 1e9;
                        if (!$skipped && !$filtered && $metadata->deadline !== null && $duration > $metadata->deadline) {
                            $this->failures[] = sprintf('Suite deadline exceeded: %.3fs > %.3fs.', $duration, $metadata->deadline);
                        }
                    } else {
                        if (!is_callable($check)) {
                            throw new \RuntimeException('Check file must return a callable or Suite.');
                        }
                        $this->event(['type' => 'configured', 'name' => $name, 'idleTimeout' => null, 'cases' => []]);
                        $filtered = $selectors && !array_any($selectors, fn ($selector) => $selector->test(null, []));
                        if (!$filtered) {
                            $this->event(['type' => 'activity', 'activity' => 'callable check']);
                            $check($this);
                        }
                    }
                } catch (Test\AssertionFailure $error) {
                    // assert() already recorded this failure before stopping the check.
                } catch (\Throwable $error) {
                    $this->failures[] = 'Execution error: ' . get_class($error) . ': ' . $error->getMessage();
                }
                $failed = $casesFailed > 0 || $this->failures;
                if (!$failed || $attempt > $retry || $skipped || $filtered) {
                    break;
                }
                $this->event(['type' => 'retry', 'scope' => 'suite', 'attempt' => $attempt + 1,
                    'failures' => $this->failures]);
                unset($check);
            } while (true);
        } finally {
            self::$active = $previous;
            restore_error_handler();
            // Application::init installs a shutdown handler that rethrows even suppressed errors.
            error_clear_last();
        }
        // Filtering cannot hide a failure raised while loading the file or constructing its suite.
        $filtered = $filtered && !$failed;
        return ['checksPassed' => !$failed && !$skipped && !$filtered ? 1 : 0, 'checksFailed' => $failed ? 1 : 0,
            'checksSkipped' => !$failed && $skipped ? 1 : 0, 'filtered' => $filtered,
            'name' => $this->currentCheck, 'attempt' => $attempt,
            'casesPassed' => $casesPassed, 'casesFailed' => $casesFailed, 'casesSkipped' => $casesSkipped,
            'assertionsPassed' => $this->passed, 'assertionsFailed' => $this->failed,
            'hasSuites' => $hasSuites, 'failures' => $this->failures, 'duration' => (hrtime(true) - $started) / 1e9];
    }

    private function event(array $message): void
    {
        if ($this->channel !== null) {
            $this->channel->send($message);
        } elseif ($message['type'] === 'case_finished') {
            $this->report?->caseResult($this->currentCheck, $message);
        } elseif ($message['type'] === 'retry') {
            $this->report?->retry($this->currentCheck, $message);
        }
    }

    /** Filter before configuration/hooks, then execute one suite attempt with per-case retries. */
    private function runSuite(Test\Suite $suite, Test\Test $metadata, array $selectors): array
    {
        $methods = Test\SuiteMethods::discover($suite);
        if (!$methods[Test\Test::class] && !$metadata->skip) {
            throw new \RuntimeException('No cases found.');
        }
        $cases = array_values(array_filter($methods[Test\Test::class], function ($case) use ($metadata, $selectors) {
            $tags = [...$metadata->tags, ...$case['tags']];
            return !$selectors || array_any($selectors, fn ($selector) => $selector->test($case['method']->getName(), $tags));
        }));
        $filtered = !$cases && $selectors !== [];
        $skipped = !$filtered && ($metadata->skip || ($cases && array_all($cases, fn ($case) => $case['skip'])));
        if ($filtered || $skipped) {
            $this->event(['type' => 'configured', 'name' => $metadata->description, 'idleTimeout' => null,
                'cases' => array_column($cases, 'name')]);
            foreach ($cases as $case) {
                $this->event(['type' => 'case_finished', 'name' => $case['name'], 'status' => 'SKIP',
                    'reason' => 'marked skip', 'duration' => 0]);
            }
            return [0, 0, count($cases), $skipped, $filtered];
        }
        $casesPassed = $casesFailed = $casesSkipped = $assertionsPassed = $assertionsFailed = 0;
        $suiteState = [$this->passed, $this->failed, $this->failures];
        try {
            $ready = false;
            $idleTimeout = null;
            try {
                $config = $suite->configuration();
                $idleTimeout = $config->idleTimeout;
                $ready = true;
            } catch (\Throwable $error) {
                $this->failures[] = 'Suite configuration: ' . get_class($error) . ': ' . $error->getMessage();
            }
            $this->event(['type' => 'configured', 'name' => $metadata->description, 'idleTimeout' => $idleTimeout,
                'cases' => array_column($cases, 'name')]);
            if ($ready) {
                $ready = $this->runHooks($suite, $methods[Test\SuiteBefore::class], 'SuiteBefore');
            }
            $suiteState = [$this->passed, $this->failed, $this->failures];
            foreach ($cases as $case) {
                if ($case['skip'] || !$ready || ($config->skipRemainingOnFailure && $casesFailed > 0)) {
                    $casesSkipped++;
                    $this->event(['type' => 'case_finished', 'name' => $case['name'], 'status' => 'SKIP',
                        'reason' => $case['skip'] ? 'marked skip' : ($ready ? 'previous case failed' : 'suite setup failed'), 'duration' => 0]);
                    continue;
                }

                $started = hrtime(true);
                $this->event(['type' => 'case_started', 'name' => $case['name']]);
                $attempt = 0;
                do {
                    $attempt++;
                    $this->passed = $this->failed = 0;
                    $this->failures = [];
                    try {
                        if ($this->runHooks($suite, $methods[Test\CaseBefore::class], 'CaseBefore')) {
                            $this->event(['type' => 'activity', 'activity' => 'case body: ' . $case['name']]);
                            $bodyStarted = hrtime(true);
                            $this->invokeSuiteMethod($suite, $case['method']);
                            $duration = (hrtime(true) - $bodyStarted) / 1e9;
                            if ($case['deadline'] !== null && $duration > $case['deadline']) {
                                $this->failures[] = sprintf('Case deadline exceeded: %.3fs > %.3fs.', $duration, $case['deadline']);
                            }
                            $this->event(['type' => 'activity', 'activity' => 'case body finished: ' . $case['name'], 'duration' => $duration]);
                        }
                    } finally {
                        $this->runHooks($suite, $methods[Test\CaseAfter::class], 'CaseAfter', teardown: true);
                    }
                    if (!$this->failures || $attempt > $case['retry']) {
                        break;
                    }
                    $this->event(['type' => 'retry', 'scope' => 'case', 'name' => $case['name'],
                        'attempt' => $attempt + 1, 'failures' => $this->failures]);
                } while (true);
                if ($this->failures) {
                    $casesFailed++;
                } else {
                    $casesPassed++;
                }
                $this->event(['type' => 'case_finished', 'name' => $case['name'],
                    'status' => $this->failures ? 'FAIL' : 'PASS', 'failures' => $this->failures,
                    'noAssertions' => $this->passed + $this->failed === 0,
                    'attempt' => $attempt,
                    'duration' => (hrtime(true) - $started) / 1e9]);
                $assertionsPassed += $this->passed;
                $assertionsFailed += $this->failed;
            }
        } finally {
            [$this->passed, $this->failed, $this->failures] = $suiteState;
            $this->runHooks($suite, $methods[Test\SuiteAfter::class], 'SuiteAfter', teardown: true);
            $this->passed += $assertionsPassed;
            $this->failed += $assertionsFailed;
        }
        return [$casesPassed, $casesFailed, $casesSkipped, false, false];
    }

    /** Stop setup at its first thrown failure; attempt every teardown hook independently. */
    private function runHooks(Test\Suite $suite, array $hooks, string $phase, bool $teardown = false): bool
    {
        foreach ($hooks as $hook) {
            $start = count($this->failures);
            $started = hrtime(true);
            $this->event(['type' => 'activity', 'activity' => $phase . ' ' . $hook['name']]);
            $completed = $this->invokeSuiteMethod($suite, $hook['method']);
            $this->event(['type' => 'activity', 'activity' => $phase . ' ' . $hook['name'] . ' finished',
                'duration' => (hrtime(true) - $started) / 1e9]);
            for ($index = $start; $index < count($this->failures); $index++) {
                $this->failures[$index] = $phase . ' ' . $hook['name'] . ': ' . $this->failures[$index];
            }
            if (!$completed && !$teardown) {
                return false;
            }
        }
        return true;
    }

    /** A stopping assertion was already recorded; unexpected exceptions need their own diagnostic. */
    private function invokeSuiteMethod(Test\Suite $suite, \ReflectionMethod $method): bool
    {
        try {
            $method->invoke($suite);
            return true;
        } catch (Test\AssertionFailure $error) {
            return false;
        } catch (\Throwable $error) {
            $this->failures[] = 'Execution error: ' . get_class($error) . ': ' . $error->getMessage();
            return false;
        }
    }
}
