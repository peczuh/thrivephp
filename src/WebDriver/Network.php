<?php
	namespace ThriveData\ThrivePHP\WebDriver;

	/** Session-wide metadata capture and request interception. Obtain the instance through Browser::network(). */
	final class Network
	{
		private const EVENTS = ['network.beforeRequestSent', 'network.responseStarted', 'network.responseCompleted', 'network.fetchError'];
		/** Only this collector's subscription and listeners are removed when capture stops. */
		private ?string $subscription = null;
		private array $listeners = [];
		/** Request ID and redirect count identify each hop; insertion order is first-observed order. */
		private array $hops = [];
		private ?\Throwable $failure = null;
		/** Each phase subscribes lazily and keeps a passive responder until session cleanup. */
		private array $interceptSubscriptions = [];
		private array $interceptListeners = [];
		/** Remote ID => handler, or null while retiring. Removed IDs remain owned for late paused events. */
		private array $rules = [];
		private array $retired = [];
		private bool $installing = false;
		private array $provisional = [];
		/** Local occurrence => request identity/in-flight state; replies can follow a newer pause of the same request. */
		private array $paused = [];
		private array $current = [];
		private int $nextPause = 0;
		private ?NetworkError $interceptionFailure = null;
		private ?\Throwable $transportFailure = null;

		/** @internal Retain the originating session ID even after Browser releases its session. */
		public function __construct(private BiDi $connection, private string $sessionId) {}

		/** Start metadata capture across the session. Repeated starts are harmless; restarting appends. */
		public function startCapture(): self
		{
			$this->connection->assertCallable();
			if (!$this->connection->active()) throw new \LogicException('No active WebDriver BiDi connection.');
			if ($this->subscription !== null):
				\ThriveData\ThrivePHP\Test::active()?->watchBrowserNetwork($this);
				return $this;
			endif;
			try {
				$this->connection->drain();
				foreach (self::EVENTS as $method):
					$this->listeners[] = $this->connection->listen($method, function (array $params) use ($method): void {
						$key = json_encode([$params['request']['request'], $params['redirectCount']]);
						$this->hops[$key] ??= ['sessionId' => $this->sessionId, 'requestId' => $params['request']['request'],
							'redirectCount' => $params['redirectCount'], 'request' => null, 'response' => null, 'events' => []];
						$this->hops[$key]['request'] = $params['request'];
						if (isset($params['response'])) $this->hops[$key]['response'] = $params['response'];
						$event = ['type' => 'event', 'method' => $method, 'params' => $params];
						$this->hops[$key]['events'][] = $event;
						\ThriveData\ThrivePHP\Test::active()?->browserNetwork($this->sessionId, $event);
					});
				endforeach;
				$result = $this->connection->command('session.subscribe', ['events' => self::EVENTS]);
				if (!is_string($result['subscription'] ?? null) || $result['subscription'] === '') throw new \RuntimeException('WebDriver BiDi did not return a subscription ID.');
				$this->subscription = $result['subscription'];
				$this->failure = null;
				\ThriveData\ThrivePHP\Test::active()?->watchBrowserNetwork($this);
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
			$this->interceptionError();
			if (($this->subscription !== null || $this->interceptSubscriptions !== []) && $this->connection->active()):
				try {
					$this->connection->drain();
				} catch (\Throwable $error) {
					if ($this->subscription !== null) $this->failure = $error;
					if ($this->interceptSubscriptions !== []) $this->recordInterceptionFailure($error);
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

		/**
		 * Install an ordered managed rule. The handler receives blocked event params and must
		 * return NetworkDecision; browser reentry is forbidden. URL patterns use BiDi syntax,
		 * not globs. Supports beforeRequestSent and authRequired. First matching managed rule wins;
		 * manual controllers must not also decide managed-owned requests. Capture is independent.
		 */
		public function intercept(callable $handler, array $urlPatterns = [], string $phase = 'beforeRequestSent'): string
		{
			$this->connection->assertCallable();
			if (!in_array($phase, ['beforeRequestSent', 'authRequired'], true)) throw new \InvalidArgumentException('Only beforeRequestSent and authRequired interception are supported.');
			if (!isset($this->interceptSubscriptions[$phase])):
				$this->interceptListeners[$phase] = $this->connection->listen('network.' . $phase, fn ($params) => $this->onRequest($params, $phase));
				try {
					$result = $this->connection->command('session.subscribe', ['events' => ['network.' . $phase]]);
					if (!is_string($result['subscription'] ?? null) || $result['subscription'] === '') throw new \RuntimeException('WebDriver BiDi did not return a subscription ID.');
					$this->interceptSubscriptions[$phase] = $result['subscription'];
				} catch (\Throwable $error) {
					$this->connection->unlisten($this->interceptListeners[$phase]);
					unset($this->interceptListeners[$phase]);
					throw $error;
				}
			endif;
			\ThriveData\ThrivePHP\Test::active()?->watchBrowserNetwork($this);
			$this->installing = true;
			try {
				$result = $this->connection->command('network.addIntercept', ['phases' => [$phase], 'urlPatterns' => array_values($urlPatterns)]);
				$id = $result['intercept'] ?? null;
				if (!is_string($id) || $id === ''):
					$error = new \RuntimeException('WebDriver BiDi did not return an intercept ID; remote ownership is unknown.');
					$this->recordInterceptionFailure($error);
					throw $error;
				endif;
				$this->rules[$id] = \Closure::fromCallable($handler);
			} finally {
				$this->installing = false;
				$events = $this->provisional;
				$this->provisional = [];
			}
			foreach ($events as $event) $this->connection->decide(fn ($params) => $this->onRequest($params, $event['phase']), $event['params']);
			return $id;
		}

		/** Remove a managed rule. Late owned pauses continue unchanged or use browser-default auth, without its handler. */
		public function removeIntercept(string $id): self
		{
			$this->connection->assertCallable();
			if (isset($this->retired[$id])) return $this;
			if (!array_key_exists($id, $this->rules)) throw new \InvalidArgumentException('Unknown managed network intercept: ' . $id);
			try {
				$this->removeRule($id);
			} catch (\Throwable $error) {
				$this->recordInterceptionFailure($error);
				throw $error;
			}
			return $this;
		}

		private function removeRule(string $id, ?float $deadline = null): void
		{
			$this->rules[$id] = null;
			$this->connection->command('network.removeIntercept', ['intercept' => $id], $deadline);
			$this->retired[$id] = true;
			unset($this->rules[$id]);
		}

		/**
		 * Remove rules and settle known decisions under one command budget. Retain a passive
		 * responder until session cleanup for late owned pauses. Cleanup is best-effort on
		 * failure; deadline exhaustion invalidates BiDi, leaving HTTP quit available.
		 * Throws retained NetworkError after cleanup, including on repeated calls.
		 */
		public function stopInterception(): self
		{
			$this->connection->assertCallable();
			$deadline = $this->connection->deadline();
			foreach ($this->rules as &$handler) $handler = null;
			unset($handler);
			foreach (array_keys($this->rules) as $id):
				try {
					$this->removeRule($id, $deadline);
				} catch (\Throwable $error) {
					$this->recordInterceptionFailure($error);
					if (!$this->connection->active()) break;
				}
			endforeach;
			if ($this->interceptSubscriptions !== [] && $this->connection->active()):
				try {
					do {
						$this->connection->drain($deadline);
						$waiting = array_any($this->paused, fn ($pause) => $pause['inflight']);
						if ($waiting) $this->connection->wait($deadline);
					} while ($waiting);
				} catch (\Throwable $error) {
					$this->recordInterceptionFailure($error);
				}
			endif;
			if ($this->interceptionError() !== null) throw $this->interceptionFailure;
			return $this;
		}

		/** Inspect retained handler, resolution and cleanup failures without I/O. Capture failures are separate. */
		public function interceptionError(): ?NetworkError
		{
			if ($this->interceptSubscriptions !== [] && !$this->connection->active() && $this->transportFailure === null) {
				$this->recordInterceptionFailure($this->connection->failure() ?? new \RuntimeException('Network interception lost its BiDi connection.'));
			}
			return $this->interceptionFailure;
		}

		/** Return one decision for an owned pause; unknown IDs are held only during an add command. */
		private function onRequest(array $event, string $phase): ?array
		{
			if (!($event['isBlocked'] ?? false)) return null;
			$ids = $event['intercepts'] ?? [];
			$owned = false;
			$handler = null;
			foreach ($this->rules as $id => $candidate):
				if (!in_array($id, $ids, true)) continue;
				$owned = true;
				if ($candidate !== null) { $handler = $candidate; break; }
			endforeach;
			$owned = $owned || array_any($ids, fn ($id) => isset($this->retired[$id]));
			if (!$owned):
				if ($this->installing) $this->provisional[] = ['phase' => $phase, 'params' => $event];
				return null;
			endif;
			$token = ++$this->nextPause;
			$request = $event['request']['request'];
			$this->paused[$token] = ['requestId' => $request, 'redirectCount' => $event['redirectCount'], 'phase' => $phase, 'inflight' => true];
			$this->current[$request] = $token;
			try {
				$decision = $handler === null
					? ($phase === 'authRequired' ? NetworkDecision::defaultAuth() : NetworkDecision::continueRequest()) : $handler($event);
				if (!$decision instanceof NetworkDecision) throw new \UnexpectedValueException('Network intercept handlers must return NetworkDecision.');
				if ($decision->phase() !== $phase) throw new \UnexpectedValueException('Network decision is not valid for ' . $phase . '.');
			} catch (\Throwable $error) {
				$this->recordInterceptionFailure($error, $this->paused[$token]);
				$decision = $phase === 'authRequired' ? NetworkDecision::cancelAuth() : NetworkDecision::fail();
			}
			return $this->resolve($token, $decision);
		}

		private function resolve(int $token, NetworkDecision $decision): array
		{
			$pause = $this->paused[$token];
			$command = $decision->command($pause['requestId']);
			// Terminal failures cannot recover again; settlers need no credential-bearing command parameters.
			$recoverable = $command['method'] !== 'network.failRequest'
				&& !($command['method'] === 'network.continueWithAuth' && $command['params']['action'] === 'cancel');
			$command['settle'] = function (array $reply) use ($token, $pause, $recoverable): ?array {
				if ($reply['type'] === 'success'):
					unset($this->paused[$token]);
					if (($this->current[$pause['requestId']] ?? null) === $token) unset($this->current[$pause['requestId']]);
					return null;
				endif;
				$this->recordInterceptionFailure(new \RuntimeException('WebDriver BiDi ' . $reply['error'] . ': ' . $reply['message']), $pause);
				if ($recoverable && $reply['error'] !== 'no such request' && ($this->current[$pause['requestId']] ?? null) === $token) {
					return $this->resolve($token, $pause['phase'] === 'authRequired' ? NetworkDecision::cancelAuth() : NetworkDecision::fail());
				}
				$this->paused[$token]['inflight'] = false;
				return null;
			};
			return $command;
		}

		private function recordInterceptionFailure(\Throwable $error, array $request = []): void
		{
			if ($request === [] && !$this->connection->active()):
				if ($this->transportFailure !== null) return;
				$this->transportFailure = $error = $this->connection->failure() ?? $error;
			endif;
			if ($this->interceptionFailure === null) $this->interceptionFailure = new NetworkError($error, $request);
			else $this->interceptionFailure->add($error, $request);
		}

		/** @internal Session deletion releases remote pauses; retain history/errors but drop all local ownership. */
		public function disconnect(): void
		{
			$this->detach();
			foreach ($this->interceptListeners as $listener) $this->connection->unlisten($listener);
			$this->interceptListeners = $this->interceptSubscriptions = [];
			$this->rules = $this->retired = $this->paused = $this->current = $this->provisional = [];
		}

		/** @internal Release local capture ownership at session end; history and errors remain readable. */
		public function detach(): void
		{
			foreach ($this->listeners as $listener) $this->connection->unlisten($listener);
			$this->listeners = [];
			$this->subscription = null;
		}
	}
