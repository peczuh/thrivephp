<?php

// Run with: php test/webdriver.php (pcntl is used only by the local test driver).
require dirname(__DIR__) . '/src/WebSocket.php';
require __DIR__ . '/fixtures/test-runner.php';
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
use ThriveData\ThrivePHP\WebDriver\Snapshot;
use ThriveData\ThrivePHP\WebDriver\PDF;
use ThriveData\ThrivePHP\Test;

/** Observe protocol assertion results independently of the runner's stopping behavior. */
class RecordingBrowser extends Browser
{
	private Closure $record;

	public function __construct(string $endpoint, string $browser, callable $record, float $timeout = 10, float $assertionTimeout = 5000)
	{
		$this->record = Closure::fromCallable($record);
		parent::__construct($endpoint, $browser, timeout: $timeout, assertionTimeout: $assertionTimeout, pollInterval: 100);
	}

	public function reportAssertion(bool $condition, string $message, bool $verify = false, array $comparison = []): void
	{
		($this->record)($condition, $message);
	}
}

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

/** Check script transport and argument marshalling; real DOM tests check the JavaScript's effects. */
function scriptExchange($listener, array $arguments, mixed $value = null, int $status = 200): void
{
	exchange($listener, 'GET', '/session/session%2F1/timeouts', null, ['script' => 30000]);
	[$stream, $head, $body] = acceptRequest($listener);
	verify(str_starts_with($head, 'POST /session/session%2F1/execute/sync HTTP/'), "Unexpected script request: $head");
	$request = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
	verify(is_string($request['script']) && $request['script'] !== '' && $request['args'] === $arguments, "Unexpected script arguments: $body");
	$json = json_encode(['value' => $value]);
	fwrite($stream, "HTTP/1.1 $status Response\r\nContent-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\nConnection: close\r\n\r\n$json");
	fclose($stream);
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

$elementScripts = [
	['property', ['custom', null], null],
	['property', ['checked', false], null],
	['attribute', ['disabled', ''], null],
	['attribute', ['disabled', null], null],
	['submit', [], null],
	['inject', ['<span>content</span>', 'afterend'], null],
	['firstChild', [], [Element::REFERENCE => 'child']],
	['lastChild', [], [Element::REFERENCE => 'child']],
	['nextSibling', [], null],
	['previousSibling', [], null],
];
scenario('element scripts restore context, preserve setter values, and bind traversal references', function ($listener) use ($elementScripts) {
	createSession($listener);
	foreach ($elementScripts as [$method, $arguments, $value]):
		exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'other'], null);
		exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'window/1'], null);
		exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
		if ($method === 'inject') $arguments = [$arguments[1], $arguments[0]];
		scriptExchange($listener, [[Element::REFERENCE => 'node'], ...$arguments], $value);
	endforeach;
	scriptExchange($listener, [[Element::REFERENCE => 'node'], 'related', ['nodes' => [[Element::REFERENCE => 'child']]]]);
	exchange($listener, 'GET', '/session/session%2F1/element/node/attribute/data%2Fkey', null, 'value');
	exchange($listener, 'GET', '/session/session%2F1/element/node/property/custom', null, false);
	foreach ($elementScripts as [$method, $arguments]):
		if ($method === 'inject') $arguments = [$arguments[1], $arguments[0]];
		scriptExchange($listener, [[Element::REFERENCE => 'node'], ...$arguments], ['error' => 'stale element reference', 'message' => 'removed'], 404);
	endforeach;
	scriptExchange($listener, [[Element::REFERENCE => 'node'], 'invalid', ''], ['error' => 'javascript error', 'message' => 'invalid position'], 500);
	exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'other'], null);
	exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'window/1'], null);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], ['error' => 'no such frame', 'message' => 'frame removed'], 404);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) use ($elementScripts) {
	$browser = new Browser($url, 'firefox');
	$window = $browser->window();
	$element = new Element($window, 'node', [0]);
	foreach ($elementScripts as [$method, $arguments, $value]):
		$browser->switch('other');
		$result = $element->$method(...$arguments);
		if (in_array($method, ['firstChild', 'lastChild', 'nextSibling', 'previousSibling'], true)):
			if ($value === null):
				verify($result === null, 'Empty traversal did not return null.');
			else:
				verify($result->reference($window, [0]) === $value && !$result->canRefresh(), 'Traversal lost context or acquired a lookup.');
			endif;
		else:
			verify($result === $element, "$method lost its fluent receiver.");
		endif;
	endforeach;
	$child = new Element($window, 'child', [0]);
	verify($element->property('related', ['nodes' => [$child]]) === $element, 'Nested property assignment lost receiver.');
	verify($element->attribute(key: 'data/key') === 'value' && $element->property(key: 'custom') === false, 'Getter behavior changed.');
	expectError(fn() => $element->property('related', new Element($window, 'foreign')), 'another browser session or browsing context');
	foreach ($elementScripts as [$method, $arguments]):
		expectError(fn() => $element->$method(...$arguments), 'stale element reference: removed');
	endforeach;
	expectError(fn() => $element->inject('', 'invalid'), 'javascript error: invalid position');
	$browser->switch('other');
	expectError(fn() => $element->submit(), 'no such frame: frame removed');
	$browser->quit();
});

scenario('script injection facade and window reject incompatible options before execution', function ($listener) {
	createSession($listener);
	scriptExchange($listener, ['window.injected = true;']);
	scriptExchange($listener, ['window.injected = false;']);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	foreach ([$browser, $browser->window()] as $receiver):
		expectError(fn() => $receiver->script('', async: true, inject: true), 'requires async: false and no arguments');
		expectError(fn() => $receiver->script('', arguments: [null], inject: true), 'requires async: false and no arguments');
	endforeach;
	verify($browser->script('window.injected = true;', inject: true) === null, 'Browser injection did not return null.');
	verify($browser->window()->script('window.injected = false;', inject: true) === null, 'Window injection did not return null.');
	$browser->quit();
});

foreach ([['check', 'checkbox', false], ['check', 'checkbox', true], ['check', 'radio', false], ['check', 'radio', true], ['uncheck', 'checkbox', false], ['uncheck', 'checkbox', true]] as [$method, $type, $selected]):
	scenario("$method $type selected=" . (int)$selected, function ($listener) use ($method, $type, $selected) {
		createSession($listener);
		exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
		exchange($listener, 'GET', '/session/session%2F1/element/node/name', null, 'input');
		exchange($listener, 'GET', '/session/session%2F1/element/node/property/type', null, $type);
		exchange($listener, 'GET', '/session/session%2F1/element/node/selected', null, $selected);
		if ($selected === ($method === 'uncheck')):
			exchange($listener, 'GET', '/session/session%2F1/element/node/enabled', null, true);
			exchange($listener, 'POST', '/session/session%2F1/element/node/click', [], null);
		endif;
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	}, function ($url) use ($method) {
		$browser = new Browser($url, 'firefox');
		$element = new Element($browser->window(), 'node', [0]);
		verify($element->$method() === $element, 'Check action lost receiver.');
		$browser->quit();
	});
endforeach;

scenario('check actions reject unsupported controls and propagate click errors', function ($listener) {
	createSession($listener);
	foreach ([['div', null], ['input', 'text'], ['input', 'radio']] as [$tag, $type]):
		exchange($listener, 'GET', '/session/session%2F1/element/node/name', null, $tag);
		if ($type !== null) exchange($listener, 'GET', '/session/session%2F1/element/node/property/type', null, $type);
	endforeach;
	exchange($listener, 'GET', '/session/session%2F1/element/node/name', null, 'input');
	exchange($listener, 'GET', '/session/session%2F1/element/node/property/type', null, 'checkbox');
	exchange($listener, 'GET', '/session/session%2F1/element/node/selected', null, false);
	exchange($listener, 'GET', '/session/session%2F1/element/node/enabled', null, true);
	exchange($listener, 'POST', '/session/session%2F1/element/node/click', [], ['error' => 'element click intercepted', 'message' => 'covered'], 400);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	$element = new Element($browser->window(), 'node');
	expectError(fn() => $element->check(), 'requires a checkbox or radio input');
	expectError(fn() => $element->check(), 'requires a checkbox or radio input');
	expectError(fn() => $element->uncheck(), 'requires a checkbox input');
	expectError(fn() => $element->check(wait: false), 'element click intercepted: covered');
	$browser->quit();
});

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

foreach (['element', 'browser URL', 'window URL', 'count', 'empty snapshot', 'find', 'wait'] as $operation):
	scenario("automatic Test reporting stops $operation failures and runs cleanup", function ($listener) use ($operation) {
		createSession($listener);
		exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#node'], [Element::REFERENCE => 'node']);
		exchange($listener, 'GET', '/session/session%2F1/element/node/displayed', null, true);
		switch ($operation) {
			case 'element':
				exchange($listener, 'GET', '/session/session%2F1/element/node/text', null, 'Actual');
				break;
			case 'browser URL':
			case 'window URL':
				exchange($listener, 'GET', '/session/session%2F1/url', null, 'https://example.test/actual');
				break;
			case 'count':
				exchange($listener, 'POST', '/session/session%2F1/elements', ['using' => 'css selector', 'value' => '.missing'], []);
				break;
			case 'find':
				exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '.missing'], ['error' => 'no such element', 'message' => 'absent'], 404);
				break;
			case 'wait':
				exchange($listener, 'GET', '/session/session%2F1/element/node/displayed', null, false);
				break;
		}
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	}, function ($url) use ($operation) {
		$continued = false;
		$cleaned = false;
		[$status, $output] = runChecks([
			'check' => function (Test $test) use ($url, $operation, &$continued, &$cleaned) {
				$browser = new Browser($url, 'firefox');
				try {
					$element = $browser->find('#node')->visible(wait: false);
					$test->verify(false, 'continuing verification');
					switch ($operation) {
						case 'element': $element->textEquals('Expected', wait: false); break;
						case 'browser URL': $browser->urlMatches('~/expected$~', wait: false); break;
						case 'window URL': $browser->window()->urlMatches('~/expected$~', wait: false); break;
						case 'count': $browser->findAll('.missing')->countEquals(1, wait: false); break;
						case 'empty snapshot': (new ElementSet())->countEquals(1, wait: false); break;
						case 'find': $browser->find('.missing', wait: false, assert: true); break;
						case 'wait': $element->wait(visible: true, assert: true, timeout: 20); break;
					}
					$continued = true;
				} finally {
					$browser->quit();
					$cleaned = true;
				}
			},
			'next' => function (Test $test) { $test->assert(true, 'Subsequent check ran.'); },
		]);
		verify($status === 1 && !$continued && $cleaned, 'Browser failure did not stop its check and run cleanup: ' . $output);
		verify(str_contains($output, 'Suites: 1 passed, 1 failed') && str_contains($output, 'Assertions: 2 passed, 2 failed'), $output);
		verify(str_contains($output, 'expected ') && !str_contains($output, 'Execution error:'), 'Browser failure diagnostic missing: ' . $output);
		verify(Test::active() === null, 'Browser failure leaked its active recorder.');
	});
endforeach;

foreach (['browser', 'window', 'element', 'shadow'] as $scope):
	scenario("$scope find verification records once, returns null on failure and leaves assert stopping", function ($listener) use ($scope) {
		createSession($listener);
		$path = '/session/session%2F1' . match ($scope) { 'element' => '/element/parent', 'shadow' => '/shadow/root', default => '' };
		foreach ([true, false] as $record) exchange($listener, 'POST', $path . '/element', ['using' => 'css selector', 'value' => '#ready'], [Element::REFERENCE => 'ready']);
		exchange($listener, 'POST', $path . '/element', ['using' => 'css selector', 'value' => '#missing'], ['error' => 'no such element', 'message' => 'absent'], 404);
		exchange($listener, 'POST', $path . '/element', ['using' => 'css selector', 'value' => '#hidden'], [Element::REFERENCE => 'hidden']);
		exchange($listener, 'GET', '/session/session%2F1/element/hidden/displayed', null, false);
		exchange($listener, 'POST', $path . '/elements', ['using' => 'css selector', 'value' => '#gone'], []);
		exchange($listener, 'POST', $path . '/elements', ['using' => 'css selector', 'value' => '#present'], [[Element::REFERENCE => 'present']]);
		exchange($listener, 'POST', $path . '/element', ['using' => 'css selector', 'value' => '#missing'], ['error' => 'no such element', 'message' => 'absent'], 404);
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	}, function ($url) use ($scope) {
		$continued = false;
		$stopped = true;
		[$status, $output, $record] = runChecks(['check' => function () use ($url, $scope, &$continued, &$stopped) {
			$browser = new Browser($url, 'firefox');
			try {
				$target = match ($scope) {
					'browser' => $browser, 'window' => $browser->window(),
					'element' => new Element($browser->window(), 'parent'),
					'shadow' => new ShadowRoot($browser->window(), 'root'),
				};
				verify($target->find('#ready', wait: false, verify: true)->id() === 'ready', 'Successful verification lost its element.');
				$target->find('#ready', wait: false);
				verify($target->find('#missing', timeout: 20, verify: true) === null, 'Expired verification did not return null.');
				verify($target->find('#hidden', visible: true, wait: false, assert: true, verify: true) === null, 'Hidden verification did not return null.');
				verify($target->find('#gone', wait: false, invert: true, verify: true) === null, 'Successful inverse verification did not return null.');
				verify($target->find('#present', wait: false, invert: true, verify: true) === null, 'Failed inverse verification did not return null.');
				$continued = true;
				$target->find('#missing', wait: false, assert: true);
				$stopped = false;
			} finally {
				$browser->quit();
			}
		}]);
		verify($status === 1 && $continued && $stopped && str_contains($output, 'Assertions: 2 passed, 4 failed')
			&& !str_contains($output, 'Execution error'), $output);
		$steps = array_values(array_filter($record['suites'][0]['attempts'][0]['steps'], fn($step) => $step['label'] === 'find'));
		verify(array_map(fn($step) => count($step['assertions']), $steps) === [1, 0, 1, 1, 1, 1, 1]
			&& array_column($steps, 'error') === array_fill(0, 7, null), 'Lookup verification duplicated assertions or errors.');
	});
