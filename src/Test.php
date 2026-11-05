<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP;

/** Runs callable check files and reports accumulated assertions. */
class Test
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];
    private string $pathRoot;
    private string $pathChecks;

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
        } catch (\Throwable $error) {
            error_clear_last();
            fwrite(STDERR, 'ERROR Application initialization failed (' . get_class($error)
                . "). Check PHP requirements, Composer dependencies, and settings JSON.\n");
            exit(1);
        }
    }

    /** Record an assertion without throwing; return its condition for prerequisite guards. */
    public function assert(bool $condition, string $message): bool
    {
        if ($condition) {
            $this->passed++;
        } else {
            $this->failed++;
            $this->failures[] = $message;
        }

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
     * Check files return callables accepting this Test; their return values are ignored.
     *
     * @param list<string> $names Paths relative to checks/, without .php; empty selects all.
     */
    public function run(array $names = []): int
    {
        $directory = PATH_ROOT . '/' . trim($this->pathChecks, '/') . '/';
        $files = [];

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $path = $file->getPathname();
                    $name = substr($path, strlen($directory), -4);
                    $files[$name] = $path;
                }
            }

            ksort($files, SORT_STRING);
            $names = $names ? array_values(array_unique($names)) : array_keys($files);

            if (!$names) {
                throw new \RuntimeException('No checks found.');
            }

            foreach ($names as $name) {
                if (!isset($files[$name])) {
                    throw new \RuntimeException("Unknown check: $name");
                }
            }
        } catch (\Throwable $error) {
            echo 'ERROR ' . $error->getMessage() . PHP_EOL;
            return 1;
        }

        $checksPassed = $checksFailed = $assertionsPassed = $assertionsFailed = 0;

        foreach ($names as $name) {
            $this->passed = $this->failed = 0;
            $this->failures = [];

            set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
                if (!(error_reporting() & $severity)) {
                    return false;
                }

                throw new \ErrorException($message, 0, $severity, $file, $line);
            });

            try {
                // Keep variables declared by a check file out of the runner's scope.
                $check = (static fn (string $path) => require($path))($files[$name]);
                if (!is_callable($check)) {
                    throw new \RuntimeException('Check file must return a callable.');
                }

                $check($this);

                if ($this->passed + $this->failed === 0) {
                    $this->failures[] = 'No assertions ran.';
                }
            } catch (\Throwable $error) {
                $this->failures[] = 'Execution error: ' . get_class($error) . ': ' . $error->getMessage();
            } finally {
                restore_error_handler();
                // Application::init installs a shutdown handler that rethrows even suppressed errors.
                error_clear_last();
            }

            if ($this->failures) {
                $checksFailed++;
                echo "FAIL $name" . PHP_EOL;
                foreach ($this->failures as $message) {
                    echo '  ' . str_replace("\n", "\n  ", $message) . PHP_EOL;
                }
            } else {
                $checksPassed++;
                echo "PASS $name" . PHP_EOL;
            }

            $assertionsPassed += $this->passed;
            $assertionsFailed += $this->failed;
        }

        echo PHP_EOL . "Checks: $checksPassed passed, $checksFailed failed" . PHP_EOL;
        echo "Assertions: $assertionsPassed passed, $assertionsFailed failed" . PHP_EOL;

        return $checksFailed ? 1 : 0;
    }
}
