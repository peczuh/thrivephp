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
    private Test\Diagnostic $diagnostic;
    private string $runDirectory = '';
    private string $checkId = '';
    private int $suiteAttempt = 1;
    private ?string $caseMethod = null;
    private int $caseAttempt = 1;
    private string $phase = 'suite-setup';
    private ?string $hook = null;
    private int $ordinal = 0;
    private ?array $step = null;
    /** Getter implementations and screenshot callbacks must not open script/action steps. */
    private bool $suppressSteps = false;
    private ?\Closure $screenshotSource = null;
    /** Capturing sessions in the current suite attempt, including manually owned browsers. */
    private array $logBrowsers = [];
    private array $logErrors = [];
    private array $networkCollectors = [];
    /** Attempt ID => collector ID => reported failure. Retries must report a persistently broken collector again. */
    private array $networkErrors = [];
    /** Attempt ID => collector ID => number of interception failures already reported. */
    private array $interceptionCursor = [];
    private ?array $failureCapture = null;
    private array $capturePolicy = ['steps' => 'failure', 'cases' => 'always', 'suites' => 'off'];
    private bool $bootstrapped = false;
    private ?string $selectedBrowser = null;
    private bool $browserResolved = false;
    private bool $headless = true;
    /** Settings plus explicit run overrides, copied into each fresh browser suite configuration. */
    private array $browserCapabilities = [];
    /** OR alternatives, matched against file paths first and case methods/tags after discovery. */
    private array $selectors = [];
    /** Worker events stay separate from incidental stdout/stderr. Null is the serial path. */
    private ?Test\Channel $channel = null;
    private ?Test\Report $report = null;
    private string $currentCheck = '';

    /** Set the project root; discovery and report paths resolve from Settings when run() starts. */
    public function __construct(string $pathRoot)
    {
        $this->pathRoot = $pathRoot;
    }

    /** Initialize the application without command dispatch; exit with status 1 on failure. */
    public function bootstrap(): void
    {
        // Catch Composer's platform guard and bootstrap warnings before application logging is installed.
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            if (!(bool) ini_get('short_open_tag')) {
                throw new \RuntimeException('Enable short_open_tag; application and framework files use short tags.');
            }
            define('PATH_ROOT', $this->pathRoot);
            require_once(PATH_ROOT . '/vendor/autoload.php');
            \ThriveData\ThrivePHP\Application::init();
            $this->bootstrapped = true;
        } catch (\Throwable $error) {
            error_clear_last();
            if ($this->channel !== null) {
                $this->event(['type' => 'error', 'error' => $this->diagnostic->error($error) + ['phase' => 'bootstrap']]);
                throw $error;
            }
            try {
                // Composer or short-tag settings may be unavailable at this point.
                $settingsLoaded = class_exists(Settings::class, false);
                $savePath = $this->pathRoot . '/test/runs';
                $pathError = null;
                if ($settingsLoaded) {
                    try {
                        $savePath = $this->savePath();
                    } catch (\Throwable $failure) {
                        $pathError = $failure;
                    }
                }
                $this->startReport($savePath, [
                    'project' => $settingsLoaded ? Settings::fetch('test.project') : null,
                    'url' => $settingsLoaded ? Settings::fetch('site.url') : null,
                ]);
                $this->report->runError($error, 'bootstrap');
                if ($pathError !== null) $this->report->runError($pathError, 'report configuration');
                $this->report->summary();
            } catch (\Throwable $reportError) {
                fwrite(STDERR, 'ERROR Bootstrap failed: ' . get_class($error) . ': ' . $error->getMessage() . PHP_EOL
                    . 'ERROR Could not write test report: ' . $reportError->getMessage() . PHP_EOL);
            } finally {
                $this->report = null;
            }
            exit(1);
        }
    }

    /** The recorder for the currently executing check, or null outside run(). */
    public static function active(): ?self
    {
        return self::$active;
    }

    /** Record a result; failure throws Test\AssertionFailure to stop the current case or hook. */
    public function assert(bool $condition, string $message, array $comparison = []): bool
    {
        if ($this->canRecordStep()) {
            return $this->recordStep('assert', null, [], fn () => $this->assert($condition, $message, $comparison));
        }
        $this->verify($condition, $message, $comparison);
        if (!$condition) {
            throw new Test\AssertionFailure($message);
        }

        return $condition;
    }

    /** Record a result and continue, returning the condition for prerequisite guards. */
    public function verify(bool $condition, string $message, array $comparison = []): bool
    {
        if ($this->canRecordStep()) {
            return $this->recordStep('verify', null, [], fn () => $this->verify($condition, $message, $comparison));
        }
        if ($condition) {
            $this->passed++;
        } else {
            $this->failed++;
            $this->failures[] = $message;
        }
        $this->event(['type' => 'assertion', 'passed' => $condition]);
        if ($this->step !== null) {
            $this->step['assertions'][] = ['passed' => $condition, 'message' => $message,
                'location' => $this->diagnostic->author()] + ($condition ? [] : ['class' => 'AssertionFailure']) + $comparison;
            if (!$condition) {
                $this->step['status'] = 'FAIL';
                $this->captureStep();
            }
            $this->event(['type' => 'step_updated', 'step' => $this->step]);
        }

        return $condition;
    }

    /**
     * Compare supplied values with PHP ===, including array key order and object identity.
     * Queries return a silent boolean. Assertion mode records once and returns this Test;
     * stop: false records through verify() and continues. is: false negates the result.
     * Values are compared immediately, without polling. message overrides the default label.
     */
    public function equal(mixed $actual, mixed $expected, bool $is = true, bool $assert = false,
        bool $stop = true, ?string $message = null): bool|self
    {
        if ($assert && $this->canRecordStep()) {
            return $this->recordStep('equal', null, ['actual' => WebDriver\Step::value($actual),
                'expected' => WebDriver\Step::value($expected), 'is' => $is],
                fn () => $this->equal($actual, $expected, $is, $assert, $stop, $message));
        }
        $passed = ($actual === $expected) === $is;
        if (!$assert) return $passed;
        return $this->comparison($passed, $actual, $expected, $is, $stop, $message ?? ($is
            ? 'Values are strictly equal.' : 'Values are not strictly equal.'));
    }

    /**
     * Compare structure recursively with strict scalar types, ordered lists and unordered maps.
     * stdClass and user-defined objects require the same class and stored instance properties;
     * getters are not invoked. Other internal objects retain identity semantics.
     * Traversal beyond 64 levels, including distinct cyclic structures, throws InvalidArgumentException.
     * Query/assertion, is, stop and message options have the same meaning as equal().
     */
    public function same(mixed $actual, mixed $expected, bool $is = true, bool $assert = false,
        bool $stop = true, ?string $message = null): bool|self
    {
        if ($assert && $this->canRecordStep()) {
            return $this->recordStep('same', null, ['actual' => WebDriver\Step::value($actual),
                'expected' => WebDriver\Step::value($expected), 'is' => $is],
                fn () => $this->same($actual, $expected, $is, $assert, $stop, $message));
        }
        $passed = self::sameStructure($actual, $expected) === $is;
        if (!$assert) return $passed;
        return $this->comparison($passed, $actual, $expected, $is, $stop, $message ?? ($is
            ? 'Values have the same structure.' : 'Values do not have the same structure.'));
    }

    /** Use existing assertion events and JSON-safe value descriptions for all report formats. */
    private function comparison(bool $passed, mixed $actual, mixed $expected, bool $is, bool $stop, string $message): self
    {
        $comparison = ['expected' => WebDriver\Step::value($expected), 'actual' => WebDriver\Step::value($actual)]
            + (!$is ? ['invert' => true] : []);
        if ($stop) $this->assert($passed, $message, $comparison);
        else $this->verify($passed, $message, $comparison);
        return $this;
    }

    /** Read stored data only; opaque internal state cannot be inferred from an object's properties. */
    private static function sameStructure(mixed $actual, mixed $expected, int $depth = 0): bool
    {
        if ($depth > 64) throw new \InvalidArgumentException('Recursive or excessively nested comparison (maximum 64 levels).');
        if (is_object($actual) && is_object($expected)) {
            if ($actual === $expected) return true;
            if (get_class($actual) !== get_class($expected)) return false;
            for ($class = new \ReflectionClass($actual); $class !== false; $class = $class->getParentClass()) {
                if ($class->isInternal() && $class->getName() !== \stdClass::class) return false;
            }
            $actual = get_mangled_object_vars($actual);
            $expected = get_mangled_object_vars($expected);
        } elseif (is_array($actual) && is_array($expected)) {
            if (array_is_list($actual) !== array_is_list($expected)) return false;
        } else {
            return $actual === $expected;
        }
        if (count($actual) !== count($expected)) return false;
        foreach ($actual as $key => $value) {
            if (!array_key_exists($key, $expected) || !self::sameStructure($value, $expected[$key], $depth + 1)) return false;
        }
        return true;
    }

    /** One recorder-wide guard covers nested calls across all browser objects. */
    public function canRecordStep(): bool
    {
        return self::$active === $this && $this->step === null && !$this->suppressSteps;
    }

    /**
     * Execute one author operation, preserving its return value and thrown exception.
     * $label must be a safe action name, not a free-form description.
     */
    public function recordStep(string $label, ?string $target, array $values, callable $operation): mixed
    {
        Test\Name::validate($label, 'action');
        if (!$this->canRecordStep()) {
            return $operation();
        }
        $this->collectBrowserLogs();
        $this->collectBrowserNetwork();
        $started = hrtime(true);
        $this->failureCapture = null;
        $this->step = ['id' => $this->attemptId() . '/s' . ++$this->ordinal, 'label' => $label,
            'target' => $target, 'values' => $values, 'phase' => $this->phase, 'hook' => $this->hook, 'status' => 'RUN',
            'startedAt' => Test\Record::now(), 'endedAt' => null, 'duration' => 0,
            'location' => $this->diagnostic->author(), 'assertions' => [], 'error' => null, 'screenshots' => []];
        try {
            $this->event(['type' => 'step_started', 'step' => $this->step]);
            return $operation();
        } catch (\Throwable $error) {
            $this->step['status'] = 'FAIL';
            // assert() already recorded its diagnostic before throwing to stop the operation.
            if (!$error instanceof Test\AssertionFailure || !array_any($this->step['assertions'],
                static fn ($assertion) => !$assertion['passed'] && $assertion['message'] === $error->getMessage())) {
                $this->step['error'] = $this->diagnostic->error($error);
            }
            throw $error;
        } finally {
            try {
                $this->collectBrowserLogs();
                $this->collectBrowserNetwork();
                if ($this->step['status'] === 'RUN') {
                    $this->step['status'] = 'PASS';
                }
                $this->captureStep();
                $this->step['endedAt'] = Test\Record::now();
                $this->step['duration'] = (hrtime(true) - $started) / 1e9;
                $this->event(['type' => 'step_finished', 'step' => $this->step]);
            } finally {
                $this->step = null;
            }
        }
    }

    /** Observation implementations can call public script methods without creating action steps. */
    public function withoutSteps(callable $operation): mixed
    {
        $previous = $this->suppressSteps;
        $this->suppressSteps = true;
        try {
            return $operation();
        } finally {
            $this->suppressSteps = $previous;
        }
    }

    /** SuiteBrowser registers capabilities and an eager PNG source for this suite attempt. */
    public function browserSession(array $session, callable $source): void
    {
        $this->screenshotSource = \Closure::fromCallable($source);
        $this->event(['type' => 'session', 'session' => $session]);
    }

    /** @internal Register lifecycle checkpoints without taking ownership of the browser session. */
    public function watchBrowserLogs(WebDriver\Browser $browser): void
    {
        $this->logBrowsers[spl_object_id($browser)] = $browser;
    }

    /** Record observation context, not a claim that the active action caused the browser entry. */
    public function browserLog(array $session, array $entry): void
    {
        $this->event(['type' => 'browser_log', 'log' => ['entry' => $entry,
            'sessionId' => $session['sessionId'], 'observedAt' => Test\Record::now(),
            'phase' => $this->phase, 'hook' => $this->hook, 'stepId' => $this->step['id'] ?? null]]);
    }

    /** Collection failures fail the attempt once; browser-generated errors remain diagnostic. */
    private function collectBrowserLogs(): void
    {
        foreach ($this->logBrowsers as $id => $browser) {
            if (isset($this->logErrors[$id])) continue;
            try {
                $browser->collectLogs();
                if ($browser->logCaptureError() !== null) throw $browser->logCaptureError();
            } catch (\Throwable $error) {
                $this->logErrors[$id] = true;
                $this->executionError($error, 'Browser log collection');
            }
        }
    }

    /** @internal Register explicit or suite-managed capture without taking session ownership. */
    public function watchBrowserNetwork(WebDriver\Network $network): void
    {
        $this->networkCollectors[spl_object_id($network)] = $network;
    }

    /** Record each event where observed, even when its request spans attempts. */
    public function browserNetwork(string $sessionId, array $event): void
    {
        $this->event(['type' => 'browser_network', 'network' => ['event' => $event,
            'sessionId' => $sessionId, 'observedAt' => Test\Record::now(),
            'phase' => $this->phase, 'hook' => $this->hook, 'stepId' => $this->step['id'] ?? null]]);
    }

    /** Keep collecting after errors so successful capture restarts resume reporting. */
    private function collectBrowserNetwork(): void
    {
        foreach ($this->networkCollectors as $id => $network) {
            try {
                $network->collect();
            } catch (\Throwable $error) {
                if ($network->captureError() === null && $network->interceptionError() === null) $this->executionError($error, 'Browser network collection');
            }
            if ($network->captureError() !== null && !isset($this->networkErrors[$this->attemptId()][$id])) {
                $this->executionError($network->captureError(), 'Browser network collection');
            }
            if ($network->interceptionError() !== null) $this->executionError($network->interceptionError());
        }
    }

    private function attemptId(): string
    {
        return $this->checkId . '/a' . $this->suiteAttempt
            . ($this->caseMethod === null ? '' : '/' . $this->caseMethod . '/a' . $this->caseAttempt);
    }

    private function captureStep(): void
    {
        $failure = $this->step['status'] === 'FAIL';
        if (!$this->step['screenshots'] && $this->captureEnabled('steps', $failure)) {
            $image = $this->capture($failure ? 'fail' : 'pass', $failure, $this->ordinal);
            if ($image !== null) {
                $this->step['screenshots'][] = $image;
                if ($failure) {
                    $this->failureCapture = $image;
                }
            }
        }
    }

    private function captureEnabled(string $level, bool $failure): bool
    {
        return $this->capturePolicy[$level] === 'always' || ($failure && $this->capturePolicy[$level] === 'failure');
    }

    /** Capture errors are report diagnostics; they must not change the test's outcome. */
    private function capture(string $point, bool $failed, ?int $step = null): ?array
    {
        if ($this->screenshotSource === null) {
            return null;
        }
        try {
            $image = $this->withoutSteps($this->screenshotSource);
            // Suite filename stems are validated for uniqueness before files or workers start.
            $directory = 'screenshots/' . basename($this->checkId);
            $parts = ['a' . $this->suiteAttempt];
            if ($this->caseMethod !== null) {
                $directory .= '/' . $this->caseMethod;
                $parts[] = 'a' . $this->caseAttempt;
            }
            if ($step !== null) {
                $parts[] = 's' . $step;
            }
            $parent = $this->runDirectory . '/' . $directory;
            // Parallel suites can create shared ancestor directories concurrently.
            if (!is_dir($parent) && !@mkdir($parent, 0777, true) && !is_dir($parent)) {
                throw new \RuntimeException('Could not create screenshot directory: ' . $parent);
            }
            $path = $directory . '/' . implode('__', $parts) . '__' . $point . '.png';
            $image->save($this->runDirectory . '/' . $path);
            return ['path' => $path, 'point' => $point, 'status' => $failed ? 'FAIL' : 'PASS',
                'width' => $image->width(), 'height' => $image->height()];
        } catch (\Throwable $error) {
            return ['point' => $point, 'status' => $failed ? 'FAIL' : 'PASS', 'diagnostic' => $error->getMessage()];
        }
    }

    private function captureCompletion(string $level, bool $failed = false): void
    {
        $failed = $failed || $this->failures !== [];
        if ($this->captureEnabled($level, $failed)) {
            $point = $level === 'cases' ? ($this->phase === 'case-teardown' ? 'case-teardown' : 'case') : 'suite';
            $image = $failed && $this->failureCapture !== null ? $this->failureCapture
                : $this->capture($point, $failed);
            if ($image !== null) {
                $this->event(['type' => 'screenshot', 'screenshot' => $image]);
            }
        }
    }

    private function executionError(\Throwable $error, string $prefix = 'Execution error'): void
    {
        foreach ($this->networkCollectors as $id => $network) {
            $interception = $network->interceptionError();
            $failures = $interception?->failures() ?? [];
            if ($error !== $interception && !array_any($failures, fn ($failure) => $failure['error'] === $error)) continue;
            foreach ($failures as $index => $failure) {
                if (($this->interceptionCursor[$this->attemptId()][$id] ?? 0) > $index) continue;
                $this->recordExecutionError($failure['error'], 'Browser network interception'
                    . ($failure['requestId'] !== null ? ' (' . $failure['requestId'] . ', hop ' . $failure['redirectCount'] . ')' : ''));
                $this->interceptionCursor[$this->attemptId()][$id] = $index + 1;
            }
            return;
        }
        $this->recordExecutionError($error, $prefix);
    }

    /** Record each interception occurrence separately, even when handlers throw the same exception object. */
    private function recordExecutionError(\Throwable $error, string $prefix): void
    {
        // An operation or its finally checkpoint can report the same capture failure first.
        $reported = false;
        foreach ($this->networkCollectors as $id => $network) {
            if ($network->captureError() !== $error) continue;
            $reported = $reported || ($this->networkErrors[$this->attemptId()][$id] ?? null) === $error;
            $this->networkErrors[$this->attemptId()][$id] = $error;
        }
        if ($reported) return;
        $this->failures[] = $prefix . ': ' . get_class($error) . ': ' . $error->getMessage();
        $this->event(['type' => 'error', 'error' => $this->diagnostic->error($error) + ['phase' => $this->phase, 'hook' => $this->hook]]);
    }

    /** Test umbrella directory; relative settings resolve against the constructor's project root. */
    protected function basePath(): string
    {
        $path = Settings::fetch('test.basepath') ?? 'test';
        return str_starts_with($path, '/') ? $path : $this->pathRoot . '/' . $path;
    }

    /** Discovery directory; relative settings resolve against the test base. Overridable by fixtures. */
    protected function casePath(): string
    {
        $path = Settings::fetch('test.casepath') ?? 'cases';
        return str_starts_with($path, '/') ? $path : $this->basePath() . '/' . $path;
    }

    /** Parent of timestamped runs; relative settings resolve against the test base. */
    protected function savePath(): string
    {
        $path = Settings::fetch('test.savepath') ?? 'runs';
        return str_starts_with($path, '/') ? $path : $this->basePath() . '/' . $path;
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
     * Null headless uses test.browser.headless, then true; explicit booleans override settings.
     * Capabilities replace matching test.browser.capabilities keys before suite configure() runs.
     * Title identifies the project; null uses test.project, then the root Composer package name.
     * Selected suite filename stems must be safe names and unique ignoring case across directories.
     * @param list<string> $names Paths relative to checks/, without .php; empty selects all.
     * @param list<string> $selectors Regex selectors: files!!exclude::cases:!exclude##tags#!exclude.
     * Empty selects all; each alternative combines its supplied conditions with AND.
     */
    public function run(array $names = [], ?int $workers = null, ?string $browser = null, ?bool $headless = null,
        array $selectors = [], ?string $title = null, array $capabilities = []): int
    {
        $pathError = null;
        try {
            $savePath = $this->savePath();
        } catch (\Throwable $error) {
            $pathError = $error;
            $savePath = $this->pathRoot . '/test/runs';
        }
        $this->startReport($savePath, ['project' => $title, 'headless' => $headless]);
        $files = [];

        try {
            if ($pathError !== null) throw $pathError;
            $headless ??= Settings::fetch('test.browser.headless') ?? true;
            if (!is_bool($headless)) throw new \InvalidArgumentException('test.browser.headless must be a boolean or null.');
            $this->report->record->environment(['headless' => $headless]);
            $this->browserCapabilities = array_replace(Settings::fetch('test.browser.capabilities') ?? [], $capabilities);
            $root = realpath($this->pathRoot) ?: $this->pathRoot;
            $title ??= Settings::fetch('test.project');
            if ($title === null && is_file($root . '/composer.json')) {
                $title = json_decode(file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR)['name'] ?? null;
            }
            $this->report->record->environment(['project' => $title, 'url' => Settings::fetch('site.url')]);
            $this->capturePolicy = ['steps' => 'failure', 'cases' => 'always', 'suites' => 'off'];
            foreach ($this->capturePolicy as $level => $default) {
                $policy = Settings::fetch('test.screenshots.' . $level) ?? $default;
                if (!in_array($policy, ['off', 'failure', 'always'], true)) {
                    throw new \InvalidArgumentException('test.screenshots.' . $level . ' must be off, failure, or always.');
                }
                $this->capturePolicy[$level] = $policy;
            }
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
            $directory = rtrim($this->casePath(), '/') . '/';
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
            }
            $suiteNames = [];
            foreach ($names as $name) {
                $suiteName = basename($name);
                Test\Name::validate($suiteName, 'suite');
                $key = strtolower($suiteName);
                if (isset($suiteNames[$key])) {
                    throw new \InvalidArgumentException('Duplicate suite name ' . var_export($suiteName, true)
                        . ': ' . $suiteNames[$key] . ' and ' . $name . '. Suite filename stems must be unique, ignoring case.');
                }
                $suiteNames[$key] = $name;
            }
            $this->headless = $headless;
            $this->browserResolved = false;
            $this->selectedBrowser = null;
            if ($workers !== 1 || $browser !== null || $headless) {
                $this->selectedBrowser = $this->resolveBrowser($browser);
                $this->browserResolved = true;
                $this->report->record->environment(['browser' => $this->selectedBrowser]);
                $this->validateHeadless();
            }
            if (in_array($this->selectedBrowser, ['safari', 'safaripreview'], true)) {
                if ($workers !== null && $workers > 1) {
                    throw new \RuntimeException('The selected browser ' . $this->selectedBrowser . ' does not support parallel sessions.');
                }
                $workers = 1;
            }
        } catch (\Throwable $error) {
            try {
                $this->report->runError($error, 'preparation');
                return $this->report->summary();
            } finally {
                $this->report = null;
            }
        }

        $this->report->record->environment(['workers' => max(1, min($workers ?? 2, count($names))), 'browser' => $this->selectedBrowser]);
        if ($workers !== 1 && $names) {
            $selected = [];
            foreach ($names as $name) {
                $selected[$name] = $files[$name];
            }
            try {
                return (new Test\Parallel())->run($selected, min($workers ?? 2, count($names)), [
                    'root' => $root, 'cases' => $directory, 'runDirectory' => $this->runDirectory,
                    'capturePolicy' => $this->capturePolicy,
                    'browser' => $this->selectedBrowser, 'headless' => $headless, 'bootstrap' => $this->bootstrapped,
                    'selectors' => $selectors, 'capabilities' => $this->browserCapabilities,
                    'settings' => json_encode(Settings::export(), JSON_THROW_ON_ERROR),
                ], $this->report);
            } finally {
                $this->report = null;
            }
        }
        try {
            foreach ($names as $name) {
                $result = $this->executeFile($name, $files[$name]);
                $this->event(['type' => 'result', 'result' => $result]);
            }
            return $this->report->summary();
        } finally {
            $this->report = null;
        }
    }

    /** Reporting must also work when Composer or application initialization has failed. */
    private function startReport(string $savePath, array $environment): void
    {
        foreach (['Record', 'Diagnostic', 'Report'] as $class) {
            require_once __DIR__ . '/Test/' . $class . '.php';
        }
        $root = realpath($this->pathRoot) ?: $this->pathRoot;
        $this->diagnostic = new Test\Diagnostic($root);
        try {
            if (!is_dir($savePath) && !@mkdir($savePath, 0777, true)) {
                throw new \RuntimeException('Could not create test save directory: ' . $savePath);
            }
            $this->runDirectory = $savePath . '/' . date('Y-m-d_H-i-s');
            if (!@mkdir($this->runDirectory)) {
                throw new \RuntimeException('Could not create run directory (it may already exist): ' . $this->runDirectory);
            }
        } catch (\Throwable $error) {
            echo 'ERROR Could not initialize test report: ' . $error->getMessage() . PHP_EOL;
            throw $error;
        }
        $this->report = new Test\Report($this->runDirectory, $environment + [
            'root' => $root, 'project' => null, 'php' => PHP_VERSION, 'os' => php_uname(),
            'workers' => null, 'browser' => null, 'headless' => true, 'url' => null,
            'browserVersion' => null, 'platform' => null,
        ], class_exists(Settings::class, false) && (bool) Settings::fetch('test.console.steps'),
            class_exists(Settings::class, false) ? Settings::fetch('test.console.screenshots.columns') : null);
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

    /** Run-level defaults; a suite can change its own copy in configure(). */
    public function browserCapabilities(): array
    {
        return $this->browserCapabilities;
    }

    /** Settings replace automatic preferences; an explicit browser wins. Non-browser checks may have no ready pair. */
    protected function resolveBrowser(?string $browser): ?string
    {
        $preference = WebDriver\WebDriver::PREFERENCE;
        if ($browser !== null && !in_array($browser, $preference, true)) {
            throw new \InvalidArgumentException('Unknown browser: ' . $browser);
        }
        if ($browser === null) {
            $preference = Settings::fetch('test.browser.driver') ?? $preference;
            if (!is_array($preference) || !array_is_list($preference)
                || array_any($preference, fn ($name) => !is_string($name) || !in_array($name, WebDriver\WebDriver::PREFERENCE, true))) {
                throw new \InvalidArgumentException('test.browser.driver must be a list of supported browser names: '
                    . implode(', ', WebDriver\WebDriver::PREFERENCE) . '.');
            }
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
        $this->diagnostic = new Test\Diagnostic($job['root']);
        $this->runDirectory = $job['runDirectory'];
        $this->capturePolicy = $job['capturePolicy'];
        $this->selectedBrowser = $job['browser'];
        $this->browserResolved = true;
        $this->headless = $job['headless'];
        $this->browserCapabilities = $job['capabilities'];
        $this->selectors = array_map(fn ($selector) => new Test\Selector($selector), $job['selectors']);
        $this->event(['type' => 'activity', 'activity' => 'application bootstrap started']);
        if ($job['bootstrap']) {
            $this->bootstrap();
        } else {
            define('PATH_ROOT', $this->pathRoot);
            require_once PATH_ROOT . '/vendor/autoload.php';
        }
        Settings::set('', json_decode($job['settings'], flags: JSON_THROW_ON_ERROR));
        $this->event(['type' => 'activity', 'activity' => 'application bootstrap finished']);
        $result = $this->executeFile($job['name'], $job['file']);
        $this->event(['type' => 'result', 'result' => $result]);
        return $result['checksFailed'] ? 1 : 0;
    }

    /** Load a check factory per suite attempt; only the final attempt contributes to totals. */
    private function executeFile(string $name, string $path): array
    {
        $this->currentCheck = $name;
        $this->checkId = $name;
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
                $this->suiteAttempt = $attempt;
                $this->caseMethod = null;
                $this->phase = 'suite-setup';
                $this->hook = null;
                $this->ordinal = 0;
                $this->screenshotSource = null;
                $this->logBrowsers = $this->logErrors = [];
                $this->networkCollectors = $this->networkErrors = $this->interceptionCursor = [];
                $this->failureCapture = null;
                $attemptStarted = hrtime(true);
                $this->event(['type' => 'suite_started', 'file' => $this->diagnostic->path($path)]);
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
                            $this->executionError(new \RuntimeException(sprintf('Suite deadline exceeded: %.3fs > %.3fs.', $duration, $metadata->deadline)));
                        }
                    } else {
                        if (!is_callable($check)) {
                            throw new \RuntimeException('Check file must return a callable or Suite.');
                        }
                        $this->event(['type' => 'configured', 'name' => $name, 'idleTimeout' => null, 'cases' => []]);
                        $filtered = $selectors && !array_any($selectors, fn ($selector) => $selector->test(null, []));
                        if (!$filtered) {
                            $this->phase = 'test';
                            $this->event(['type' => 'activity', 'activity' => 'callable check']);
                            $check($this);
                        }
                    }
                } catch (Test\AssertionFailure $error) {
                    // assert() already recorded this failure before stopping the check.
                } catch (\Throwable $error) {
                    $this->executionError($error);
                }
                $this->collectBrowserLogs();
                $this->collectBrowserNetwork();
                $failed = $casesFailed > 0 || $this->failures;
                $this->event(['type' => 'suite_attempt_finished', 'status' => $failed ? 'FAIL' : ($skipped ? 'SKIP' : ($filtered ? 'FILTERED' : 'PASS')),
                    'duration' => (hrtime(true) - $attemptStarted) / 1e9, 'maxAttempts' => $retry + 1]);
                if (!$failed || $attempt > $retry || $skipped || $filtered) {
                    break;
                }
                $this->event(['type' => 'retry', 'scope' => 'suite', 'attempt' => $attempt,
                    'failures' => $this->failures]);
                unset($check);
            } while (true);
        } finally {
            $this->screenshotSource = null;
            $this->logBrowsers = $this->logErrors = [];
            $this->networkCollectors = $this->networkErrors = $this->interceptionCursor = [];
            $this->failureCapture = null;
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
        $message += ['suiteAttempt' => $this->suiteAttempt, 'time' => Test\Record::now(), 'clock' => hrtime(true) / 1e9];
        if ($this->caseMethod !== null) {
            $message += ['method' => $this->caseMethod, 'caseAttempt' => $this->caseAttempt];
        }
        if ($this->channel !== null) {
            $this->channel->send($message);
        } else {
            $this->report?->event($this->checkId, $message);
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
        $definitions = array_map(fn ($case) => ['method' => $case['method']->getName(), 'name' => $case['name'],
            'maxAttempts' => $case['retry'] + 1,
            'location' => $this->diagnostic->location($case['method']->getFileName(), $case['method']->getStartLine())], $cases);
        $class = new \ReflectionObject($suite);
        $location = $this->diagnostic->location($class->getFileName(), $class->getStartLine());
        $skipped = !$filtered && ($metadata->skip || ($cases && array_all($cases, fn ($case) => $case['skip'])));
        if ($filtered || $skipped) {
            $this->event(['type' => 'configured', 'name' => $metadata->description, 'idleTimeout' => null,
                'cases' => array_column($cases, 'name'), 'definitions' => $definitions, 'location' => $location,
                'maxAttempts' => $metadata->retry + 1]);
            foreach ($cases as $case) {
                $this->event(['type' => 'case_finished', 'name' => $case['name'], 'status' => 'SKIP',
                    'method' => $case['method']->getName(), 'reason' => 'marked skip', 'duration' => 0]);
            }
            return [0, 0, count($cases), $skipped, $filtered];
        }
        $casesPassed = $casesFailed = $casesSkipped = $assertionsPassed = $assertionsFailed = 0;
        $suiteState = [$this->passed, $this->failed, $this->failures];
        $suiteOrdinal = $this->ordinal;
        try {
            $ready = false;
            $idleTimeout = null;
            try {
                $config = $suite->configuration();
                $idleTimeout = $config->idleTimeout;
                $ready = true;
            } catch (\Throwable $error) {
                $this->executionError($error, 'Suite configuration');
            }
            $this->event(['type' => 'configured', 'name' => $metadata->description, 'idleTimeout' => $idleTimeout,
                'cases' => array_column($cases, 'name'), 'definitions' => $definitions, 'location' => $location,
                'maxAttempts' => $metadata->retry + 1]);
            if ($ready) {
                $ready = $this->runHooks($suite, $methods['suite-setup'], 'suite-setup');
            }
            $suiteState = [$this->passed, $this->failed, $this->failures];
            $suiteOrdinal = $this->ordinal;
            foreach ($cases as $case) {
                if ($case['skip'] || !$ready || ($config->skipRemainingOnFailure && $casesFailed > 0)) {
                    $casesSkipped++;
                    $this->event(['type' => 'case_finished', 'name' => $case['name'], 'status' => 'SKIP',
                        'method' => $case['method']->getName(),
                        'reason' => $case['skip'] ? 'marked skip' : ($ready ? 'previous case failed' : 'suite setup failed'), 'duration' => 0]);
                    continue;
                }

                $started = hrtime(true);
                $this->caseMethod = $case['method']->getName();
                $this->event(['type' => 'case_started', 'name' => $case['name']]);
                $attempt = 0;
                do {
                    $attempt++;
                    $this->caseAttempt = $attempt;
                    $this->phase = 'case-setup';
                    $this->hook = null;
                    $this->ordinal = 0;
                    $this->failureCapture = null;
                    $attemptStarted = hrtime(true);
                    $this->event(['type' => 'case_attempt_started', 'name' => $case['name']]);
                    $this->passed = $this->failed = 0;
                    $this->failures = [];
                    try {
                        if ($this->runHooks($suite, $methods['case-setup'], 'case-setup')) {
                            $this->phase = 'test';
                            $this->event(['type' => 'activity', 'activity' => 'case test: ' . $case['name']]);
                            $bodyStarted = hrtime(true);
                            $this->invokeSuiteMethod($suite, $case['method']);
                            $duration = (hrtime(true) - $bodyStarted) / 1e9;
                            if ($case['deadline'] !== null && $duration > $case['deadline']) {
                                $this->executionError(new \RuntimeException(sprintf('Case deadline exceeded: %.3fs > %.3fs.', $duration, $case['deadline'])));
                            }
                            $this->event(['type' => 'activity', 'activity' => 'case test finished: ' . $case['name'], 'duration' => $duration]);
                        }
                    } finally {
                        $this->captureCompletion('cases');
                        $beforeCleanup = count($this->failures);
                        $this->runHooks($suite, $methods['case-teardown'], 'case-teardown', teardown: true);
                        if (count($this->failures) > $beforeCleanup) {
                            $this->captureCompletion('cases');
                        }
                    }
                    $this->collectBrowserLogs();
                    $this->collectBrowserNetwork();
                    $this->event(['type' => 'case_attempt_finished', 'status' => $this->failures ? 'FAIL' : 'PASS',
                        'duration' => (hrtime(true) - $attemptStarted) / 1e9]);
                    if (!$this->failures || $attempt > $case['retry']) {
                        break;
                    }
                    $this->event(['type' => 'retry', 'scope' => 'case', 'name' => $case['name'],
                        'attempt' => $attempt, 'failures' => $this->failures]);
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
                $this->caseMethod = null;
            }
        } finally {
            $this->caseMethod = null;
            $this->phase = 'suite-teardown';
            $this->hook = null;
            $this->ordinal = $suiteOrdinal;
            $this->failureCapture = null;
            [$this->passed, $this->failed, $this->failures] = $suiteState;
            $this->captureCompletion('suites', $casesFailed > 0);
            $this->runHooks($suite, $methods['suite-teardown'], 'suite-teardown', teardown: true);
            $this->passed += $assertionsPassed;
            $this->failed += $assertionsFailed;
        }
        return [$casesPassed, $casesFailed, $casesSkipped, false, false];
    }

    /** Stop setup at its first thrown failure; attempt every teardown hook independently. */
    private function runHooks(Test\Suite $suite, array $hooks, string $phase, bool $teardown = false): bool
    {
        $this->collectBrowserLogs();
        $this->collectBrowserNetwork();
        $this->phase = $phase;
        $label = match ($phase) {
            'suite-setup' => 'Before', 'case-setup' => 'Before(case: true)',
            'case-teardown' => 'After(case: true)', 'suite-teardown' => 'After',
        };
        foreach ($hooks as $hook) {
            $this->hook = $label . ' ' . $hook['name'];
            $start = count($this->failures);
            $started = hrtime(true);
            $this->event(['type' => 'activity', 'activity' => $label . ' ' . $hook['name']]);
            $completed = $this->invokeSuiteMethod($suite, $hook['method']);
            $this->collectBrowserLogs();
            $this->collectBrowserNetwork();
            $this->hook = null;
            $this->event(['type' => 'activity', 'activity' => $label . ' ' . $hook['name'] . ' finished',
                'duration' => (hrtime(true) - $started) / 1e9]);
            for ($index = $start; $index < count($this->failures); $index++) {
                $this->failures[$index] = $label . ' ' . $hook['name'] . ': ' . $this->failures[$index];
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
            $this->executionError($error);
            return false;
        }
    }
}
