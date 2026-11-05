<?php

// Run with: php test/webdriver.php (pcntl is used only by the local test driver).
require dirname(__DIR__) . '/src/WebSocket.php';
spl_autoload_register(function ($class) {
	$prefix = 'ThriveData\\ThrivePHP\\WebDriver\\';
	if (str_starts_with($class, $prefix)):
		require dirname(__DIR__) . '/src/WebDriver/' . substr($class, strlen($prefix)) . '.php';
	endif;
});

use ThriveData\ThrivePHP\WebDriver\Browser;
use ThriveData\ThrivePHP\WebDriver\Element;
use ThriveData\ThrivePHP\WebDriver\ElementSet;
use ThriveData\ThrivePHP\WebDriver\Window;
use ThriveData\ThrivePHP\WebDriver\ShadowRoot;
use ThriveData\ThrivePHP\WebDriver\Screenshot;
use ThriveData\ThrivePHP\WebDriver\PDF;

const PRINT_DEFAULTS = [
	'orientation' => 'portrait', 'scale' => 1, 'background' => false,
	'page' => ['width' => 21.59, 'height' => 27.94],
	'margin' => ['top' => 1, 'bottom' => 1, 'left' => 1, 'right' => 1],
	'pageRanges' => [], 'shrinkToFit' => true,
];

function verify(bool $condition, string $message): void
{
	if (!$condition) throw new RuntimeException($message);
}

function expectError(callable $action, string $text): void
{
	try {
		$action();
	} catch (Throwable $error) {
		verify(str_contains($error->getMessage(), $text), $error->getMessage());
		return;
	}
	throw new RuntimeException("Expected error: $text");
}

function bytes($stream, int $length): string
{
	$result = '';
	while (strlen($result) < $length):
		$part = fread($stream, $length - strlen($result));
		verify($part !== false && $part !== '', 'Fixture received incomplete input.');
		$result .= $part;
	endwhile;
	return $result;
}

/** Accept one HTTP request, leaving the connection available for upgrade or reply. */
function acceptRequest($listener): array
{
	$stream = stream_socket_accept($listener, 3);
	verify($stream !== false, 'Expected driver request did not arrive.');
	stream_set_timeout($stream, 3);
	$head = '';
	while (!str_ends_with($head, "\r\n\r\n")):
		$head .= bytes($stream, 1);
	endwhile;
	preg_match('/Content-Length: (\d+)/i', $head, $match);
	$body = bytes($stream, (int)($match[1] ?? 0));
	return [$stream, $head, $body];
}

/** Assert an exact HTTP command, including object-shaped empty parameters. */
function exchange($listener, string $method, string $path, ?array $body, mixed $value, int $status = 200, float $delay = 0): void
{
	[$stream, $head, $actual] = acceptRequest($listener);
	verify(str_starts_with($head, "$method $path HTTP/"), "Unexpected request: $head");
	verify($actual === ($body === null ? '' : json_encode((object)$body)), "Unexpected request body: $actual");
	if ($delay > 0) usleep((int)($delay * 1e6));
	$json = json_encode(['value' => $value]);
	fwrite($stream, "HTTP/1.1 $status Response\r\nContent-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\nConnection: close\r\n\r\n$json");
	fclose($stream);
}

function createSession($listener, string $browser = 'firefox', bool $bidi = false, bool $supported = true, bool $discover = true): void
{
	$capabilities = ['browserName' => $browser];
	$returned = $capabilities;
	if ($bidi):
		$capabilities['webSocketUrl'] = true;
		if ($supported):
			$returned['webSocketUrl'] = 'ws://' . stream_socket_get_name($listener, false) . '/bidi';
		endif;
	endif;
	exchange($listener, 'POST', '/session', ['capabilities' => ['alwaysMatch' => $capabilities]], [
		'sessionId' => 'session/1', 'capabilities' => $returned,
	]);
	if (!$bidi && $discover):
		exchange($listener, 'GET', '/session/session%2F1/window', null, 'window/1');
	endif;
}

function upgrade($listener)
{
	[$stream, $head] = acceptRequest($listener);
	verify(str_starts_with($head, 'GET /bidi HTTP/'), 'Expected BiDi upgrade.');
	preg_match('/Sec-WebSocket-Key: (.+)\r\n/', $head, $match);
	$accept = base64_encode(sha1($match[1] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
	fwrite($stream, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $accept\r\n\r\n");
	return $stream;
}

function bidiRequest($stream, int $id, string $method, array $params = []): void
{
	$header = bytes($stream, 2);
	verify(ord($header[0]) === 129 && (ord($header[1]) & 128), 'Expected masked text command.');
	$length = ord($header[1]) & 127;
	if ($length === 126) $length = unpack('n', bytes($stream, 2))[1];
	verify($length < 65536, 'Fixture expects small commands.');
	$mask = bytes($stream, 4);
	$text = bytes($stream, $length);
	for ($i = 0; $i < $length; $i++):
		$text[$i] = $text[$i] ^ $mask[$i % 4];
	endfor;
	verify($text === json_encode(['id' => $id, 'method' => $method, 'params' => (object)$params]), "Unexpected BiDi command: $text");
}

function bidiReply($stream, array $message): void
{
	$text = json_encode($message);
	$length = strlen($text);
	fwrite($stream, "\x81" . ($length < 126 ? chr($length) : pack('Cn', 126, $length)) . $text);
}

/** Fork only the test peer; the actual client uses both real HTTP and WebSocket streams. */
function scenario(string $name, callable $server, callable $client): void
{
	$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
	verify($listener !== false, $error);
	$url = 'http://' . stream_socket_get_name($listener, false);
	$pid = pcntl_fork();
	verify($pid !== -1, 'Could not fork fixture.');
	if ($pid === 0):
		try {
			$server($listener);
			exit(0);
		} catch (Throwable $error) {
			fwrite(STDERR, "$name fixture: {$error->getMessage()}\n");
			exit(1);
		}
	endif;
	fclose($listener);
	try {
		$client($url);
	} finally {
		pcntl_waitpid($pid, $status);
	}
	verify(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, "$name fixture failed.");
	echo "PASS: $name\n";
}

foreach (['chrome', 'firefox', 'safari'] as $browser):
	scenario("classic commands: $browser", function ($listener) use ($browser) {
		createSession($listener, $browser);
		exchange($listener, 'POST', '/session/session%2F1/url', ['url' => 'https://example.test/'], null);
		exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#name'], ['element-6066-11e4-a52e-4f735466cecf' => 'element/1']);
		exchange($listener, 'POST', '/session/session%2F1/element/element%2F1/value', ['text' => 'Hello €'], null);
		exchange($listener, 'POST', '/session/session%2F1/element/element%2F1/click', [], null);
		exchange($listener, 'GET', '/session/session%2F1/title', null, 'Example');
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	}, function ($url) use ($browser) {
		$driver = new Browser($url, $browser);
		$driver->navigate('https://example.test/');
		$id = $driver->find('#name');
		verify($id->type('Hello €')->click() === $id, 'Element chain lost its receiver.');
		verify($driver->title() === 'Example', 'Incorrect title.');
		expectError(fn() => $driver->command('browsingContext.getTree'), 'No active WebDriver BiDi');
		$driver->quit();
		$driver->quit();
		expectError(fn() => $driver->title(), 'No active WebDriver session');
	});
endforeach;

$assertionCases = [
	['isVisible', [], '/displayed', true, true, 'expected true, got true'],
	['isVisible', [], '/displayed', false, false, 'expected true, got false'],
	['isNotVisible', [], '/displayed', false, true, 'expected false or stale, got false'],
	['isNotVisible', [], '/displayed', true, false, 'expected false or stale, got true'],
	['valueEquals', ['Jane'], '/property/value', 'Jane', true, "expected 'Jane', got 'Jane'"],
	['valueEquals', ['0'], '/property/value', 0, false, "expected '0', got 0"],
	['textEquals', ['Hello €'], '/text', 'Hello €', true, "expected 'Hello €', got 'Hello €'"],
	['textEquals', ['Hello'], '/text', 'hello', false, "expected 'Hello', got 'hello'"],
	['textContains', ['€'], '/text', 'Hello €', true, "expected to contain '€', got 'Hello €'"],
	['textContains', ['Hello'], '/text', 'hello', false, "expected to contain 'Hello', got 'hello'"],
	['attributeEquals', ['data/name', '0'], '/attribute/data%2Fname', '0', true, "expected '0', got '0'"],
	['attributeEquals', ['missing', null], '/attribute/missing', null, true, 'expected NULL, got NULL'],
	['attributeEquals', ['missing', ''], '/attribute/missing', null, false, "expected '', got NULL"],
	['attributeMatches', ['data/name', '/^item-[0-9]+$/'], '/attribute/data%2Fname', 'item-42', true, "expected a match for '/^item-[0-9]+$/', got 'item-42'"],
	['attributeMatches', ['data/name', '/^item-[0-9]+$/'], '/attribute/data%2Fname', 'other', false, "expected a match for '/^item-[0-9]+$/', got 'other'"],
	['attributeMatches', ['missing', '/^$/'], '/attribute/missing', null, false, "expected a match for '/^$/', got NULL"],
];

scenario('fluent element assertions report each result once and continue after recorded failures', function ($listener) use ($assertionCases) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#name'], [Element::REFERENCE => 'input']);
	foreach ($assertionCases as [$method, $arguments, $path, $actual]):
		exchange($listener, 'GET', '/session/session%2F1/element/input' . $path, null, $actual);
	endforeach;
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) use ($assertionCases) {
	$recorder = new class {
		public array $results = [];
		public function assert(bool $condition, string $message): bool
		{
			$this->results[] = [$condition, $message];
			return $condition;
		}
	};
	$browser = new Browser($url, 'firefox', assert: [$recorder, 'assert']);
	$element = $browser->find('#name');
	foreach ($assertionCases as $index => [$method, $arguments, $path, $actual, $expected, $message]):
		verify($element->$method(...$arguments) === $element, "$method lost the fluent receiver.");
		verify(count($recorder->results) === $index + 1, "$method did not record exactly once.");
		verify($recorder->results[$index][0] === $expected, "$method reported the wrong result.");
		verify(str_contains($recorder->results[$index][1], "Element 'input'") && str_contains($recorder->results[$index][1], $message), "$method lost expected/actual diagnostics.");
	endforeach;
	$browser->quit();
});

scenario('assertions throw on failure without a recorder and preserve the session', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#name'], [Element::REFERENCE => 'input']);
	exchange($listener, 'POST', '/session/session%2F1/element/input/clear', [], null);
	exchange($listener, 'POST', '/session/session%2F1/element/input/value', ['text' => 'Jane'], null);
	exchange($listener, 'GET', '/session/session%2F1/element/input/property/value', null, 'Jane');
	exchange($listener, 'GET', '/session/session%2F1/element/input/displayed', null, true);
	exchange($listener, 'GET', '/session/session%2F1/element/input/text', null, 'Actual');
	exchange($listener, 'POST', '/session/session%2F1/elements', ['using' => 'css selector', 'value' => '.missing'], []);
	exchange($listener, 'GET', '/session/session%2F1/url', null, 'https://example.test/actual');
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	$element = $browser->find('#name');
	verify($element->setValue('Jane')->valueEquals('Jane')->isVisible() === $element, 'Action/assertion chain failed.');
	expectError(fn() => $element->textEquals('Expected'), "WebDriver assertion failed: Element 'input' text: expected 'Expected', got 'Actual'.");
	expectError(fn() => $browser->findAll('.missing')->countEquals(1), 'expected 1, got 0');
	expectError(fn() => $browser->urlMatches('~/expected$~'), "got 'https://example.test/actual'");
	$browser->quit();
});

scenario('count assertions report empty window, descendant and shadow searches without re-querying', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/elements', ['using' => 'css selector', 'value' => '.missing'], []);
	exchange($listener, 'POST', '/session/session%2F1/elements', ['using' => 'css selector', 'value' => 'input'], [[Element::REFERENCE => 'one'], [Element::REFERENCE => 'two']]);
	exchange($listener, 'POST', '/session/session%2F1/element/one/elements', ['using' => 'css selector', 'value' => '.missing'], []);
	exchange($listener, 'GET', '/session/session%2F1/element/one/shadow', null, [ShadowRoot::REFERENCE => 'shadow']);
	exchange($listener, 'POST', '/session/session%2F1/shadow/shadow/elements', ['using' => 'css selector', 'value' => '.missing'], []);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$results = [];
	$browser = new Browser($url, 'firefox', assert: function ($condition, $message) use (&$results) { $results[] = [$condition, $message]; });
	$empty = $browser->findAll('.missing');
	verify($empty->countEquals(0)->countEquals(1) === $empty, 'Empty count assertion lost its set.');
	$set = $browser->findAll('input');
	verify($set->countEquals(2)->countEquals(1) === $set, 'Count assertion refreshed membership or lost its set.');
	$set->first()->findAll('.missing')->countEquals(0);
	$set->first()->shadowRoot()->findAll('.missing')->countEquals(0);
	$explicit = new ElementSet(...$set->all());
	verify($explicit->countEquals(2) === $explicit, 'Explicit snapshot lost its element recorder.');
	$standalone = new ElementSet();
	verify($standalone->countEquals(0) === $standalone, 'Standalone empty snapshot cannot assert its count.');
	expectError(fn() => $standalone->countEquals(1), 'expected 1, got 0');
	verify(array_column($results, 0) === [true, false, true, false, true, true, true], 'Count results were not shared with the browser recorder.');
	verify($results[1][1] === 'Element count: expected 1, got 0.', 'Count failure lacks expected/actual values.');
	$browser->quit();
});

