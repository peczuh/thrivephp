<?php

// Run with: php test/websocket.php (requires pcntl for the local test peer).
require dirname(__DIR__) . '/src/WebSocket.php';

use ThriveData\ThrivePHP\WebSocket;

function check(bool $condition, string $message): void
{
	if (!$condition) throw new RuntimeException($message);
}

function failure(callable $action, string $message): void
{
	try {
		$action();
	} catch (RuntimeException $error) {
		check(str_contains($error->getMessage(), $message), $error->getMessage());
		return;
	}
	throw new RuntimeException("Expected failure containing: $message");
}

function readBytes($stream, int $length): string
{
	$data = '';
	while (strlen($data) < $length) {
		$part = fread($stream, $length - strlen($data));
		check($part !== false && $part !== '', 'Test peer received incomplete data.');
		$data .= $part;
	}
	return $data;
}

function frame(string $text, int $opcode = 1, bool $final = true): string
{
	$size = strlen($text);
	return chr(($final ? 128 : 0) | $opcode)
		. ($size < 126 ? chr($size) : ($size < 65536 ? pack('Cn', 126, $size) : pack('CNN', 127, 0, $size)))
		. $text;
}

function clientFrame($stream, int $opcode, string $expected): void
{
	$header = readBytes($stream, 2);
	check(ord($header[0]) === (128 | $opcode), 'Wrong client opcode or FIN.');
	check((ord($header[1]) & 128) !== 0, 'Client frame must be masked.');
	$size = ord($header[1]) & 127;
	if ($size === 126) $size = unpack('n', readBytes($stream, 2))[1];
	if ($size === 127) {
		$words = unpack('Nhigh/Nlow', readBytes($stream, 8));
		check($words['high'] === 0, 'Unexpected client length.');
		$size = $words['low'];
	}
	$mask = readBytes($stream, 4);
	$text = readBytes($stream, $size);
	for ($i = 0; $i < $size; $i++) $text[$i] = $text[$i] ^ $mask[$i % 4];
	check($text === $expected, 'Wrong client payload.');
}

