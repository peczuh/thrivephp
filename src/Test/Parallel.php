<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP\Test;

/** @internal Bounded fresh-process scheduler. Only structured test events travel over TCP. */
final class Parallel
{
    private const CONNECT_TIMEOUT = 60;
    private array $workers = [];
    private array $connections = [];
    private mixed $listener = null;
    private Report $report;

    public function run(array $files, int $limit, array $configuration): int
    {
        $this->report = new Report();
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
                    unset($files[$name]);
                    $token = bin2hex(random_bytes(16));
                    $process = new WorkerProcess($php, $address, $token, $job);
                    $this->workers[$token] = ['process' => $process, 'name' => $name, 'label' => $name,
                        'started' => hrtime(true) / 1e9, 'lastProgress' => hrtime(true) / 1e9,
                        'connection' => null, 'connected' => false, 'configured' => false,
                        'idleTimeout' => null, 'activity' => 'connecting', 'cases' => [], 'activeCase' => null,
                        'error' => null, 'result' => null, 'hasSuites' => false,
                        'casesPassed' => 0, 'casesFailed' => 0, 'casesSkipped' => 0,
                        'assertionsPassed' => 0, 'assertionsFailed' => 0];
                    echo "RUN $name (worker {$process->pid})" . PHP_EOL;
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
        switch ($message['type']) {
            case 'configured':
                $timeout = $message['idleTimeout'];
                if ($worker['configured'] || !is_string($message['name'] ?? null)
                    || ($timeout !== null && (!is_numeric($timeout) || !is_finite((float) $timeout) || $timeout <= 0))
                    || !is_array($message['cases']) || !array_is_list($message['cases'])
                    || array_any($message['cases'], fn ($name) => !is_string($name))) {
                    throw new \RuntimeException('Invalid suite configuration.');
                }
                $worker['configured'] = true;
                $worker['label'] = $message['name'];
                $worker['idleTimeout'] = $timeout;
                $worker['cases'] = $message['cases'];
                $worker['hasSuites'] = $message['cases'] !== [];
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
                $worker[$message['passed'] ? 'assertionsPassed' : 'assertionsFailed']++;
                break;
            case 'case_started':
                if ($worker['activeCase'] !== null || ($worker['cases'][0] ?? null) !== ($message['name'] ?? null)) {
                    throw new \RuntimeException('Unexpected case start.');
                }
                $worker['activeCase'] = $message['name'];
                $worker['caseAssertions'] = [$worker['assertionsPassed'], $worker['assertionsFailed']];
                $worker['activity'] = 'case setup: ' . $message['name'];
                echo 'RUN ' . $worker['label'] . ' > ' . $message['name'] . PHP_EOL;
                break;
            case 'case_finished':
                if (!isset($worker['cases'][0]) || $worker['cases'][0] !== ($message['name'] ?? null)
                    || !in_array($message['status'] ?? null, ['PASS', 'FAIL', 'SKIP'], true)) {
                    throw new \RuntimeException('Unexpected case result.');
                }
                array_shift($worker['cases']);
                $worker['activeCase'] = null;
                $worker[match ($message['status']) { 'PASS' => 'casesPassed', 'FAIL' => 'casesFailed', 'SKIP' => 'casesSkipped' }]++;
                $worker['activity'] = 'case finished: ' . $message['name'];
                $this->report->caseResult($worker['label'], $message);
                break;
            case 'retry':
                if (!is_int($message['attempt'] ?? null) || $message['attempt'] < 2 || !is_array($message['failures'] ?? null)) {
                    throw new \RuntimeException('Invalid retry result.');
                }
                if ($message['scope'] === 'case') {
                    if ($worker['activeCase'] === null || $worker['activeCase'] !== ($message['name'] ?? null)) {
                        throw new \RuntimeException('Unexpected case retry.');
                    }
                    // Keep completed cases and suite-hook assertions when discarding this attempt.
                    [$worker['assertionsPassed'], $worker['assertionsFailed']] = $worker['caseAssertions'];
                } elseif ($message['scope'] === 'suite') {
                    if ($worker['activeCase'] !== null || $worker['cases']) {
                        throw new \RuntimeException('Unexpected suite retry.');
                    }
                    $worker['configured'] = false;
                    $worker['hasSuites'] = false;
                    $worker['idleTimeout'] = null;
                    foreach (['casesPassed', 'casesFailed', 'casesSkipped', 'assertionsPassed', 'assertionsFailed'] as $key) {
                        $worker[$key] = 0;
                    }
                } else {
                    throw new \RuntimeException('Unknown retry scope.');
                }
                $worker['activity'] = $message['scope'] . ' retry';
                $this->report->retry($worker['label'], $message);
                break;
            case 'result':
                $result = $message['result'] ?? null;
                if (!is_array($result) || !is_bool($result['hasSuites'] ?? null) || !is_array($result['failures'] ?? null)) {
                    throw new \RuntimeException('Invalid terminal result.');
                }
                foreach (['checksPassed', 'checksFailed', 'checksSkipped', 'casesPassed', 'casesFailed', 'casesSkipped', 'assertionsPassed', 'assertionsFailed'] as $key) {
                    if (!is_int($result[$key] ?? null) || $result[$key] < 0 || (isset($worker[$key]) && $result[$key] !== $worker[$key])) {
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
            default:
                throw new \RuntimeException('Unknown message type: ' . $message['type']);
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
            if ($worker['activeCase'] !== null) {
                $this->report->caseResult($worker['label'], ['name' => array_shift($worker['cases']), 'status' => 'FAIL',
                    'failures' => [$worker['error']]]);
                $worker['casesFailed']++;
            }
            foreach ($worker['cases'] as $case) {
                $this->report->caseResult($worker['label'], ['name' => $case, 'status' => 'SKIP', 'reason' => 'worker failed']);
                $worker['casesSkipped']++;
            }
            $result = array_intersect_key($worker, array_flip(['hasSuites', 'casesPassed', 'casesFailed', 'casesSkipped', 'assertionsPassed', 'assertionsFailed']));
            $result += ['checksPassed' => 0, 'checksFailed' => 1, 'checksSkipped' => 0, 'name' => $worker['label'],
                'failures' => [...($worker['result']['failures'] ?? []), $worker['error']]];
        } else {
            $result = $worker['result'];
        }
        $this->report->result($worker['name'], $result);
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