scenario('URL assertions retain receivers and select the top-level window without restoring frames', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
	exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'other'], null);
	exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'window/1'], null);
	exchange($listener, 'GET', '/session/session%2F1/url', null, 'https://example.test/projects/42');
	exchange($listener, 'GET', '/session/session%2F1/url', null, 'https://example.test/projects/42');
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$results = [];
	$browser = new Browser($url, 'firefox', assert: function ($condition, $message) use (&$results) { $results[] = [$condition, $message]; });
	$window = $browser->window()->switchFrame(0);
	$browser->switch('other');
	verify($window->urlMatches('~/projects/\d+$~') === $window, 'URL assertion lost its window.');
	verify($browser->urlMatches('~/missing$~') === $browser, 'URL assertion lost its browser.');
	verify(array_column($results, 0) === [true, false], 'URL assertions reported incorrect results.');
	verify(str_contains($results[1][1], "'~/missing$~'") && str_contains($results[1][1], 'https://example.test/projects/42'), 'URL diagnostics lost expected/actual values.');
	$browser->quit();
});

scenario('element assertions restore captured context and distinguish stale references from driver errors', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#original'], [Element::REFERENCE => 'original']);
	exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'other'], null);
	exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'window/1'], null);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
	exchange($listener, 'GET', '/session/session%2F1/element/original/displayed', null, true);
	foreach (['stale element reference', 'stale element reference', 'no such element', 'unknown error'] as $error):
		exchange($listener, 'GET', '/session/session%2F1/element/original/displayed', null, ['error' => $error, 'message' => 'stale element reference: detail'], 404);
	endforeach;
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => null], null);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], ['error' => 'stale element reference', 'message' => 'frame removed'], 404);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$results = [];
	$browser = new Browser($url, 'firefox', assert: function ($condition, $message) use (&$results) { $results[] = [$condition, $message]; });
	$element = $browser->switchFrame(0)->find('#original');
	$browser->switch('other');
	$element->isVisible();
	verify($element->isNotVisible() === $element, 'Stale visibility assertion lost original identity.');
	expectError(fn() => $element->isVisible(), 'stale element reference');
	expectError(fn() => $element->isNotVisible(), 'no such element');
	expectError(fn() => $element->isNotVisible(), 'unknown error');
	$browser->defaultFrame();
	expectError(fn() => $element->isNotVisible(), 'frame removed');
	verify(array_column($results, 0) === [true, true], 'Driver/context errors were reported as assertions.');
	verify(str_contains($results[1][1], 'got stale'), 'Stale assertion diagnostics were lost.');
	$browser->quit();
});

scenario('invalid assertion patterns and callback exceptions propagate without extra reports', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#name'], [Element::REFERENCE => 'input']);
	exchange($listener, 'GET', '/session/session%2F1/element/input/attribute/missing', null, null);
	exchange($listener, 'GET', '/session/session%2F1/url', null, 'https://example.test/');
	exchange($listener, 'GET', '/session/session%2F1/element/input/displayed', null, false);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$calls = 0;
	$expected = new RuntimeException('WebDriver HTTP 404 stale element reference: recorder failure');
	$browser = new Browser($url, 'firefox', assert: function () use (&$calls, $expected) { $calls++; throw $expected; });
	$element = $browser->find('#name');
	expectError(fn() => $element->attributeMatches('missing', '/[/'), 'Could not evaluate assertion pattern');
	expectError(fn() => $browser->urlMatches('not delimited'), 'Could not evaluate assertion pattern');
	verify($calls === 0, 'Invalid patterns reached the assertion recorder.');
	try {
		$element->isNotVisible();
		throw new RuntimeException('Callback exception was swallowed.');
	} catch (RuntimeException $error) {
		verify($error === $expected, 'Callback exception was replaced.');
	}
	verify($calls === 1, 'Callback was called more than once.');
	$browser->quit();
});

scenario('endpoint prefix and browser capabilities', function ($listener) {
	exchange($listener, 'POST', '/wd/hub/session', ['capabilities' => ['alwaysMatch' => [
		'goog:chromeOptions' => ['args' => ['--headless=new']], 'browserName' => 'chrome',
	]]], ['sessionId' => 'session/1', 'capabilities' => []]);
	exchange($listener, 'DELETE', '/wd/hub/session/session%2F1', null, null);
}, function ($url) {
	$driver = new Browser($url . '/wd/hub/', 'chrome', capabilities: [
		'goog:chromeOptions' => ['args' => ['--headless=new']],
	]);
	$driver->quit();
});

scenario('non-JSON HTTP error reports status', function ($listener) {
	[$stream] = acceptRequest($listener);
	fwrite($stream, "HTTP/1.1 502 Bad Gateway\r\nContent-Length: 4\r\nConnection: close\r\n\r\noops");
	fclose($stream);
}, function ($url) {
	expectError(fn() => new Browser($url, 'firefox'), 'HTTP 502 returned invalid JSON');
});

scenario('failed WebSocket upgrade cleans up HTTP session', function ($listener) {
	createSession($listener, bidi: true);
	[$stream] = acceptRequest($listener);
	fwrite($stream, "HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\n\r\n");
	fclose($stream);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	expectError(fn() => new Browser($url, 'firefox', bidi: true), 'Invalid WebSocket upgrade');
});

scenario('HTTP errors preserve session and driver message', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#missing'], ['error' => 'no such element', 'message' => 'Missing selector #missing'], 404);
	exchange($listener, 'DELETE', '/session/session%2F1', null, ['error' => 'unknown error', 'message' => 'retry deletion'], 500);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$driver = new Browser($url, 'firefox');
	expectError(fn() => $driver->find('#missing'), 'no such element: Missing selector #missing');
	expectError(fn() => $driver->quit(), 'retry deletion');
	$driver->quit();
});

scenario('unsupported BiDi cleans up HTTP session', function ($listener) {
	createSession($listener, bidi: true, supported: false);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	expectError(fn() => new Browser($url, 'firefox', bidi: true), 'requested BiDi WebSocket URL');
});

scenario('BiDi response matching, event queue, errors, and live events', function ($listener) {
	createSession($listener, bidi: true);
	$socket = upgrade($listener);
	bidiRequest($socket, 1, 'session.subscribe', ['events' => ['log.entryAdded']]);
	foreach (['first', 'second'] as $text):
		bidiReply($socket, ['type' => 'event', 'method' => 'log.entryAdded', 'params' => ['text' => $text]]);
	endforeach;
	bidiReply($socket, ['type' => 'success', 'id' => 1, 'result' => ['subscription' => 'subscription-1']]);
	bidiRequest($socket, 2, 'unknown.command');
	bidiReply($socket, ['type' => 'error', 'id' => 2, 'error' => 'unknown command', 'message' => 'Unsupported method']);
	bidiRequest($socket, 3, 'browsingContext.getTree');
	bidiReply($socket, ['type' => 'success', 'id' => 3, 'result' => ['contexts' => []]]);
	bidiReply($socket, ['type' => 'event', 'method' => 'log.entryAdded', 'params' => ['text' => 'third']]);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($socket);
}, function ($url) {
	$driver = new Browser($url, 'firefox', bidi: true);
	verify($driver->command('session.subscribe', ['events' => ['log.entryAdded']]) === ['subscription' => 'subscription-1'], 'Incorrect BiDi result.');
	verify($driver->event()['params']['text'] === 'first', 'Lost first queued event.');
	verify($driver->event()['params']['text'] === 'second', 'Lost second queued event.');
	expectError(fn() => $driver->command('unknown.command'), 'unknown command: Unsupported method');
	verify($driver->command('browsingContext.getTree') === ['contexts' => []], 'BiDi unusable after command error.');
	verify($driver->event()['params']['text'] === 'third', 'Lost live event.');
	$driver->quit();
});

foreach (['timeout', 'mismatched ID', 'malformed envelope'] as $mode):
	scenario("BiDi $mode leaves HTTP cleanup available", function ($listener) use ($mode) {
		createSession($listener, bidi: true);
		$socket = upgrade($listener);
		if ($mode !== 'timeout'):
			bidiRequest($socket, 1, 'browsingContext.getTree');
			bidiReply($socket, $mode === 'mismatched ID'
				? ['type' => 'success', 'id' => 99, 'result' => []]
				: ['type' => 'success', 'id' => 1, 'result' => null]);
		endif;
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
		fclose($socket);
	}, function ($url) use ($mode) {
		$driver = new Browser($url, 'firefox', bidi: true);
		if ($mode === 'timeout'):
			expectError(fn() => $driver->event(0.05), 'timed out');
		else:
			expectError(fn() => $driver->command('browsingContext.getTree'), $mode === 'mismatched ID' ? 'unexpected command ID' : 'Invalid WebDriver BiDi');
		endif;
		expectError(fn() => $driver->command('browsingContext.getTree'), 'No active WebDriver BiDi');
		$driver->quit();
	});
endforeach;