endforeach;

scenario('original-element wait verification continues while preserving identity and explicit assertion counts', function ($listener) {
	createSession($listener);
	foreach ([false, false, true] as $visible) exchange($listener, 'GET', '/session/session%2F1/element/node/displayed', null, $visible);
	foreach ([true, false] as $record) exchange($listener, 'GET', '/session/session%2F1/element/node/name', null, 'input');
	exchange($listener, 'GET', '/session/session%2F1/element/node/displayed', null, false);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$continued = false;
	$stopped = true;
	[$status, $output] = runChecks(['check' => function () use ($url, &$continued, &$stopped) {
		$browser = new Browser($url, 'firefox');
		try {
			$element = new Element($browser->window(), 'node');
			verify($element->wait(visible: true, timeout: 20, verify: true) === $element, 'Expired verification lost its receiver.');
			verify($element->wait(visible: true, invert: true, verify: true) === $element, 'Hiddenness verification lost its receiver.');
			$element->wait(visible: true, assert: true, verify: true);
			$element->wait(invert: true, timeout: 20, verify: true);
			$element->wait();
			verify($element->id() === 'node', 'Original-node wait changed identity.');
			$continued = true;
			$element->wait(visible: true, assert: true, timeout: 20);
			$stopped = false;
		} finally {
			$browser->quit();
		}
	}]);
	verify($status === 1 && $continued && $stopped && str_contains($output, 'Assertions: 2 passed, 3 failed')
		&& !str_contains($output, 'Execution error'), $output);
});

scenario('wait verification failures still throw without an active Test', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#missing'], ['error' => 'no such element', 'message' => 'absent'], 404);
	exchange($listener, 'GET', '/session/session%2F1/element/node/displayed', null, false);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	expectError(fn() => $browser->find('#missing', wait: false, verify: true), 'assertion failed');
	expectError(fn() => (new Element($browser->window(), 'node'))->wait(visible: true, timeout: 20, verify: true), 'assertion failed');
	$browser->quit();
});

scenario('wait verification propagates selector, session and stale-context errors without assertion reports', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '[broken'], ['error' => 'invalid selector', 'message' => 'broken'], 400);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#node'], ['error' => 'invalid session id', 'message' => 'gone'], 404);
	exchange($listener, 'GET', '/session/session%2F1/element/node/displayed', null, ['error' => 'stale element reference', 'message' => 'removed'], 404);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], ['error' => 'no such frame', 'message' => 'removed'], 404);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$results = [];
	$browser = new RecordingBrowser($url, 'firefox', record: function ($passed) use (&$results) { $results[] = $passed; });
	expectError(fn() => $browser->find('[broken', verify: true), 'invalid selector');
	expectError(fn() => $browser->find('#node', verify: true), 'invalid session id');
	expectError(fn() => (new Element($browser->window(), 'node'))->wait(visible: true, verify: true), 'stale element reference');
	expectError(fn() => (new Element($browser->window(), 'node', [0]))->wait(verify: true), 'no such frame');
	verify($results === [], 'Driver failures became verification mismatches.');
	$browser->quit();
});

/** Supply an observation to a comparison, preserving its actual WebDriver endpoint and body. */
function comparisonExchange($listener, string $path, mixed $value, bool $document, string $id = 'original', int $status = 200): void
{
	$path = '/session/session%2F1' . ($document ? '' : '/element/' . $id) . $path;
	exchange($listener, str_ends_with($path, '/elements') ? 'POST' : 'GET', $path,
		str_ends_with($path, '/elements') ? ['using' => 'css selector', 'value' => '*'] : null, $value, $status);
}

foreach (require __DIR__ . '/fixtures/webdriver-assertions.php' as $method => [$arguments, $path, $good, $bad]):
	$document = in_array($path, ['/url', '/title'], true);
	foreach ($document ? ['browser', 'window'] : ['element'] as $scope):
		scenario("$scope $method obeys the complete comparison contract", function ($listener) use ($path, $good, $bad, $document) {
			createSession($listener);
			if (!$document) exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#node'], [Element::REFERENCE => 'original']);
			comparisonExchange($listener, $path, $good, $document);
			comparisonExchange($listener, $path, $bad, $document);
			foreach ([[$bad, $good], [$good, $bad], [$bad], [$good]] as $values):
				foreach ($values as $index => $value):
					$id = $index === 0 ? 'pending' : 'ready';
					if (!$document) exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#node'], [Element::REFERENCE => $id]);
					comparisonExchange($listener, $path, $value, $document, $id);
				endforeach;
			endforeach;
			foreach ([$bad, $bad, $bad] as $value) comparisonExchange($listener, $path, $value, $document, 'ready');
			comparisonExchange($listener, $path, ['error' => 'unknown error', 'message' => 'driver failure'], $document, 'ready', 500);
			exchange($listener, 'DELETE', '/session/session%2F1', null, null);
		}, function ($url) use ($method, $arguments, $scope, $document) {
			$browser = new Browser($url, 'firefox', waitTimeout: 1, assertionTimeout: 300, pollInterval: 10);
			$target = match ($scope) { 'browser' => $browser, 'window' => $browser->window(), default => $browser->find('#node', wait: false) };
			try {
				[$status, $output, $record] = runChecks(['check' => function () use ($target, $method, $arguments, $document) {
					foreach ([false, true] as $invert):
						verify($target->$method(...[...$arguments, 'wait' => false, 'timeout' => 0, 'invert' => $invert]) === $target, 'One-shot comparison lost receiver.');
					endforeach;
					foreach ([false, true] as $invert):
						verify($target->$method(...[...$arguments, 'invert' => $invert, 'verify' => true]) === $target, 'Waiting verification lost receiver.');
						if (!$document) verify($target->id() === 'ready', 'Successful comparison did not adopt replacement.');
					endforeach;
					foreach ([false, true] as $invert):
						verify($target->$method(...[...$arguments, 'timeout' => 1, 'verify' => true, 'invert' => $invert]) === $target, 'Expired verification lost receiver.');
						if (!$document) verify($target->id() === 'ready', 'Failed comparison adopted replacement.');
					endforeach;
					expectError(fn() => $target->$method(...[...$arguments, 'wait' => false]), 'expected');
				}]);
				verify($status === 1 && str_contains($output, 'Assertions: 4 passed, 3 failed'), $output);
				$steps = $record['suites'][0]['attempts'][0]['steps'];
				verify(count($steps) === 7 && array_column($steps, 'label') === array_fill(0, 7, $method)
					&& array_column($steps, 'error') === array_fill(0, 7, null)
					&& array_map(fn($step) => count($step['assertions']), $steps) === array_fill(0, 7, 1), 'Comparison counted nested steps or duplicate errors.');
				foreach ([false, true] as $verify) expectError(fn() => $target->$method(...[...$arguments, 'wait' => false, 'verify' => $verify]), 'assertion failed');
				foreach ([0, -1, INF, NAN] as $timeout) expectError(fn() => $target->$method(...[...$arguments, 'timeout' => $timeout, 'verify' => true]), 'positive and finite');
				[$status, $output, $record] = runChecks(['check' => fn() => $target->$method(...[...$arguments, 'wait' => false, 'verify' => true])]);
				verify($status === 1 && $record['totals']['assertionsFailed'] === 0
					&& $record['suites'][0]['attempts'][0]['steps'][0]['error']['message'] === 'WebDriver HTTP 500 unknown error: driver failure',
					'Driver exception became a verification mismatch.');
			} finally {
				$browser->quit();
			}
		});
	endforeach;
endforeach;

foreach (['browser', 'window'] as $scope):
	scenario("$scope callback wait propagates stale frame restoration immediately", function ($listener) {
		createSession($listener);
		exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => [Element::REFERENCE => 'frame']], ['error' => 'stale element reference', 'message' => 'frame gone'], 404);
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	}, function ($url) use ($scope) {
		$browser = new Browser($url, 'firefox', waitTimeout: 300, pollInterval: 10);
		$window = $browser->window();
		$frames = [[Element::REFERENCE => 'frame']];
		if ($scope === 'window') $window->rememberFrames($frames);
		$element = new Element($window, 'node', $frames);
		try {
			expectError(fn() => ($scope === 'window' ? $window : $browser)->wait(fn() => $element->text()), 'context restoration failed');
		} finally {
			$browser->quit();
		}
	});
endforeach;

scenario('comparison arguments fail before polling even during inverted verification', function ($listener) {
	createSession($listener);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	$element = new Element($browser->window(), 'node');
	try {
		foreach ([
			[$element, 'textMatches', ['[']], [$element, 'attributeMatches', ['name', '[']],
			[$element, 'propertyMatches', ['name', '[']], [$browser, 'urlMatches', ['[']],
			[$browser, 'titleMatches', ['[']], [$browser->window(), 'urlMatches', ['[']],
			[$browser->window(), 'titleMatches', ['[']],
		] as [$target, $method, $arguments]):
			expectError(fn() => $target->$method(...[...$arguments, 'wait' => false, 'invert' => true, 'verify' => true]), 'Could not evaluate assertion pattern');
		endforeach;
		foreach (['', '  ', [], ['two words'], [null]] as $classes):
			expectError(fn() => $element->hasClass($classes, invert: true, verify: true), 'class');
		endforeach;
	} finally {
		$browser->quit();
	}
});

scenario('negative comparisons require a live matching node and count inversion preserves snapshot semantics', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#node'], [Element::REFERENCE => 'original']);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#node'], ['error' => 'no such element', 'message' => 'absent'], 404);
	exchange($listener, 'POST', '/session/session%2F1/elements', ['using' => 'css selector', 'value' => '.row'], [[Element::REFERENCE => 'original']]);
	exchange($listener, 'POST', '/session/session%2F1/elements', ['using' => 'css selector', 'value' => '.row'], []);
	exchange($listener, 'POST', '/session/session%2F1/elements', ['using' => 'css selector', 'value' => '.row'], [[Element::REFERENCE => 'replacement']]);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox', pollInterval: 10);
	try {
		[$status, $output] = runChecks(['check' => function () use ($browser) {
			$element = $browser->find('#node');
			$element->textEquals('other', invert: true, verify: true, timeout: 1);
			verify($element->id() === 'original', 'Absent node replaced the captured reference.');
			$set = $browser->findAll('.row');
			verify($set->countEquals(1, invert: true) === $set && $set->count() === 0, 'Inverted count did not adopt successful membership.');
			$set->countEquals(1, invert: true, verify: true, timeout: 1);
			verify($set->count() === 0, 'Failed inverted count adopted membership.');
			(new ElementSet())->countEquals(1, invert: true, wait: false);
			(new ElementSet())->countEquals(0, invert: true, wait: false, verify: true);
		}]);
		verify($status === 1 && str_contains($output, 'Assertions: 2 passed, 3 failed'), $output);
	} finally {
		$browser->quit();
	}
});

$assertionCases = [
	['visible', [], '/displayed', true, true, 'expected true, got true'],
	['visible', [], '/displayed', false, false, 'expected true, got false'],
	['visible', [true], '/displayed', false, true, 'expected false or stale, got false'],
	['visible', [true], '/displayed', true, false, 'expected false or stale, got true'],
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
	['propertyContains', ['status', 'Saved'], '/property/status', 'Saved draft', true, "expected to contain 'Saved'"],
	['propertyContains', ['status', ''], '/property/status', null, false, "expected to contain ''"],
	['propertyContains', ['items', ['0']], '/property/items', [0], false, 'property'],
	['hasClass', ['one two'], '/attribute/class', "one\ntwo", true, "expected 'one two'"],
	['hasClass', ['one'], '/attribute/class', 'someone', false, "expected 'one'"],
	['valueContains', [''], '/property/value', null, false, "expected to contain ''"],
];

