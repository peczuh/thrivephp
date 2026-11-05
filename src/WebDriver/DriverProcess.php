<?php
	namespace ThriveData\ThrivePHP\WebDriver;

	/** @internal An owned child process with captured output and bounded termination. */
	class DriverProcess
	{
		private $process = null;
		private $log = null;
		private ?int $exitCode = null;
		/** Retain the last diagnostic output after closing the temporary log. */
		private string $output = '';

		/** Start an argument-vector command without a shell; close its unused stdin immediately. */
		public function __construct(array $command)
		{
			if (!function_exists('proc_open')):
				throw new \RuntimeException('PHP proc_open() is required to inspect or start WebDriver executables.');
			endif;
			// Convert syscall failure returns ourselves instead of invoking an application's warning handler.
			set_error_handler(static fn() => true, E_WARNING);
			try {
				$this->log = tmpfile();
				if ($this->log === false):
					throw new \RuntimeException('Could not create a temporary WebDriver process log.');
				endif;
				// File-backed output avoids full-pipe deadlocks, including on Windows.
				$this->process = proc_open($command, [0 => ['pipe', 'r'], 1 => $this->log, 2 => $this->log], $pipes, null, null, ['bypass_shell' => true]);
				if (!is_resource($this->process)):
					fclose($this->log);
					$this->log = null;
					$this->process = null;
					throw new \RuntimeException('Could not start executable: ' . $command[0]);
				endif;
			} finally {
				restore_error_handler();
			}
			fclose($pipes[0]);
		}

		/** Inspect child state, retaining the first exit status (PHP 8.0 reports it only once). */
		public function running(): bool
		{
			if ($this->process === null):
				return false;
			endif;
			$status = proc_get_status($this->process);
			if (!$status['running'] && $this->exitCode === null):
				$this->exitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : ($status['signaled'] ? 128 + $status['termsig'] : -1);
			endif;
			return $status['running'];
		}

		/** Read at most the last 64 KiB without changing the child's output-file position. */
		public function output(): string
		{
			if (!is_resource($this->log)):
				return $this->output;
			endif;
			$reader = @fopen(stream_get_meta_data($this->log)['uri'], 'rb');
			if ($reader === false):
				return $this->output;
			endif;
			try {
				$size = fstat($reader)['size'];
				fseek($reader, max(0, $size - 65536));
				$output = stream_get_contents($reader, 65536);
				$this->output = $output === false ? '' : $output;
			} finally {
				fclose($reader);
			}
			return $this->output;
		}

		/**
		 * Terminate, escalate if necessary, then reap. $timeout is positive seconds for
		 * process termination, not HTTP session cleanup. Retain handles if termination
		 * fails so the owner can retry rather than blocking indefinitely in proc_close().
		 */
		public function stop(float $timeout = 3): void
		{
			if (!is_finite($timeout) || $timeout <= 0):
				throw new \InvalidArgumentException('Process shutdown timeout must be positive and finite.');
			endif;
			if ($this->process !== null):
				$deadline = hrtime(true) / 1e9 + $timeout;
				if ($this->running()):
					$this->terminate(15);
					$this->waitUntil($deadline - $timeout / 2);
					if ($this->running()):
						$this->terminate(9);
						$this->waitUntil($deadline);
					endif;
				endif;
				if ($this->running()):
					throw new \RuntimeException('WebDriver process did not terminate within ' . $timeout . ' seconds.');
				endif;
				proc_close($this->process);
				$this->process = null;
			endif;
			if (is_resource($this->log)):
				$this->output();
				fclose($this->log);
				$this->log = null;
			endif;
		}

		/** Run a short discovery command; timeouts are returned separately from nonzero exits. */
		public static function run(array $command, float $timeout = 3): array
		{
			if (!is_finite($timeout) || $timeout <= 0):
				throw new \InvalidArgumentException('Process probe timeout must be positive and finite.');
			endif;
			$process = new self($command);
			try {
				$process->waitUntil(hrtime(true) / 1e9 + $timeout);
				$timedOut = $process->running();
				return ['exitCode' => $process->exitCode, 'output' => $process->output(), 'timedOut' => $timedOut];
			} finally {
				$process->stop();
			}
		}

		/** Poll without allowing a child to extend the caller's monotonic deadline. */
		private function waitUntil(float $deadline): void
		{
			while ($this->running() && ($remaining = $deadline - hrtime(true) / 1e9) > 0):
				usleep((int)(min(0.02, $remaining) * 1e6));
			endwhile;
		}

		/** A child may exit between the status check and signal; subsequent status determines success. */
		private function terminate(int $signal): void
		{
			set_error_handler(static fn() => true, E_WARNING);
			try {
				proc_terminate($this->process, $signal);
			} finally {
				restore_error_handler();
			}
		}

		/** Best-effort cleanup for probes and partially constructed runtime objects. */
		public function __destruct()
		{
			try {
				$this->stop();
			} catch (\Throwable $error) {
				error_log('WebDriver warning: ' . $error->getMessage());
			}
		}
	}