scenario('fluent browser, window, and descendant element chains', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/window/rect', ['width' => 1440, 'height' => 900], ['width' => 1440, 'height' => 900]);
	exchange($listener, 'POST', '/session/session%2F1/url', ['url' => 'https://example.test/'], null);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#form'], [Element::REFERENCE => 'form/1']);
	exchange($listener, 'POST', '/session/session%2F1/element/form%2F1/element', ['using' => 'css selector', 'value' => '#name'], [Element::REFERENCE => 'input/1']);
	exchange($listener, 'POST', '/session/session%2F1/element/input%2F1/clear', [], null);
	exchange($listener, 'POST', '/session/session%2F1/element/input%2F1/value', ['text' => 'Hello'], null);
	exchange($listener, 'POST', '/session/session%2F1/element/input%2F1/value', ['text' => Browser::TAB], null);
	exchange($listener, 'GET', '/session/session%2F1/element/input%2F1/property/value', null, 'Hello');
	exchange($listener, 'POST', '/session/session%2F1/window/rect', ['width' => 800, 'height' => 600], ['width' => 800, 'height' => 600]);
	exchange($listener, 'POST', '/session/session%2F1/url', ['url' => 'https://example.test/next'], null);
	exchange($listener, 'GET', '/session/session%2F1/window/rect', null, ['width' => 800, 'height' => 600]);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	verify($browser->resize(1440, 900)->navigate('https://example.test/') === $browser, 'Browser chain lost identity.');
	$input = $browser->find('#form')->find('#name');
	verify($input->clear()->type('Hello')->key(Browser::TAB) === $input, 'Element chain lost identity.');
	verify($input->property('value') === 'Hello', 'Query should return its value.');
	$window = $browser->window();
	verify($window->resize(800, 600)->navigate('https://example.test/next') === $window, 'Window chain lost identity.');
	verify($browser->rect() === ['width' => 800, 'height' => 600], 'Geometry query lost its result.');
	$browser->quit();
});

scenario('window creation, automatic selection, closure, and shared identity', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/window/new', ['type' => 'tab'], ['handle' => 'tab/2', 'type' => 'tab']);
	exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'tab/2'], null);
	exchange($listener, 'POST', '/session/session%2F1/url', ['url' => 'https://example.test/tab'], null);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => 'button'], [Element::REFERENCE => 'button/2']);
	exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'window/1'], null);
	exchange($listener, 'GET', '/session/session%2F1/title', null, 'Original');
	exchange($listener, 'GET', '/session/session%2F1/title', null, 'Original');
	exchange($listener, 'GET', '/session/session%2F1/window/handles', null, ['tab/2', 'window/1']);
	exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'tab/2'], null);
	exchange($listener, 'DELETE', '/session/session%2F1/actions', null, null);
	exchange($listener, 'DELETE', '/session/session%2F1/window', null, ['window/1']);
	exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'window/1'], null);
	exchange($listener, 'POST', '/session/session%2F1/window/new', ['type' => 'window'], ['handle' => 'window/3', 'type' => 'window']);
	exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'window/3'], null);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	$original = $browser->window();
	$tab = $browser->open()->navigate('https://example.test/tab');
	verify($tab instanceof Window && $browser->window() === $tab, 'Open did not select its Window.');
	$button = $tab->find('button');
	verify($original->title() === 'Original' && $browser->title() === 'Original', 'Explicit window did not become current.');
	verify($browser->windows() === [$tab, $original], 'Window objects were not shared.');
	verify($tab->releaseActions() === $tab && $browser->window() === $tab, 'Release did not select its owning window.');
	verify($tab->close() === $browser && $browser->window() === $original, 'Close did not select remaining window.');
	expectError(fn() => $tab->title(), 'window is closed');
	expectError(fn() => $tab->releaseActions(), 'window is closed');
	expectError(fn() => $button->click(), 'window is closed');
	$other = $browser->open('window');
	verify($other->handle() === 'window/3' && $browser->window() === $other, 'Window creation lost selection.');
	$browser->quit();
	expectError(fn() => $original->navigate('about:blank'), 'No active WebDriver session');
});

scenario('elements and shadow roots restore nested frames across window switches', function ($listener) {
	createSession($listener);
	$outer = [Element::REFERENCE => 'outer'];
	$inner = [Element::REFERENCE => 'inner'];
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#outer'], $outer);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => $outer], null);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#inner'], $inner);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => $inner], null);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#input'], [Element::REFERENCE => 'input']);
	exchange($listener, 'GET', '/session/session%2F1/element/input/shadow', null, [ShadowRoot::REFERENCE => 'root']);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => null], null);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#top'], [Element::REFERENCE => 'top']);
	foreach ([$outer, $inner] as $frame) exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => $frame], null);
	exchange($listener, 'POST', '/session/session%2F1/element/input/click', [], null);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => null], null);
	exchange($listener, 'POST', '/session/session%2F1/element/top/click', [], null);
	foreach ([$outer, $inner] as $frame) exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => $frame], null);
	exchange($listener, 'POST', '/session/session%2F1/shadow/root/element', ['using' => 'css selector', 'value' => 'button'], [Element::REFERENCE => 'shadow-button']);
	exchange($listener, 'POST', '/session/session%2F1/element/shadow-button/click', [], null);
	exchange($listener, 'POST', '/session/session%2F1/window/new', ['type' => 'tab'], ['handle' => 'tab', 'type' => 'tab']);
	exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'tab'], null);
	exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'window/1'], null);
	foreach ([$outer, $inner] as $frame) exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => $frame], null);
	exchange($listener, 'GET', '/session/session%2F1/element/input/text', null, 'Nested');
	exchange($listener, 'POST', '/session/session%2F1/frame/parent', [], null);
	exchange($listener, 'POST', '/session/session%2F1/url', ['url' => 'about:blank'], null);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => 'body'], [Element::REFERENCE => 'new-body']);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	$window = $browser->window();
	$browser->switchFrame($browser->find('#outer'))->switchFrame($browser->find('#inner'));
	$input = $browser->find('#input');
	$root = $input->shadowRoot();
	verify($browser->defaultFrame() === $browser, 'Default frame is not fluent.');
	$top = $browser->find('#top');
	expectError(fn() => $browser->script('return arguments[0]', arguments: [$input]), 'another browser session or browsing context');
	expectError(fn() => $top->dragDrop($input), 'another browser session or browsing context');
	expectError(fn() => $browser->switchFrame($input), 'another browser session or browsing context');
	$input->click();
	$top->click();
	$root->find('button')->click();
	$browser->open();
	verify($input->text() === 'Nested' && $browser->window() === $window, 'Element lost its original window/frame.');
	verify($window->parentFrame() === $window, 'Parent frame is not fluent.');
	verify($browser->navigate('about:blank')->find('body')->id() === 'new-body', 'Navigation did not reset frames.');
	$browser->quit();
});

scenario('nested script references, action origins, and foreign-session rejection', function ($listener) {
	createSession($listener);
	createSession($listener);
	$element = [Element::REFERENCE => 'input'];
	$root = [ShadowRoot::REFERENCE => 'root'];
	$arguments = [['nested' => [$element, ['root' => $root]]]];
	exchange($listener, 'GET', '/session/session%2F1/timeouts', null, ['script' => 100]);
	exchange($listener, 'POST', '/session/session%2F1/execute/sync', ['script' => 'return arguments[0]', 'args' => $arguments], $arguments[0]);
	exchange($listener, 'POST', '/session/session%2F1/element/input/click', [], null);
	exchange($listener, 'POST', '/session/session%2F1/shadow/root/element', ['using' => 'css selector', 'value' => 'button'], [Element::REFERENCE => 'button']);
	exchange($listener, 'POST', '/session/session%2F1/actions', ['actions' => [[
		'type' => 'pointer', 'id' => 'mouse', 'actions' => [['type' => 'pointerMove', 'origin' => $element, 'x' => 0, 'y' => 0]],
	]]], null);
	exchange($listener, 'DELETE', '/session/session%2F1/actions', null, null);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	$window = $browser->window();
	$foreign = new Browser($url, 'firefox');
	$foreignWindow = $foreign->window();
	$element = new Element($window, 'input');
	$root = new ShadowRoot($window, 'root');
	$other = new Element($foreignWindow, 'input');
	expectError(fn() => $browser->switch($foreignWindow), 'another browser session');
	expectError(fn() => $browser->script('', arguments: [['nested' => $other]]), 'another browser session');
	expectError(fn() => $browser->script('', arguments: [new ShadowRoot($foreignWindow, 'root')]), 'another browser session');
	expectError(fn() => $browser->script('', arguments: [new Element($browser->window('other-tab'), 'input')]), 'another browser session or browsing context');
	expectError(fn() => $element->dragDrop($other), 'another browser session');
	expectError(fn() => $browser->actions([['origin' => $other]]), 'another browser session');
	$result = $browser->script('return arguments[0]', arguments: [['nested' => [$element, (object)['root' => $root]]]]);
	verify($result['nested'][0] instanceof Element && $result['nested'][0]->id() === 'input', 'Nested element result lost.');
	verify($result['nested'][1]['root'] instanceof ShadowRoot, 'Nested shadow-root result lost.');
	$result['nested'][0]->click();
	verify($result['nested'][1]['root']->find('button')->id() === 'button', 'Returned root lost context.');
	verify($browser->actions([['type' => 'pointer', 'id' => 'mouse', 'actions' => [
		['type' => 'pointerMove', 'origin' => $element, 'x' => 0, 'y' => 0],
	]]])->releaseActions() === $browser, 'Actions chain lost its receiver.');
	$foreign->quit();
	$browser->quit();
});

scenario('window waits restore their context and empty sets require an explicit check', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/elements', ['using' => 'css selector', 'value' => '.ready'], []);
	exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'other'], null);
	exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'window/1'], null);
	exchange($listener, 'POST', '/session/session%2F1/elements', ['using' => 'css selector', 'value' => '.ready'], [[Element::REFERENCE => 'ready']]);
	exchange($listener, 'POST', '/session/session%2F1/element/ready/click', [], null);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	$window = $browser->window();
	$calls = 0;
	$window->wait(function ($receiver) use ($window, $browser, &$calls) {
		verify($receiver === $window, 'Window wait callback received the wrong receiver.');
		$elements = $receiver->findAll('.ready');
		if (++$calls === 1) $browser->switch('other');
		return $elements->first();
	}, timeout: 1)->click();
	verify($calls === 2 && $browser->window() === $window, 'Wait did not restore its original window.');
	$browser->quit();
});

scenario('failed frame restoration never redirects an element command', function ($listener) {
	createSession($listener);
	$frame = [Element::REFERENCE => 'frame'];
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => 'iframe'], $frame);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => $frame], null);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => 'input'], [Element::REFERENCE => 'input']);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => null], null);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => $frame], ['error' => 'stale element reference', 'message' => 'frame removed'], 404);
	exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'window/1'], null);
	exchange($listener, 'POST', '/session/session%2F1/url', ['url' => 'about:blank'], null);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	$window = $browser->window();
	$input = $browser->switchFrame($browser->find('iframe'))->find('input');
	$browser->defaultFrame();
	expectError(fn() => $input->click(), 'stale element reference: frame removed');
	$window->navigate('about:blank');
	$browser->quit();
});

