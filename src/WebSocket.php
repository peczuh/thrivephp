<?php
	namespace ThriveData\ThrivePHP;

	/**
	 * Synchronous RFC 6455 text client using PHP streams; wss:// requires OpenSSL.
	 * No extensions or subprotocols are negotiated. Connection/protocol failures and
	 * operation timeouts close the socket and throw RuntimeException: a partially
	 * transferred frame cannot safely be retried. Invalid arguments instead throw
	 * InvalidArgumentException without closing an established connection.
	 *
	 * Example (requires a running WebSocket echo server):
	 *
	 * ```php
	 * $socket = new \ThriveData\ThrivePHP\WebSocket('ws://localhost:8080', timeout: 10);
	 * try {
	 *     $socket->send('Hello');
	 *     $reply = $socket->receive(); // Returns "Hello"; null means the peer closed normally.
	 * } finally {
	 *     $socket->close();
	 * }
	 * ```
	 *
	 * Protocol reference: https://www.rfc-editor.org/rfc/rfc6455
	 */
	class WebSocket
	{
		/** Byte limit for a complete text message, including all its fragments. */
		private const MAX_MESSAGE = 16777216;
		private $socket = null;
		private string $incoming = '';
		private string $outgoing = '';
		private string $text = '';
		private bool $started = false;
		private bool $needsRead = true;
		private bool $ended = false;

		/**
		 * Connect and complete the HTTP upgrade immediately, verifying TLS for wss://.
		 * $timeout is a positive number of seconds, also stored as the default for
		 * send(), receive(), and close(). Each operation shares one deadline across
		 * its transfers rather than restarting the timeout for every read or frame.
		 */
		public function __construct(string $url, private float $timeout = 10)
		{
			$deadline = $this->deadline($timeout);
			$parts = parse_url($url);
			if (!$parts || !isset($parts['host'])
				|| !in_array($parts['scheme'] ?? '', ['ws', 'wss'], true)
				|| isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
				|| preg_match('/[\x00-\x20\x7f]/', $url)):
				throw new \InvalidArgumentException('Expected a ws:// or wss:// URL without credentials or fragment.');
			endif;

			$tls = $parts['scheme'] === 'wss';
			$host = $parts['host'];
			$port = $parts['port'] ?? ($tls ? 443 : 80);
			$path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
			$context = stream_context_create(['ssl' => [
				'verify_peer' => true,
				'verify_peer_name' => true,
				'peer_name' => trim($host, '[]'),
			]]);
			$this->socket = @stream_socket_client(
				($tls ? 'tls' : 'tcp') . "://$host:$port",
				$errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context
			);
			if ($this->socket === false):
				$this->fail("WebSocket connection failed: $error ($errno).");
			endif;

			// Blocking stream writes do not consistently honor stream_set_timeout().
			stream_set_blocking($this->socket, false);

			try {
				// The server must prove it recognized this handshake by hashing this nonce.
				$key = base64_encode(random_bytes(16));
				$this->write("GET $path HTTP/1.1\r\nHost: $host:$port\r\n"
					. "Upgrade: websocket\r\nConnection: Upgrade\r\n"
					. "Sec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n", $deadline);

				// Read only the headers: the server may send its first frame immediately.
				$response = '';
				while (!str_ends_with($response, "\r\n\r\n")):
					if (strlen($response) >= 16384):
						$this->fail('WebSocket handshake headers exceed 16 KiB.');
					endif;
					$response .= $this->read(1, $deadline);
				endwhile;
				$lines = explode("\r\n", trim($response));
				$status = array_shift($lines);
				$headers = [];
				foreach ($lines as $line):
					if (!str_contains($line, ':')):
						$this->fail('Malformed WebSocket handshake header.');
					endif;
					[$name, $value] = explode(':', $line, 2);
					$name = strtolower(trim($name));
					$headers[$name] = isset($headers[$name]) ? $headers[$name] . ',' . trim($value) : trim($value);
				endforeach;

				// RFC 6455 fixes this GUID and hash formula; this is not authentication.
				$accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
				if (!preg_match('/^HTTP\/1\.1 101(?: |$)/', $status)
					|| strtolower($headers['upgrade'] ?? '') !== 'websocket'
					|| !in_array('upgrade', array_map('trim', explode(',', strtolower($headers['connection'] ?? ''))), true)
					|| ($headers['sec-websocket-accept'] ?? '') !== $accept
					|| isset($headers['sec-websocket-extensions']) || isset($headers['sec-websocket-protocol'])):
					$this->fail('Invalid WebSocket upgrade response.');
				endif;
			} catch (\Throwable $error) {
				$this->disconnect();
				throw $error;
			}
		}

		/** Send one UTF-8 text message, limited to 16 MiB. */
		public function send(string $text): void
		{
			if (strlen($text) > self::MAX_MESSAGE || preg_match('//u', $text) !== 1):
				throw new \InvalidArgumentException('WebSocket messages must be UTF-8 text of at most 16 MiB.');
			endif;
			$this->writeFrame(1, $text, $this->deadline($this->timeout));
		}

		/**
		 * Return one complete text message, or null for a peer close with no code,
		 * code 1000 (normal), or 1001 (going away). Other close codes throw.
		 * Handle ping/pong internally. $timeout overrides the default seconds for
		 * this call; control frames and text fragments do not reset its deadline.
		 */
		public function receive(?float $timeout = null): ?string
		{
			return $this->message($this->deadline($timeout ?? $this->timeout));
		}

		/** Queue a UTF-8 message for incremental writes by pump(); does not wait for delivery. */
		public function enqueue(string $text): void
		{
			if (strlen($text) > self::MAX_MESSAGE || preg_match('//u', $text) !== 1):
				throw new \InvalidArgumentException('WebSocket messages must be UTF-8 text of at most 16 MiB.');
			endif;
			$this->queueFrame(1, $text);
		}

		/** @internal Stream used for readiness waits; callers must not read or write it directly. */
		public function stream(): mixed { return $this->socket; }
		/** @internal Whether incremental writes still need socket readiness. */
		public function writing(): bool { return $this->outgoing !== ''; }

		/** Advance available I/O once without waiting. Partial frames and writes survive calls. */
		public function pump(bool $read = true): void
		{
			if (!is_resource($this->socket)) $this->fail('WebSocket is closed.');
			if ($this->outgoing !== ''):
				$written = @fwrite($this->socket, substr($this->outgoing, 0, 65536));
				if ($written === false) $this->fail('WebSocket write failed.');
				$this->outgoing = substr($this->outgoing, $written);
			endif;
			if ($read && $this->needsRead):
				$chunk = @fread($this->socket, 65536);
				if ($chunk === false) $this->fail('WebSocket read failed.');
				$this->incoming .= $chunk;
				$this->needsRead = false;
			endif;
		}

		/** @internal Parsed messages may remain in userspace even when the stream is not readable. */
		public function buffered(): bool { return !$this->needsRead && $this->incoming !== ''; }
		/** @internal EOF between complete messages; still an error without a WebSocket close frame. */
		public function ended(): bool { return $this->ended; }

		/**
		 * Consume one buffered text message: false means incomplete/idle, null means normal
		 * peer closure. Call pump() to advance I/O. Empty text is a valid complete message.
		 */
		public function poll(bool $closing = false): string|false|null
		{
			while (($frame = $this->readFrame(self::MAX_MESSAGE - strlen($this->text))) !== null):
				[$final, $opcode, $payload] = $frame;
				if ($opcode === 8):
					$code = strlen($payload) >= 2 ? unpack('n', substr($payload, 0, 2))[1] : null;
					if (strlen($payload) === 1 || ($code !== null
						&& !(in_array($code, [1000, 1001, 1002, 1003, 1007, 1008, 1009, 1010, 1011, 1012, 1013, 1014], true)
							|| ($code >= 3000 && $code <= 4999))) || preg_match('//u', substr($payload, 2)) !== 1):
						$this->fail('Invalid WebSocket close frame.');
					endif;
					if (!$closing):
						$this->queueFrame(8, $payload);
						// Best effort close acknowledgement; polling must never block on the peer.
						@fwrite($this->socket, $this->outgoing);
					endif;
					$this->disconnect();
					if ($code !== null && $code !== 1000 && $code !== 1001):
						throw new \RuntimeException("WebSocket peer closed with code $code: " . substr($payload, 2));
					endif;
					return null;
				endif;
				if ($opcode === 9):
					$this->queueFrame(10, $payload);
					continue;
				endif;
				if ($opcode === 10) continue;
				if (($opcode === 1 && $this->started) || ($opcode === 0 && !$this->started)):
					$this->fail('Invalid WebSocket fragmentation sequence.');
				endif;
				$this->started = true;
				$this->text .= $payload;
				if ($final):
					if (preg_match('//u', $this->text) !== 1) $this->fail('WebSocket message is not valid UTF-8.');
					$text = $this->text;
					$this->text = '';
					$this->started = false;
					return $text;
				endif;
			endwhile;
			if (!is_resource($this->socket) || feof($this->socket)):
				$this->ended = is_resource($this->socket) && $this->incoming === '' && !$this->started;
				$this->fail('WebSocket connection ended unexpectedly.');
			endif;
			$this->needsRead = true;
			return false;
		}

		/**
		 * Perform the close handshake within the configured timeout. Safe to repeat.
		 * Failures throw, but the socket is released even if the peer never replies.
		 */
		public function close(): void
		{
			if (!is_resource($this->socket)):
				return;
			endif;
			try {
				$deadline = $this->deadline($this->timeout);
				$this->writeFrame(8, pack('n', 1000), $deadline);
				// Discard messages already in flight while waiting for the peer's close.
				while ($this->message($deadline, true) !== null):
				endwhile;
			} finally {
				$this->disconnect();
			}
		}

		/** Release the socket without waiting; call close() for a graceful handshake. */
		public function __destruct()
		{
			$this->disconnect();
		}

		/**
		 * Assemble one text message while servicing interleaved control frames.
		 * $closing means our close frame was already sent and must not be echoed again.
		 */
		private function message(float $deadline, bool $closing = false): ?string
		{
			while (true):
				$this->pump();
				$result = $this->poll($closing);
				if ($result !== false) return $result;
				$this->wait($deadline, $this->writing());
			endwhile;
		}

		/**
		 * Read one server frame as [final, opcode, payload]. $remaining is the unused
		 * byte allowance for the current text message; control frames do not consume it.
		 */
		private function readFrame(int $remaining): ?array
		{
			if (strlen($this->incoming) < 2) return null;
			$header = substr($this->incoming, 0, 2);
			$offset = 2;
			$first = ord($header[0]);
			$second = ord($header[1]);
			// Byte 1 contains FIN, reserved bits, and opcode; byte 2 contains MASK and length.
			$final = (bool)($first & 0x80);
			$opcode = $first & 0x0f;
			$length = $second & 0x7f;
			// Server frames must be unmasked. No extensions, binary messages, or reserved
			// opcodes are supported; control frames must be final and at most 125 bytes.
			if (($first & 0x70) || ($second & 0x80) || !in_array($opcode, [0, 1, 8, 9, 10], true)
				|| ($opcode >= 8 && (!$final || $length > 125))):
				$this->fail('Unsupported or malformed WebSocket frame.');
			endif;

			// Length markers 126/127 introduce 16/64-bit network-order lengths.
			if ($length === 126):
				if (strlen($this->incoming) < 4) return null;
				$length = unpack('n', substr($this->incoming, 2, 2))[1];
				$offset = 4;
				if ($length < 126):
					$this->fail('Non-minimal WebSocket frame length.');
				endif;
			elseif ($length === 127):
				if (strlen($this->incoming) < 10) return null;
				$words = unpack('Nhigh/Nlow', substr($this->incoming, 2, 8));
				$offset = 10;
				// Any nonzero high word exceeds our message limit; require minimal encoding.
				if ($words['high'] !== 0 || $words['low'] < 65536):
					$this->fail('Invalid or excessive WebSocket frame length.');
				endif;
				$length = $words['low'];
			endif;
			if ($opcode < 8 && $length > $remaining):
				$this->fail('WebSocket message exceeds 16 MiB.');
			endif;
			if (strlen($this->incoming) < $offset + $length) return null;
			$payload = substr($this->incoming, $offset, $length);
			$this->incoming = substr($this->incoming, $offset + $length);
			return [$final, $opcode, $payload];
		}

		/** Write one final, client-masked text or control frame before the deadline. */
		private function writeFrame(int $opcode, string $payload, float $deadline): void
		{
			$this->queueFrame($opcode, $payload);
			$this->write($this->outgoing, $deadline);
			$this->outgoing = '';
		}

		/** Encode frames in enqueue order so control frames cannot split partial writes. */
		private function queueFrame(int $opcode, string $payload): void
		{
			if (!is_resource($this->socket)) $this->fail('WebSocket is closed.');
			$length = strlen($payload);
			$header = chr(0x80 | $opcode);
			$header .= $length < 126 ? chr(0x80 | $length)
				: ($length < 65536 ? chr(0xfe) . pack('n', $length) : chr(0xff) . pack('NN', 0, $length));
			// Every client frame needs a fresh four-byte XOR mask, even over TLS.
			$mask = random_bytes(4);
			$payload ^= substr(str_repeat($mask, (int)ceil($length / 4)), 0, $length);
			$this->outgoing .= $header . $mask . $payload;
		}

		/** Read exactly $length bytes, allowing partial reads under one absolute deadline. */
		private function read(int $length, float $deadline): string
		{
			$data = '';
			while (strlen($data) < $length):
				$this->wait($deadline, false);
				$chunk = @fread($this->socket, $length - strlen($data));
				if ($chunk === false || ($chunk === '' && feof($this->socket))):
					$this->fail('WebSocket read failed, timed out, or connection ended unexpectedly.');
				endif;
				$data .= $chunk;
			endwhile;
			return $data;
		}

		/** Write all bytes, allowing partial writes under one absolute deadline. */
		private function write(string $data, float $deadline): void
		{
			$offset = 0;
			while ($offset < strlen($data)):
				$this->wait($deadline, true);
				$written = @fwrite($this->socket, substr($data, $offset));
				if ($written === false || ($written === 0 && feof($this->socket))):
					$this->fail('WebSocket write failed or timed out.');
				endif;
				$offset += $written;
			endwhile;
		}

		/** Convert a duration in seconds to a monotonic deadline, unaffected by clock changes. */
		private function deadline(float $timeout): float
		{
			if (!is_finite($timeout) || $timeout <= 0):
				throw new \InvalidArgumentException('WebSocket timeout must be positive and finite.');
			endif;
			return hrtime(true) / 1e9 + $timeout;
		}

		/** Wait for read/write readiness using only the seconds left in the operation. */
		private function wait(float $deadline, bool $writing): void
		{
			$remaining = $deadline - hrtime(true) / 1e9;
			if (!is_resource($this->socket) || $remaining <= 0):
				$this->fail('WebSocket is closed or operation timed out.');
			endif;
			$read = $writing ? [] : [$this->socket];
			$write = $writing ? [$this->socket] : [];
			$except = [];
			$ready = @stream_select($read, $write, $except, (int)$remaining, (int)(($remaining - (int)$remaining) * 1e6));
			if ($ready === false || $ready === 0):
				$this->fail('WebSocket wait failed or operation timed out.');
			endif;
		}

		private function fail(string $message): void
		{
			$this->disconnect();
			throw new \RuntimeException($message);
		}

		private function disconnect(): void
		{
			if (is_resource($this->socket)):
				fclose($this->socket);
			endif;
			$this->socket = null;
			$this->incoming = $this->outgoing = $this->text = '';
			$this->started = false;
			$this->needsRead = true;
		}
	}