function upgrade($stream, string $extra = '', string $acceptOverride = ''): void
{
	$request = '';
	while (!str_ends_with($request, "\r\n\r\n")) $request .= readBytes($stream, 1);
	check(str_starts_with($request, "GET /test?query=1 HTTP/1.1\r\n"), 'Incorrect request target.');
	check(preg_match('/Sec-WebSocket-Key: (.+)\r\n/', $request, $match) === 1, 'Missing key.');
	check(strlen(base64_decode($match[1], true)) === 16, 'Incorrect handshake key.');
	$accept = $acceptOverride ?: base64_encode(sha1($match[1] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
	fwrite($stream, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\n"
		. "Connection: keep-alive, Upgrade\r\nSec-WebSocket-Accept: $accept\r\n\r\n" . $extra);
}

function scenario(string $name, callable $server, callable $client): void
{
	$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
	check($listener !== false, "Could not create test listener: $error");
	$address = stream_socket_get_name($listener, false);
	$pid = pcntl_fork();
	check($pid !== -1, 'Could not fork test peer.');
	if ($pid === 0) {
		try {
			$stream = stream_socket_accept($listener, 5);
			check($stream !== false, 'Test connection did not arrive.');
			stream_set_timeout($stream, 3);
			$server($stream);
			fclose($stream);
			fclose($listener);
			exit(0);
		} catch (Throwable $error) {
			fwrite(STDERR, "$name peer: {$error->getMessage()}\n");
			exit(1);
		}
	}
	fclose($listener);
	try {
		$client("ws://$address/test?query=1");
	} finally {
		pcntl_waitpid($pid, $status);
	}
	check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, "$name: test peer failed.");
	echo "PASS: $name\n";
}

scenario('masking, all length encodings, coalesced and fragmented messages', function ($stream) {
	upgrade($stream, frame('immediate'));
	foreach ([0, 125, 126, 65535, 65536] as $size) {
		$text = str_repeat('x', $size);
		clientFrame($stream, 1, $text);
		fwrite($stream, frame($text));
	}
	// Split a UTF-8 character across fragments, with control frames in between.
	$frames = frame("A\xe2", 1, false) . frame('ping', 9)
		. frame('unsolicited', 10) . frame("\x82\xacB", 0);
	foreach (str_split($frames) as $byte) {
		fwrite($stream, $byte);
		usleep(1000);
	}
	clientFrame($stream, 10, 'ping');
	clientFrame($stream, 8, pack('n', 1000));
	fwrite($stream, frame(pack('n', 1000), 8));
}, function ($url) {
	$socket = new WebSocket($url, timeout: 2);
	check($socket->receive() === 'immediate', 'Lost frame following handshake.');
	foreach ([0, 125, 126, 65535, 65536] as $size) {
		$text = str_repeat('x', $size);
		$socket->send($text);
		check($socket->receive() === $text, "Incorrect echo for size $size.");
	}
	check($socket->receive() === "A€B", 'Incorrect fragmented text.');
	$socket->close();
	$socket->close();
	failure(fn() => $socket->send('closed'), 'closed');
});

scenario('peer-initiated close', function ($stream) {
	upgrade($stream, frame(pack('n', 1000) . 'bye', 8));
	clientFrame($stream, 8, pack('n', 1000) . 'bye');
}, function ($url) {
	$socket = new WebSocket($url);
	check($socket->receive() === null, 'Normal close must return null.');
	$socket->close();
});

scenario('invalid handshake', fn($stream) => upgrade($stream, '', 'wrong'), function ($url) {
	failure(fn() => new WebSocket($url), 'upgrade');
});

$invalidFrames = [
	'reserved bit' => ["\xc1\x00", 'malformed'],
	'masked server frame' => ["\x81\x80", 'malformed'],
	'binary message' => [frame('binary', 2), 'Unsupported'],
	'reserved opcode' => [frame('', 3), 'malformed'],
	'fragmented ping' => [frame('', 9, false), 'malformed'],
	'oversized ping' => ["\x89\x7e", 'malformed'],
	'non-minimal 16-bit length' => ["\x81\x7e\x00\x01", 'Non-minimal'],
	'non-minimal 64-bit length' => ["\x81\x7f" . pack('NN', 0, 126), 'length'],
	'64-bit overflow' => ["\x81\x7f" . pack('NN', 0x80000000, 0), 'length'],
	'message limit' => ["\x81\x7f" . pack('NN', 0, 16777217), '16 MiB'],
	'continuation without start' => [frame('x', 0), 'fragmentation'],
	'new message during fragmentation' => [frame('a', 1, false) . frame('b'), 'fragmentation'],
	'invalid UTF-8' => [frame("\xff"), 'UTF-8'],
	'one-byte close' => [frame('x', 8), 'close frame'],
	'reserved close code' => [frame(pack('n', 1005), 8), 'close frame'],
	'invalid close reason' => [frame(pack('n', 1000) . "\xff", 8), 'close frame'],
	'truncated frame' => ["\x81\x05ab", 'ended unexpectedly'],
];
foreach ($invalidFrames as $name => [$bytes, $message]) {
	scenario($name, fn($stream) => upgrade($stream, $bytes), function ($url) use ($message) {
		$socket = new WebSocket($url);
		failure(fn() => $socket->receive(), $message);
		failure(fn() => $socket->send('after failure'), 'closed');
	});
}

scenario('partial-frame receive timeout', function ($stream) {
	upgrade($stream, "\x81\x05ab");
	usleep(300000);
}, function ($url) {
	$socket = new WebSocket($url);
	$start = microtime(true);
	failure(fn() => $socket->receive(0.05), 'timed out');
	check(microtime(true) - $start < 0.25, 'Receive deadline was not enforced.');
	failure(fn() => $socket->send('after timeout'), 'closed');
});

scenario('close timeout', function ($stream) {
	upgrade($stream);
	clientFrame($stream, 8, pack('n', 1000));
	usleep(300000);
}, function ($url) {
	$socket = new WebSocket($url, timeout: 0.1);
	failure(fn() => $socket->close(), 'timed out');
	$socket->close();
});

scenario('outgoing UTF-8 and size validation', function ($stream) {
	upgrade($stream);
	clientFrame($stream, 1, 'still usable');
}, function ($url) {
	$socket = new WebSocket($url);
	foreach (["\xff", str_repeat('x', 16777217)] as $text) {
		try {
			$socket->send($text);
			throw new RuntimeException('Invalid outgoing message accepted.');
		} catch (InvalidArgumentException $error) {
		}
	}
	$socket->send('still usable');
});

scenario('write timeout under backpressure', function ($stream) {
	upgrade($stream);
	usleep(500000);
}, function ($url) {
	$socket = new WebSocket($url, timeout: 0.1);
	$start = microtime(true);
	failure(fn() => $socket->send(str_repeat('x', 16777216)), 'timed out');
	check(microtime(true) - $start < 0.4, 'Write deadline was not enforced.');
	failure(fn() => $socket->send('after timeout'), 'closed');
});

scenario('fragmented-message size limit', function ($stream) {
	upgrade($stream);
	$bytes = frame(str_repeat('x', 16777216), 1, false) . frame('x', 0);
	$offset = 0;
	while ($offset < strlen($bytes)) {
		$written = fwrite($stream, substr($bytes, $offset));
		check($written !== false && $written > 0, 'Could not send large test message.');
		$offset += $written;
	}
}, function ($url) {
	$socket = new WebSocket($url);
	failure(fn() => $socket->receive(), '16 MiB');
});