foreach (['switchFrame', 'parentFrame'] as $command):
	foreach ([false, true] as $explicitReset):
		scenario("lost $command response recovers with " . ($explicitReset ? 'defaultFrame' : 'saved selection'), function ($listener) use ($command, $explicitReset) {
			createSession($listener);
			if ($command === 'parentFrame') exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
			[$stream, $head, $body] = acceptRequest($listener);
			$path = $command === 'switchFrame' ? '/frame' : '/frame/parent';
			verify(str_starts_with($head, 'POST /session/session%2F1' . $path . ' HTTP/'), 'Expected frame-changing request.');
			verify(json_decode($body, true) === ($command === 'switchFrame' ? ['id' => 0] : []), 'Wrong frame request body.');
			// Simulate the driver changing context, then losing the response.
			fclose($stream);
			exchange($listener, 'GET', '/session/session%2F1/window', null, 'window/1');
			exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => null], null);
			if (!$explicitReset && $command === 'parentFrame') exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
			exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#target'], [Element::REFERENCE => 'target']);
			exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => null], null);
			exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => null], null);
			exchange($listener, 'DELETE', '/session/session%2F1', null, null);
		}, function ($url) use ($command, $explicitReset) {
			$browser = new Browser($url, 'firefox');
			if ($command === 'parentFrame') $browser->switchFrame(0);
			expectError(fn() => $command === 'switchFrame' ? $browser->switchFrame(0) : $browser->parentFrame(), 'failed to connect or receive headers');
			if ($explicitReset) verify($browser->defaultFrame() === $browser, 'Reset lost receiver.');
			verify($browser->find('#target')->id() === 'target', 'Lookup did not recover.');
			$browser->defaultFrame()->defaultFrame();
			$browser->quit();
		});
	endforeach;
endforeach;

$topLevelCalls = [
	['title', [], 'GET', '/title', null, 'Page'],
	['url', [], 'GET', '/url', null, 'https://example.test/'],
	['rect', [], 'GET', '/window/rect', null, ['width' => 800, 'height' => 600]],
	['resize', [800, 600], 'POST', '/window/rect', ['width' => 800, 'height' => 600], []],
	['move', [10, 20], 'POST', '/window/rect', ['x' => 10, 'y' => 20], []],
	['maximize', [], 'POST', '/window/maximize', [], []],
	['minimize', [], 'POST', '/window/minimize', [], []],
	['fullscreen', [], 'POST', '/window/fullscreen', [], []],
	['alertText', [], 'GET', '/alert/text', null, 'Prompt'],
	['alertType', ['Answer'], 'POST', '/alert/text', ['text' => 'Answer'], null],
	['alertAccept', [], 'POST', '/alert/accept', [], null],
	['alertDismiss', [], 'POST', '/alert/dismiss', [], null],
	['screenshot', [], 'GET', '/screenshot', null, base64_encode('capture')],
];
scenario('top-level operations bypass stale frames but preserve logical selection', function ($listener) use ($topLevelCalls) {
	createSession($listener);
	$frame = [Element::REFERENCE => 'removed-frame'];
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => 'iframe'], $frame);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => $frame], null);
	foreach ($topLevelCalls as [$method, $args, $verb, $path, $body, $value]):
		exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'other'], null);
		exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'window/1'], null);
		exchange($listener, $verb, '/session/session%2F1' . $path, $body, $value);
	endforeach;
	// The saved iframe is still used for a frame-dependent lookup, which must fail.
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => $frame], ['error' => 'stale element reference', 'message' => 'iframe replaced'], 404);
	exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'window/1'], null);
	exchange($listener, 'GET', '/session/session%2F1/title', null, 'Still available');
	exchange($listener, 'POST', '/session/session%2F1/window/new', ['type' => 'tab'], ['handle' => 'new', 'type' => 'tab']);
	exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'new'], null);
	exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'window/1'], null);
	exchange($listener, 'DELETE', '/session/session%2F1/window', null, ['new']);
	exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'new'], null);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) use ($topLevelCalls) {
	$browser = new Browser($url, 'firefox');
	$window = $browser->window();
	$window->switchFrame($window->find('iframe'));
	foreach ($topLevelCalls as [$method, $args, $verb, $path, $body, $value]):
		$browser->switch('other');
		$result = $window->$method(...$args);
		if ($method === 'screenshot'):
			verify($result->data() === 'capture', 'Screenshot failed.');
		else:
			verify($result === ($verb === 'POST' ? $window : $value), "$method returned the wrong result.");
		endif;
		verify($browser->window() === $window, 'Owning window was not selected.');
	endforeach;
	expectError(fn() => $window->find('input'), 'iframe replaced');
	verify($window->title() === 'Still available', 'Title did not recover after stale frame.');
	$browser->open();
	$window->close();
	$browser->quit();
});

scenario('frame-dependent operations restore logical selection after top-level queries', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
	foreach (['source', 'pdf', 'releaseActions'] as $operation):
		exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'other'], null);
		exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'window/1'], null);
		exchange($listener, 'GET', '/session/session%2F1/title', null, 'Top');
		exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
		if ($operation === 'source') exchange($listener, 'GET', '/session/session%2F1/source', null, '<p>Frame</p>');
		if ($operation === 'pdf') exchange($listener, 'POST', '/session/session%2F1/print', PRINT_DEFAULTS, base64_encode('PDF'));
		if ($operation === 'releaseActions') exchange($listener, 'DELETE', '/session/session%2F1/actions', null, null);
	endforeach;
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	$window = $browser->window()->switchFrame(0);
	foreach (['source', 'pdf', 'releaseActions'] as $operation):
		$browser->switch('other');
		verify($window->title() === 'Top', 'Top-level query failed.');
		$result = $window->$operation();
		verify($result === $window || $result === '<p>Frame</p>' || ($result instanceof PDF && $result->data() === 'PDF'), 'Frame operation failed.');
	endforeach;
	$browser->quit();
});

scenario('navigation discards saved stale frames and subsequent lookups target the top level', function ($listener) {
	createSession($listener);
	foreach (['navigate' => '/url', 'back' => '/back', 'forward' => '/forward', 'refresh' => '/refresh'] as $method => $path):
		exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
		exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'other'], null);
		exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'window/1'], null);
		exchange($listener, 'POST', '/session/session%2F1' . $path, $method === 'navigate' ? ['url' => 'about:blank'] : [], null);
		exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => 'body'], [Element::REFERENCE => 'top']);
	endforeach;
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	$window = $browser->window();
	foreach (['navigate', 'back', 'forward', 'refresh'] as $method):
		$window->switchFrame(0);
		$browser->switch('other');
		$result = $method === 'navigate' ? $window->navigate('about:blank') : $window->$method();
		verify($result === $window && $window->find('body')->id() === 'top', 'Navigation retained a stale frame.');
	endforeach;
	$browser->quit();
});

scenario('recursive and deep arguments throw safely while repeated references remain valid', function ($listener) {
	createSession($listener);
	$shared = ['element' => [Element::REFERENCE => 'input'], 'root' => [ShadowRoot::REFERENCE => 'root']];
	exchange($listener, 'GET', '/session/session%2F1/timeouts', null, ['script' => 100]);
	exchange($listener, 'POST', '/session/session%2F1/execute/sync', ['script' => 'return arguments', 'args' => [$shared, $shared]], 'ok');
	exchange($listener, 'POST', '/session/session%2F1/actions', ['actions' => [
		['id' => 'mouse', 'type' => 'pointer', 'actions' => [
			['type' => 'pointerMove', 'origin' => [Element::REFERENCE => 'input'], 'x' => 0, 'y' => 0],
			['type' => 'pointerMove', 'origin' => [Element::REFERENCE => 'input'], 'x' => 0, 'y' => 0],
		]],
	]], null);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	$object = new stdClass();
	$object->self = $object;
	$array = [];
	$array['self'] = &$array;
	$mixed = new stdClass();
	$mixed->array = ['object' => $mixed];
	$deep = 'leaf';
	for ($i = 0; $i < 70; $i++) $deep = [$deep];
	foreach ([$object, $array, $mixed, $deep] as $argument):
		foreach (['script', 'actions'] as $method):
			try {
				if ($method === 'script') $browser->script('', arguments: [$argument]);
				else $browser->actions([$argument]);
				throw new RuntimeException('Expected catchable argument rejection.');
			} catch (InvalidArgumentException $error) {
				verify(str_contains($error->getMessage(), 'maximum 64 levels'), 'Wrong validation error.');
			}
		endforeach;
	endforeach;
	$shared = (object)['element' => new Element($browser->window(), 'input'), 'root' => new ShadowRoot($browser->window(), 'root')];
	verify($browser->script('return arguments', arguments: [$shared, $shared]) === 'ok', 'Shared references were rejected.');
	$move = ['type' => 'pointerMove', 'origin' => $shared->element, 'x' => 0, 'y' => 0];
	$browser->actions([['id' => 'mouse', 'type' => 'pointer', 'actions' => [$move, $move]]]);
	$browser->quit();
});

scenario('malformed object references throw without losing the session', function ($listener) {
	createSession($listener, discover: false);
	exchange($listener, 'GET', '/session/session%2F1/window', null, null);
	exchange($listener, 'GET', '/session/session%2F1/window', null, 'window/1');
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => 'input'], [Element::REFERENCE => '']);
	exchange($listener, 'GET', '/session/session%2F1/element/host/shadow', null, [ShadowRoot::REFERENCE => 123]);
	exchange($listener, 'POST', '/session/session%2F1/window/new', ['type' => 'tab'], ['handle' => '', 'type' => 'tab']);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	expectError(fn() => $browser->window(), 'did not return a window handle');
	expectError(fn() => $browser->find('input'), 'did not return an element ID');
	expectError(fn() => (new Element($browser->window(), 'host'))->shadowRoot(), 'did not return a shadow-root ID');
	expectError(fn() => $browser->open(), 'did not return a window handle');
	$browser->quit();
});