scenario('element verification records every comparison and leaves subsequent assertions stopping', function ($listener) use ($assertionCases) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#name'], [Element::REFERENCE => 'input']);
	foreach ($assertionCases as [$method, $arguments, $path, $actual]):
		exchange($listener, 'GET', '/session/session%2F1/element/input' . $path, null, $actual);
	endforeach;
	exchange($listener, 'GET', '/session/session%2F1/element/input/text', null, 'Actual');
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) use ($assertionCases) {
	$completed = 0;
	$stopped = true;
	[$status, $output] = runChecks(['check' => function () use ($url, $assertionCases, &$completed, &$stopped) {
		$browser = new Browser($url, 'firefox');
		try {
			$element = $browser->find('#name');
			foreach ($assertionCases as [$method, $arguments]):
				verify($element->$method(...[...$arguments, 'wait' => false, 'verify' => true]) === $element, "$method did not preserve the verification receiver.");
				$completed++;
			endforeach;
			$element->textEquals('Default must stop', wait: false);
			$stopped = false;
		} finally {
			$browser->quit();
		}
	}]);
	$passed = count(array_filter(array_column($assertionCases, 4)));
	$failed = count($assertionCases) - $passed + 1;
	verify($status === 1 && $completed === count($assertionCases) && $stopped, $output);
	verify(str_contains($output, "Assertions: $passed passed, $failed failed") && !str_contains($output, 'Execution error'), $output);
});

scenario('verification waits by default, adopts replacements and records timeout once before continuing', function ($listener) {
	createSession($listener);
	$selector = ['using' => 'css selector', 'value' => '#status'];
	exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => 'original']);
	foreach (['pending' => 'Pending', 'replacement' => 'Saved'] as $id => $text):
		exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => $id]);
		exchange($listener, 'GET', "/session/session%2F1/element/$id/text", null, $text);
	endforeach;
	exchange($listener, 'POST', '/session/session%2F1/element/replacement/click', [], null);
	exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => 'pending']);
	exchange($listener, 'GET', '/session/session%2F1/element/pending/text', null, 'Pending');
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$continued = false;
	[$status, $output] = runChecks(['check' => function () use ($url, &$continued) {
		$browser = new Browser($url, 'firefox');
		try {
			$element = $browser->find('#status');
			verify($element->textEquals('Saved', timeout: 1000, verify: true)->click() === $element, 'Successful verification lost the receiver.');
			verify($element->id() === 'replacement', 'Successful verification did not adopt its replacement.');
			$start = microtime(true);
			verify($element->textEquals('Done', timeout: 20, verify: true) === $element, 'Failed verification lost the receiver.');
			verify(microtime(true) - $start >= 0.02 && $element->id() === 'replacement', 'Timeout did not wait or changed the retained reference.');
			$continued = true;
		} finally {
			$browser->quit();
		}
	}]);
	verify($status === 1 && $continued && str_contains($output, 'Assertions: 1 passed, 1 failed'), $output);
	verify(str_contains($output, "expected 'Done', got 'Pending'") && !str_contains($output, 'Execution error:'), $output);
});

scenario('count and URL verification continue after mismatches and preserve snapshots and receivers', function ($listener) {
	createSession($listener);
	$selector = ['using' => 'css selector', 'value' => '.row'];
	exchange($listener, 'POST', '/session/session%2F1/elements', $selector, [[Element::REFERENCE => 'old']]);
	exchange($listener, 'POST', '/session/session%2F1/elements', $selector, []);
	foreach (['pending', 'pending', 'done'] as $path):
		exchange($listener, 'GET', '/session/session%2F1/url', null, 'https://example.test/' . $path);
	endforeach;
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$continued = false;
	[$status, $output] = runChecks(['check' => function () use ($url, &$continued) {
		$browser = new Browser($url, 'firefox');
		try {
			$set = $browser->findAll('.row');
			verify($set->countEquals(1, timeout: 20, verify: true) === $set && $set->first()->id() === 'old', 'Failed count verification replaced its snapshot.');
			$set->countEquals(1, wait: false);
			verify($browser->urlMatches('~/done$~', timeout: 20, verify: true) === $browser, 'Browser verification lost its receiver.');
			$window = $browser->window();
			verify($window->urlMatches('~/done$~', wait: false, verify: true) === $window, 'Window verification lost its receiver.');
			$browser->urlMatches('~/done$~', verify: true);
			$empty = new ElementSet();
			verify($empty->countEquals(1, wait: false, verify: true)->countEquals(0, wait: false, verify: true) === $empty, 'Explicit empty snapshot lost its receiver.');
			$continued = true;
		} finally {
			$browser->quit();
		}
	}]);
	verify($status === 1 && $continued && str_contains($output, 'Assertions: 3 passed, 4 failed') && !str_contains($output, 'Execution error'), $output);
	expectError(fn() => (new ElementSet())->countEquals(1, wait: false, verify: true), 'WebDriver assertion failed');
});

scenario('verification propagates invalid patterns and driver errors instead of recording mismatches', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#node'], [Element::REFERENCE => 'node']);
	exchange($listener, 'GET', '/session/session%2F1/element/node/text', null, ['error' => 'unknown error', 'message' => 'driver failure'], 500);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$continued = false;
	[$status, $output] = runChecks(['check' => function () use ($url, &$continued) {
		$browser = new Browser($url, 'firefox');
		try {
			$element = $browser->find('#node');
			expectError(fn() => $element->attributeMatches('name', '/[/', verify: true), 'Could not evaluate assertion pattern');
			expectError(fn() => $browser->urlMatches('not delimited', verify: true), 'Could not evaluate assertion pattern');
			$element->textEquals('Expected', wait: false, verify: true);
			$continued = true;
		} finally {
			$browser->quit();
		}
	}]);
	verify($status === 1 && !$continued && str_contains($output, 'Assertions: 0 passed, 0 failed'), $output);
	verify(str_contains($output, 'Execution error: RuntimeException: WebDriver HTTP 500 unknown error: driver failure'), $output);
});

scenario('protocol assertions report each result once through the recording probe', function ($listener) use ($assertionCases) {
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
	$browser = new RecordingBrowser($url, 'firefox', record: [$recorder, 'assert']);
	$element = $browser->find('#name');
	foreach ($assertionCases as $index => [$method, $arguments, $path, $actual, $expected, $message]):
		verify($element->$method(...[...$arguments, 'wait' => false]) === $element, "$method lost the fluent receiver.");
		verify(count($recorder->results) === $index + 1, "$method did not record exactly once.");
		verify($recorder->results[$index][0] === $expected, "$method reported the wrong result.");
		verify(str_contains($recorder->results[$index][1], "Selector '#name' using 'css selector'") && str_contains($recorder->results[$index][1], $message), "$method lost selector or expected/actual diagnostics.");
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
	verify($element->value('Jane')->valueEquals('Jane', wait: false)->visible(wait: false) === $element, 'Action/assertion chain failed.');
	expectError(fn() => $element->textEquals('Expected', wait: false, verify: true), "WebDriver assertion failed: Selector '#name' using 'css selector' text: expected 'Expected', got 'Actual'.");
	expectError(fn() => $browser->findAll('.missing')->countEquals(1, wait: false), 'expected 1, got 0');
	expectError(fn() => $browser->urlMatches('~/expected$~', wait: false), "got 'https://example.test/actual'");
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
	$browser = new RecordingBrowser($url, 'firefox', record: function ($condition, $message) use (&$results) { $results[] = [$condition, $message]; });
	$empty = $browser->findAll('.missing');
	verify($empty->countEquals(0, wait: false)->countEquals(1, wait: false) === $empty, 'Empty count assertion lost its set.');
	$set = $browser->findAll('input');
	verify($set->countEquals(2, wait: false)->countEquals(1, wait: false) === $set, 'Count assertion refreshed membership or lost its set.');
	$set->first()->findAll('.missing')->countEquals(0, wait: false);
	$set->first()->shadowRoot()->findAll('.missing')->countEquals(0, wait: false);
	$explicit = new ElementSet(...$set->all());
	verify($explicit->countEquals(2, wait: false) === $explicit, 'Explicit snapshot lost its element recorder.');
	$standalone = new ElementSet();
	verify($standalone->countEquals(0, wait: false) === $standalone, 'Standalone empty snapshot cannot assert its count.');
	expectError(fn() => $standalone->countEquals(1, wait: false), 'expected 1, got 0');
	verify(array_column($results, 0) === [true, false, true, false, true, true, true], 'Count results were not shared with the browser recorder.');
	verify($results[1][1] === 'Element count: expected 1, got 0.', 'Count failure lacks expected/actual values.');
	$browser->quit();
});

foreach (['browser', 'window'] as $receiver):
	scenario("$receiver selector visibility assertions check all matches and propagate driver errors", function ($listener) {
		createSession($listener);
		$selector = ['using' => 'css selector', 'value' => '.error'];
		exchange($listener, 'POST', '/session/session%2F1/elements', $selector, []);
		foreach ([false, true] as $visible):
			exchange($listener, 'POST', '/session/session%2F1/elements', $selector, [[Element::REFERENCE => 'first'], [Element::REFERENCE => 'second']]);
			exchange($listener, 'GET', '/session/session%2F1/element/first/displayed', null, false);
			exchange($listener, 'GET', '/session/session%2F1/element/second/displayed', null, $visible);
		endforeach;
		exchange($listener, 'POST', '/session/session%2F1/elements', ['using' => 'css selector', 'value' => '[broken'], ['error' => 'invalid selector', 'message' => 'bad syntax'], 400);
		exchange($listener, 'POST', '/session/session%2F1/elements', $selector, [[Element::REFERENCE => 'old']]);
		exchange($listener, 'GET', '/session/session%2F1/element/old/displayed', null, ['error' => 'stale element reference', 'message' => 'replaced'], 404);
		exchange($listener, 'POST', '/session/session%2F1/elements', $selector, [[Element::REFERENCE => 'current']]);
		exchange($listener, 'GET', '/session/session%2F1/element/current/displayed', null, ['error' => 'unknown error', 'message' => 'display query failed'], 500);
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	}, function ($url) use ($receiver) {
		$results = [];
		$browser = new RecordingBrowser($url, 'firefox', record: function (bool $condition, string $message) use (&$results): bool {
			$results[] = [$condition, $message];
			return $condition;
		});
		$target = $receiver === 'browser' ? $browser : $browser->window();
		foreach (range(1, 2) as $ignored):
			verify($target->find('.error', visible: true, invert: true, assert: true) === null, 'Inverted find should return null.');
		endforeach;
		expectError(fn() => $target->find('.error', visible: true, wait: false, invert: true, assert: true), 'WebDriver find failed');
		verify(array_column($results, 0) === [true, true, false], 'Absent, hidden, or mixed visibility was reported incorrectly.');
		verify($results[2][1] === "Selector '.error' using 'css selector': expected no visible matches, got visible match #2.", 'Selector failure lacks expected/actual diagnostics.');
		expectError(fn() => $target->find('[broken', visible: true, invert: true, assert: true), 'invalid selector');
		expectError(fn() => $target->find('.error', visible: true, wait: false, invert: true, assert: true), 'stale element reference');
		expectError(fn() => $target->find('.error', visible: true, invert: true, assert: true), 'unknown error');
		verify(count($results) === 3, 'Driver errors were converted to assertion results.');
		$browser->quit();
	});
endforeach;

scenario('selector visibility assertions restore window/frame context and throw without a recorder', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
	exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'other'], null);
	exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'window/1'], null);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
	exchange($listener, 'POST', '/session/session%2F1/elements', ['using' => 'xpath', 'value' => '//aside'], [[Element::REFERENCE => 'visible']]);
	exchange($listener, 'GET', '/session/session%2F1/element/visible/displayed', null, true);
	exchange($listener, 'POST', '/session/session%2F1/elements', ['using' => 'css selector', 'value' => '.gone'], []);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	$window = $browser->window()->switchFrame(0);
	$browser->switch('other');
	expectError(fn() => $window->find('//aside', 'xpath', visible: true, wait: false, invert: true, assert: true), "WebDriver assertion failed: Selector '//aside' using 'xpath': expected no visible matches, got visible match #1.");
	verify($browser->find('.gone', visible: true, invert: true, assert: true) === null, 'Inverted find should return null.');
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
	$browser = new RecordingBrowser($url, 'firefox', record: function ($condition, $message) use (&$results) { $results[] = [$condition, $message]; });
	$window = $browser->window()->switchFrame(0);
	$browser->switch('other');
	verify($window->urlMatches('~/projects/\d+$~') === $window, 'URL assertion lost its window.');
	verify($browser->urlMatches('~/missing$~', wait: false) === $browser, 'URL assertion lost its browser.');
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
	$browser = new RecordingBrowser($url, 'firefox', record: function ($condition, $message) use (&$results) { $results[] = [$condition, $message]; });
	$element = $browser->switchFrame(0)->find('#original');
	$browser->switch('other');
	$element->visible(wait: false);
	verify($element->visible(invert: true, wait: false) === $element, 'Stale visibility assertion lost original identity.');
	expectError(fn() => $element->visible(wait: false), 'stale element reference');
	expectError(fn() => $element->visible(invert: true, wait: false), 'no such element');
	expectError(fn() => $element->visible(invert: true, wait: false), 'unknown error');
	$browser->defaultFrame();
	expectError(fn() => $element->visible(invert: true), 'frame removed');
	verify(array_column($results, 0) === [true, true], 'Driver/context errors were reported as assertions.');
	verify(str_contains($results[1][1], 'got stale'), 'Stale assertion diagnostics were lost.');
	$browser->quit();
});

