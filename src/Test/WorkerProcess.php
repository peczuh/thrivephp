<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP\Test;

/** @internal Owns one suite process and tracks its descendants for crash/timeout recovery. */
final class WorkerProcess
{
    private mixed $process;
    public readonly int $pid;
    private ?int $exitCode = null;
    /** Check observed Unix descendants' start times before signaling their PIDs. */
    private array $descendants = [];
    private bool $group = false;

    public function __construct(array $php, string $address, string $token)
    {
        $command = [...$php, __DIR__ . '/worker.php', $address, $token];
        if (PHP_OS_FAMILY === 'Windows') {
            // The job-owning supervisor survives a PHP crash and closes the entire job on exit.
            $command = ['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
                '-File', __DIR__ . '/worker-windows.ps1', base64_encode(json_encode($command, JSON_THROW_ON_ERROR))];
        }
        $this->process = proc_open($command, [0 => ['null'], 1 => STDOUT, 2 => STDERR], $pipes, options: ['bypass_shell' => true]);
        if (!is_resource($this->process)) {
            throw new \RuntimeException('Could not start test worker.');
        }
        $this->pid = proc_get_status($this->process)['pid'];
    }

    /** Same interpreter, ini loading, environment, and effective ini values as the parent. */
    public static function phpCommand(): array
    {
        $command = [PHP_BINARY];
        if ($ini = php_ini_loaded_file()) {
            array_push($command, '-c', $ini);
        } elseif (!php_ini_scanned_files()) {
            $command[] = '-n';
        }
        // Normalized defaults are not always valid startup inputs, and replaying deprecated
        // settings emits warnings. Probe the same ini loading once, then forward differences.
        $probe = proc_open([...$command, '-r', 'echo json_encode(ini_get_all(null, false), JSON_THROW_ON_ERROR);'],
            [0 => ['null'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        if (!is_resource($probe)) {
            throw new \RuntimeException('Could not inspect worker PHP configuration.');
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($probe) !== 0) {
            throw new \RuntimeException('Could not inspect worker PHP configuration.');
        }
        $defaults = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        foreach (ini_get_all(null, false) as $name => $value) {
            if (($defaults[$name] ?? null) !== $value) {
                array_push($command, '-d', $name . '="' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], (string) $value) . '"');
            }
        }
        return $command;
    }

    public function running(): bool
    {
        if (!is_resource($this->process)) {
            return false;
        }
        $status = proc_get_status($this->process);
        if (!$status['running'] && $this->exitCode === null) {
            $this->exitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : ($status['signaled'] ? 128 + $status['termsig'] : -1);
        }
        return $status['running'];
    }

    public function exitCode(): ?int
    {
        $this->running();
        return $this->exitCode;
    }

    /** One system snapshot can serve every worker in the scheduler. */
    public static function snapshot(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return [];
        }
        $process = proc_open(['/bin/ps', '-axo', 'pid=,ppid=,pgid=,lstart=,stat='],
            [0 => ['null'], 1 => ['pipe', 'w'], 2 => ['null']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not inspect worker process trees.');
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException('Could not inspect worker process trees.');
        }
        $snapshot = [];
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^\s*(\d+)\s+(\d+)\s+(\d+)\s+(.+?)\s+(\S+)\s*$/', $line, $match)) {
                $snapshot[(int) $match[1]] = ['parent' => (int) $match[2], 'group' => (int) $match[3],
                    'start' => $match[4], 'alive' => !str_starts_with($match[5], 'Z')];
            }
        }
        return $snapshot;
    }

    /** Retain observed descendants even if they later detach or their immediate parent exits. */
    public function track(array $snapshot): void
    {
        if (array_any($snapshot, fn ($entry) => $entry['group'] === $this->pid)) {
            $this->group = true;
        }
        $owners = [$this->pid => true];
        foreach ($this->descendants as $pid => $start) {
            if (($snapshot[$pid]['start'] ?? null) === $start) {
                $owners[$pid] = true;
            }
        }
        do {
            $added = false;
            foreach ($snapshot as $pid => $entry) {
                if ($pid !== $this->pid && !isset($owners[$pid])
                    && (isset($owners[$entry['parent']]) || ($this->group && $entry['group'] === $this->pid))) {
                    $this->descendants[$pid] = $entry['start'];
                    $owners[$pid] = true;
                    $added = true;
                }
            }
        } while ($added);
    }

    /** Terminate survivors even after normal worker exit, then reap the direct child. */
    public function close(): void
    {
        if (!is_resource($this->process)) {
            return;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            if ($this->running()) {
                // The supervisor owns the sole job handle; its termination closes the entire job.
                proc_terminate($this->process);
            }
        } else {
            $until = hrtime(true) / 1e9 + 3;
            $force = hrtime(true) / 1e9 + 0.5;
            do {
                $snapshot = self::snapshot();
                $this->track($snapshot);
                $targets = [];
                foreach ($this->descendants as $pid => $start) {
                    if (($snapshot[$pid]['start'] ?? null) === $start && $snapshot[$pid]['alive']) {
                        $targets[] = $pid;
                    }
                }
                $running = $this->running();
                if (!$running && !$targets) {
                    break;
                }
                $signal = hrtime(true) / 1e9 >= $force ? 9 : 15;
                // The worker calls setsid before connecting when POSIX is available. Descendant
                // snapshots additionally cover observed children that create a different session.
                if ($this->group && ($running || array_any($snapshot, fn ($entry) => $entry['group'] === $this->pid && $entry['alive']))) {
                    self::signal(-$this->pid, $signal);
                }
                foreach (array_reverse($targets) as $pid) {
                    self::signal($pid, $signal);
                }
                if ($running) {
                    proc_terminate($this->process, $signal);
                }
                usleep(20000);
            } while (hrtime(true) / 1e9 < $until);
            if ($targets && hrtime(true) / 1e9 >= $until) {
                throw new \RuntimeException('Could not terminate all descendants of worker ' . $this->pid . '.');
            }
        }
        $until = hrtime(true) / 1e9 + 3;
        while ($this->running() && hrtime(true) / 1e9 < $until) {
            usleep(20000);
        }
        if ($this->running()) {
            throw new \RuntimeException('Could not terminate worker ' . $this->pid . '.');
        }
        proc_close($this->process);
        $this->process = null;
    }

    private static function signal(int $pid, int $signal): void
    {
        if (function_exists('posix_kill')) {
            @posix_kill($pid, $signal);
        } else {
            $process = proc_open(['/bin/kill', '-' . $signal, '--', (string) $pid],
                [0 => ['null'], 1 => ['null'], 2 => ['null']], $pipes);
            if (is_resource($process)) {
                proc_close($process);
            }
        }
    }
}