// Golden HTTP exchanges cover parameter serialization and nullable/typed return values.
$calls = [
	['clear', ['input/1'], 'POST', '/element/input%2F1/clear', [], null],
	['key', ['input/1', Browser::ENTER], 'POST', '/element/input%2F1/value', ['text' => "\u{E007}"], null],
	['key', ['input/1', Browser::CONTROL . 'a' . Browser::RELEASE], 'POST', '/element/input%2F1/value', ['text' => "\u{E009}a\u{E000}"], null],
	['text', ['input/1'], 'GET', '/element/input%2F1/text', null, 'Visible text'],
	['attribute', ['input/1', 'data/a'], 'GET', '/element/input%2F1/attribute/data%2Fa', null, null],
	['attribute', ['input/1', 'checked'], 'GET', '/element/input%2F1/attribute/checked', null, 'true'],
	['property', ['input/1', 'value'], 'GET', '/element/input%2F1/property/value', null, 'edited'],
	['property', ['input/1', 'checked'], 'GET', '/element/input%2F1/property/checked', null, false],
	['property', ['input/1', 'missing'], 'GET', '/element/input%2F1/property/missing', null, null],
	['property', ['input/1', 'details'], 'GET', '/element/input%2F1/property/details', null, ['count' => 2]],
	['displayed', ['input/1'], 'GET', '/element/input%2F1/displayed', null, false],
	['enabled', ['input/1'], 'GET', '/element/input%2F1/enabled', null, true],
	['selected', ['input/1'], 'GET', '/element/input%2F1/selected', null, false],
	['alertText', [], 'GET', '/alert/text', null, 'Enter a name'],
	['alertType', ['Jane'], 'POST', '/alert/text', ['text' => 'Jane'], null],
	['alertAccept', [], 'POST', '/alert/accept', [], null],
	['alertDismiss', [], 'POST', '/alert/dismiss', [], null],
	['switchFrame', ['frame/1'], 'POST', '/frame', ['id' => ['element-6066-11e4-a52e-4f735466cecf' => 'frame/1']], null],
	['parentFrame', [], 'POST', '/frame/parent', [], null],
	['windows', [], 'GET', '/window/handles', null, ['window/2', 'window/1']],
	['switch', ['window/2'], 'POST', '/window', ['handle' => 'window/2'], null],
	['close', [], 'DELETE', '/window', null, ['window/1']],
	['back', [], 'POST', '/back', [], null],
	['forward', [], 'POST', '/forward', [], null],
	['refresh', [], 'POST', '/refresh', [], null],
];
scenario('interaction, observation, dialog, context, and navigation contracts', function ($listener) use ($calls) {
	createSession($listener);
	foreach ($calls as [$method, $args, $verb, $path, $body, $value]):
		exchange($listener, $verb, '/session/session%2F1' . $path, $body, $value);
		if ($method === 'close') exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'window/1'], null);
	endforeach;
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) use ($calls) {
	$driver = new Browser($url, 'firefox');
	foreach ($calls as [$method, $args, $verb, $path, $body, $value]):
		$receiver = $driver;
		if (str_starts_with($path, '/element/')):
			$receiver = new Element($driver->window(), array_shift($args));
		elseif ($method === 'switchFrame'):
			$args = [new Element($driver->window(), $args[0])];
		endif;
		$result = $receiver->$method(...$args);
		if ($method === 'windows'):
			verify(array_map(fn($window) => $window->handle(), $result) === $value, 'Window discovery failed.');
		else:
			verify($result === ($verb === 'POST' || $method === 'close' ? $receiver : $value), "$method returned the wrong value/type.");
		endif;
	endforeach;
	$driver->quit();
});

scenario('closing the last window ends the session locally', function ($listener) {
	createSession($listener);
	exchange($listener, 'DELETE', '/session/session%2F1/window', null, []);
}, function ($url) {
	$driver = new Browser($url, 'firefox');
	$window = $driver->window();
	$element = new Element($window, 'gone');
	verify($driver->close() === $driver, 'Close lost its browser receiver.');
	$driver->quit();
	expectError(fn() => $driver->title(), 'No active WebDriver session');
	expectError(fn() => $window->title(), 'No active WebDriver session');
	expectError(fn() => $element->click(), 'No active WebDriver session');
});

foreach (['hover' => [], 'doubleClick' => [0, 0], 'rightClick' => [2]] as $method => $buttons):
	scenario("native pointer $method and release", function ($listener) use ($buttons) {
		createSession($listener);
		$actions = [['type' => 'pointerMove', 'duration' => 0, 'origin' => ['element-6066-11e4-a52e-4f735466cecf' => 'target/1'], 'x' => 0, 'y' => 0]];
		foreach ($buttons as $button):
			$actions[] = ['type' => 'pointerDown', 'button' => $button];
			$actions[] = ['type' => 'pointerUp', 'button' => $button];
		endforeach;
		exchange($listener, 'POST', '/session/session%2F1/actions', ['actions' => [[
			'type' => 'pointer', 'id' => 'mouse', 'parameters' => ['pointerType' => 'mouse'], 'actions' => $actions,
		]]], null);
		exchange($listener, 'DELETE', '/session/session%2F1/actions', null, null);
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	}, function ($url) use ($method) {
		$driver = new Browser($url, 'firefox');
		$element = new Element($driver->window(), 'target/1');
		verify($element->$method() === $element, 'Pointer command lost its receiver.');
		$driver->quit();
	});
endforeach;

foreach (['unselected', 'selected', 'disabled', 'missing'] as $state):
	scenario("native select: $state", function ($listener) use ($state) {
		createSession($listener);
		exchange($listener, 'GET', '/session/session%2F1/element/select%2F1/name', null, 'select');
		exchange($listener, 'GET', '/session/session%2F1/element/select%2F1/enabled', null, true);
		exchange($listener, 'POST', '/session/session%2F1/element/select%2F1/elements', ['using' => 'css selector', 'value' => 'option'], [
			['element-6066-11e4-a52e-4f735466cecf' => 'option/1'],
			['element-6066-11e4-a52e-4f735466cecf' => 'option/2'],
		]);
		exchange($listener, 'GET', '/session/session%2F1/element/option%2F1/property/value', null, 'other');
		exchange($listener, 'GET', '/session/session%2F1/element/option%2F2/property/value', null, 'a"b\c');
		if ($state !== 'missing'):
			exchange($listener, 'GET', '/session/session%2F1/element/option%2F2/enabled', null, $state !== 'disabled');
			if ($state !== 'disabled'):
				exchange($listener, 'GET', '/session/session%2F1/element/option%2F2/selected', null, $state === 'selected');
				if ($state === 'unselected'):
					exchange($listener, 'POST', '/session/session%2F1/element/option%2F2/click', [], null);
				endif;
			endif;
		endif;
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	}, function ($url) use ($state) {
		$driver = new Browser($url, 'firefox');
		$select = new Element($driver->window(), 'select/1');
		if ($state === 'missing'):
			expectError(fn() => $select->select('absent'), 'No option matches');
		elseif ($state === 'disabled'):
			expectError(fn() => $select->select('a"b\c'), 'disabled option');
		else:
			verify($select->select('a"b\c') === $select, 'Select lost its receiver.');
		endif;
		$driver->quit();
	});
endforeach;

scenario('wait re-finds missing/stale elements and returns the ready element', function ($listener) {
	createSession($listener);
	$find = ['using' => 'css selector', 'value' => '#ready'];
	exchange($listener, 'POST', '/session/session%2F1/element', $find, ['error' => 'no such element', 'message' => 'not added yet'], 404);
	foreach (['stale', 'hidden', 'ready'] as $id):
		exchange($listener, 'POST', '/session/session%2F1/element', $find, ['element-6066-11e4-a52e-4f735466cecf' => $id]);
		exchange($listener, 'GET', "/session/session%2F1/element/$id/displayed", null,
			$id === 'stale' ? ['error' => 'stale element reference', 'message' => 'replaced'] : $id === 'ready', $id === 'stale' ? 404 : 200);
		if ($id === 'ready'):
			exchange($listener, 'GET', '/session/session%2F1/element/ready/enabled', null, true);
		endif;
	endforeach;
	exchange($listener, 'POST', '/session/session%2F1/element/ready/click', [], null);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$driver = new Browser($url, 'firefox');
	$element = $driver->wait(function ($driver) {
		$id = $driver->find('#ready');
		return $id->displayed() && $id->enabled() ? $id : false;
	}, timeout: 2);
	verify($element instanceof Element && $element->id() === 'ready', 'Wait did not return the callback result.');
	$element->click();
	$driver->quit();
});

scenario('find waits retry searches and return the first present or visible match', function ($listener) {
	createSession($listener);
	$present = ['using' => 'xpath', 'value' => '//input'];
	exchange($listener, 'POST', '/session/session%2F1/element', $present, ['error' => 'no such element', 'message' => 'not yet'], 404);
	exchange($listener, 'POST', '/session/session%2F1/element', $present, [Element::REFERENCE => 'hidden']);
	$visible = ['using' => 'css selector', 'value' => '.ready'];
	foreach (['hidden' => false, 'old' => null, 'replacement' => true] as $id => $displayed):
		exchange($listener, 'POST', '/session/session%2F1/element', $visible, [Element::REFERENCE => $id]);
		exchange($listener, 'GET', "/session/session%2F1/element/$id/displayed", null,
			$displayed ?? ['error' => 'stale element reference', 'message' => 'replaced'], $displayed === null ? 404 : 200);
	endforeach;
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	verify($browser->findWaitPresent('//input', 'xpath', 1000)->id() === 'hidden', 'Presence should not require displayedness.');
	verify($browser->window()->findWaitVisible('.ready', timeout: 1000)->id() === 'replacement', 'Visibility did not re-find after stale reference.');
	$browser->quit();
});

scenario('selector negative waits track replacements and all matching elements', function ($listener) {
	createSession($listener);
	$selector = ['using' => 'xpath', 'value' => '//aside'];
	foreach ([[[Element::REFERENCE => 'old']], [[Element::REFERENCE => 'replacement']], []] as $matches):
		exchange($listener, 'POST', '/session/session%2F1/elements', $selector, $matches);
	endforeach;
	$selector = ['using' => 'css selector', 'value' => '.spinner'];
	exchange($listener, 'POST', '/session/session%2F1/elements', $selector, [[Element::REFERENCE => 'hidden'], [Element::REFERENCE => 'old']]);
	exchange($listener, 'GET', '/session/session%2F1/element/hidden/displayed', null, false);
	exchange($listener, 'GET', '/session/session%2F1/element/old/displayed', null, ['error' => 'stale element reference', 'message' => 'replaced'], 404);
	foreach ([true, false] as $displayed):
		exchange($listener, 'POST', '/session/session%2F1/elements', $selector, [[Element::REFERENCE => 'hidden'], [Element::REFERENCE => 'replacement']]);
		exchange($listener, 'GET', '/session/session%2F1/element/hidden/displayed', null, false);
		exchange($listener, 'GET', '/session/session%2F1/element/replacement/displayed', null, $displayed);
	endforeach;
	foreach (range(1, 4) as $ignored):
		exchange($listener, 'POST', '/session/session%2F1/elements', $selector, []);
	endforeach;
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	verify($browser->waitNotPresent('//aside', 'xpath', 1000) === $browser, 'Browser negative wait lost receiver.');
	verify($browser->waitNotVisible('.spinner', timeout: 1000) === $browser, 'Visible replacement was ignored.');
	$window = $browser->window();
	verify($window->waitNotPresent('.spinner') === $window, 'Window absence wait lost receiver.');
	verify($window->waitNotVisible('.spinner') === $window, 'Window hidden wait lost receiver.');
	verify($browser->waitNotPresent('.spinner') === $browser, 'Absent selector should succeed.');
	verify($browser->waitNotVisible('.spinner') === $browser, 'Absent selector should be invisible.');
	$browser->quit();
});

foreach (['waitNotPresent' => 'name', 'waitNotVisible' => 'displayed'] as $method => $endpoint):
	scenario("element $method retains identity and restores its captured frame", function ($listener) use ($endpoint) {
		createSession($listener);
		exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
		exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#old'], [Element::REFERENCE => 'old']);
		exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'other'], null);
		exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'window/1'], null);
		exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
		exchange($listener, 'GET', "/session/session%2F1/element/old/$endpoint", null, $endpoint === 'name' ? 'input' : true);
		exchange($listener, 'GET', "/session/session%2F1/element/old/$endpoint", null, ['error' => 'stale element reference', 'message' => 'removed'], 404);
		exchange($listener, 'GET', '/session/session%2F1/element/old/name', null, ['error' => 'stale element reference', 'message' => 'still removed'], 404);
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	}, function ($url) use ($method) {
		$browser = new Browser($url, 'firefox');
		$element = $browser->switchFrame(0)->find('#old');
		$browser->switch('other');
		verify($element->$method(timeout: 1000) === $element, 'Element wait lost original receiver.');
		expectError(fn() => $element->tag(), 'still removed');
		$browser->quit();
	});