scenario('invalid assertion patterns and callback exceptions propagate without extra reports', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#name'], [Element::REFERENCE => 'input']);
	exchange($listener, 'GET', '/session/session%2F1/element/input/displayed', null, false);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$calls = 0;
	$expected = new RuntimeException('WebDriver HTTP 404 stale element reference: recorder failure');
	$browser = new RecordingBrowser($url, 'firefox', record: function () use (&$calls, $expected) { $calls++; throw $expected; });
	$element = $browser->find('#name');
	expectError(fn() => $element->attributeMatches('missing', '/[/'), 'Could not evaluate assertion pattern');
	expectError(fn() => $browser->urlMatches('not delimited'), 'Could not evaluate assertion pattern');
	expectError(fn() => $element->attributeMatches('missing', '/[/', wait: true), 'Could not evaluate assertion pattern');
	expectError(fn() => $browser->urlMatches('not delimited', wait: true), 'Could not evaluate assertion pattern');
	verify($calls === 0, 'Invalid patterns reached the assertion recorder.');
	try {
		$element->visible(invert: true, wait: false);
		throw new RuntimeException('Callback exception was swallowed.');
	} catch (RuntimeException $error) {
		verify($error === $expected, 'Callback exception was replaced.');
	}
	verify($calls === 1, 'Callback was called more than once.');
	$browser->quit();
});

$retryAssertions = [
	['visible', [], '/displayed', false, true],
	['visible', [true], '/displayed', true, false],
	['valueEquals', ['Jane'], '/property/value', 'Pending', 'Jane'],
	['textEquals', ['Saved'], '/text', 'Saving', 'Saved'],
	['textContains', ['Saved'], '/text', 'Saving', 'Saved successfully'],
	['attributeEquals', ['data-state', 'saved'], '/attribute/data-state', 'saving', 'saved'],
	['attributeMatches', ['data-state', '/^saved$/'], '/attribute/data-state', 'saving', 'saved'],
];
foreach ($retryAssertions as [$method, $arguments, $path, $pending, $ready]):
	scenario("$method waits by default, re-finds replacements in the captured context and updates chained actions", function ($listener) use ($path, $pending, $ready) {
		createSession($listener);
		$selector = ['using' => 'xpath', 'value' => '//status'];
		exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
		exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => 'original']);
		exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'other'], null);
		exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'window/1'], null);
		exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
		foreach (['original' => $pending, 'removed' => null, 'replacement' => $ready] as $id => $value):
			exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => $id]);
			exchange($listener, 'GET', "/session/session%2F1/element/$id$path", null,
				$value ?? ['error' => 'stale element reference', 'message' => 'replaced'], $value === null ? 404 : 200);
		endforeach;
		exchange($listener, 'POST', '/session/session%2F1/element/replacement/click', [], null);
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	}, function ($url) use ($method, $arguments) {
		$results = [];
		$browser = new RecordingBrowser($url, 'firefox', record: function ($condition, $message) use (&$results) { $results[] = [$condition, $message]; });
		$element = $browser->switchFrame(0)->find('//status', 'xpath');
		$browser->switch('other');
		verify($element->$method(...[...$arguments, 'timeout' => 1000])->click() === $element, 'Retry lost its fluent receiver.');
		verify($element->id() === 'replacement', 'Successful retry did not adopt the replacement reference.');
		verify(array_column($results, 0) === [true], 'Retry reported intermediate assertion results.');
		$browser->quit();
	});
endforeach;

scenario('assertion retries handle missing matches and report timeout once with the final observation', function ($listener) {
	createSession($listener);
	$selector = ['using' => 'css selector', 'value' => '#status'];
	exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => 'original']);
	exchange($listener, 'POST', '/session/session%2F1/element', $selector, ['error' => 'no such element', 'message' => 'between renders'], 404);
	exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => 'ready']);
	exchange($listener, 'GET', '/session/session%2F1/element/ready/text', null, 'Saved');
	foreach (range(1, 2) as $ignored):
		exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => 'pending']);
		exchange($listener, 'GET', '/session/session%2F1/element/pending/text', null, 'Pending');
	endforeach;
	exchange($listener, 'POST', '/session/session%2F1/element', $selector, ['error' => 'no such element', 'message' => 'removed'], 404);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$results = [];
	$browser = new RecordingBrowser($url, 'firefox', assertionTimeout: 50, record: function ($condition, $message) use (&$results) { $results[] = [$condition, $message]; });
	$element = $browser->find('#status')->textEquals('Saved', wait: true, timeout: 1000);
	foreach ([20, null] as $timeout):
		$start = microtime(true);
		verify($element->textEquals('Done', wait: true, timeout: $timeout) === $element, 'Recorded timeout should preserve chaining.');
		$elapsed = microtime(true) - $start;
		verify($elapsed >= ($timeout ?? 50) / 1000 && $elapsed < 0.5, 'Assertion timeout units/default changed.');
		verify($element->id() === 'ready', 'Failed assertion replaced the last successful reference.');
	endforeach;
	$element->visible(invert: true, wait: true, timeout: 1000);
	verify(array_column($results, 0) === [true, false, false, true], 'Missing match or timeout reported the wrong result.');
	verify(str_contains($results[1][1], "expected 'Done', got 'Pending'"), 'Timeout lost its last observation.');
	$browser->quit();
});

scenario('selectorless focus and script references poll live nodes but fail immediately when stale', function ($listener) {
	createSession($listener);
	exchange($listener, 'GET', '/session/session%2F1/element/active', null, [Element::REFERENCE => 'focused']);
	exchange($listener, 'GET', '/session/session%2F1/element/focused/text', null, 'Pending');
	exchange($listener, 'GET', '/session/session%2F1/element/focused/text', null, 'Done');
	exchange($listener, 'GET', '/session/session%2F1/element/focused/text', null, ['error' => 'stale element reference', 'message' => 'focus removed'], 404);
	exchange($listener, 'GET', '/session/session%2F1/timeouts', null, ['script' => 30000]);
	exchange($listener, 'POST', '/session/session%2F1/execute/sync', ['script' => 'return node;', 'args' => []], [Element::REFERENCE => 'script']);
	exchange($listener, 'GET', '/session/session%2F1/element/script/property/value', null, ['error' => 'stale element reference', 'message' => 'script node removed'], 404);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$results = [];
	$browser = new RecordingBrowser($url, 'firefox', record: function ($condition) use (&$results) { $results[] = $condition; });
	$element = $browser->activeElement()->textEquals('Done', wait: true, timeout: 1000);
	expectError(fn() => $element->textEquals('Done', wait: true, timeout: 1000), 'focus removed');
	$script = $browser->script('return node;');
	expectError(fn() => $script->valueEquals('Done', wait: true, timeout: 1000), 'script node removed');
	verify($results === [true], 'Stale references without lookups should not become assertion failures.');
	$browser->quit();
});

scenario('descendant lookups wait by default, re-find ancestors and count an absent parent as an empty search', function ($listener) {
	createSession($listener);
	$parent = ['using' => 'css selector', 'value' => '#parent'];
	$child = ['using' => 'xpath', 'value' => './/span'];
	exchange($listener, 'POST', '/session/session%2F1/element', $parent, [Element::REFERENCE => 'parent-old']);
	exchange($listener, 'POST', '/session/session%2F1/element', $parent, [Element::REFERENCE => 'parent-old']);
	exchange($listener, 'POST', '/session/session%2F1/element/parent-old/element', $child, ['error' => 'no such element', 'message' => 'not yet'], 404);
	exchange($listener, 'POST', '/session/session%2F1/element', $parent, [Element::REFERENCE => 'parent-old']);
	exchange($listener, 'POST', '/session/session%2F1/element/parent-old/element', $child, [Element::REFERENCE => 'child-old']);
	exchange($listener, 'POST', '/session/session%2F1/element/parent-old/elements', $child, [[Element::REFERENCE => 'child-old']]);
	foreach (['old' => 'Pending', 'new' => 'Done'] as $suffix => $text):
		exchange($listener, 'POST', '/session/session%2F1/element', $parent, [Element::REFERENCE => "parent-$suffix"]);
		exchange($listener, 'POST', "/session/session%2F1/element/parent-$suffix/element", $child, [Element::REFERENCE => "child-$suffix"]);
		exchange($listener, 'GET', "/session/session%2F1/element/child-$suffix/text", null, $text);
	endforeach;
	exchange($listener, 'POST', '/session/session%2F1/element/child-new/click', [], null);
	exchange($listener, 'POST', '/session/session%2F1/element', $parent, ['error' => 'no such element', 'message' => 'parent removed'], 404);
	exchange($listener, 'POST', '/session/session%2F1/element', $parent, ['error' => 'no such element', 'message' => 'parent removed'], 404);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	$parent = $browser->find('#parent');
	$child = $parent->find('.//span', 'xpath', timeout: 1000);
	$set = $parent->findAll('.//span', 'xpath');
	$child->textEquals('Done', wait: true, timeout: 1000)->click();
	verify($parent->id() === 'parent-old', 'Re-finding a scope changed an independently held reference.');
	verify($set->countEquals(0, wait: true)->count() === 0, 'Absent parent did not produce an empty refreshed set.');
	verify($parent->find('.//span', 'xpath', wait: true, invert: true) === null, 'Absent parent did not satisfy inverted find.');
	$browser->quit();
});

scenario('shadow lookups wait by default and descendant retries reacquire replaced hosts and roots', function ($listener) {
	createSession($listener);
	$host = ['using' => 'css selector', 'value' => '#host'];
	$child = ['using' => 'css selector', 'value' => '.status'];
	exchange($listener, 'POST', '/session/session%2F1/element', $host, [Element::REFERENCE => 'host-old']);
	exchange($listener, 'GET', '/session/session%2F1/element/host-old/shadow', null, [ShadowRoot::REFERENCE => 'root-old']);
	exchange($listener, 'POST', '/session/session%2F1/element', $host, [Element::REFERENCE => 'host-old']);
	exchange($listener, 'GET', '/session/session%2F1/element/host-old/shadow', null, [ShadowRoot::REFERENCE => 'root-old']);
	exchange($listener, 'POST', '/session/session%2F1/shadow/root-old/element', $child, ['error' => 'no such element', 'message' => 'not yet'], 404);
	exchange($listener, 'POST', '/session/session%2F1/element', $host, [Element::REFERENCE => 'host-old']);
	exchange($listener, 'GET', '/session/session%2F1/element/host-old/shadow', null, [ShadowRoot::REFERENCE => 'root-old']);
	exchange($listener, 'POST', '/session/session%2F1/shadow/root-old/element', $child, [Element::REFERENCE => 'child-old']);
	foreach (['old' => 'Pending', 'new' => 'Done'] as $suffix => $text):
		exchange($listener, 'POST', '/session/session%2F1/element', $host, [Element::REFERENCE => "host-$suffix"]);
		exchange($listener, 'GET', "/session/session%2F1/element/host-$suffix/shadow", null, [ShadowRoot::REFERENCE => "root-$suffix"]);
		exchange($listener, 'POST', "/session/session%2F1/shadow/root-$suffix/element", $child, [Element::REFERENCE => "child-$suffix"]);
		exchange($listener, 'GET', "/session/session%2F1/element/child-$suffix/text", null, $text);
	endforeach;
	exchange($listener, 'POST', '/session/session%2F1/element/child-new/click', [], null);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	$browser->find('#host')->shadowRoot()->find('.status', timeout: 1000)->textEquals('Done', wait: true, timeout: 1000)->click();
	$browser->quit();
});

scenario('waiting counts refresh empty searches and positional members retain their lookup', function ($listener) {
	createSession($listener);
	$selector = ['using' => 'css selector', 'value' => '.row'];
	exchange($listener, 'POST', '/session/session%2F1/elements', $selector, []);
	exchange($listener, 'POST', '/session/session%2F1/elements', $selector, [[Element::REFERENCE => 'one']]);
	exchange($listener, 'POST', '/session/session%2F1/elements', $selector, [[Element::REFERENCE => 'one'], [Element::REFERENCE => 'two']]);
	foreach (['two' => 'Pending', 'new' => 'Done'] as $id => $text):
		exchange($listener, 'POST', '/session/session%2F1/elements', $selector, [[Element::REFERENCE => 'one'], [Element::REFERENCE => $id]]);
		exchange($listener, 'GET', "/session/session%2F1/element/$id/text", null, $text);
	endforeach;
	exchange($listener, 'POST', '/session/session%2F1/element/new/click', [], null);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$results = [];
	$browser = new RecordingBrowser($url, 'firefox', record: function ($condition) use (&$results) { $results[] = $condition; });
	$set = $browser->findAll('.row');
	verify($set->countEquals(2, timeout: 1000) === $set && $set->count() === 2, 'Successful count did not update the snapshot.');
	$set->get(1)->textEquals('Done', wait: true, timeout: 1000)->click();
	verify($set->get(1)->id() === 'new', 'Positional member did not adopt the replacement.');
	expectError(fn() => (new ElementSet(...$set->all()))->countEquals(3), 'without a retained search');
	verify($results === [true, true], 'Count/member retries reported intermediate results.');
	$browser->quit();
});

