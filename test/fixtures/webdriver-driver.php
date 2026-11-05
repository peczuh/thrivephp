<?php

// A real child-process HTTP peer for webdriver-runtime.php. It never starts a browser.
$mode = getenv('WEBDRIVER_FIXTURE_MODE') ?: 'normal';
$log = getenv('WEBDRIVER_FIXTURE_LOG');
$record = function (array $event) use ($log) {
	if ($log) file_put_contents($log, json_encode($event) . "\n", FILE_APPEND | LOCK_EX);
};
$port = null;
foreach ($argv as $index => $argument):
	if (str_starts_with($argument, '--port=')) $port = (int)substr($argument, 7);
	if ($argument === '--port') $port = (int)($argv[$index + 1] ?? 0);
endforeach;
$record(['event' => 'start', 'pid' => getmypid(), 'args' => array_slice($argv, 1)]);
if ($mode === 'exit'):
	fwrite(STDERR, "fixture startup failed\n");
	exit(1);
endif;
if ($mode === 'ignore-term' && function_exists('pcntl_signal')):
	pcntl_async_signals(true);
	pcntl_signal(SIGTERM, SIG_IGN);
endif;
$listener = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error);
if ($listener === false):
	fwrite(STDERR, "bind failed: $error\n");
	exit(2);
endif;
$nextSession = 0;
while (true):
	$stream = @stream_socket_accept($listener, 1);
	if ($stream === false) continue;
	stream_set_timeout($stream, 2);
	$head = '';
	while (($line = fgets($stream)) !== false):
		$head .= $line;
		if ($line === "\r\n") break;
	endwhile;
	preg_match('/^(\S+) (\S+) HTTP\//', $head, $request);
	preg_match('/Content-Length:\s*(\d+)/i', $head, $length);
	$body = '';
	$remaining = (int)($length[1] ?? 0);
	while ($remaining > 0):
		$chunk = fread($stream, $remaining);
		if ($chunk === false || $chunk === '') break;
		$body .= $chunk;
		$remaining -= strlen($chunk);
	endwhile;
	$method = $request[1] ?? '';
	$path = $request[2] ?? '';
	$parameters = $body === '' ? null : json_decode($body, true);
	$record(['event' => 'request', 'method' => $method, 'path' => $path, 'body' => $parameters]);
	$status = 200;
	$value = null;
	if ($path === '/status'):
		$value = ['ready' => $mode !== 'notready', 'message' => 'fixture'];
	elseif ($path === '/session' && $method === 'POST'):
		$value = ['sessionId' => 'fixture-' . ++$nextSession, 'capabilities' => $parameters['capabilities']['alwaysMatch']];
		if ($mode === 'session-drop'):
			fclose($stream);
			continue;
		elseif ($mode === 'session-error'):
			$status = 500;
			$value = ['error' => 'session not created', 'message' => 'fixture session refused'];
		endif;
	elseif (preg_match('#/session/[^/]+/window$#', $path) && $method === 'GET'):
		$value = 'window';
	elseif (preg_match('#/session/[^/]+/window$#', $path) && $method === 'DELETE'):
		$value = [];
	elseif (str_ends_with($path, '/title')):
		$value = 'Fixture';
	elseif (str_ends_with($path, '/url') && $method === 'GET'):
		$value = 'https://example.test/fixture';
	elseif (preg_match('#^/session/[^/]+$#', $path) && $method === 'DELETE' && $mode === 'delete-error'):
		$status = 500;
		$value = ['error' => 'unknown error', 'message' => 'fixture session deletion failed'];
	endif;
	$json = json_encode(['value' => $value]);
	@fwrite($stream, "HTTP/1.1 $status Response\r\nContent-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\nConnection: close\r\n\r\n$json");
	fclose($stream);
endwhile;