endforeach;

scenario('value replacement restores element context and preserves append behavior', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#name'], [Element::REFERENCE => 'input']);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => null], null);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
	exchange($listener, 'POST', '/session/session%2F1/element/input/clear', [], null);
	exchange($listener, 'POST', '/session/session%2F1/element/input/value', ['text' => 'Jane €'], null);
	exchange($listener, 'POST', '/session/session%2F1/element/input/value', ['text' => ' Doe'], null);
	exchange($listener, 'POST', '/session/session%2F1/element/input/clear', [], null);
	exchange($listener, 'POST', '/session/session%2F1/element/input/clear', [], ['error' => 'invalid element state', 'message' => 'readonly'], 400);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	$element = $browser->switchFrame(0)->find('#name');
	$browser->defaultFrame();
	verify($element->setValue('Jane €')->type(' Doe') === $element, 'Value replacement lost receiver.');
	verify($element->setValue('') === $element, 'Empty replacement should only clear.');
	expectError(fn() => $element->setValue('blocked'), 'invalid element state');
	$browser->quit();
});

scenario('element visibility wait succeeds when the original node becomes hidden', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#name'], [Element::REFERENCE => 'input']);
	exchange($listener, 'GET', '/session/session%2F1/element/input/displayed', null, true);
	exchange($listener, 'GET', '/session/session%2F1/element/input/displayed', null, false);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	$element = $browser->switchFrame(0)->findWaitPresent('#name');
	verify($element->waitNotVisible(1000) === $element, 'Hidden element did not finish wait.');
	$browser->quit();
});

foreach (['findWaitPresent', 'findWaitVisible', 'waitNotPresent', 'waitNotVisible'] as $method):
	scenario("$method uses millisecond and default timeouts and propagates selector errors", function ($listener) use ($method) {
		createSession($listener);
		$positive = str_starts_with($method, 'find');
		foreach (range(1, 2) as $ignored):
			exchange($listener, 'POST', '/session/session%2F1/' . ($positive ? 'element' : 'elements'),
				['using' => 'css selector', 'value' => '#pending'],
				$method === 'findWaitPresent' ? ['error' => 'no such element', 'message' => 'not yet'] : ($positive ? [Element::REFERENCE => 'pending'] : [[Element::REFERENCE => 'pending']]),
				$method === 'findWaitPresent' ? 404 : 200);
			if (str_ends_with($method, 'Visible')):
				exchange($listener, 'GET', '/session/session%2F1/element/pending/displayed', null, !$positive);
			endif;
		endforeach;
		exchange($listener, 'POST', '/session/session%2F1/' . ($positive ? 'element' : 'elements'),
			['using' => 'css selector', 'value' => '[broken'], ['error' => 'invalid selector', 'message' => 'bad syntax'], 400);
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	}, function ($url) use ($method) {
		$browser = new Browser($url, 'firefox', timeout: 0.05);
		foreach ([20, null] as $timeout):
			$start = microtime(true);
			expectError(fn() => $browser->$method('#pending', timeout: $timeout), 'wait timed out');
			$elapsed = microtime(true) - $start;
			verify($elapsed >= ($timeout ?? 50) / 1000 && $elapsed < 0.5, 'Timeout was not milliseconds or constructor default.');
		endforeach;
		expectError(fn() => $browser->$method('[broken'), 'invalid selector');
		foreach ([0, -1, INF, NAN] as $timeout):
			expectError(fn() => $browser->$method('#pending', timeout: $timeout), 'positive and finite');
		endforeach;
		$browser->quit();
	});
endforeach;

foreach (['waitNotPresent' => 'name', 'waitNotVisible' => 'displayed'] as $method => $endpoint):
	scenario("element $method timeout and unrelated errors", function ($listener) use ($endpoint) {
		createSession($listener);
		exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#pending'], [Element::REFERENCE => 'pending']);
		foreach (range(1, 2) as $ignored):
			exchange($listener, 'GET', "/session/session%2F1/element/pending/$endpoint", null, $endpoint === 'name' ? 'input' : true);
		endforeach;
		foreach (['no such element' => 404, 'unknown error' => 500] as $error => $status):
			exchange($listener, 'GET', "/session/session%2F1/element/pending/$endpoint", null, ['error' => $error, 'message' => 'stale element reference: not a stale error'], $status);
		endforeach;
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	}, function ($url) use ($method) {
		$browser = new Browser($url, 'firefox', timeout: 0.05);
		$element = $browser->find('#pending');
		foreach ([20, null] as $timeout):
			$start = microtime(true);
			expectError(fn() => $element->$method($timeout), 'wait timed out');
			$elapsed = microtime(true) - $start;
			verify($elapsed >= ($timeout ?? 50) / 1000 && $elapsed < 0.5, 'Element timeout units/default changed.');
		endforeach;
		expectError(fn() => $element->$method(20), 'wait timed out');
		expectError(fn() => $element->$method(), 'unknown error');
		foreach ([0, -1, INF, NAN] as $timeout):
			expectError(fn() => $element->$method($timeout), 'positive and finite');
		endforeach;
		$browser->quit();
	});

	scenario("element $method does not treat a stale frame as a stale node", function ($listener) {
		createSession($listener);
		exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
		exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#node'], [Element::REFERENCE => 'node']);
		exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => null], null);
		exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], ['error' => 'stale element reference', 'message' => 'frame removed'], 404);
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	}, function ($url) use ($method) {
		$browser = new Browser($url, 'firefox');
		$element = $browser->switchFrame(0)->find('#node');
		$browser->defaultFrame();
		expectError(fn() => $element->$method(20), 'wait timed out');
		$browser->quit();
	});
endforeach;

scenario('window find wait restores selected window and frame', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
	exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'other'], null);
	exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'window/1'], null);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'xpath', 'value' => '//input'], [Element::REFERENCE => 'input']);
	exchange($listener, 'GET', '/session/session%2F1/element/input/displayed', null, true);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	$window = $browser->window()->switchFrame(0);
	$browser->switch('other');
	verify($window->findWaitVisible('//input', 'xpath')->window() === $window, 'Find wait lost window context.');
	$browser->quit();
});

scenario('wait timeout and nonretryable errors', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '[broken'],
		['error' => 'invalid selector', 'message' => 'no such element is not the error code'], 400);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$driver = new Browser($url, 'firefox');
	$start = microtime(true);
	expectError(fn() => $driver->wait(fn() => false, timeout: 0.04), 'wait timed out');
	verify(microtime(true) - $start < 0.5, 'Wait slept beyond its polling deadline.');
	expectError(fn() => $driver->wait(fn($d) => $d->find('[broken'), timeout: 1), 'invalid selector');
	$expected = new LogicException('callback failure');
	try {
		$driver->wait(function () use ($expected) { throw $expected; });
		throw new RuntimeException('Callback error was swallowed.');
	} catch (LogicException $actual) {
		verify($actual === $expected, 'Callback error was replaced.');
	}
	verify($driver->wait(fn() => ['ready']) === ['ready'], 'Truthy non-boolean result was lost.');
	$driver->quit();
});

scenario('failed pointer sequence still releases input state', function ($listener) {
	createSession($listener);
	$actions = [
		['type' => 'pointerMove', 'duration' => 0, 'origin' => ['element-6066-11e4-a52e-4f735466cecf' => 'target'], 'x' => 0, 'y' => 0],
		['type' => 'pointerDown', 'button' => 2],
		['type' => 'pointerUp', 'button' => 2],
	];
	exchange($listener, 'POST', '/session/session%2F1/actions', ['actions' => [[
		'type' => 'pointer', 'id' => 'mouse', 'parameters' => ['pointerType' => 'mouse'], 'actions' => $actions,
	]]], ['error' => 'move target out of bounds', 'message' => 'outside viewport'], 500);
	exchange($listener, 'DELETE', '/session/session%2F1/actions', null, null);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$driver = new Browser($url, 'firefox');
	expectError(fn() => (new Element($driver->window(), 'target'))->rightClick(), 'move target out of bounds: outside viewport');
	$driver->quit();
});

scenario('select rejects custom and disabled dropdowns', function ($listener) {
	createSession($listener);
	exchange($listener, 'GET', '/session/session%2F1/element/custom/name', null, 'div');
	exchange($listener, 'GET', '/session/session%2F1/element/disabled/name', null, 'select');
	exchange($listener, 'GET', '/session/session%2F1/element/disabled/enabled', null, false);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$driver = new Browser($url, 'firefox');
	expectError(fn() => (new Element($driver->window(), 'custom'))->select('value'), 'native HTML select');
	expectError(fn() => (new Element($driver->window(), 'disabled'))->select('value'), 'disabled select');
	$driver->quit();
});

scenario('script timeout, positional arguments, element references, and result types', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/timeouts', ['script' => 125], null);
	$reference = ['element-6066-11e4-a52e-4f735466cecf' => 'input/1'];
	exchange($listener, 'GET', '/session/session%2F1/timeouts', null, ['script' => 125]);
	exchange($listener, 'POST', '/session/session%2F1/execute/sync', [
		'script' => 'return [arguments[0], arguments[1], arguments[2]];',
		'args' => [$reference, 'input/1', ['nested' => [true, null, 2.5]]],
	], [$reference, 'input/1', ['nested' => [true, null, 2.5]]]);
	foreach ([null, false, 42, 'text'] as $value):
		$script = 'return ' . json_encode($value) . ';';
		exchange($listener, 'GET', '/session/session%2F1/timeouts', null, ['script' => 125]);
		exchange($listener, 'POST', '/session/session%2F1/execute/sync', ['script' => $script, 'args' => []], $value);
	endforeach;
	exchange($listener, 'POST', '/session/session%2F1/timeouts', ['script' => 0], null);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$driver = new Browser($url, 'firefox');
	$driver->window();
	$driver->scriptTimeout(0.125);
	$reference = new Element($driver->window(), 'input/1');
	verify($reference->reference() === ['element-6066-11e4-a52e-4f735466cecf' => 'input/1'], 'Incorrect element reference.');
	$arguments = [3 => $reference, 5 => 'input/1', 7 => ['nested' => [true, null, 2.5]]];
	$result = $driver->script('return [arguments[0], arguments[1], arguments[2]];', arguments: $arguments);
	verify($result[0] instanceof Element && $result[0]->id() === 'input/1', 'Script result was not converted to an Element.');
	verify(array_slice($result, 1) === array_slice(array_values($arguments), 1), 'Script result types changed.');
	foreach ([null, false, 42, 'text'] as $value):
		verify($driver->script('return ' . json_encode($value) . ';') === $value, 'Script result type was lost.');
	endforeach;
	$driver->scriptTimeout(0);
	foreach ([-1, INF, NAN] as $seconds):
		expectError(fn() => $driver->scriptTimeout($seconds), 'Script timeout must be');
	endforeach;
	$driver->quit();
});