scenario('stale selectorless search ancestors fail without retrying or recording a mismatch', function ($listener) {
	createSession($listener);
	exchange($listener, 'GET', '/session/session%2F1/element/active', null, [Element::REFERENCE => 'parent']);
	$selector = ['using' => 'css selector', 'value' => '.child'];
	exchange($listener, 'POST', '/session/session%2F1/element/parent/element', $selector, [Element::REFERENCE => 'child']);
	exchange($listener, 'POST', '/session/session%2F1/element/parent/elements', $selector, [[Element::REFERENCE => 'child']]);
	exchange($listener, 'POST', '/session/session%2F1/element/parent/element', $selector, ['error' => 'stale element reference', 'message' => 'unrecoverable parent'], 404);
	exchange($listener, 'POST', '/session/session%2F1/element/parent/elements', $selector, ['error' => 'stale element reference', 'message' => 'unrecoverable parent'], 404);
	exchange($listener, 'GET', '/session/session%2F1/timeouts', null, ['script' => 30000]);
	exchange($listener, 'POST', '/session/session%2F1/execute/sync', ['script' => 'return root;', 'args' => []], [ShadowRoot::REFERENCE => 'root']);
	exchange($listener, 'POST', '/session/session%2F1/shadow/root/element', $selector, [Element::REFERENCE => 'shadow-child']);
	exchange($listener, 'POST', '/session/session%2F1/shadow/root/element', $selector, ['error' => 'detached shadow root', 'message' => 'unrecoverable root'], 404);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$results = [];
	$browser = new RecordingBrowser($url, 'firefox', record: function ($condition) use (&$results) { $results[] = $condition; });
	$parent = $browser->activeElement();
	$child = $parent->find('.child');
	$set = $parent->findAll('.child');
	expectError(fn() => $child->visible(invert: true, wait: true, timeout: 1000), 'unrecoverable parent');
	expectError(fn() => $set->countEquals(0, wait: true, timeout: 1000), 'unrecoverable parent');
	$shadowChild = $browser->script('return root;')->find('.child');
	expectError(fn() => $shadowChild->textEquals('Done', wait: true, timeout: 1000), 'unrecoverable root');
	verify($results === [], 'Unrecoverable ancestors were treated as assertion mismatches.');
	$browser->quit();
});

scenario('count timeouts preserve the snapshot and context errors propagate', function ($listener) {
	createSession($listener);
	$selector = ['using' => 'css selector', 'value' => '.row'];
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
	exchange($listener, 'POST', '/session/session%2F1/elements', $selector, [[Element::REFERENCE => 'old']]);
	foreach (range(1, 2) as $ignored):
		exchange($listener, 'POST', '/session/session%2F1/elements', $selector, []);
	endforeach;
	exchange($listener, 'POST', '/session/session%2F1/elements', $selector, ['error' => 'invalid selector', 'message' => 'bad selector'], 400);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => null], null);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], ['error' => 'stale element reference', 'message' => 'frame removed'], 404);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$results = [];
	$browser = new RecordingBrowser($url, 'firefox', assertionTimeout: 50, record: function ($condition, $message) use (&$results) { $results[] = [$condition, $message]; });
	$set = $browser->switchFrame(0)->findAll('.row');
	foreach ([20, null] as $timeout):
		$start = microtime(true);
		$set->countEquals(2, wait: true, timeout: $timeout);
		$elapsed = microtime(true) - $start;
		verify($elapsed >= ($timeout ?? 50) / 1000 && $elapsed < 0.5, 'Count timeout units/default changed.');
		verify($set->count() === 1 && $set->first()->id() === 'old', 'Timed-out count replaced the snapshot.');
	endforeach;
	foreach ([0, -1, INF, NAN] as $timeout):
		expectError(fn() => $set->countEquals(2, wait: true, timeout: $timeout), 'positive and finite');
		expectError(fn() => $set->first()->textEquals('Done', wait: true, timeout: $timeout), 'positive and finite');
		expectError(fn() => $browser->urlMatches('/done/', wait: true, timeout: $timeout), 'positive and finite');
	endforeach;
	expectError(fn() => $set->countEquals(2, wait: true), 'invalid selector');
	$browser->defaultFrame();
	expectError(fn() => $set->countEquals(0, wait: true), 'frame removed');
	verify(array_column($results, 0) === [false, false] && str_contains($results[0][1], 'expected 2, got 0'), 'Count timeout diagnostics or reporting changed.');
	$browser->quit();
});

scenario('consolidated find reports once and still throws when a prerequisite fails', function ($listener) {
	createSession($listener);
	$selector = ['using' => 'css selector', 'value' => '#new'];
	exchange($listener, 'POST', '/session/session%2F1/element', $selector, ['error' => 'no such element', 'message' => 'not yet'], 404);
	exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => 'new']);
	exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => 'new']);
	exchange($listener, 'GET', '/session/session%2F1/element/new/displayed', null, false);
	exchange($listener, 'POST', '/session/session%2F1/elements', $selector, [[Element::REFERENCE => 'new']]);
	exchange($listener, 'POST', '/session/session%2F1/elements', $selector, []);
	exchange($listener, 'POST', '/session/session%2F1/element', $selector, ['error' => 'no such element', 'message' => 'gone'], 404);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$results = [];
	$browser = new RecordingBrowser($url, 'firefox', record: function ($condition) use (&$results) { $results[] = $condition; return false; });
	verify($browser->find('#new', assert: true, timeout: 1000)->id() === 'new', 'Find lost its match.');
	expectError(fn() => $browser->find('#new', visible: true, wait: false, assert: true, timeout: -1), 'WebDriver find failed');
	verify($browser->find('#new', wait: true, invert: true, assert: true, timeout: 1000) === null, 'Inverse presence did not return null.');
	expectError(fn() => $browser->find('#new', wait: false, assert: true), 'no such element: gone');
	verify($results === [true, false, true, false], 'Find recorded intermediate results or skipped failures.');
	foreach (['findWaitPresent', 'findWaitVisible', 'waitNotPresent', 'waitNotVisible', 'isNotVisible'] as $removed):
		verify(!method_exists($browser, $removed) && !method_exists($browser->window(), $removed), 'Overlapping selector helper still exists.');
	endforeach;
	verify(!method_exists(Element::class, 'isVisible') && !method_exists(Element::class, 'isNotVisible'), 'Old element visibility assertions remain.');
	$browser->quit();
});

scenario('URL assertion retries report once and preserve timeout/default and callback failure behavior', function ($listener) {
	createSession($listener);
	foreach (['pending', 'done', 'pending', 'pending', 'done'] as $path):
		exchange($listener, 'GET', '/session/session%2F1/url', null, 'https://example.test/' . $path);
	endforeach;
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$results = [];
	$throw = false;
	$expected = new RuntimeException('WebDriver HTTP 404 no such element: recorder failure');
	$browser = new RecordingBrowser($url, 'firefox', assertionTimeout: 50, record: function ($condition, $message) use (&$results, &$throw, $expected) {
		$results[] = [$condition, $message];
		if ($throw) throw $expected;
	});
	$browser->urlMatches('~/done$~', timeout: 1000);
	foreach ([20, null] as $timeout):
		$start = microtime(true);
		$browser->window()->urlMatches('~/done$~', timeout: $timeout);
		$elapsed = microtime(true) - $start;
		verify($elapsed >= ($timeout ?? 50) / 1000 && $elapsed < 0.5, 'URL timeout units/default changed.');
	endforeach;
	$throw = true;
	try {
		$browser->urlMatches('~/done$~', wait: true);
		throw new RuntimeException('Recorder exception was swallowed.');
	} catch (RuntimeException $error) {
		verify($error === $expected, 'Recorder exception was replaced or retried.');
	}
	verify(array_column($results, 0) === [true, false, false, true], 'URL retry results are incorrect.');
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
	expectError(fn() => $driver->find('#missing', wait: false), 'no such element: Missing selector #missing');
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

scenario('BiDi decision response unblocks classic HTTP before headers arrive', function ($listener) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	[$http, $head] = acceptRequest($listener);
	verify(str_starts_with($head, 'POST /session/session%2F1/url HTTP/'), 'Expected pending navigation.');
	bidiReply($ws, ['type' => 'event', 'method' => 'network.beforeRequestSent', 'params' => ['request' => ['request' => 'r1'], 'isBlocked' => true]]);
	bidiRequest($ws, 1, 'network.continueRequest', ['request' => 'r1']);
	bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => (object)[]]);
	fwrite($http, "HTTP/1.1 200 OK\r\nContent-Length: 14\r\n\r\n{\"value\":null}");
	fclose($http);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($ws);
}, function ($url) {
	$browser = new Browser($url, 'firefox', bidi: true);
	$browser->bidi()->listen('network.beforeRequestSent', fn ($event) => ['method' => 'network.continueRequest', 'params' => ['request' => $event['request']['request']]]);
	$browser->sessionRequest('POST', '/url', ['url' => 'http://example.test/']);
	verify($browser->event()['method'] === 'network.beforeRequestSent', 'Internal observer stole the low-level event.');
	$browser->quit();
});

scenario('BiDi routes out-of-order decision replies while another command waits', function ($listener) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	bidiRequest($ws, 1, 'browsingContext.navigate', ['context' => 'c1', 'url' => 'http://example.test/']);
	bidiReply($ws, ['type' => 'event', 'method' => 'network.beforeRequestSent', 'params' => ['request' => ['request' => 'r1']]]);
	bidiRequest($ws, 2, 'network.continueRequest', ['request' => 'r1']);
	bidiReply($ws, ['type' => 'success', 'id' => 2, 'result' => (object)[]]);
	bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['url' => 'http://example.test/']]);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($ws);
}, function ($url) {
	$browser = new Browser($url, 'firefox', bidi: true);
	$browser->bidi()->listen('network.beforeRequestSent', fn ($event) => ['method' => 'network.continueRequest', 'params' => ['request' => $event['request']['request']]]);
	verify($browser->command('browsingContext.navigate', ['context' => 'c1', 'url' => 'http://example.test/'])['url'] === 'http://example.test/', 'Decision response replaced the navigation response.');
	$browser->quit();
});

foreach (['close', 'eof', 'remaining-window', 'truncated'] as $ending):
	scenario('BiDi closure during window deletion: ' . $ending, function ($listener) use ($ending) {
		createSession($listener, bidi: true);
		$ws = upgrade($listener);
		exchange($listener, 'GET', '/session/session%2F1/window', null, 'window/1');
		[$http] = acceptRequest($listener);
		if ($ending === 'close') fwrite($ws, "\x88\x00");
		if ($ending === 'truncated') fwrite($ws, "\x81\x05x");
		fclose($ws);
		usleep(20000);
		$json = json_encode(['value' => $ending === 'remaining-window' ? ['window/2'] : []]);
		fwrite($http, "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($json) . "\r\n\r\n" . $json);
		fclose($http);
		if (in_array($ending, ['remaining-window', 'truncated'], true)) exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	}, function ($url) use ($ending) {
		$browser = new Browser($url, 'firefox', bidi: true);
		$connection = $browser->bidi();
		if (in_array($ending, ['remaining-window', 'truncated'], true)) {
			expectError(fn () => $browser->window()->close(), 'connection ended unexpectedly');
		} else {
			$browser->window()->close();
			verify(!$browser->active() && !$connection->active(), 'Last-window deletion retained the session or dispatcher.');
		}
		$browser->quit();
	});
endforeach;

scenario('console capture owns its subscription, records once and survives stop, restart and quit', function ($listener) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	bidiRequest($ws, 1, 'session.subscribe', ['events' => ['log.entryAdded']]);
	bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['subscription' => 'raw']]);
	bidiRequest($ws, 2, 'session.subscribe', ['events' => ['log.entryAdded']]);
	bidiReply($ws, ['type' => 'event', 'method' => 'log.entryAdded', 'params' => ['type' => 'console', 'level' => 'error', 'text' => 'console-error', 'timestamp' => 100]]);
	bidiReply($ws, ['type' => 'success', 'id' => 2, 'result' => ['subscription' => 'capture']]);
	[$http] = acceptRequest($listener);
	bidiReply($ws, ['type' => 'event', 'method' => 'log.entryAdded', 'params' => ['type' => 'javascript', 'level' => 'error', 'text' => 'exception', 'timestamp' => 101]]);
	usleep(20000);
	fwrite($http, "HTTP/1.1 200 OK\r\nContent-Length: 14\r\n\r\n{\"value\":null}");
	fclose($http);
	bidiRequest($ws, 3, 'session.unsubscribe', ['subscriptions' => ['capture']]);
	bidiReply($ws, ['type' => 'success', 'id' => 3, 'result' => (object)[]]);
	bidiReply($ws, ['type' => 'event', 'method' => 'log.entryAdded', 'params' => ['type' => 'console', 'text' => 'raw-only']]);
	bidiRequest($ws, 4, 'session.subscribe', ['events' => ['log.entryAdded']]);
	bidiReply($ws, ['type' => 'success', 'id' => 4, 'result' => ['subscription' => 'capture2']]);
	bidiReply($ws, ['type' => 'event', 'method' => 'log.entryAdded', 'params' => ['type' => 'console', 'text' => 'restarted']]);
	bidiRequest($ws, 5, 'browsingContext.getTree');
	bidiReply($ws, ['type' => 'success', 'id' => 5, 'result' => ['contexts' => []]]);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($ws);
}, function ($url) {
	$browser = new Browser($url, 'firefox', bidi: true);
	$browser->subscribe(['log.entryAdded']);
	[$status, $output, $record] = runChecks(['check' => function ($test) use ($browser) {
		$browser->startLogCapture()->startLogCapture();
		$test->recordStep('navigate', 'browser', [], fn () => $browser->sessionRequest('POST', '/url', ['url' => 'http://example.test/']));
		verify(count($browser->logs('javascript')) === 1 && count($browser->logs('console')) === 1, 'Console errors were confused with exceptions.');
		$browser->stopLogCapture();
	}]);
	verify($status === 0 && $record['totals']['assertionsFailed'] === 0, 'Browser logs failed a diagnostic-only test.');
	$logs = $record['suites'][0]['attempts'][0]['browserLogs'];
	verify(count(array_filter($logs, fn ($log) => $log['entry']['text'] === 'exception')) === 1 && $logs[1]['stepId'] !== null, 'Logs duplicated or lost their observed-during action.');
	verify(str_contains($output, 'console-error') && str_contains($output, 'exception'), 'Console output omitted browser entries.');
	verify($browser->event()['params']['text'] === 'console-error' && $browser->event()['params']['text'] === 'exception', 'Capture consumed raw events.');
	verify($browser->event()['params']['text'] === 'raw-only', 'Stopping capture cancelled another subscription.');
	$browser->startLogCapture();
	$browser->command('browsingContext.getTree');
	$before = $browser->logs();
	$connection = $browser->bidi();
	$browser->quit();
	verify(!$connection->active(), 'A retained dispatcher kept the deleted session connection alive.');
	verify($browser->logs() === $before && end($before)['text'] === 'restarted', 'Restart or cleanup lost retained entries.');
});

