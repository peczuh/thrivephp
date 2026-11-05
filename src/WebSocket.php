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
			$text = '';
			// An empty first fragment still starts a message, so text length is insufficient.
			$started = false;
			while (true):
				[$final, $opcode, $payload] = $this->readFrame($deadline, self::MAX_MESSAGE - strlen($text));
				if ($opcode === 8):
					// Close payloads are empty, or contain a two-byte code and UTF-8 reason.
					$code = strlen($payload) >= 2 ? unpack('n', substr($payload, 0, 2))[1] : null;
					// Accept defined wire codes and application/private codes, not reserved sentinels.
					if (strlen($payload) === 1 || ($code !== null
						&& !(in_array($code, [1000, 1001, 1002, 1003, 1007, 1008, 1009, 1010, 1011, 1012, 1013, 1014], true)
							|| ($code >= 3000 && $code <= 4999)))
						|| preg_match('//u', substr($payload, 2)) !== 1):
						$this->fail('Invalid WebSocket close frame.');
					endif;
					if (!$closing):
						$this->writeFrame(8, $payload, $deadline);
					endif;
					$this->disconnect();
					if ($code !== null && $code !== 1000 && $code !== 1001):
						throw new \RuntimeException("WebSocket peer closed with code $code: " . substr($payload, 2));
					endif;
					return null;
				endif;

				// Ping (9) requires pong (10) with the same payload, even between fragments.
				if ($opcode === 9):
					$this->writeFrame(10, $payload, $deadline);
					continue;
				endif;
				if ($opcode === 10):
					continue;
				endif;
				if (($opcode === 1 && $started) || ($opcode === 0 && !$started)):
					$this->fail('Invalid WebSocket fragmentation sequence.');
				endif;
				$started = true;
				$text .= $payload;
				if ($final):
					// A UTF-8 character can span fragments; validate only the assembled message.
					if (preg_match('//u', $text) !== 1):
						$this->fail('WebSocket message is not valid UTF-8.');
					endif;
					return $text;
				endif;
			endwhile;
		}

		/**
		 * Read one server frame as [final, opcode, payload]. $remaining is the unused
		 * byte allowance for the current text message; control frames do not consume it.
		 */
		private function readFrame(float $deadline, int $remaining): array
		{
			$header = $this->read(2, $deadline);
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
				$length = unpack('n', $this->read(2, $deadline))[1];
				if ($length < 126):
					$this->fail('Non-minimal WebSocket frame length.');
				endif;
			elseif ($length === 127):
				$words = unpack('Nhigh/Nlow', $this->read(8, $deadline));
				// Any nonzero high word exceeds our message limit; require minimal encoding.
				if ($words['high'] !== 0 || $words['low'] < 65536):
					$this->fail('Invalid or excessive WebSocket frame length.');
				endif;
				$length = $words['low'];
			endif;
			if ($opcode < 8 && $length > $remaining):
				$this->fail('WebSocket message exceeds 16 MiB.');
			endif;
			return [$final, $opcode, $this->read($length, $deadline)];
		}

		/** Write one final, client-masked text or control frame before the deadline. */
		private function writeFrame(int $opcode, string $payload, float $deadline): void
		{
			$length = strlen($payload);
			$header = chr(0x80 | $opcode);
			$header .= $length < 126 ? chr(0x80 | $length)
				: ($length < 65536 ? chr(0xfe) . pack('n', $length) : chr(0xff) . pack('NN', 0, $length));
			// Every client frame needs a fresh four-byte XOR mask, even over TLS.
			$mask = random_bytes(4);
			$payload ^= substr(str_repeat($mask, (int)ceil($length / 4)), 0, $length);
			$this->write($header . $mask . $payload, $deadline);
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
		}
	}
