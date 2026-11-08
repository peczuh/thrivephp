<?php
	namespace ThriveData\ThrivePHP\WebDriver;

	/** Retains original interception failures and subsequent resolution/cleanup errors across checkpoints. */
	final class NetworkError extends \RuntimeException
	{
		private array $failures = [];

		/** @internal The first error remains the exception cause even when recovery also fails. */
		public function __construct(\Throwable $error, array $request = [])
		{
			parent::__construct('Network interception failed.', 0, $error);
			$this->add($error, $request);
		}

		/** @internal Append a failure; request correlation is absent for controller/transport failures. */
		public function add(\Throwable $error, array $request = []): void
		{
			$this->failures[] = ['requestId' => $request['requestId'] ?? null, 'redirectCount' => $request['redirectCount'] ?? null,
				'phase' => $request['phase'] ?? null, 'error' => $error];
			$this->message .= "\n" . (isset($request['requestId']) ? $request['requestId'] . ' hop ' . $request['redirectCount'] . ': ' : '') . $error->getMessage();
		}

		/** Arrival-ordered {requestId, redirectCount, phase, error} entries; error is the original Throwable. */
		public function failures(): array { return $this->failures; }
	}