scenario('capture failure retains entries, reports once, and still permits HTTP cleanup', function ($listener) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	bidiRequest($ws, 1, 'session.subscribe', ['events' => ['log.entryAdded']]);
	bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['subscription' => 'capture']]);
	bidiReply($ws, ['type' => 'event', 'method' => 'log.entryAdded', 'params' => ['type' => 'console', 'text' => 'retained']]);
	[$http] = acceptRequest($listener);
	bidiReply($ws, ['type' => 'broken']);
	usleep(20000);
	fclose($http);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($ws);
}, function ($url) {
	$browser = new Browser($url, 'firefox', bidi: true);
	[$status, $output, $record] = runChecks(['check' => function () use ($browser) {
		$browser->startLogCapture();
		expectError(fn () => $browser->sessionRequest('GET', '/title'), 'Invalid WebDriver BiDi');
		verify($browser->logCaptureError() !== null && $browser->logs()[0]['text'] === 'retained', 'Collection failure hid retained data or its error.');
		$browser->quit();
	}]);
	verify($status === 1 && count($record['suites'][0]['attempts'][0]['errors']) === 1, 'Swallowed transport error did not produce one collection diagnostic.');
	verify($browser->logs()[0]['text'] === 'retained', 'Failed capture data depended on session lifetime.');
});

scenario('capture drains a burst larger than one dispatcher batch', function ($listener) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	bidiRequest($ws, 1, 'session.subscribe', ['events' => ['log.entryAdded']]);
	bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['subscription' => 'capture']]);
	bidiRequest($ws, 2, 'browsingContext.getTree');
	for ($i = 0; $i < 512; $i++) bidiReply($ws, ['type' => 'event', 'method' => 'log.entryAdded', 'params' => ['type' => 'console', 'text' => (string)$i]]);
	bidiReply($ws, ['type' => 'success', 'id' => 2, 'result' => ['contexts' => []]]);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($ws);
}, function ($url) {
	$browser = new Browser($url, 'firefox', bidi: true);
	$browser->startLogCapture();
	$browser->command('browsingContext.getTree');
	verify(array_column($browser->logs(), 'text') === array_map(strval(...), range(0, 511)), 'Burst entries were lost or reordered.');
	$browser->quit();
});

scenario('normal BiDi close during last-window deletion preserves capture', function ($listener) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	bidiRequest($ws, 1, 'session.subscribe', ['events' => ['log.entryAdded']]);
	bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['subscription' => 'capture']]);
	exchange($listener, 'GET', '/session/session%2F1/window', null, 'window/1');
	[$http] = acceptRequest($listener);
	bidiReply($ws, ['type' => 'event', 'method' => 'log.entryAdded', 'params' => ['type' => 'console', 'text' => 'last-window']]);
	fwrite($ws, "\x88\x00");
	usleep(20000);
	fwrite($http, "HTTP/1.1 200 OK\r\nContent-Length: 12\r\n\r\n{\"value\":[]}");
	fclose($http);
	fclose($ws);
}, function ($url) {
	$browser = new Browser($url, 'firefox', bidi: true);
	$browser->startLogCapture();
	$browser->window()->close();
	verify(!$browser->active() && $browser->logCaptureError() === null && $browser->logs()[0]['text'] === 'last-window', 'Normal last-window closure lost logs or became a capture failure.');
	$browser->quit();
});

scenario('BiDi handlers cannot reenter classic browser operations', function ($listener) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	bidiRequest($ws, 1, 'browsingContext.getTree');
	bidiReply($ws, ['type' => 'event', 'method' => 'test.event', 'params' => (object)[]]);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($ws);
}, function ($url) {
	$browser = new Browser($url, 'firefox', bidi: true);
	$browser->bidi()->listen('test.event', fn () => $browser->sessionRequest('GET', '/title'));
	expectError(fn () => $browser->command('browsingContext.getTree'), 'not allowed inside a BiDi event handler');
	$browser->quit();
});

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
	$input = $browser->find('#form')->find('#name', wait: false);
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
	$root->find('button', wait: false)->click();
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
	}, timeout: 1000)->click();
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
			expectError(fn() => $select->select('absent', wait: false), 'No option matches');
		elseif ($state === 'disabled'):
			expectError(fn() => $select->select('a"b\c', wait: false), 'disabled option');
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
		$id = $driver->find('#ready', wait: false);
		return $id->displayed() && $id->enabled() ? $id : false;
	}, timeout: 2000);
	verify($element instanceof Element && $element->id() === 'ready', 'Wait did not return the callback result.');
	$element->click();
	$driver->quit();
});

scenario('find waits by default and returns the first present or visible match', function ($listener) {
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
	$browser = new Browser($url, 'firefox', pollInterval: 100);
	verify($browser->find('//input', 'xpath', timeout: 1000)->id() === 'hidden', 'Presence should not require displayedness.');
	verify($browser->window()->find('.ready', visible: true, timeout: 1000)->id() === 'replacement', 'Visibility did not re-find after stale reference.');
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
	$browser = new Browser($url, 'firefox', pollInterval: 100);
	verify($browser->find('//aside', 'xpath', wait: true, invert: true, timeout: 1000) === null, 'Inverted find should return null.');
	verify($browser->find('.spinner', visible: true, wait: true, invert: true, timeout: 1000) === null, 'Visible replacement was ignored.');
	$window = $browser->window();
	verify($window->find('.spinner', wait: true, invert: true) === null, 'Window absence wait failed.');
	verify($window->find('.spinner', visible: true, wait: true, invert: true) === null, 'Window hidden wait failed.');
	verify($browser->find('.spinner', wait: true, invert: true) === null, 'Absent selector should succeed.');
	verify($browser->find('.spinner', visible: true, wait: true, invert: true) === null, 'Absent selector should be invisible.');
	$browser->quit();
});

foreach (['name', 'displayed'] as $endpoint):
	scenario("element inverted $endpoint wait retains identity and restores its captured frame", function ($listener) use ($endpoint) {
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
	}, function ($url) use ($endpoint) {
		$browser = new Browser($url, 'firefox');
		$element = $browser->switchFrame(0)->find('#old');
		$browser->switch('other');
		verify($element->wait(visible: $endpoint === 'displayed', invert: true, timeout: 1000) === $element, 'Element wait lost original receiver.');
		expectError(fn() => $element->tag(), 'still removed');
		$browser->quit();
	});
endforeach;

scenario('value reads live properties and replaces input in its captured context', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#name'], [Element::REFERENCE => 'input']);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => null], null);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
	exchange($listener, 'GET', '/session/session%2F1/element/input/property/value', null, 'Original');
	exchange($listener, 'GET', '/session/session%2F1/element/input/property/value', null, 'Changed');
	exchange($listener, 'POST', '/session/session%2F1/element/input/clear', [], null);
	exchange($listener, 'POST', '/session/session%2F1/element/input/value', ['text' => 'Jane €'], null);
	exchange($listener, 'POST', '/session/session%2F1/element/input/value', ['text' => ' Doe'], null);
	exchange($listener, 'POST', '/session/session%2F1/element/input/clear', [], null);
	exchange($listener, 'POST', '/session/session%2F1/element/input/clear', [], null);
	exchange($listener, 'POST', '/session/session%2F1/element/input/value', ['text' => '0'], null);
	exchange($listener, 'POST', '/session/session%2F1/element/input/clear', [], ['error' => 'invalid element state', 'message' => 'readonly'], 400);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	$element = $browser->switchFrame(0)->find('#name');
	$browser->defaultFrame();
	verify($element->value() === 'Original' && $element->value(null) === 'Changed', 'Getter did not read the current DOM value.');
	verify($element->value('Jane €')->type(' Doe') === $element, 'Value replacement lost receiver.');
	verify($element->value('') === $element, 'Empty replacement should only clear.');
	verify($element->value('0') === $element, 'Zero should set rather than read.');
	expectError(fn() => $element->value('blocked', wait: false), 'invalid element state');
	$browser->quit();
});

foreach (['click', 'type', 'key', 'clear', 'value'] as $method):
	scenario("$method recovers replacements in the captured frame and adopts the successful node", function ($listener) use ($method) {
		createSession($listener);
		$selector = ['using' => 'css selector', 'value' => '#action'];
		exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
		exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => 'old']);
		exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => null], null);
		exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
		$endpoint = in_array($method, ['type', 'key', 'value'], true) ? 'value' : $method;
		$body = $endpoint === 'value' ? ['text' => $method === 'key' ? Browser::ENTER : 'new'] : [];
		if ($method === 'value') exchange($listener, 'POST', '/session/session%2F1/element/old/clear', [], null);
		exchange($listener, 'POST', '/session/session%2F1/element/old/' . $endpoint, $body,
			['error' => 'stale element reference', 'message' => 'replaced'], 404);
		exchange($listener, 'POST', '/session/session%2F1/element', $selector, ['error' => 'no such element', 'message' => 'rendering'], 404);
		exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => 'new']);
		if ($method === 'value') exchange($listener, 'POST', '/session/session%2F1/element/new/clear', [], null);
		exchange($listener, 'POST', '/session/session%2F1/element/new/' . $endpoint, $body, null);
		exchange($listener, 'GET', '/session/session%2F1/element/new/property/value', null, 'new');
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	}, function ($url) use ($method) {
		$browser = new Browser($url, 'firefox');
		$element = $browser->switchFrame(0)->find('#action');
		$browser->defaultFrame();
		$args = in_array($method, ['type', 'key', 'value'], true) ? [$method === 'key' ? Browser::ENTER : 'new'] : [];
		verify($element->$method(...$args, timeout: 1500) === $element && $element->id() === 'new'
			&& $element->value() === 'new', 'Action did not adopt the replacement or preserve its receiver.');
		$browser->quit();
	});
endforeach;

foreach (['click' => ['element click intercepted', 'element not interactable'],
	'type' => ['element not interactable'], 'clear' => ['invalid element state', 'element not interactable']] as $method => $errors):
	scenario("$method retries rejected input on the original node without an extra lookup", function ($listener) use ($method, $errors) {
		createSession($listener);
		$endpoint = $method === 'type' ? 'value' : $method;
		$body = $method === 'type' ? ['text' => 'append'] : [];
		foreach ($errors as $error):
			exchange($listener, 'POST', '/session/session%2F1/element/node/' . $endpoint, $body, ['error' => $error, 'message' => 'temporary'], 400);
		endforeach;
		exchange($listener, 'POST', '/session/session%2F1/element/node/' . $endpoint, $body, null);
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	}, function ($url) use ($method) {
		$browser = new Browser($url, 'firefox');
		$element = new Element($browser->window(), 'node');
		$args = $method === 'type' ? ['append'] : [];
		verify($element->$method(...$args) === $element, 'Transient action did not succeed.');
		$browser->quit();
	});
endforeach;