scenario('async script outlives ordinary HTTP timeout without changing it', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/timeouts', ['script' => 300], null);
	exchange($listener, 'GET', '/session/session%2F1/timeouts', null, ['script' => 300]);
	exchange($listener, 'POST', '/session/session%2F1/execute/async', [
		'script' => 'arguments[arguments.length - 1](arguments[0]);', 'args' => ['done'],
	], 'done', delay: 0.15);
	exchange($listener, 'GET', '/session/session%2F1/title', null, 'too late', delay: 0.15);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$driver = new Browser($url, 'firefox', timeout: 0.1);
	$driver->window();
	$driver->scriptTimeout(0.3);
	verify($driver->script('arguments[arguments.length - 1](arguments[0]);', async: true, arguments: ['done']) === 'done', 'Async script used the short ordinary timeout.');
	expectError(fn() => $driver->title(), 'failed to connect or receive headers');
	$driver->quit();
});

scenario('JavaScript and browser script-timeout errors preserve the session', function ($listener) {
	createSession($listener);
	exchange($listener, 'GET', '/session/session%2F1/timeouts', null, ['script' => 30000]);
	exchange($listener, 'POST', '/session/session%2F1/execute/sync', ['script' => 'throw new Error("broken");', 'args' => []],
		['error' => 'javascript error', 'message' => 'broken'], 500);
	exchange($listener, 'GET', '/session/session%2F1/timeouts', null, ['script' => 100]);
	exchange($listener, 'POST', '/session/session%2F1/execute/async', ['script' => '/* never invokes callback */', 'args' => []],
		['error' => 'script timeout', 'message' => 'callback did not run'], 500);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$driver = new Browser($url, 'firefox');
	expectError(fn() => $driver->script('throw new Error("broken");'), 'javascript error: broken');
	expectError(fn() => $driver->script('/* never invokes callback */', true), 'script timeout: callback did not run');
	$driver->quit();
});

scenario('subscription helpers preserve interleaved console and other events', function ($listener) {
	createSession($listener, bidi: true);
	$socket = upgrade($listener);
	bidiRequest($socket, 1, 'session.subscribe', ['events' => ['log.entryAdded']]);
	bidiReply($socket, ['type' => 'event', 'method' => 'log.entryAdded', 'params' => ['type' => 'console', 'text' => 'hello']]);
	bidiReply($socket, ['type' => 'success', 'id' => 1, 'result' => ['subscription' => 'subscription-1']]);
	bidiRequest($socket, 2, 'session.unsubscribe', ['subscriptions' => ['subscription-1']]);
	bidiReply($socket, ['type' => 'event', 'method' => 'browsingContext.load', 'params' => ['context' => 'context-1']]);
	bidiReply($socket, ['type' => 'success', 'id' => 2, 'result' => new stdClass()]);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($socket);
}, function ($url) {
	$driver = new Browser($url, 'firefox', bidi: true);
	$id = $driver->subscribe([2 => 'log.entryAdded']);
	verify($id === 'subscription-1', 'Subscription ID was not returned.');
	$driver->unsubscribe([3 => $id]);
	verify($driver->event()['params']['text'] === 'hello', 'Queued console event was lost on unsubscribe.');
	verify($driver->event()['method'] === 'browsingContext.load', 'Unrelated event was consumed.');
	$driver->quit();
});

foreach (['element', 'offset', 'failed'] as $mode):
	scenario("native drag: $mode", function ($listener) use ($mode) {
		createSession($listener);
		$destination = $mode === 'element'
			? ['origin' => ['element-6066-11e4-a52e-4f735466cecf' => 'target/1'], 'x' => 0, 'y' => 0]
			: ['origin' => 'pointer', 'x' => -150, 'y' => 40];
		exchange($listener, 'POST', '/session/session%2F1/actions', ['actions' => [[
			'type' => 'pointer', 'id' => 'mouse', 'parameters' => ['pointerType' => 'mouse'], 'actions' => [
				['type' => 'pointerMove', 'duration' => 0, 'origin' => ['element-6066-11e4-a52e-4f735466cecf' => 'source/1'], 'x' => 0, 'y' => 0],
				['type' => 'pointerDown', 'button' => 0],
				['type' => 'pointerMove', 'duration' => 250] + $destination,
				['type' => 'pointerUp', 'button' => 0],
			],
		]]], $mode === 'failed' ? ['error' => 'move target out of bounds', 'message' => 'outside viewport'] : null, $mode === 'failed' ? 500 : 200);
		exchange($listener, 'DELETE', '/session/session%2F1/actions', null, null);
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	}, function ($url) use ($mode) {
		$driver = new Browser($url, 'firefox');
		$source = new Element($driver->window(), 'source/1');
		if ($mode === 'element'):
			verify($source->dragDrop(new Element($driver->window(), 'target/1')) === $source, 'Drag lost its source.');
		elseif ($mode === 'offset'):
			$source->dragBy(x: -150, y: 40);
		else:
			expectError(fn() => $source->dragBy(-150, 40), 'move target out of bounds');
		endif;
		$driver->quit();
	});
endforeach;

scenario('status before creating any session', function ($listener) {
	exchange($listener, 'GET', '/wd/hub/status', null, ['ready' => false, 'message' => 'busy']);
	exchange($listener, 'GET', '/wd/hub/status', null, ['ready' => true, 'message' => 'ready']);
}, function ($url) {
	verify(Browser::status($url . '/wd/hub/')['ready'] === false, 'Status lost false readiness.');
	verify(Browser::status($url . '/wd/hub')['ready'] === true, 'Status lost true readiness.');
	expectError(fn() => Browser::status($url, timeout: 0), 'positive and finite');
	expectError(fn() => Browser::status('file:///etc/hosts'), 'HTTP(S) driver base URL');
});

scenario('plural, scoped, alternate-locator, focused, and shadow lookups', function ($listener) {
	createSession($listener);
	$element = fn($id) => ['element-6066-11e4-a52e-4f735466cecf' => $id];
	exchange($listener, 'POST', '/session/session%2F1/elements', ['using' => 'css selector', 'value' => 'tr'], [$element('row/1'), $element('row/2')]);
	exchange($listener, 'POST', '/session/session%2F1/elements', ['using' => 'css selector', 'value' => '.absent'], []);
	exchange($listener, 'POST', '/session/session%2F1/element/row%2F1/element', ['using' => 'xpath', 'value' => './/input'], $element('input/1'));
	exchange($listener, 'POST', '/session/session%2F1/element/row%2F2/elements', ['using' => 'tag name', 'value' => 'td'], [$element('cell/1')]);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'link text', 'value' => 'Continue'], $element('link/1'));
	exchange($listener, 'POST', '/session/session%2F1/elements', ['using' => 'partial link text', 'value' => 'More'], [$element('link/2')]);
	exchange($listener, 'GET', '/session/session%2F1/element/active', null, $element('input/1'));
	exchange($listener, 'GET', '/session/session%2F1/element/host%2F1/shadow', null, ['shadow-6066-11e4-a52e-4f735466cecf' => 'shadow/1']);
	exchange($listener, 'POST', '/session/session%2F1/shadow/shadow%2F1/element', ['using' => 'css selector', 'value' => 'button'], $element('button/1'));
	exchange($listener, 'POST', '/session/session%2F1/shadow/shadow%2F1/elements', ['using' => 'css selector', 'value' => 'input'], [$element('input/2'), $element('input/3')]);
	exchange($listener, 'POST', '/session/session%2F1/shadow/shadow%2F1/elements', ['using' => 'css selector', 'value' => '.absent'], []);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$driver = new Browser($url, 'firefox');
	$ids = fn(ElementSet $set) => array_map(fn($element) => $element->id(), $set->all());
	$rows = $driver->findAll('tr');
	verify($ids($rows) === ['row/1', 'row/2'], 'Plural lookup lost ID order.');
	verify(count($rows) === 2 && $rows->first() === $rows->get(0), 'Collection count/first failed.');
	verify(iterator_to_array($rows) === $rows->all(), 'Iteration changed membership/order.');
	expectError(fn() => $rows->get(-1), 'out of range');
	expectError(fn() => $rows->get(2), 'out of range');
	$empty = $driver->findAll('.absent');
	verify(count($empty) === 0 && $empty->first() === null && $empty->all() === [], 'Empty collection failed.');
	verify($rows->get(0)->find('.//input', using: 'xpath')->id() === 'input/1', 'Scoped XPath lookup failed.');
	verify($ids($rows->get(1)->findAll('td', using: 'tag name')) === ['cell/1'], 'Scoped plural lookup failed.');
	verify($driver->find('Continue', using: 'link text')->id() === 'link/1', 'Link lookup failed.');
	verify($ids($driver->findAll('More', using: 'partial link text')) === ['link/2'], 'Partial-link lookup failed.');
	verify($driver->activeElement()->id() === 'input/1', 'Focused element was not unwrapped.');
	$shadow = (new Element($driver->window(), 'host/1'))->shadowRoot();
	verify($shadow instanceof ShadowRoot && $shadow->id() === 'shadow/1', 'Wrong shadow-root ID.');
	verify($shadow->find('button')->id() === 'button/1', 'Shadow lookup failed.');
	verify($ids($shadow->findAll('input')) === ['input/2', 'input/3'], 'Plural shadow lookup failed.');
	verify(count($shadow->findAll('.absent')) === 0, 'Empty shadow lookup should return an empty set.');
	$driver->quit();
});

$windowRect = ['x' => -20, 'y' => 10, 'width' => 1200, 'height' => 800];
$commands = [
	['url', [], 'GET', '/url', null, 'https://example.test/redirected?x=1'],
	['source', [], 'GET', '/source', null, '<html><body>Current DOM</body></html>'],
	['css', ['input/1', '--theme/color'], 'GET', '/element/input%2F1/css/--theme%2Fcolor', null, 'rgb(1, 2, 3)'],
	['tag', ['input/1'], 'GET', '/element/input%2F1/name', null, 'input'],
	['rect', ['input/1'], 'GET', '/element/input%2F1/rect', null, ['x' => 10.5, 'y' => 20, 'width' => 100.25, 'height' => 25]],
	['role', ['input/1'], 'GET', '/element/input%2F1/computedrole', null, 'textbox'],
	['label', ['input/1'], 'GET', '/element/input%2F1/computedlabel', null, 'Full name'],
	['switchFrame', [0], 'POST', '/frame', ['id' => 0], null],
	['switchFrame', ['0'], 'POST', '/frame', ['id' => ['element-6066-11e4-a52e-4f735466cecf' => '0']], null],
	['rect', [], 'GET', '/window/rect', null, $windowRect],
	['resize', [1200, 800], 'POST', '/window/rect', ['width' => 1200, 'height' => 800], $windowRect],
	['move', [-20, 10], 'POST', '/window/rect', ['x' => -20, 'y' => 10], $windowRect],
	['maximize', [], 'POST', '/window/maximize', [], $windowRect],
	['minimize', [], 'POST', '/window/minimize', [], $windowRect],
	['fullscreen', [], 'POST', '/window/fullscreen', [], $windowRect],
	['timeouts', [], 'GET', '/timeouts', null, ['script' => null, 'pageLoad' => 300000, 'implicit' => 0]],
	['pageLoadTimeout', [12.345], 'POST', '/timeouts', ['pageLoad' => 12345], null],
	['implicitTimeout', [0.125], 'POST', '/timeouts', ['implicit' => 125], null],
	['implicitTimeout', [0], 'POST', '/timeouts', ['implicit' => 0], null],
];
scenario('document, geometry, accessibility, window, and timeout commands', function ($listener) use ($commands) {
	createSession($listener);
	foreach ($commands as [$method, $args, $verb, $path, $body, $value]):
		exchange($listener, $verb, '/session/session%2F1' . $path, $body, $value);
	endforeach;
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) use ($commands) {
	$driver = new Browser($url, 'firefox');
	foreach ($commands as [$method, $args, $verb, $path, $body, $value]):
		$receiver = $driver;
		if (str_starts_with($path, '/element/')):
			$receiver = new Element($driver->window(), array_shift($args));
		elseif ($method === 'switchFrame' && is_string($args[0])):
			$args = [new Element($driver->window(), $args[0], [0])];
		endif;
		verify($receiver->$method(...$args) === ($verb === 'POST' ? $receiver : $value), "$method changed the returned value/type.");
	endforeach;
	expectError(fn() => $driver->pageLoadTimeout(-1), 'PageLoad timeout must be');
	expectError(fn() => $driver->implicitTimeout(NAN), 'Implicit timeout must be');
	$driver->quit();
});

