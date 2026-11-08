<?php
	namespace ThriveData\ThrivePHP\WebDriver;

	/** Session-wide network metadata capture. Obtain the session's instance through Browser::network(). */
	final class Network
	{
		private const EVENTS = ['network.beforeRequestSent', 'network.responseStarted', 'network.responseCompleted', 'network.fetchError'];
		/** Only this collector's subscription and listeners are removed when capture stops. */
		private ?string $subscription = null;
		private array $listeners = [];
		/** Request ID and redirect count identify each hop; insertion order is first-observed order. */
		private array $hops = [];
		private ?\Throwable $failure = null;

		/** @internal Retain the originating session ID even after Browser releases its session. */
		public function __construct(private BiDi $connection, private string $sessionId) {}

		/** Start metadata capture across the session. Repeated starts are harmless; restarting appends. */
		public function startCapture(): self
		{
			$this->connection->assertCallable();
			if (!$this->connection->active()) throw new \LogicException('No active WebDriver BiDi connection.');
			if ($this->subscription !== null) return $this;
			try {
				$this->connection->drain();
				foreach (self::EVENTS as $method):
					$this->listeners[] = $this->connection->listen($method, function (array $params) use ($method): void {
						$key = json_encode([$params['request']['request'], $params['redirectCount']]);
						$this->hops[$key] ??= ['sessionId' => $this->sessionId, 'requestId' => $params['request']['request'],
							'redirectCount' => $params['redirectCount'], 'request' => null, 'response' => null, 'events' => []];
						$this->hops[$key]['request'] = $params['request'];
						if (isset($params['response'])) $this->hops[$key]['response'] = $params['response'];
						$this->hops[$key]['events'][] = ['type' => 'event', 'method' => $method, 'params' => $params];
					});
				endforeach;
				$result = $this->connection->command('session.subscribe', ['events' => self::EVENTS]);
				if (!is_string($result['subscription'] ?? null) || $result['subscription'] === '') throw new \RuntimeException('WebDriver BiDi did not return a subscription ID.');
				$this->subscription = $result['subscription'];
				$this->failure = null;
			} catch (\Throwable $error) {
				$this->failure = $error;
				$this->detach();
				throw $error;
			}
			return $this;
		}

		/** Stop only this collector, retaining history. Events observed while stopping are included. */
		public function stopCapture(): self
		{
			$this->connection->assertCallable();
			if ($this->subscription === null) return $this;
			try {
				$this->connection->command('session.unsubscribe', ['subscriptions' => [$this->subscription]]);
				$this->connection->drain();
			} catch (\Throwable $error) {
				$this->failure = $error;
				throw $error;
			} finally {
				$this->detach();
			}
			return $this;
		}

		/**
		 * Collect available events and return one value snapshot per observed redirect hop.
		 * Each record contains sessionId, requestId, redirectCount, latest request data,
		 * latest response data (or null), and arrival-ordered event envelopes. Protocol
		 * headers/byte values are preserved; bodies are not collected. Partial histories
		 * are normal. Reads never consume history or Browser::event() entries.
		 * The optional predicate receives each hop outside dispatch and retains matches.
		 * The operation detecting a collection failure throws; later reads retain history.
		 */
		public function requests(?callable $filter = null): array
		{
			$this->collect();
			return array_values($filter === null ? $this->hops : array_filter($this->hops, $filter));
		}

		/** @internal Collect before session cleanup without creating a test step or consuming raw events. */
		public function collect(): void
		{
			$this->connection->assertCallable();
			$this->captureError();
			if ($this->subscription !== null && $this->connection->active()):
				try {
					$this->connection->drain();
				} catch (\Throwable $error) {
					$this->failure = $error;
					throw $error;
				}
			endif;
		}

		/** Inspect capture failure without I/O. HTTP statuses and network.fetchError are diagnostic data. */
		public function captureError(): ?\Throwable
		{
			if ($this->subscription !== null && !$this->connection->active()):
				$this->failure ??= $this->connection->failure() ?? new \RuntimeException('Network collection lost its BiDi connection.');
			endif;
			return $this->failure;
		}

		/** @internal Release local capture ownership at session end; history and errors remain readable. */
		public function detach(): void
		{
			foreach ($this->listeners as $listener) $this->connection->unlisten($listener);
			$this->listeners = [];
			$this->subscription = null;
		}
	}
