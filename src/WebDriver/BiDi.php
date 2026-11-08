<?php
	namespace ThriveData\ThrivePHP\WebDriver;

	use ThriveData\ThrivePHP\WebSocket;

	/** @internal Session dispatcher shared by classic HTTP waits and BiDi consumers. */
	final class BiDi
	{
		private int $nextId = 0;
		private int $nextListener = 0;
		/** Command ID => absolute deadline, internal decision flag, and eventual response envelope. */
		private array $pending = [];
		/** Arrival-ordered envelopes for the existing consuming event() API. */
		private array $events = [];
		/** Listener token => exact event method and observer/decision function. */
		private array $listeners = [];
		private bool $dispatching = false;
		private ?\Throwable $failure = null;
		private bool $peerClosed = false;

		/** Adopt the session's socket; timeout is a positive command budget in seconds. */
		public function __construct(private ?WebSocket $socket, private float $timeout) {}

		/** Reject browser reentry from decision handlers; only the dispatcher sends their decisions. */
		public function assertCallable(): void
		{
			if ($this->dispatching) throw new \LogicException('Browser operations are not allowed inside a BiDi event handler.');
		}

		public function active(): bool { return $this->socket !== null; }
		public function failure(): ?\Throwable { return $this->failure; }
		/** A close frame or EOF between messages can accompany successful last-window deletion. */
		public function peerClosed(): bool { return $this->peerClosed; }

		/** Release session state even if a feature still holds a reference to this dispatcher. */
		public function disconnect(): void
		{
			$this->assertCallable();
			$this->socket = null;
			$this->pending = $this->events = $this->listeners = [];
		}

		/** Drain available input without waiting for new events; a continuous flood shares one deadline. */
		public function drain(): void
		{
			$this->assertCallable();
			$deadline = hrtime(true) / 1e9 + $this->timeout;
			do {
				$this->tick();
				if (hrtime(true) / 1e9 >= $deadline) throw new \RuntimeException('WebDriver BiDi collection timed out.');
				if ($this->socket->buffered()) continue;
				$read = [$this->socket->stream()];
				$write = $except = [];
				$ready = @stream_select($read, $write, $except, 0, 0);
				if ($ready === false) throw new \RuntimeException('WebDriver BiDi readiness wait failed.');
				if ($ready === 0) break;
			} while (true);
		}

		/** Register an internal observer. A returned {method, params} schedules a protocol command. */
		public function listen(string $method, callable $handler): int
		{
			$this->assertCallable();
			$id = ++$this->nextListener;
			$this->listeners[$id] = [$method, $handler];
			return $id;
		}

		public function unlisten(int $id): void { unset($this->listeners[$id]); }

		/** Run a command while continuing to dispatch events and responses to other pending commands. */
		public function command(string $method, array $params = []): array
		{
			$this->assertCallable();
			$id = $this->submit($method, $params, false);
			try {
				while ($this->pending[$id]['response'] === null):
					$this->tick();
					if ($this->pending[$id]['response'] === null) $this->wait($this->pending[$id]['deadline']);
				endwhile;
				$message = $this->pending[$id]['response'];
			} finally {
				unset($this->pending[$id]);
			}
			return $this->result($message);
		}

		/** Preserve the low-level queue independently of internal observers. */
		public function event(float $timeout): array
		{
			$this->assertCallable();
			$deadline = hrtime(true) / 1e9 + $timeout;
			while (!$this->events):
				$this->tick();
				if (!$this->events) $this->wait($deadline);
			endwhile;
			return array_shift($this->events);
		}

		/** Process a bounded batch so a busy browser cannot starve HTTP progress or deadlines. */
		public function tick(): void
		{
			$this->assertCallable();
			$this->requireConnection();
			try {
				$this->checkDeadlines();
				$this->socket->pump();
				for ($count = 0; $count < 64; $count++):
					$text = $this->socket->poll();
					if ($text === false) break;
					if ($text === null):
						$this->peerClosed = true;
						throw new \RuntimeException('WebDriver BiDi connection closed by the peer.');
					endif;
					$message = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
					if (!is_array($message)) throw new \RuntimeException('Invalid WebDriver BiDi message.');
					$type = $message['type'] ?? null;
					if ($type === 'event'):
						if (!is_string($message['method'] ?? null) || !is_array($message['params'] ?? null)) throw new \RuntimeException('Invalid WebDriver BiDi event.');
						$this->events[] = $message;
						$this->dispatching = true;
						try {
							foreach ($this->listeners as [$method, $handler]):
								if ($method !== $message['method']) continue;
								$decision = $handler($message['params']);
								if ($decision !== null) $this->submit($decision['method'], $decision['params'] ?? [], true);
							endforeach;
						} finally {
							$this->dispatching = false;
						}
					else:
						if (($type !== 'success' && $type !== 'error')
							|| ($type === 'success' && !is_array($message['result'] ?? null))
							|| ($type === 'error' && (!is_string($message['error'] ?? null) || !is_string($message['message'] ?? null)))):
							throw new \RuntimeException('Invalid WebDriver BiDi message.');
						endif;
						$id = $message['id'] ?? null;
						if (!is_int($id) || !isset($this->pending[$id]) || $this->pending[$id]['response'] !== null):
							throw new \RuntimeException('WebDriver BiDi response has an unexpected command ID.');
						endif;
						if ($this->pending[$id]['internal']):
							unset($this->pending[$id]);
							$this->result($message);
						else:
							$this->pending[$id]['response'] = $message;
						endif;
					endif;
					$this->checkDeadlines();
				endfor;
				// Responses to events and ping frames should leave in this same turn.
				$this->socket->pump(false);
			} catch (\Throwable $error) {
				// ChromeDriver can end the stream without a close frame when the last window closes.
				$this->peerClosed = $this->peerClosed || $this->socket?->ended() === true;
				$this->failure = $error;
				$this->socket = null;
				throw $error;
			}
		}

		/** Bounded readiness wait. HTTP coordination uses its own shorter cURL wait slice. */
		public function wait(float $deadline): void
		{
			$this->requireConnection();
			if ($this->socket->buffered()) return;
			foreach ($this->pending as $pending) $deadline = min($deadline, $pending['deadline']);
			$remaining = $deadline - hrtime(true) / 1e9;
			if ($remaining <= 0):
				$this->socket = null;
				throw new \RuntimeException('WebDriver BiDi operation timed out.');
			endif;
			$read = [$this->socket->stream()];
			$write = $this->socket->writing() ? $read : [];
			$except = [];
			// pump() can already hold complete messages; never wait a full command timeout.
			$remaining = min($remaining, 0.01);
			if (@stream_select($read, $write, $except, 0, (int)ceil($remaining * 1e6)) === false):
				$this->socket = null;
				throw new \RuntimeException('WebDriver BiDi readiness wait failed.');
			endif;
		}

		private function submit(string $method, array $params, bool $internal): int
		{
			$this->requireConnection();
			$id = ++$this->nextId;
			$this->socket->enqueue(json_encode(['id' => $id, 'method' => $method, 'params' => (object)$params], JSON_THROW_ON_ERROR));
			$this->pending[$id] = ['deadline' => hrtime(true) / 1e9 + $this->timeout, 'internal' => $internal, 'response' => null];
			return $id;
		}

		private function result(array $message): array
		{
			if ($message['type'] === 'error') throw new \RuntimeException('WebDriver BiDi ' . $message['error'] . ': ' . $message['message']);
			return $message['result'];
		}

		private function checkDeadlines(): void
		{
			foreach ($this->pending as $pending):
				if ($pending['response'] === null && hrtime(true) / 1e9 >= $pending['deadline']) throw new \RuntimeException('WebDriver BiDi operation timed out.');
			endforeach;
		}

		private function requireConnection(): void
		{
			if ($this->socket === null) throw new \LogicException('No active WebDriver BiDi connection.', 0, $this->failure);
		}
	}