scenario('cookie fields, encoded names, deletion, and missing-cookie errors', function ($listener) {
	createSession($listener);
	$cookie = ['name' => 'session/name', 'value' => 'opaque', 'path' => '/', 'domain' => 'example.test',
		'secure' => true, 'httpOnly' => true, 'sameSite' => 'Lax', 'expiry' => 1900000000];
	exchange($listener, 'POST', '/session/session%2F1/cookie', ['cookie' => $cookie], null);
	exchange($listener, 'GET', '/session/session%2F1/cookie', null, [$cookie]);
	exchange($listener, 'GET', '/session/session%2F1/cookie/session%2Fname', null, $cookie);
	exchange($listener, 'DELETE', '/session/session%2F1/cookie/session%2Fname', null, null);
	exchange($listener, 'GET', '/session/session%2F1/cookie/session%2Fname', null, ['error' => 'no such cookie', 'message' => 'missing'], 404);
	exchange($listener, 'DELETE', '/session/session%2F1/cookie', null, null);
	exchange($listener, 'GET', '/session/session%2F1/cookie', null, []);
	exchange($listener, 'POST', '/session/session%2F1/cookie', ['cookie' => [
		'name' => 'empty', 'value' => '', 'path' => '/', 'secure' => false, 'httpOnly' => false,
	]], null);
	exchange($listener, 'GET', '/session/session%2F1/cookie/empty', null, ['name' => 'empty', 'value' => '']);
	exchange($listener, 'POST', '/session/session%2F1/cookie', ['cookie' => [
		'name' => 'zero', 'value' => '0', 'path' => '/', 'secure' => false, 'httpOnly' => false,
	]], null);
	exchange($listener, 'GET', '/session/session%2F1/cookie/zero', null, ['name' => 'zero', 'value' => '0']);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$driver = new Browser($url, 'firefox');
	$cookie = ['name' => 'session/name', 'value' => 'opaque', 'path' => '/', 'domain' => 'example.test',
		'secure' => true, 'httpOnly' => true, 'sameSite' => 'Lax', 'expiry' => 1900000000];
	verify($driver->cookie(
		name: 'session/name',
		value: 'opaque',
		domain: 'example.test',
		path: '/',
		secure: true,
		httpOnly: true,
		sameSite: 'Lax',
		expiry: 1900000000,
	) === $driver, 'Adding a cookie lost the browser receiver.');
	verify($driver->cookies() === [$cookie], 'Cookie fields changed.');
	verify($driver->cookie('session/name') === 'opaque', 'Named cookie value changed.');
	$driver->cookieDelete('session/name');
	expectError(fn() => $driver->cookie('session/name'), 'no such cookie: missing');
	$driver->cookieDeleteAll();
	verify($driver->cookies() === [], 'Expected no cookies.');
	$window = $driver->window();
	verify($window->cookie('empty', '') === $window, 'Setting an empty cookie lost the window receiver.');
	verify($window->cookie('empty') === '', 'Empty cookie value changed.');
	verify($driver->cookie('zero', '0') === $driver, 'Setting a zero cookie lost the browser receiver.');
	verify($driver->cookie('zero', null) === '0', 'Null value did not read the cookie.');
	$driver->quit();
});

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
scenario('screenshot and PDF objects, decoding, persistence, and invalid captures', function ($listener) use ($png) {
	createSession($listener);
	exchange($listener, 'GET', '/session/session%2F1/screenshot', null, base64_encode($png));
	exchange($listener, 'GET', '/session/session%2F1/element/input%2F1/screenshot', null, base64_encode("\x89PNG\r\n\x1a\n\0element"));
	exchange($listener, 'POST', '/session/session%2F1/print', PRINT_DEFAULTS, base64_encode('%PDF-1.7 default'));
	foreach (['window', 'browser'] as $receiver):
		exchange($listener, 'POST', '/session/session%2F1/print', [
			'orientation' => 'landscape', 'scale' => 0.75, 'background' => true,
			'page' => ['width' => 21, 'height' => 29.7],
			'margin' => ['top' => 0, 'bottom' => 0.25, 'left' => 0.5, 'right' => 1.5],
			'pageRanges' => [1, '3-5'], 'shrinkToFit' => false,
		], base64_encode('%PDF-1.7 ' . $receiver));
	endforeach;
	exchange($listener, 'GET', '/session/session%2F1/screenshot', null, '%%%invalid%%%');
	exchange($listener, 'POST', '/session/session%2F1/print', PRINT_DEFAULTS, ['error' => 'unsupported operation', 'message' => 'printing unavailable'], 500);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) use ($png) {
	$driver = new Browser($url, 'firefox');
	$screenshot = $driver->screenshot();
	verify($screenshot instanceof Screenshot && $screenshot->data() === $png, 'Viewport screenshot was not decoded.');
	verify($screenshot->width() === 1 && $screenshot->height() === 1, 'Screenshot dimensions changed.');
	$elementScreenshot = (new Element($driver->window(), 'input/1'))->screenshot();
	verify($elementScreenshot instanceof Screenshot && $elementScreenshot->data() === "\x89PNG\r\n\x1a\n\0element", 'Element screenshot was not decoded.');
	expectError(fn() => $elementScreenshot->width(), 'valid PNG image header');
	$pdf = $driver->pdf();
	verify($pdf instanceof PDF && $pdf->data() === '%PDF-1.7 default', 'Default PDF was not decoded.');
	foreach (['window' => $driver->window(), 'browser' => $driver] as $name => $receiver):
		verify($receiver->pdf(
			orientation: 'landscape', scale: 0.75, background: true,
			pageWidth: 21, pageHeight: 29.7,
			marginTop: 0, marginBottom: 0.25, marginLeft: 0.5, marginRight: 1.5,
			pageRanges: [1, '3-5'], shrinkToFit: false,
		)->data() === '%PDF-1.7 ' . $name, 'Named print arguments or PDF changed.');
	endforeach;
	expectError(fn() => $driver->screenshot(), 'invalid base64 capture');
	expectError(fn() => $driver->pdf(), 'unsupported operation: printing unavailable');
	$driver->quit();
	verify($screenshot->data() === $png && $pdf->data() === '%PDF-1.7 default', 'Captures depend on a live session.');
	$path = tempnam(sys_get_temp_dir(), 'webdriver-capture-');
	verify($path !== false, 'Could not create capture test file.');
	try {
		foreach ([$screenshot, $pdf] as $capture):
			verify($capture->save($path) === $capture, 'Saving a capture lost its receiver.');
			verify(file_get_contents($path) === $capture->data(), 'Saved capture bytes changed.');
			expectError(fn() => $capture->save($path . '/missing/file'), 'Could not write the complete');
		endforeach;
	} finally {
		unlink($path);
	}
});

scenario('general actions retain input until explicit release', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/actions', ['actions' => [
		['type' => 'key', 'id' => 'keyboard', 'actions' => [['type' => 'keyDown', 'value' => "\u{E008}"]]],
	]], null);
	exchange($listener, 'POST', '/session/session%2F1/actions', ['actions' => [
		['type' => 'wheel', 'id' => 'wheel', 'actions' => [['type' => 'scroll', 'x' => 0, 'y' => 0, 'deltaX' => 0, 'deltaY' => 500, 'duration' => 250, 'origin' => 'viewport']]],
	]], null);
	exchange($listener, 'DELETE', '/session/session%2F1/actions', null, null);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$driver = new Browser($url, 'firefox');
	$driver->actions([2 => ['type' => 'key', 'id' => 'keyboard', 'actions' => [['type' => 'keyDown', 'value' => Browser::SHIFT]]]]);
	$driver->actions([['type' => 'wheel', 'id' => 'wheel', 'actions' => [[
		'type' => 'scroll', 'x' => 0, 'y' => 0, 'deltaX' => 0, 'deltaY' => 500, 'duration' => 250, 'origin' => 'viewport',
	]]]]);
	$driver->releaseActions();
	$driver->quit();
});

scenario('session response completes without waiting for the server to close', function ($listener) {
	[$stream, $head] = acceptRequest($listener);
	verify(str_starts_with($head, 'POST /session HTTP/'), 'Expected session creation.');
	$json = json_encode(['value' => ['sessionId' => 'session/1', 'capabilities' => []]]);
	fwrite($stream, "HTTP/1.1 200 OK\r\nContent-Length:" . strlen($json) . "\r\nContent-Type: application/json\r\nConnection: close\r\n\r\n$json");
	// Reproduce ChromeDriver: keep the response connection open until the client closes it.
	verify(fread($stream, 1) === '' && feof($stream), 'Client did not finish the framed response.');
	fclose($stream);
	exchange($listener, 'GET', '/session/session%2F1/window', null, 'window/1');
	exchange($listener, 'POST', '/session/session%2F1/url', ['url' => 'https://example.test/'], null);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$driver = new Browser($url, 'chrome', timeout: 0.1);
	$driver->navigate('https://example.test/');
	$driver->quit();
});

foreach (['truncated', 'stalled'] as $mode):
	scenario("reject $mode HTTP response bodies", function ($listener) use ($mode) {
		[$stream] = acceptRequest($listener);
		// Even valid JSON is incomplete when it is shorter than the declared body length.
		$json = '{"value":{"ready":true}}';
		fwrite($stream, "HTTP/1.1 200 OK\r\nContent-Length: " . (strlen($json) + 10) . "\r\n\r\n$json");
		if ($mode === 'stalled') usleep(200000);
		fclose($stream);
	}, function ($url) {
		expectError(fn() => Browser::status($url, timeout: 0.05), 'incomplete body');
	});
endforeach;

foreach (['chunked', 'eof'] as $mode):
	scenario("read $mode HTTP response without Content-Length", function ($listener) use ($mode) {
		[$stream] = acceptRequest($listener);
		$json = '{"value":{"ready":true}}';
		if ($mode === 'chunked'):
			fwrite($stream, "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n" . dechex(strlen($json)) . "\r\n$json\r\n0\r\n\r\n");
		else:
			fwrite($stream, "HTTP/1.1 200 OK\r\nConnection: close\r\n\r\n$json");
		endif;
		fclose($stream);
	}, function ($url) {
		verify(Browser::status($url, timeout: 0.1)['ready'] === true, 'Unframed HTTP response failed.');
	});
endforeach;
