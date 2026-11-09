<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP\Test;

/** @internal Bounded fresh-process scheduler. Jobs and structured test events travel over TCP. */
final class Parallel
{
    private const CONNECT_TIMEOUT = 60;
    private array $workers = [];
    private array $connections = [];
    private mixed $listener = null;
    private Report $report;

    public function run(array $files, int $limit, array $configuration, Report $report): int
    {
        $this->report = $report;
        $diagnostic = new Diagnostic($configuration['root']);
        $signals = [];
        $async = null;
        $reference = \WeakReference::create($this);
        register_shutdown_function(static fn () => $reference->get()?->cleanup());
        try {
            $this->listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
            if ($this->listener === false) {
                throw new \RuntimeException('Could not open worker listener: ' . $error);
            }
            stream_set_blocking($this->listener, false);
            $address = 'tcp://' . stream_socket_get_name($this->listener, false);
            $php = WorkerProcess::phpCommand();
            // PCNTL is optional; where available, Ctrl-C/termination takes the same cleanup path.
            if (function_exists('pcntl_async_signals')) {
                $async = pcntl_async_signals(true);
                foreach ([SIGINT, SIGTERM] as $signal) {
                    $signals[$signal] = pcntl_signal_get_handler($signal);
                    pcntl_signal($signal, static function (int $signal): void {
                        throw new \RuntimeException('Test run interrupted by signal ' . $signal . '.');
                    });
                }
            }
            $lastSnapshot = 0;
            while ($files || $this->workers) {
                while ($files && count($this->workers) < $limit) {
                    $name = array_key_first($files);
                    $job = $configuration + ['name' => $name, 'file' => $files[$name]];
                    $token = bin2hex(random_bytes(16));
                    $process = new WorkerProcess($php, $address, $token);
                    unset($files[$name]);
                    $this->workers[$token] = ['process' => $process, 'name' => $name, 'label' => $name, 'job' => $job,
                        'started' => hrtime(true) / 1e9, 'lastProgress' => hrtime(true) / 1e9,
                        'connection' => null, 'connected' => false, 'configured' => false,
                        'idleTimeout' => null, 'activity' => 'connecting', 'cases' => [], 'activeCase' => null,
                        'error' => null, 'result' => null];
                    $this->report->event($name, ['type' => 'suite_started', 'file' => $diagnostic->path($job['file']), 'worker' => $process->pid]);
                }
                $read = [$this->listener];
                foreach ($this->connections as $connection) {
                    $read[] = $connection['channel']->stream;
                }
                $write = $except = null;
                $ready = @stream_select($read, $write, $except, 0, 100000);
                if ($ready === false) {
                    throw new \RuntimeException('Could not monitor worker connections.');
                }
                foreach ($read as $stream) {
                    if ($stream === $this->listener) {
                        $this->acceptConnections();
                    } else {
                        $this->readConnection((int) $stream);
                    }
                }
                $now = hrtime(true) / 1e9;
                if ($now - $lastSnapshot >= 0.25) {
                    $snapshot = WorkerProcess::snapshot();
                    foreach ($this->workers as $worker) {
                        $worker['process']->track($snapshot);
                    }
                    $lastSnapshot = $now;
                }
                foreach (array_keys($this->workers) as $token) {
                    $worker = &$this->workers[$token];
                    if (!$worker['process']->running()) {
                        // A fast worker can exit after select() but before its connection is accepted.
                        $this->acceptConnections();
                        foreach (array_keys($this->connections) as $connection) {
                            if ($this->connections[$connection]['token'] === null) {
                                $this->readConnection($connection);
                            }
                        }
                        // Exit can precede socket consumption. Drain all buffered messages before
                        // deciding whether the worker produced a complete terminal result.
                        $id = $worker['connection'];
                        while ($id !== null && isset($this->connections[$id])) {
                            $channel = $this->connections[$id]['channel'];
                            $this->readConnection($id);
                            if ($channel->bytesRead === 0) {
                                break;
                            }
                        }
                        $code = $worker['process']->exitCode();
                        if ($worker['result'] === null) {
                            $worker['error'] ??= 'Worker exited without a complete result (exit ' . $code . ').';
                        } elseif ($code !== ($worker['result']['checksFailed'] ? 1 : 0)) {
                            $worker['error'] ??= 'Worker exit status ' . $code . ' disagrees with its result.';
                        }
                        $this->finish($token);
                    } elseif (!$worker['connected'] && $now - $worker['started'] >= self::CONNECT_TIMEOUT) {
                        $worker['error'] = 'Worker did not connect within ' . self::CONNECT_TIMEOUT . ' seconds.';
                        $this->finish($token);
                    } elseif ($worker['error'] !== null) {
                        $this->finish($token);
                    } elseif ($worker['idleTimeout'] !== null && $now - $worker['lastProgress'] >= $worker['idleTimeout']) {
                        $worker['error'] = sprintf('Worker inactivity timeout after %.3fs without progress. Last activity: %s. Worker %d.',
                            $now - $worker['lastProgress'], $worker['activity'], $worker['process']->pid);
                        $this->finish($token);
                    }
                    unset($worker);
                }
                foreach ($this->connections as $id => $connection) {
                    if ($connection['token'] === null && $now - $connection['accepted'] >= self::CONNECT_TIMEOUT) {
                        $this->disconnect($id);
                    }
                }
            }
            return $this->report->summary();
        } catch (\Throwable $error) {
            echo 'ERROR ' . $error->getMessage() . PHP_EOL;
            foreach ($this->workers as $worker) {
                $this->report->interrupt($worker['name'], $error->getMessage() . ' Suite incomplete.');
            }
            foreach ($files as $name => $file) {
                $this->report->event($name, ['type' => 'suite_started', 'file' => $diagnostic->path($file)]);
                $this->report->interrupt($name, 'Run interrupted before worker startup.');
            }
            $this->report->summary();
            return 1;
        } finally {
            $this->cleanup();
            foreach ($signals as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            if ($async !== null) {
                pcntl_async_signals($async);
            }
        }
    }

    private function readConnection(int $id): void
    {
        $connection = &$this->connections[$id];
        try {
            foreach ($connection['channel']->receive() as $message) {
                if ($connection['token'] === null) {
                    $token = $message['token'] ?? '';
                    if ($message['type'] !== 'hello' || !isset($this->workers[$token]) || $this->workers[$token]['connected']) {
                        throw new \RuntimeException('Unknown worker connection.');
                    }
                    $connection['token'] = $token;
                    $this->workers[$token]['connection'] = $id;
                    $this->workers[$token]['connected'] = true;
                    $this->workers[$token]['lastProgress'] = hrtime(true) / 1e9;
                    $this->workers[$token]['activity'] = 'connected';
                    // The worker is waiting to read its job. Keep settings out of process arguments.
                    stream_set_blocking($connection['channel']->stream, true);
                    stream_set_timeout($connection['channel']->stream, self::CONNECT_TIMEOUT);
                    try {
                        $connection['channel']->send(['type' => 'job', 'job' => $this->workers[$token]['job']]);
                    } finally {
                        stream_set_blocking($connection['channel']->stream, false);
                    }
                    unset($this->workers[$token]['job']);
                    continue;
                }
                $this->message($connection['token'], $message);
            }
            if (feof($connection['channel']->stream)) {
                $token = $connection['token'];
                if ($token !== null && $this->workers[$token]['result'] === null) {
                    $this->workers[$token]['error'] ??= 'Worker disconnected before reporting completion.';
                }
                $this->disconnect($id);
            }
        } catch (\Throwable $error) {
            if ($connection['token'] !== null) {
                $this->workers[$connection['token']]['error'] = 'Worker protocol error: ' . $error->getMessage();
            }
            $this->disconnect($id);
        }
    }

    private function acceptConnections(): void
    {
        while (($socket = @stream_socket_accept($this->listener, 0)) !== false) {
            stream_set_blocking($socket, false);
            $this->connections[(int) $socket] = ['channel' => new Channel($socket),
                'token' => null, 'accepted' => hrtime(true) / 1e9];
        }
    }

    private function message(string $token, array $message): void
    {
        $worker = &$this->workers[$token];
        if ($worker['result'] !== null) {
            throw new \RuntimeException('Message received after the final result.');
        }
        if (!is_int($message['suiteAttempt'] ?? null) || $message['suiteAttempt'] < 1
            || !is_string($message['time'] ?? null) || !is_numeric($message['clock'] ?? null) || !is_finite((float) $message['clock'])
            || (isset($message['method']) && !is_string($message['method']))
            || (isset($message['caseAttempt']) && (!is_int($message['caseAttempt']) || $message['caseAttempt'] < 1))) {
            throw new \RuntimeException('Invalid event identity or timing.');
        }
        switch ($message['type']) {
            case 'configured':
                $timeout = $message['idleTimeout'];
                if ($worker['configured'] || !is_string($message['name'] ?? null)
                    || ($timeout !== null && (!is_numeric($timeout) || !is_finite((float) $timeout) || $timeout <= 0))
                    || !is_array($message['cases']) || !array_is_list($message['cases'])
                    || array_any($message['cases'], fn ($name) => !is_string($name))
                    || ($message['cases'] && (!is_array($message['definitions'] ?? null)
                        || count($message['definitions']) !== count($message['cases'])
                        || array_any($message['definitions'], fn ($case) => !is_array($case)
                            || !is_string($case['method'] ?? null) || !is_string($case['name'] ?? null))))) {
                    throw new \RuntimeException('Invalid suite configuration.');
                }
                $worker['configured'] = true;
                $worker['label'] = $message['name'];
                $worker['idleTimeout'] = $timeout;
                $worker['cases'] = $message['cases'];
                $worker['activity'] = 'suite configured';
                break;
            case 'activity':
                if (!is_string($message['activity'] ?? null)) {
                    throw new \RuntimeException('Invalid worker activity.');
                }
                $worker['activity'] = $message['activity'];
                break;
            case 'assertion':
                if (!is_bool($message['passed'] ?? null)) {
                    throw new \RuntimeException('Invalid assertion result.');
                }
                break;
            case 'case_started':
                if (!isset($message['method']) || $worker['activeCase'] !== null || ($worker['cases'][0] ?? null) !== ($message['name'] ?? null)) {
                    throw new \RuntimeException('Unexpected case start.');
                }
                $worker['activeCase'] = $message['name'];
                $worker['activity'] = 'case setup: ' . $message['name'];
                break;
            case 'case_finished':
                if (!isset($message['method']) || !isset($worker['cases'][0]) || $worker['cases'][0] !== ($message['name'] ?? null)
                    || !in_array($message['status'] ?? null, ['PASS', 'FAIL', 'SKIP'], true)) {
                    throw new \RuntimeException('Unexpected case result.');
                }
                array_shift($worker['cases']);
                $worker['activeCase'] = null;
                $worker['activity'] = 'case finished: ' . $message['name'];
                break;
            case 'retry':
                if (!is_int($message['attempt'] ?? null) || $message['attempt'] < 1 || !is_array($message['failures'] ?? null)) {
                    throw new \RuntimeException('Invalid retry result.');
                }
                if ($message['scope'] === 'case') {
                    if ($worker['activeCase'] === null || $worker['activeCase'] !== ($message['name'] ?? null)) {
                        throw new \RuntimeException('Unexpected case retry.');
                    }
                } elseif ($message['scope'] === 'suite') {
                    if ($worker['activeCase'] !== null || $worker['cases']) {
                        throw new \RuntimeException('Unexpected suite retry.');
                    }
                    $worker['configured'] = false;
                    $worker['idleTimeout'] = null;
                } else {
                    throw new \RuntimeException('Unknown retry scope.');
                }
                $worker['activity'] = $message['scope'] . ' retry';
                break;
            case 'result':
                $result = $message['result'] ?? null;
                if (!is_array($result) || !is_bool($result['hasSuites'] ?? null) || !is_array($result['failures'] ?? null)) {
                    throw new \RuntimeException('Invalid terminal result.');
                }
                $counts = $this->report->record->counts($worker['name']);
                foreach (array_keys(Record::TOTALS) as $key) {
                    if (!is_int($result[$key] ?? null) || $result[$key] < 0
                        || (!str_starts_with($key, 'checks') && $result[$key] !== $counts[$key])) {
                        throw new \RuntimeException('Inconsistent result count: ' . $key);
                    }
                }
                if (!is_bool($result['filtered'] ?? null)
                    || $result['checksPassed'] + $result['checksFailed'] + $result['checksSkipped'] !== ($result['filtered'] ? 0 : 1)
                    || $worker['cases']) {
                    throw new \RuntimeException('Incomplete terminal result.');
                }
                $worker['result'] = $result;
                $worker['activity'] = 'worker shutdown';
                break;
            case 'suite_started':
                if (!is_string($message['file'] ?? null)) {
                    throw new \RuntimeException('Invalid suite start.');
                }
                break;
            case 'suite_attempt_finished':
            case 'case_attempt_finished':
                if (!in_array($message['status'] ?? null, ['PASS', 'FAIL', 'SKIP', 'FILTERED'], true)
                    || !is_numeric($message['duration'] ?? null) || !is_finite((float) $message['duration']) || $message['duration'] < 0) {
                    throw new \RuntimeException('Invalid attempt completion.');
                }
                break;
            case 'case_attempt_started':
                if (!isset($message['method']) || $worker['activeCase'] !== ($message['name'] ?? null)) {
                    throw new \RuntimeException('Unexpected case attempt.');
                }
                break;
            case 'step_started':
            case 'step_updated':
            case 'step_finished':
                $step = $message['step'] ?? null;
                if (!is_array($step) || !is_string($step['id'] ?? null) || !is_string($step['label'] ?? null)
                    || !is_string($step['phase'] ?? null) || !in_array($step['status'] ?? null, ['RUN', 'PASS', 'FAIL'], true)
                    || !is_array($step['values'] ?? null) || !is_array($step['assertions'] ?? null)
                    || !is_array($step['screenshots'] ?? null) || !is_numeric($step['duration'] ?? null)
                    || !is_finite((float) $step['duration']) || $step['duration'] < 0) {
                    throw new \RuntimeException('Invalid step record.');
                }
                break;
            case 'session':
                if (!is_array($message['session'] ?? null) || !is_array($message['session']['capabilities'] ?? null)) {
                    throw new \RuntimeException('Invalid browser session.');
                }
                break;
            case 'browser_log':
                $log = $message['log'] ?? null;
                if (!is_array($log) || !is_array($log['entry'] ?? null) || !is_string($log['entry']['type'] ?? null)
                    || !is_string($log['observedAt'] ?? null) || !is_string($log['phase'] ?? null)
                    || !is_string($log['sessionId'] ?? null)) {
                    throw new \RuntimeException('Invalid browser log entry.');
                }
                break;
            case 'browser_network':
                $network = $message['network'] ?? null;
                if (!is_array($network) || !is_array($network['event'] ?? null)
                    || ($network['event']['type'] ?? null) !== 'event'
                    || !is_string($network['event']['method'] ?? null) || $network['event']['method'] === ''
                    || !is_array($network['event']['params'] ?? null)
                    || !is_string($network['sessionId'] ?? null) || !is_string($network['observedAt'] ?? null)
                    || !is_string($network['phase'] ?? null)
                    || !array_key_exists('hook', $network) || ($network['hook'] !== null && !is_string($network['hook']))
                    || !array_key_exists('stepId', $network) || ($network['stepId'] !== null && !is_string($network['stepId']))) {
                    throw new \RuntimeException('Invalid browser network event.');
                }
                break;
            case 'screenshot':
                if (!is_array($message['screenshot'] ?? null)
                    || (!is_string($message['screenshot']['path'] ?? null) && !is_string($message['screenshot']['diagnostic'] ?? null))) {
                    throw new \RuntimeException('Invalid screenshot reference.');
                }
                break;
            case 'error':
                if (!is_array($message['error'] ?? null) || !is_string($message['error']['message'] ?? null)) {
                    throw new \RuntimeException('Invalid error diagnostic.');
                }
                break;
            default:
                throw new \RuntimeException('Unknown message type: ' . $message['type']);
        }
        if ($message['type'] !== 'result') {
            $this->report->event($worker['name'], $message);
        }
        $worker['lastProgress'] = hrtime(true) / 1e9;
    }

    private function finish(string $token): void
    {
        $worker = &$this->workers[$token];
        $worker['process']->close();
        if ($worker['connection'] !== null) {
            $channel = $this->connections[$worker['connection']]['channel'];
            if (!$channel->complete()) {
                $worker['error'] ??= 'Worker exited with an incomplete message.';
            }
            $this->disconnect($worker['connection']);
        }
        if ($worker['error'] !== null) {
            $worker['error'] .= ' Suite incomplete.';
            $this->report->interrupt($worker['name'], $worker['error']);
        } else {
            $this->report->event($worker['name'], ['type' => 'result', 'result' => $worker['result']]);
        }
        unset($this->workers[$token]);
    }

    private function disconnect(int $id): void
    {
        if (!isset($this->connections[$id])) {
            return;
        }
        $token = $this->connections[$id]['token'];
        if ($token !== null && isset($this->workers[$token])) {
            $this->workers[$token]['connection'] = null;
        }
        $this->connections[$id]['channel']->close();
        unset($this->connections[$id]);
    }

    /** Shutdown, interruption, and ordinary exceptions all attempt cleanup of every owned tree. */
    public function cleanup(): void
    {
        foreach ($this->workers as $token => $worker) {
            try {
                $worker['process']->close();
                unset($this->workers[$token]);
            } catch (\Throwable $error) {
                fwrite(STDERR, 'Worker cleanup error: ' . $error->getMessage() . PHP_EOL);
            }
        }
        foreach (array_keys($this->connections) as $id) {
            $this->disconnect($id);
        }
        if (is_resource($this->listener)) {
            fclose($this->listener);
        }
        $this->listener = null;
    }
}