scenario('value retries typing without clearing the same node again', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/element/node/clear', [], null);
	exchange($listener, 'POST', '/session/session%2F1/element/node/value', ['text' => 'new'], ['error' => 'element not interactable', 'message' => 'temporary'], 400);
	exchange($listener, 'POST', '/session/session%2F1/element/node/value', ['text' => 'new'], null);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	$element = new Element($browser->window(), 'node');
	verify($element->value('new') === $element, 'Value retry lost its receiver.');
	$browser->quit();
});

foreach (['check', 'uncheck'] as $method):
	foreach (['one-shot', 'expiry', 'eventual'] as $mode):
		scenario("$method disabled controls: $mode", function ($listener) use ($method, $mode) {
			createSession($listener);
			foreach ($mode === 'eventual' ? [false, true] : [false] as $enabled):
				exchange($listener, 'GET', '/session/session%2F1/element/node/name', null, 'input');
				exchange($listener, 'GET', '/session/session%2F1/element/node/property/type', null, 'checkbox');
				exchange($listener, 'GET', '/session/session%2F1/element/node/selected', null, $method === 'uncheck');
				exchange($listener, 'GET', '/session/session%2F1/element/node/enabled', null, $enabled);
				if ($enabled) exchange($listener, 'POST', '/session/session%2F1/element/node/click', [], null);
			endforeach;
			exchange($listener, 'DELETE', '/session/session%2F1', null, null);
		}, function ($url) use ($method, $mode) {
			$browser = new Browser($url, 'firefox', pollInterval: 10);
			$element = new Element($browser->window(), 'node');
			try {
				if ($mode === 'eventual') verify($element->$method(timeout: 300) === $element, 'State change lost receiver.');
				else expectError(fn() => $element->$method(wait: $mode !== 'one-shot', timeout: 1), 'disabled');
			} finally {
				$browser->quit();
			}
		});
	endforeach;
endforeach;

scenario('value retries invalid state only while clearing and preserves typing failures', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/element/node/clear', [], ['error' => 'invalid element state', 'message' => 'disabled'], 400);
	exchange($listener, 'POST', '/session/session%2F1/element/node/clear', [], null);
	exchange($listener, 'POST', '/session/session%2F1/element/node/value', ['text' => 'new'], ['error' => 'invalid element state', 'message' => 'typing rejected'], 400);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox', pollInterval: 10);
	try {
		expectError(fn() => (new Element($browser->window(), 'node'))->value('new', timeout: 300), 'invalid element state: typing rejected');
	} finally {
		$browser->quit();
	}
});

foreach (['check' => true, 'uncheck' => false] as $method => $selected):
	scenario("$method rechecks selection after replacement rather than toggling it", function ($listener) use ($selected) {
		createSession($listener);
		$selector = ['using' => 'css selector', 'value' => '#choice'];
		exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => 'old']);
		foreach (['old', 'new'] as $id):
			if ($id === 'new') exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => 'new']);
			exchange($listener, 'GET', "/session/session%2F1/element/$id/name", null, 'input');
			exchange($listener, 'GET', "/session/session%2F1/element/$id/property/type", null, 'checkbox');
			exchange($listener, 'GET', "/session/session%2F1/element/$id/selected", null, $id === 'new' ? $selected : !$selected);
			if ($id === 'old'):
				exchange($listener, 'GET', '/session/session%2F1/element/old/enabled', null, true);
				exchange($listener, 'POST', '/session/session%2F1/element/old/click', [], ['error' => 'stale element reference', 'message' => 'replaced'], 404);
			endif;
		endforeach;
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	}, function ($url) use ($method) {
		$browser = new Browser($url, 'firefox');
		$element = $browser->find('#choice');
		verify($element->$method() === $element && $element->id() === 'new', 'Selection retry did not adopt the satisfied replacement.');
		$browser->quit();
	});
endforeach;

scenario('select waits for options and rechecks selection after an intercepted click', function ($listener) {
	createSession($listener);
	foreach (['missing', 'disabled', 'covered', 'selected'] as $state):
		exchange($listener, 'GET', '/session/session%2F1/element/select/name', null, 'select');
		exchange($listener, 'GET', '/session/session%2F1/element/select/enabled', null, true);
		exchange($listener, 'POST', '/session/session%2F1/element/select/elements', ['using' => 'css selector', 'value' => 'option'],
			$state === 'missing' ? [] : [[Element::REFERENCE => 'option']]);
		if ($state === 'missing') continue;
		exchange($listener, 'GET', '/session/session%2F1/element/option/property/value', null, 'choice');
		exchange($listener, 'GET', '/session/session%2F1/element/option/enabled', null, $state !== 'disabled');
		if ($state === 'disabled') continue;
		exchange($listener, 'GET', '/session/session%2F1/element/option/selected', null, $state === 'selected');
		if ($state === 'covered') exchange($listener, 'POST', '/session/session%2F1/element/option/click', [], ['error' => 'element click intercepted', 'message' => 'covered'], 400);
	endforeach;
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	$element = new Element($browser->window(), 'select');
	verify($element->select('choice', timeout: 2500) === $element, 'Select did not wait for the desired option.');
	$browser->quit();
});

foreach (['one-shot', 'selectorless', 'session', 'navigation', 'unknown', 'timeout', 'default-timeout'] as $mode):
	scenario("action failure handling: $mode", function ($listener) use ($mode) {
		createSession($listener);
		if ($mode === 'one-shot') exchange($listener, 'POST', '/session/session%2F1/element',
			['using' => 'css selector', 'value' => '#node'], [Element::REFERENCE => 'node']);
		$error = match ($mode) {
			'one-shot', 'selectorless' => 'stale element reference', 'session' => 'invalid session id',
			'navigation' => 'timeout', 'unknown' => 'unknown error', default => 'element click intercepted',
		};
		exchange($listener, 'POST', '/session/session%2F1/element/node/click', [], ['error' => $error, 'message' => 'original failure'], 400,
			$mode === 'timeout' ? 0.05 : 0);
		if ($mode === 'default-timeout') exchange($listener, 'POST', '/session/session%2F1/element/node/click', [],
			['error' => $error, 'message' => 'original failure'], 400, 0.06);
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	}, function ($url) use ($mode) {
		$browser = new Browser($url, 'firefox', waitTimeout: $mode === 'default-timeout' ? 150 : 5000, pollInterval: 100);
		$element = $mode === 'one-shot' ? $browser->find('#node') : new Element($browser->window(), 'node');
		try {
			$element->click(wait: $mode !== 'one-shot', timeout: $mode === 'timeout' ? 30 : null);
			throw new LogicException('Expected action failure.');
		} catch (RuntimeException $error) {
			verify(str_contains($error->getMessage(), 'original failure'), 'Original action error was lost.');
			verify(($error->getPrevious() !== null) === in_array($mode, ['timeout', 'default-timeout'], true), 'Only exhausted retries should wrap the driver error.');
		}
		$browser->quit();
	});
endforeach;

foreach ([false, true] as $shadow):
	scenario('action replacement recovers ancestor scopes, shadow=' . (int)$shadow, function ($listener) use ($shadow) {
		createSession($listener);
		$parent = ['using' => 'css selector', 'value' => '#parent'];
		$child = ['using' => 'css selector', 'value' => 'button'];
		exchange($listener, 'POST', '/session/session%2F1/element', $parent, [Element::REFERENCE => 'parent-old']);
		if ($shadow) exchange($listener, 'GET', '/session/session%2F1/element/parent-old/shadow', null, [ShadowRoot::REFERENCE => 'root-old']);
		$scope = $shadow ? 'shadow/root-' : 'element/parent-';
		exchange($listener, 'POST', '/session/session%2F1/' . $scope . 'old/element', $child, [Element::REFERENCE => 'child-old']);
		exchange($listener, 'POST', '/session/session%2F1/element/child-old/click', [], ['error' => 'stale element reference', 'message' => 'parent replaced'], 404);
		exchange($listener, 'POST', '/session/session%2F1/element', $parent, [Element::REFERENCE => 'parent-new']);
		if ($shadow) exchange($listener, 'GET', '/session/session%2F1/element/parent-new/shadow', null, [ShadowRoot::REFERENCE => 'root-new']);
		exchange($listener, 'POST', '/session/session%2F1/' . $scope . 'new/element', $child, [Element::REFERENCE => 'child-new']);
		exchange($listener, 'POST', '/session/session%2F1/element/child-new/click', [], null);
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	}, function ($url) use ($shadow) {
		$browser = new Browser($url, 'firefox');
		$parent = $browser->find('#parent');
		$scope = $shadow ? $parent->shadowRoot() : $parent;
		$child = $scope->find('button', wait: false);
		verify($child->click() === $child && $child->id() === 'child-new' && $parent->id() === 'parent-old',
			'Recovery changed the search scope or an independently held parent.');
		$browser->quit();
	});
endforeach;

scenario('a lost click response is not retried', function ($listener) {
	createSession($listener);
	[$stream, $head, $body] = acceptRequest($listener);
	verify(str_starts_with($head, 'POST /session/session%2F1/element/node/click HTTP/'), 'Expected the original click.');
	fclose($stream);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	try {
		(new Element($browser->window(), 'node'))->click();
		throw new LogicException('Expected transport failure.');
	} catch (RuntimeException $error) {
		verify($error->getPrevious() === null && !str_contains($error->getMessage(), 'action timed out'), 'Lost response was retried.');
	}
	$browser->quit();
});

scenario('compound value retry uses one deadline for clear and type', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/element/node/clear', [], null, 200, 0.05);
	exchange($listener, 'POST', '/session/session%2F1/element/node/value', ['text' => 'new'], ['error' => 'element not interactable', 'message' => 'blocked'], 400);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	expectError(fn() => (new Element($browser->window(), 'node'))->value('new', timeout: 30), 'action timed out');
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
	$element = $browser->switchFrame(0)->find('#name', wait: true);
	verify($element->wait(visible: true, invert: true, timeout: 1000) === $element, 'Hidden element did not finish wait.');
	$browser->quit();
});

foreach ([[false, false], [true, false], [false, true], [true, true]] as [$visible, $invert]):
	scenario('find timeout and errors: visible=' . (int)$visible . ', invert=' . (int)$invert, function ($listener) use ($visible, $invert) {
		createSession($listener);
		$positive = !$invert;
		foreach (range(1, 2) as $ignored):
			exchange($listener, 'POST', '/session/session%2F1/' . ($positive ? 'element' : 'elements'),
				['using' => 'css selector', 'value' => '#pending'],
				!$visible && $positive ? ['error' => 'no such element', 'message' => 'not yet'] : ($positive ? [Element::REFERENCE => 'pending'] : [[Element::REFERENCE => 'pending']]),
				!$visible && $positive ? 404 : 200);
			if ($visible):
				exchange($listener, 'GET', '/session/session%2F1/element/pending/displayed', null, !$positive);
			endif;
		endforeach;
		exchange($listener, 'POST', '/session/session%2F1/' . ($positive ? 'element' : 'elements'),
			['using' => 'css selector', 'value' => '[broken'], ['error' => 'invalid selector', 'message' => 'bad syntax'], 400);
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	}, function ($url) use ($visible, $invert) {
		$browser = new Browser($url, 'firefox', waitTimeout: 50);
		foreach ([20, null] as $timeout):
			$start = microtime(true);
			expectError(fn() => $browser->find('#pending', visible: $visible, wait: true, invert: $invert, timeout: $timeout), 'wait timed out');
			$elapsed = microtime(true) - $start;
			verify($elapsed >= ($timeout ?? 50) / 1000 && $elapsed < 0.5, 'Timeout was not milliseconds or constructor default.');
		endforeach;
		expectError(fn() => $browser->find('[broken', visible: $visible, wait: true, invert: $invert), 'invalid selector');
		foreach ([0, -1, INF, NAN] as $timeout):
			expectError(fn() => $browser->find('#pending', visible: $visible, wait: true, invert: $invert, timeout: $timeout), 'positive and finite');
		endforeach;
		$browser->quit();
	});
endforeach;

foreach (['name', 'displayed'] as $endpoint):
	scenario("element inverted $endpoint wait timeout and unrelated errors", function ($listener) use ($endpoint) {
		createSession($listener);
		exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#pending'], [Element::REFERENCE => 'pending']);
		foreach (range(1, 2) as $ignored):
			exchange($listener, 'GET', "/session/session%2F1/element/pending/$endpoint", null, $endpoint === 'name' ? 'input' : true);
		endforeach;
		foreach (['no such element' => 404, 'unknown error' => 500] as $error => $status):
			exchange($listener, 'GET', "/session/session%2F1/element/pending/$endpoint", null, ['error' => $error, 'message' => 'stale element reference: not a stale error'], $status);
		endforeach;
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	}, function ($url) use ($endpoint) {
		$browser = new Browser($url, 'firefox', waitTimeout: 50);
		$element = $browser->find('#pending');
		foreach ([20, null] as $timeout):
			$start = microtime(true);
			expectError(fn() => $element->wait(visible: $endpoint === 'displayed', invert: true, timeout: $timeout), 'wait timed out');
			$elapsed = microtime(true) - $start;
			verify($elapsed >= ($timeout ?? 50) / 1000 && $elapsed < 0.5, 'Element timeout units/default changed.');
		endforeach;
		expectError(fn() => $element->wait(visible: $endpoint === 'displayed', invert: true, timeout: 1000), 'no such element');
		expectError(fn() => $element->wait(visible: $endpoint === 'displayed', invert: true), 'unknown error');
		foreach ([0, -1, INF, NAN] as $timeout):
			expectError(fn() => $element->wait(visible: $endpoint === 'displayed', invert: true, timeout: $timeout), 'positive and finite');
		endforeach;
		$browser->quit();
	});

	scenario("element inverted $endpoint wait propagates stale frames", function ($listener) {
		createSession($listener);
		exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
		exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#node'], [Element::REFERENCE => 'node']);
		exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => null], null);
		exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], ['error' => 'stale element reference', 'message' => 'frame removed'], 404);
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	}, function ($url) use ($endpoint) {
		$browser = new Browser($url, 'firefox');
		$element = $browser->switchFrame(0)->find('#node');
		$browser->defaultFrame();
		expectError(fn() => $element->wait(visible: $endpoint === 'displayed', invert: true, timeout: 1000), 'frame removed');
		$browser->quit();
	});
endforeach;

foreach ([[false, false], [true, false], [false, true], [true, true]] as [$visible, $invert]):
	scenario('original-element wait conditions: visible=' . (int)$visible . ', invert=' . (int)$invert, function ($listener) use ($visible, $invert) {
		createSession($listener);
		exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#node'], [Element::REFERENCE => 'original']);
		$path = '/session/session%2F1/element/original/' . ($visible ? 'displayed' : 'name');
		if ($visible || $invert):
			exchange($listener, 'GET', $path, null, $visible ? $invert : 'input');
		endif;
		if (!$visible && $invert):
			exchange($listener, 'GET', $path, null, ['error' => 'stale element reference', 'message' => 'removed'], 404);
		else:
			exchange($listener, 'GET', $path, null, $visible ? !$invert : 'input');
		endif;
		if (!$invert):
			exchange($listener, 'POST', '/session/session%2F1/element/original/click', [], null);
		endif;
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	}, function ($url) use ($visible, $invert) {
		$results = [];
		$browser = new RecordingBrowser($url, 'firefox', record: function ($condition, $message) use (&$results) { $results[] = [$condition, $message]; });
		$element = $browser->find('#node');
		verify($element->wait(visible: $visible, invert: $invert, assert: true, timeout: 1000) === $element, 'Wait lost its original receiver.');
		verify($element->id() === 'original' && array_column($results, 0) === [true], 'Wait re-found a replacement or reported intermediate results.');
		if (!$invert) $element->click();
		verify(!method_exists($element, 'waitNotPresent') && !method_exists($element, 'waitNotVisible'), 'Overlapping element waits remain.');
		$browser->quit();
	});
endforeach;

scenario('positive element waits propagate stale references without re-finding or assertion reports', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#node'], [Element::REFERENCE => 'original']);
	foreach (['name', 'displayed'] as $endpoint):
		exchange($listener, 'GET', '/session/session%2F1/element/original/' . $endpoint, null, ['error' => 'stale element reference', 'message' => 'original removed'], 404);
	endforeach;
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$results = [];
	$browser = new RecordingBrowser($url, 'firefox', record: function ($condition) use (&$results) { $results[] = $condition; });
	$element = $browser->find('#node');
	expectError(fn() => $element->wait(assert: true, timeout: 1000), 'original removed');
	expectError(fn() => $element->wait(visible: true, assert: true, timeout: 1000), 'original removed');
	verify($results === [], 'Stale reference was recorded as a condition mismatch.');
	$browser->quit();
});

scenario('element wait reports expiry before throwing and propagates recorder exceptions', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#node'], [Element::REFERENCE => 'original']);
	exchange($listener, 'GET', '/session/session%2F1/element/original/displayed', null, false);
	exchange($listener, 'GET', '/session/session%2F1/element/original/displayed', null, true);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$results = [];
	$throw = false;
	$expected = new RuntimeException('WebDriver HTTP 404 stale element reference: recorder failure');
	$browser = new RecordingBrowser($url, 'firefox', record: function ($condition, $message) use (&$results, &$throw, $expected) {
		$results[] = [$condition, $message];
		if ($throw) throw $expected;
		return false;
	});
	$element = $browser->find('#node');
	expectError(fn() => $element->wait(visible: true, assert: true, timeout: 20), 'wait timed out');
	verify($results === [[false, "Selector '#node' using 'css selector': expected visible, got hidden."]], 'Expiry did not report exactly one failure.');
	$throw = true;
	try {
		$element->wait(visible: true, assert: true, timeout: 1000);
		throw new RuntimeException('Recorder exception was swallowed.');
	} catch (RuntimeException $error) {
		verify($error === $expected, 'Recorder exception was replaced.');
	}
	verify(array_column($results, 0) === [false, true], 'Recorder was retried.');
	$browser->quit();
});

scenario('callable browser and window waits use milliseconds and the configured default', function ($listener) {
	createSession($listener);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox', waitTimeout: 50);
	foreach ([$browser, $browser->window()] as $receiver):
		foreach ([20, null] as $timeout):
			$start = microtime(true);
			expectError(fn() => $receiver->wait(fn() => false, timeout: $timeout), 'wait timed out');
			$elapsed = microtime(true) - $start;
			verify($elapsed >= ($timeout ?? 50) / 1000 && $elapsed < 0.5, 'Callable wait timeout units/default changed.');
		endforeach;
		foreach ([0, -1, INF, NAN] as $timeout):
			expectError(fn() => $receiver->wait(fn() => true, timeout: $timeout), 'positive and finite');
		endforeach;
		verify($receiver->wait(fn($actual) => $actual === $receiver ? ['ready'] : false, timeout: 1000) === ['ready'], 'Callable wait changed its callback receiver or result.');
	endforeach;
	$browser->quit();
});

scenario('wait and assertion budgets are independent of command I/O and honor per-call overrides', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#missing'], ['error' => 'no such element', 'message' => 'absent'], 404);
	exchange($listener, 'GET', '/session/session%2F1/element/node/displayed', null, false);
	exchange($listener, 'POST', '/session/session%2F1/element/node/click', [], ['error' => 'element click intercepted', 'message' => 'covered'], 400);
	exchange($listener, 'GET', '/session/session%2F1/element/node/text', null, 'actual');
	exchange($listener, 'POST', '/session/session%2F1/elements', ['using' => 'css selector', 'value' => '.missing'], []);
	exchange($listener, 'POST', '/session/session%2F1/elements', ['using' => 'css selector', 'value' => '.missing'], []);
	exchange($listener, 'GET', '/session/session%2F1/url', null, 'http://fixture/old');
	exchange($listener, 'GET', '/session/session%2F1/element/node/text', null, 'actual');
	exchange($listener, 'GET', '/session/session%2F1/title', null, 'slow command', 200, 0.12);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox', timeout: 0.5, waitTimeout: 40, assertionTimeout: 80);
	$element = new Element($browser->window(), 'node');
	foreach ([
		[fn() => $browser->find('#missing'), 40, 'wait timed out'],
		[fn() => $element->wait(visible: true), 40, 'wait timed out'],
		[fn() => $element->click(), 40, 'action timed out'],
		[fn() => $element->textEquals('expected'), 80, 'assertion failed'],
		[fn() => $browser->findAll('.missing')->countEquals(1), 80, 'assertion failed'],
		[fn() => $browser->urlMatches('~/new$~'), 80, 'assertion failed'],
		[fn() => $element->textEquals('expected', timeout: 20), 20, 'assertion failed'],
	] as [$operation, $milliseconds, $error]):
		$start = microtime(true);
		expectError($operation, $error);
		$elapsed = microtime(true) - $start;
		verify($elapsed >= $milliseconds / 1000 && $elapsed < $milliseconds / 1000 + 0.3, 'Wrong polling budget.');
	endforeach;
	verify($browser->title() === 'slow command', 'Polling settings changed the HTTP timeout.');
	$browser->quit();
});

foreach ([null, 20] as $interval):
	scenario('callback and condition polling use the configured interval: ' . ($interval ?? 'default'), function ($listener) {
		createSession($listener, discover: false);
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	}, function ($url) use ($interval) {
		$browser = $interval === null ? new Browser($url, 'firefox') : new Browser($url, 'firefox', pollInterval: $interval);
		foreach ([false, true] as $evaluate):
			$calls = 0;
			$condition = function () use (&$calls) { return ++$calls === 2; };
			$start = microtime(true);
			$result = $evaluate ? $browser->evaluate($condition, true, null) : $browser->wait($condition);
			$elapsed = microtime(true) - $start;
			verify($result === true && $calls === 2 && $elapsed >= ($interval ?? 500) / 1000
				&& $elapsed < ($interval ?? 500) / 1000 + 0.3, 'Polling interval or callback return changed.');
		endforeach;
		$browser->quit();
	});
endforeach;

foreach (['waitTimeout', 'assertionTimeout', 'pollInterval'] as $option):
	foreach ([0, -1, INF, NAN] as $invalid):
		expectError(fn() => new Browser('http://127.0.0.1:1', 'firefox', ...[$option => $invalid]), $option . ' must be positive and finite');
	endforeach;
endforeach;
echo "PASS: invalid polling configuration fails before session creation\n";

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
	verify($window->find('//input', 'xpath', visible: true, wait: true)->window() === $window, 'Find wait lost window context.');
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
	expectError(fn() => $driver->wait(fn() => false, timeout: 40), 'wait timed out');
	$elapsed = microtime(true) - $start;
	verify($elapsed >= 0.04 && $elapsed < 0.5, 'Wait did not use its millisecond polling deadline.');
	expectError(fn() => $driver->wait(fn($d) => $d->find('[broken'), timeout: 1000), 'invalid selector');
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
	expectError(fn() => (new Element($driver->window(), 'disabled'))->select('value', wait: false), 'disabled select');
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
	verify($rows->get(0)->find('.//input', using: 'xpath', wait: false)->id() === 'input/1', 'Scoped XPath lookup failed.');
	verify($ids($rows->get(1)->findAll('td', using: 'tag name')) === ['cell/1'], 'Scoped plural lookup failed.');
	verify($driver->find('Continue', using: 'link text')->id() === 'link/1', 'Link lookup failed.');
	verify($ids($driver->findAll('More', using: 'partial link text')) === ['link/2'], 'Partial-link lookup failed.');
	verify($driver->activeElement()->id() === 'input/1', 'Focused element was not unwrapped.');
	$shadow = (new Element($driver->window(), 'host/1'))->shadowRoot();
	verify($shadow instanceof ShadowRoot && $shadow->id() === 'shadow/1', 'Wrong shadow-root ID.');
	verify($shadow->find('button', wait: false)->id() === 'button/1', 'Shadow lookup failed.');
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

scenario('HTML snapshots are eager, unrecorded captures with independent persistence and driver errors', function ($listener) {
	createSession($listener);
	scriptExchange($listener, [], '<!DOCTYPE html><html><head><base href="https://example.test/a/"></head><body>First €</body></html>');
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
	scriptExchange($listener, [], '<html><head><base href="https://example.test/frame/"></head><body>Frame</body></html>');
	scriptExchange($listener, [], ['error' => 'javascript error', 'message' => 'snapshot failed'], 500);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	$snapshot = null;
	[$status, $output, $record] = runChecks(['check' => function () use ($browser, &$snapshot) { $snapshot = $browser->snapshot(); }]);
	verify($status === 0 && $record['suites'][0]['attempts'][0]['steps'] === [], 'Snapshot recorded its internal script as an action.');
	verify($snapshot instanceof Snapshot && str_contains($snapshot->data(), 'First €'), 'Snapshot HTML changed or was not returned eagerly.');
	$browser->switchFrame(0);
	$frame = $browser->window()->snapshot();
	verify(str_contains($frame->data(), '>Frame<'), 'Window snapshot lost frame capture.');
	expectError(fn() => $browser->snapshot(), 'javascript error: snapshot failed');
	$browser->quit();
	$path = tempnam(sys_get_temp_dir(), 'webdriver-snapshot-');
	try {
		foreach ([$snapshot, $frame] as $capture):
			verify($capture->save($path) === $capture && file_get_contents($path) === $capture->data(), 'Snapshot save lost its receiver or captured HTML.');
			expectError(fn() => $capture->save($path . '/missing/file'), 'Could not write the complete snapshot');
		endforeach;
	} finally {
		unlink($path);
	}
});

scenario('snapshot propagates stale selected-frame restoration without executing the script', function ($listener) {
	createSession($listener);
	exchange($listener, 'GET', '/session/session%2F1/timeouts', null, ['script' => 30000]);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => [Element::REFERENCE => 'gone']], ['error' => 'stale element reference', 'message' => 'frame gone'], 404);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	$browser->window()->rememberFrames([[Element::REFERENCE => 'gone']]);
	expectError(fn() => $browser->snapshot(), 'context restoration failed');
	$browser->quit();
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
