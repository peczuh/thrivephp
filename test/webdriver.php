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
use ThriveData\ThrivePHP\WebDriver\NetworkDecision;
use ThriveData\ThrivePHP\Test;
use ThriveData\ThrivePHP\Test\Suite;
use ThriveData\ThrivePHP\Test\SuiteBefore;
use ThriveData\ThrivePHP\Test\SuiteAfter;
use ThriveData\ThrivePHP\Test\Test as TestCase;

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

require __DIR__ . '/fixtures/webdriver-targets.php';

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
		exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#name'], [Element::REFERENCE => 'element/1']);
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
				exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#node'], [Element::REFERENCE => 'node']);
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
				exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#node'], [Element::REFERENCE => 'node']);
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
					$element = $browser->find('#node')->visible(assert: true, wait: false);
					$test->verify(false, 'continuing verification');
					switch ($operation) {
						case 'element': $element->textEquals('Expected', assert: true, wait: false); break;
						case 'browser URL': $browser->urlMatches('~/expected$~', assert: true, wait: false); break;
						case 'window URL': $browser->window()->urlMatches('~/expected$~', assert: true, wait: false); break;
						case 'count': $browser->findAll('.missing')->countEquals(1, assert: true, wait: false); break;
						case 'empty snapshot': (new ElementSet())->countEquals(1, assert: true, wait: false); break;
						case 'find': $browser->find('.missing')->present(wait: false, assert: true); break;
						case 'wait': $element->visible(assert: true, timeout: 20); break;
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
	scenario("$scope target assertions record once, retain receivers and honor stop", function ($listener) use ($scope) {
		createSession($listener);
		$path = '/session/session%2F1' . match ($scope) { 'element' => '/element/parent', 'shadow' => '/shadow/root', default => '' };
		foreach ([true, false] as $record):
			exchange($listener, 'POST', $path . '/element', ['using' => 'css selector', 'value' => '#ready'], [Element::REFERENCE => 'ready']);
			exchange($listener, 'GET', '/session/session%2F1/element/ready/name', null, 'div');
		endforeach;
		exchange($listener, 'POST', $path . '/element', ['using' => 'css selector', 'value' => '#missing'], ['error' => 'no such element', 'message' => 'absent'], 404);
		exchange($listener, 'POST', $path . '/element', ['using' => 'css selector', 'value' => '#hidden'], [Element::REFERENCE => 'hidden']);
		exchange($listener, 'GET', '/session/session%2F1/element/hidden/displayed', null, false);
		exchange($listener, 'POST', $path . '/element', ['using' => 'css selector', 'value' => '#gone'], ['error' => 'no such element', 'message' => 'absent'], 404);
		exchange($listener, 'POST', $path . '/element', ['using' => 'css selector', 'value' => '#present'], [Element::REFERENCE => 'present']);
		exchange($listener, 'GET', '/session/session%2F1/element/present/name', null, 'div');
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
				$ready = $target->find('#ready');
				verify($ready->present(wait: false, assert: true, stop: false) === $ready, 'Successful verification lost its target.');
				verify($ready->present(), 'One-shot query did not observe presence.');
				$target->find('#missing')->present(timeout: 20, assert: true, stop: false);
				$target->find('#hidden')->visible(wait: false, assert: true, stop: false);
				$target->find('#gone')->present(wait: false, is: false, assert: true, stop: false);
				$target->find('#present')->present(wait: false, is: false, assert: true, stop: false);
				$continued = true;
				$target->find('#missing')->present(wait: false, assert: true);
				$stopped = false;
			} finally {
				$browser->quit();
			}
		}]);
		verify($status === 1 && $continued && $stopped && str_contains($output, 'Assertions: 2 passed, 4 failed')
			&& !str_contains($output, 'Execution error'), $output);
		$steps = array_values(array_filter($record['suites'][0]['attempts'][0]['steps'], fn($step) => $step['label'] !== 'quit'));
		verify(array_column($steps, 'label') === ['present', 'present', 'visible', 'present', 'present', 'present']
			&& array_map(fn($step) => count($step['assertions']), $steps) === array_fill(0, 6, 1)
			&& array_column($steps, 'error') === array_fill(0, 6, null), 'Queries recorded steps or verification duplicated assertions.');
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
			verify($element->visible(timeout: 20, assert: true, stop: false) === $element, 'Expired verification lost its receiver.');
			verify($element->visible(is: false, assert: true, stop: false) === $element, 'Hiddenness verification lost its receiver.');
			$element->visible(assert: true, stop: false);
			$element->present(is: false, timeout: 20, assert: true, stop: false);
			verify($element->present(), 'Exact node was absent.');
			verify($element->id() === 'node', 'Original-node wait changed identity.');
			$continued = true;
			$element->visible(assert: true, timeout: 20);
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
	expectError(fn() => $browser->find('#missing')->present(wait: false, assert: true, stop: false), 'assertion failed');
	expectError(fn() => (new Element($browser->window(), 'node'))->visible(timeout: 20, assert: true, stop: false), 'assertion failed');
	$browser->quit();
});

scenario('wait verification propagates selector, session and stale-context errors without assertion reports', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '[broken'], ['error' => 'invalid selector', 'message' => 'broken'], 400);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#node'], ['error' => 'invalid session id', 'message' => 'gone'], 404);
	exchange($listener, 'GET', '/session/session%2F1/element/node/text', null, ['error' => 'stale element reference', 'message' => 'removed'], 404);
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], ['error' => 'no such frame', 'message' => 'removed'], 404);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$results = [];
	$browser = new RecordingBrowser($url, 'firefox', record: function ($passed) use (&$results) { $results[] = $passed; });
	expectError(fn() => $browser->find('[broken')->present(assert: true, stop: false), 'invalid selector');
	expectError(fn() => $browser->find('#node')->present(assert: true, stop: false), 'invalid session id');
	expectError(fn() => (new Element($browser->window(), 'node'))->textEquals('text', assert: true, stop: false, wait: false), 'stale element reference');
	expectError(fn() => (new Element($browser->window(), 'node', [0]))->present(assert: true, stop: false), 'no such frame');
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
			foreach ([$good, $bad] as $value):
				if (!$document) exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#node'], [Element::REFERENCE => 'original']);
				comparisonExchange($listener, $path, $value, $document);
			endforeach;
			foreach ([[$bad, $good], [$good, $bad], [$bad], [$good]] as $values):
				foreach ($values as $index => $value):
					$id = $index === 0 ? 'pending' : 'ready';
					if (!$document) exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#node'], [Element::REFERENCE => $id]);
					comparisonExchange($listener, $path, $value, $document, $id);
				endforeach;
			endforeach;
			foreach ([$bad, $bad, $bad] as $value):
				if (!$document) exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#node'], [Element::REFERENCE => 'ready']);
				comparisonExchange($listener, $path, $value, $document, 'ready');
			endforeach;
			if (!$document) exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#node'], [Element::REFERENCE => 'ready']);
			comparisonExchange($listener, $path, ['error' => 'unknown error', 'message' => 'driver failure'], $document, 'ready', 500);
			exchange($listener, 'DELETE', '/session/session%2F1', null, null);
		}, function ($url) use ($method, $arguments, $scope, $document) {
			$browser = new Browser($url, 'firefox', waitTimeout: 1, assertionTimeout: 300, pollInterval: 10);
			$target = match ($scope) { 'browser' => $browser, 'window' => $browser->window(), default => $browser->find('#node') };
			try {
				[$status, $output, $record] = runChecks(['check' => function () use ($target, $method, $arguments, $document) {
					foreach ([false, true] as $invert):
						verify($target->$method(...[...$arguments, 'assert' => true, 'wait' => false, 'timeout' => 0, 'is' => !$invert]) === $target, 'One-shot comparison lost receiver.');
					endforeach;
					foreach ([false, true] as $invert):
						verify($target->$method(...[...$arguments, 'assert' => true, 'is' => !$invert, 'stop' => false]) === $target, 'Waiting verification lost receiver.');
					endforeach;
					foreach ([false, true] as $invert):
						verify($target->$method(...[...$arguments, 'assert' => true, 'timeout' => 1, 'stop' => false, 'is' => !$invert]) === $target, 'Expired verification lost receiver.');
					endforeach;
					expectError(fn() => $target->$method(...[...$arguments, 'assert' => true, 'wait' => false]), 'expected');
				}]);
				verify($status === 1 && str_contains($output, 'Assertions: 4 passed, 3 failed'), $output);
				$steps = $record['suites'][0]['attempts'][0]['steps'];
				verify(count($steps) === 7 && array_column($steps, 'label') === array_fill(0, 7, $method)
					&& array_column($steps, 'error') === array_fill(0, 7, null)
					&& array_map(fn($step) => count($step['assertions']), $steps) === array_fill(0, 7, 1), 'Comparison counted nested steps or duplicate errors.');
				foreach ([false, true] as $stop) expectError(fn() => $target->$method(...[...$arguments, 'assert' => true, 'wait' => false, 'stop' => $stop]), 'assertion failed');
				foreach ([0, -1, INF, NAN] as $timeout) expectError(fn() => $target->$method(...[...$arguments, 'assert' => true, 'timeout' => $timeout, 'stop' => false]), 'positive and finite');
				[$status, $output, $record] = runChecks(['check' => fn() => $target->$method(...[...$arguments, 'assert' => true, 'wait' => false, 'stop' => false])]);
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
			expectError(fn() => $target->$method(...[...$arguments, 'assert' => true, 'wait' => false, 'is' => false, 'stop' => false]), 'Could not evaluate assertion pattern');
		endforeach;
		foreach (['', '  ', [], ['two words'], [null]] as $classes):
			expectError(fn() => $element->hasClass($classes, is: false, assert: true, stop: false), 'class');
		endforeach;
	} finally {
		$browser->quit();
	}
});

scenario('negative comparisons require a live matching node and count inversion preserves snapshot semantics', function ($listener) {
	createSession($listener);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#node'], ['error' => 'no such element', 'message' => 'absent'], 404);
	exchange($listener, 'POST', '/session/session%2F1/elements', ['using' => 'css selector', 'value' => '.row'], []);
	exchange($listener, 'POST', '/session/session%2F1/elements', ['using' => 'css selector', 'value' => '.row'], [[Element::REFERENCE => 'replacement']]);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox', pollInterval: 10);
	try {
		[$status, $output] = runChecks(['check' => function () use ($browser) {
			$element = $browser->find('#node');
			$element->textEquals('other', is: false, assert: true, stop: false, timeout: 1);
			$set = $browser->findAll('.row');
			verify($set->countEquals(1, is: false, assert: true) === $set, 'Inverted count lost receiver.');
			$set->countEquals(1, is: false, assert: true, stop: false, timeout: 1);
			(new ElementSet())->countEquals(1, is: false, assert: true, wait: false);
			(new ElementSet())->countEquals(0, is: false, assert: true, wait: false, stop: false);
		}]);
		verify($status === 1 && str_contains($output, 'Assertions: 2 passed, 3 failed'), $output);
	} finally {
		$browser->quit();
	}
});

$assertionCases = [
	['visible', [], '/displayed', true, true, 'expected true, got true'],
	['visible', [], '/displayed', false, false, 'expected true, got false'],
	['visible', [false], '/displayed', false, true, 'not (visibility: expected true), got false'],
	['visible', [false], '/displayed', true, false, 'not (visibility: expected true), got true'],
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
	foreach ($assertionCases as [$method, $arguments, $path, $actual]):
		exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#name'], [Element::REFERENCE => 'input']);
		exchange($listener, 'GET', '/session/session%2F1/element/input' . $path, null, $actual);
	endforeach;
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#name'], [Element::REFERENCE => 'input']);
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
				verify($element->$method(...[...$arguments, 'assert' => true, 'wait' => false, 'stop' => false]) === $element, "$method did not preserve the verification receiver.");
				$completed++;
			endforeach;
			$element->textEquals('Default must stop', assert: true, wait: false);
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

scenario('continuing assertions wait by default, follow replacements and record timeout once', function ($listener) {
	createSession($listener);
	$selector = ['using' => 'css selector', 'value' => '#status'];
	foreach (['pending' => 'Pending', 'replacement' => 'Saved'] as $id => $text):
		exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => $id]);
		exchange($listener, 'GET', "/session/session%2F1/element/$id/text", null, $text);
	endforeach;
	exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => 'replacement']);
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
			verify($element->textEquals('Saved', assert: true, timeout: 1000, stop: false)->click() === $element, 'Successful verification lost the receiver.');
			$start = microtime(true);
			verify($element->textEquals('Done', assert: true, timeout: 20, stop: false) === $element, 'Failed verification lost the receiver.');
			verify(microtime(true) - $start >= 0.02, 'Timeout did not wait.');
			$continued = true;
		} finally {
			$browser->quit();
		}
	}]);
	verify($status === 1 && $continued && str_contains($output, 'Assertions: 1 passed, 1 failed'), $output);
	verify(str_contains($output, "expected 'Done', got 'Pending'") && !str_contains($output, 'Execution error:'), $output);
});

scenario('count and URL assertions continue after mismatches and preserve receivers', function ($listener) {
	createSession($listener);
	$selector = ['using' => 'css selector', 'value' => '.row'];
	exchange($listener, 'POST', '/session/session%2F1/elements', $selector, []);
	exchange($listener, 'POST', '/session/session%2F1/elements', $selector, [[Element::REFERENCE => 'old']]);
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
			verify($set->countEquals(1, assert: true, timeout: 20, stop: false) === $set, 'Failed count verification lost receiver.');
			$set->countEquals(1, assert: true, wait: false);
			verify($browser->urlMatches('~/done$~', assert: true, timeout: 20, stop: false) === $browser, 'Browser verification lost its receiver.');
			$window = $browser->window();
			verify($window->urlMatches('~/done$~', assert: true, wait: false, stop: false) === $window, 'Window verification lost its receiver.');
			$browser->urlMatches('~/done$~', assert: true, stop: false);
			$empty = new ElementSet();
			verify($empty->countEquals(1, assert: true, wait: false, stop: false)->countEquals(0, assert: true, wait: false, stop: false) === $empty, 'Explicit empty snapshot lost its receiver.');
			$continued = true;
		} finally {
			$browser->quit();
		}
	}]);
	verify($status === 1 && $continued && str_contains($output, 'Assertions: 3 passed, 4 failed') && !str_contains($output, 'Execution error'), $output);
	expectError(fn() => (new ElementSet())->countEquals(1, assert: true, wait: false, stop: false), 'WebDriver assertion failed');
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
			expectError(fn() => $element->attributeMatches('name', '/[/', assert: true, stop: false), 'Could not evaluate assertion pattern');
			expectError(fn() => $browser->urlMatches('not delimited', assert: true, stop: false), 'Could not evaluate assertion pattern');
			$element->textEquals('Expected', assert: true, wait: false, stop: false);
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
	foreach ($assertionCases as [$method, $arguments, $path, $actual]):
		exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#name'], [Element::REFERENCE => 'input']);
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
		verify($element->$method(...[...$arguments, 'assert' => true, 'wait' => false]) === $element, "$method lost the fluent receiver.");
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
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#name'], [Element::REFERENCE => 'input']);
	exchange($listener, 'GET', '/session/session%2F1/element/input/property/value', null, 'Jane');
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#name'], [Element::REFERENCE => 'input']);
	exchange($listener, 'GET', '/session/session%2F1/element/input/displayed', null, true);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#name'], [Element::REFERENCE => 'input']);
	exchange($listener, 'GET', '/session/session%2F1/element/input/text', null, 'Actual');
	exchange($listener, 'POST', '/session/session%2F1/elements', ['using' => 'css selector', 'value' => '.missing'], []);
	exchange($listener, 'GET', '/session/session%2F1/url', null, 'https://example.test/actual');
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	$element = $browser->find('#name');
	verify($element->value('Jane')->valueEquals('Jane', assert: true, wait: false)->visible(assert: true, wait: false) === $element, 'Action/assertion chain failed.');
	expectError(fn() => $element->textEquals('Expected', assert: true, wait: false, stop: false), "WebDriver assertion failed: Selector '#name' using 'css selector' text: expected 'Expected', got 'Actual'.");
	expectError(fn() => $browser->findAll('.missing')->countEquals(1, assert: true, wait: false), 'expected 1, got 0');
	expectError(fn() => $browser->urlMatches('~/expected$~', assert: true, wait: false), "got 'https://example.test/actual'");
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
	$empty = ElementSet::fromReferences($browser->window(), [], []);
	$browser->findAll('.missing')->countEquals(0, assert: true, wait: false);
	verify($empty->countEquals(1, assert: true, wait: false) === $empty, 'Empty count assertion lost its set.');
	$set = new ElementSet(...$browser->findAll('input')->all());
	verify($set->countEquals(2, assert: true, wait: false)->countEquals(1, assert: true, wait: false) === $set, 'Snapshot assertion lost its set.');
	$set->first()->findAll('.missing')->countEquals(0, assert: true, wait: false);
	$set->first()->shadowRoot()->findAll('.missing')->countEquals(0, assert: true, wait: false);
	$explicit = new ElementSet(...$set->all());
	verify($explicit->countEquals(2, assert: true, wait: false) === $explicit, 'Explicit snapshot lost its element recorder.');
	$standalone = new ElementSet();
	verify($standalone->countEquals(0, assert: true, wait: false) === $standalone, 'Standalone empty snapshot cannot assert its count.');
	expectError(fn() => $standalone->countEquals(1, assert: true, wait: false), 'expected 1, got 0');
	verify(array_column($results, 0) === [true, false, true, false, true, true, true], 'Count results were not shared with the browser recorder.');
	verify($results[1][1] === 'Element count: expected 1, got 0.', 'Count failure lacks expected/actual values.');
	$browser->quit();
});

foreach (['browser', 'window'] as $receiver):
	scenario("$receiver selector visibility checks the first match and propagates driver errors", function ($listener) {
		createSession($listener);
		$selector = ['using' => 'css selector', 'value' => '.error'];
		exchange($listener, 'POST', '/session/session%2F1/element', $selector, ['error' => 'no such element', 'message' => 'absent'], 404);
		foreach ([false, true] as $visible):
			exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => 'first']);
			exchange($listener, 'GET', '/session/session%2F1/element/first/displayed', null, $visible);
		endforeach;
		exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '[broken'], ['error' => 'invalid selector', 'message' => 'bad syntax'], 400);
		exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => 'old']);
		exchange($listener, 'GET', '/session/session%2F1/element/old/displayed', null, ['error' => 'stale element reference', 'message' => 'replaced'], 404);
		exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => 'current']);
		exchange($listener, 'GET', '/session/session%2F1/element/current/displayed', null, ['error' => 'unknown error', 'message' => 'display query failed'], 500);
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	}, function ($url) use ($receiver) {
		$results = [];
		$browser = new RecordingBrowser($url, 'firefox', record: function (bool $condition, string $message) use (&$results): bool {
			$results[] = [$condition, $message];
			return $condition;
		});
		$target = $receiver === 'browser' ? $browser : $browser->window();
		foreach (range(1, 3) as $ignored):
			$target->find('.error')->visible(is: false, assert: true, wait: false);
		endforeach;
		verify(array_column($results, 0) === [true, true, false], 'Absent, hidden, or visible first match was reported incorrectly.');
		verify(str_contains($results[2][1], 'not (visibility: expected true), got true'), 'Selector failure lacks expected/actual diagnostics.');
		expectError(fn() => $target->find('[broken')->visible(is: false, assert: true), 'invalid selector');
		verify($target->find('.error')->visible(is: false), 'A stale node should be invisible.');
		expectError(fn() => $target->find('.error')->visible(is: false, assert: true), 'unknown error');
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
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'xpath', 'value' => '//aside'], [Element::REFERENCE => 'visible']);
	exchange($listener, 'GET', '/session/session%2F1/element/visible/displayed', null, true);
	exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '.gone'], ['error' => 'no such element', 'message' => 'absent'], 404);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	$window = $browser->window()->switchFrame(0);
	$browser->switch('other');
	expectError(fn() => $window->find('//aside', 'xpath')->visible(wait: false, is: false, assert: true), 'not (visibility: expected true), got true');
	$browser->find('.gone')->visible(is: false, assert: true);
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
	verify($window->urlMatches('~/projects/\d+$~', assert: true) === $window, 'URL assertion lost its window.');
	verify($browser->urlMatches('~/missing$~', assert: true, wait: false) === $browser, 'URL assertion lost its browser.');
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
	$element = $browser->switchFrame(0)->find('#original')->resolve();
	$browser->switch('other');
	$element->visible(assert: true, wait: false);
	verify($element->visible(is: false, assert: true, wait: false) === $element, 'Stale visibility assertion lost original identity.');
	verify(!$element->visible(), 'A stale node should be invisible.');
	verify($element->visible(is: false), 'A missing node should be invisible.');
	expectError(fn() => $element->visible(is: false), 'unknown error');
	$browser->defaultFrame();
	expectError(fn() => $element->visible(is: false), 'frame removed');
	verify(array_column($results, 0) === [true, true], 'Driver/context errors were reported as assertions.');
	verify(str_contains($results[1][1], 'stale element reference'), 'Stale assertion diagnostics were lost.');
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
		$element->visible(is: false, assert: true, wait: false);
		throw new RuntimeException('Callback exception was swallowed.');
	} catch (RuntimeException $error) {
		verify($error === $expected, 'Callback exception was replaced.');
	}
	verify($calls === 1, 'Callback was called more than once.');
	$browser->quit();
});

$retryAssertions = [
	['visible', [], '/displayed', false, true],
	['visible', [false], '/displayed', true, false],
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
		exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'other'], null);
		exchange($listener, 'POST', '/session/session%2F1/window', ['handle' => 'window/1'], null);
		exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
		foreach (['original' => $pending, 'removed' => null, 'replacement' => $ready] as $id => $value):
			if ($id === 'removed' && $path === '/displayed' && $ready === false) continue;
			exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => $id]);
			exchange($listener, 'GET', "/session/session%2F1/element/$id$path", null,
				$value ?? ['error' => 'stale element reference', 'message' => 'replaced'], $value === null ? 404 : 200);
		endforeach;
		exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => 'replacement']);
		exchange($listener, 'POST', '/session/session%2F1/element/replacement/click', [], null);
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	}, function ($url) use ($method, $arguments) {
		$results = [];
		$browser = new RecordingBrowser($url, 'firefox', record: function ($condition, $message) use (&$results) { $results[] = [$condition, $message]; });
		$element = $browser->switchFrame(0)->find('//status', 'xpath');
		$browser->switch('other');
		verify($element->$method(...[...$arguments, 'assert' => true, 'timeout' => 1000])->click() === $element, 'Retry lost its fluent receiver.');
		verify(array_column($results, 0) === [true], 'Retry reported intermediate assertion results.');
		$browser->quit();
	});
endforeach;

scenario('assertion retries handle missing matches and report timeout once with the final observation', function ($listener) {
	createSession($listener);
	$selector = ['using' => 'css selector', 'value' => '#status'];
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
	$element = $browser->find('#status')->textEquals('Saved', assert: true, wait: true, timeout: 1000);
	foreach ([20, null] as $timeout):
		$start = microtime(true);
		verify($element->textEquals('Done', assert: true, wait: true, timeout: $timeout) === $element, 'Recorded timeout should preserve chaining.');
		$elapsed = microtime(true) - $start;
		verify($elapsed >= ($timeout ?? 50) / 1000 && $elapsed < 0.5, 'Assertion timeout units/default changed.');
	endforeach;
	$element->visible(is: false, assert: true, wait: true, timeout: 1000);
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
	$element = $browser->activeElement()->textEquals('Done', assert: true, wait: true, timeout: 1000);
	expectError(fn() => $element->textEquals('Done'), 'focus removed');
	$script = $browser->script('return node;');
	expectError(fn() => $script->valueEquals('Done'), 'script node removed');
	verify($results === [true], 'Stale references without lookups should not become assertion failures.');
	$browser->quit();
});

scenario('descendant lookups wait by default, re-find ancestors and count an absent parent as an empty search', function ($listener) {
	createSession($listener);
	$parent = ['using' => 'css selector', 'value' => '#parent'];
	$child = ['using' => 'xpath', 'value' => './/span'];
	exchange($listener, 'POST', '/session/session%2F1/element', $parent, [Element::REFERENCE => 'parent-old']);
	exchange($listener, 'POST', '/session/session%2F1/element/parent-old/element', $child, ['error' => 'no such element', 'message' => 'not yet'], 404);
	exchange($listener, 'POST', '/session/session%2F1/element', $parent, [Element::REFERENCE => 'parent-old']);
	exchange($listener, 'POST', '/session/session%2F1/element/parent-old/element', $child, [Element::REFERENCE => 'child-old']);
	exchange($listener, 'POST', '/session/session%2F1/element', $parent, [Element::REFERENCE => 'parent-old']);
	exchange($listener, 'POST', '/session/session%2F1/element/parent-old/elements', $child, [[Element::REFERENCE => 'child-old']]);
	foreach (['old' => 'Pending', 'new' => 'Done'] as $suffix => $text):
		exchange($listener, 'POST', '/session/session%2F1/element', $parent, [Element::REFERENCE => "parent-$suffix"]);
		exchange($listener, 'POST', "/session/session%2F1/element/parent-$suffix/element", $child, [Element::REFERENCE => "child-$suffix"]);
		exchange($listener, 'GET', "/session/session%2F1/element/child-$suffix/text", null, $text);
	endforeach;
	exchange($listener, 'POST', '/session/session%2F1/element', $parent, [Element::REFERENCE => 'parent-new']);
	exchange($listener, 'POST', '/session/session%2F1/element/parent-new/element', $child, [Element::REFERENCE => 'child-new']);
	exchange($listener, 'POST', '/session/session%2F1/element/child-new/click', [], null);
	exchange($listener, 'POST', '/session/session%2F1/element', $parent, ['error' => 'no such element', 'message' => 'parent removed'], 404);
	exchange($listener, 'POST', '/session/session%2F1/element', $parent, ['error' => 'no such element', 'message' => 'parent removed'], 404);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	$parent = $browser->find('#parent');
	$child = $parent->find('.//span', 'xpath');
	verify($child->resolve(timeout: 1000)->id() === 'child-old', 'Target acquisition failed.');
	$set = $parent->findAll('.//span', 'xpath');
	verify($set->count() === 1, 'Scoped count failed.');
	$child->textEquals('Done', assert: true, wait: true, timeout: 1000)->click();
	verify($set->countEquals(0, wait: true), 'Absent parent did not produce an empty search.');
	verify($parent->find('.//span', 'xpath')->present(is: false), 'Absent parent did not satisfy inverted presence.');
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
	exchange($listener, 'POST', '/session/session%2F1/element', $host, [Element::REFERENCE => 'host-new']);
	exchange($listener, 'GET', '/session/session%2F1/element/host-new/shadow', null, [ShadowRoot::REFERENCE => 'root-new']);
	exchange($listener, 'POST', '/session/session%2F1/shadow/root-new/element', $child, [Element::REFERENCE => 'child-new']);
	exchange($listener, 'POST', '/session/session%2F1/element/child-new/click', [], null);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	$child = $browser->find('#host')->shadowRoot()->find('.status');
	verify($child->resolve(timeout: 1000)->id() === 'child-old', 'Shadow target acquisition failed.');
	$child->textEquals('Done', assert: true, wait: true, timeout: 1000)->click();
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
	exchange($listener, 'POST', '/session/session%2F1/elements', $selector, [[Element::REFERENCE => 'one'], [Element::REFERENCE => 'new']]);
	exchange($listener, 'POST', '/session/session%2F1/element/new/click', [], null);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$results = [];
	$browser = new RecordingBrowser($url, 'firefox', record: function ($condition) use (&$results) { $results[] = $condition; });
	$set = $browser->findAll('.row');
	verify($set->countEquals(2, assert: true, timeout: 1500) === $set, 'Successful count lost receiver.');
	$set->get(1)->textEquals('Done', assert: true, wait: true, timeout: 1000)->click();
	expectError(fn() => (new ElementSet())->countEquals(3, assert: true), 'without a retained search');
	verify($results === [true, true], 'Count/member retries reported intermediate results.');
	$browser->quit();
});

scenario('stale selectorless search ancestors fail without retrying or recording a mismatch', function ($listener) {
	createSession($listener);
	exchange($listener, 'GET', '/session/session%2F1/element/active', null, [Element::REFERENCE => 'parent']);
	$selector = ['using' => 'css selector', 'value' => '.child'];
	exchange($listener, 'POST', '/session/session%2F1/element/parent/element', $selector, ['error' => 'stale element reference', 'message' => 'unrecoverable parent'], 404);
	exchange($listener, 'POST', '/session/session%2F1/element/parent/elements', $selector, ['error' => 'stale element reference', 'message' => 'unrecoverable parent'], 404);
	exchange($listener, 'GET', '/session/session%2F1/timeouts', null, ['script' => 30000]);
	exchange($listener, 'POST', '/session/session%2F1/execute/sync', ['script' => 'return root;', 'args' => []], [ShadowRoot::REFERENCE => 'root']);
	exchange($listener, 'POST', '/session/session%2F1/shadow/root/element', $selector, ['error' => 'detached shadow root', 'message' => 'unrecoverable root'], 404);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$results = [];
	$browser = new RecordingBrowser($url, 'firefox', record: function ($condition) use (&$results) { $results[] = $condition; });
	$parent = $browser->activeElement();
	$child = $parent->find('.child');
	$set = $parent->findAll('.child');
	expectError(fn() => $child->textEquals('Done'), 'unrecoverable parent');
	expectError(fn() => $set->countEquals(0, wait: true, timeout: 1000), 'unrecoverable parent');
	$shadowChild = $browser->script('return root;')->find('.child');
	expectError(fn() => $shadowChild->textEquals('Done'), 'unrecoverable root');
	verify($results === [], 'Unrecoverable ancestors were treated as assertion mismatches.');
	$browser->quit();
});

scenario('count conditions honor deadlines and propagate context errors', function ($listener) {
	createSession($listener);
	$selector = ['using' => 'css selector', 'value' => '.row'];
	exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
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
		$set->countEquals(2, assert: true, wait: true, timeout: $timeout);
		$elapsed = microtime(true) - $start;
		verify($elapsed >= ($timeout ?? 50) / 1000 && $elapsed < 0.5, 'Count timeout units/default changed.');
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
	exchange($listener, 'GET', '/session/session%2F1/element/new/name', null, 'div');
	exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => 'new']);
	exchange($listener, 'GET', '/session/session%2F1/element/new/displayed', null, false);
	exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => 'new']);
	exchange($listener, 'GET', '/session/session%2F1/element/new/name', null, 'div');
	exchange($listener, 'POST', '/session/session%2F1/element', $selector, ['error' => 'no such element', 'message' => 'gone'], 404);
	exchange($listener, 'POST', '/session/session%2F1/element', $selector, ['error' => 'no such element', 'message' => 'gone'], 404);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$results = [];
	$browser = new RecordingBrowser($url, 'firefox', record: function ($condition) use (&$results) { $results[] = $condition; return false; });
	$target = $browser->find('#new');
	verify($target->present(assert: true, timeout: 1000) === $target, 'Presence lost receiver.');
	$target->visible(wait: false, assert: true, timeout: -1);
	$target->present(wait: true, is: false, assert: true, timeout: 1000);
	$target->present(wait: false, assert: true);
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
	$browser->urlMatches('~/done$~', assert: true, timeout: 1000);
	foreach ([20, null] as $timeout):
		$start = microtime(true);
		$browser->window()->urlMatches('~/done$~', assert: true, timeout: $timeout);
		$elapsed = microtime(true) - $start;
		verify($elapsed >= ($timeout ?? 50) / 1000 && $elapsed < 0.5, 'URL timeout units/default changed.');
	endforeach;
	$throw = true;
	try {
		$browser->urlMatches('~/done$~', assert: true, wait: true);
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
	expectError(fn() => $driver->find('#missing')->resolve(wait: false), 'no such element: Missing selector #missing');
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

/** Network fixtures retain protocol byte values, duplicate headers, and arbitrary event fields. */
function networkEvent(string $method, string $id, int $redirect = 0, array $extra = []): array
{
	return ['type' => 'event', 'method' => 'network.' . $method, 'params' => array_replace([
		'context' => 'window/1', 'navigation' => null, 'timestamp' => 100, 'isBlocked' => false,
		'redirectCount' => $redirect, 'request' => ['request' => $id, 'url' => 'http://example.test/' . $redirect,
			'method' => 'GET', 'headers' => [['name' => 'X-Test', 'value' => ['type' => 'string', 'value' => 'one']],
				['name' => 'X-Test', 'value' => ['type' => 'base64', 'value' => '/w==']]], 'cookies' => []],
	], $extra)];
}

$networkEvents = [
	networkEvent('beforeRequestSent', 'redirect'),
	networkEvent('responseStarted', 'partial', extra: ['response' => ['status' => 503, 'headers' => []]]),
	networkEvent('beforeRequestSent', 'unfinished'),
	networkEvent('responseCompleted', 'redirect', extra: ['response' => ['status' => 302, 'headers' => []]]),
	networkEvent('beforeRequestSent', 'redirect', 1),
	networkEvent('fetchError', 'partial', extra: ['errorText' => 'connection reset']),
	networkEvent('responseCompleted', 'redirect', 1, ['response' => ['status' => 307, 'headers' => []]]),
];
$networkMethods = ['network.beforeRequestSent', 'network.responseStarted', 'network.responseCompleted', 'network.fetchError'];

scenario('cache policy forwards session and context scope without local state', function ($listener) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	bidiRequest($ws, 1, 'network.setCacheBehavior', ['cacheBehavior' => 'bypass']);
	bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => (object)[]]);
	bidiRequest($ws, 2, 'network.setCacheBehavior', ['cacheBehavior' => 'default', 'contexts' => ['window/1', 'window/2', 'window/1']]);
	bidiReply($ws, ['type' => 'success', 'id' => 2, 'result' => (object)[]]);
	bidiRequest($ws, 3, 'network.setCacheBehavior', ['cacheBehavior' => 'bypass', 'contexts' => [12]]);
	bidiReply($ws, ['type' => 'error', 'id' => 3, 'error' => 'invalid argument', 'message' => 'context must be a string']);
	bidiRequest($ws, 4, 'network.setCacheBehavior', ['cacheBehavior' => 'bypass', 'contexts' => ['unknown']]);
	bidiReply($ws, ['type' => 'error', 'id' => 4, 'error' => 'no such frame', 'message' => 'unknown context']);
	bidiRequest($ws, 5, 'network.setCacheBehavior', ['cacheBehavior' => 'default']);
	bidiReply($ws, ['type' => 'error', 'id' => 5, 'error' => 'unsupported operation', 'message' => 'cache policy rejected']);
	bidiRequest($ws, 6, 'network.setCacheBehavior', ['cacheBehavior' => 'default']);
	bidiReply($ws, ['type' => 'success', 'id' => 6, 'result' => (object)[]]);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($ws);
}, function ($url) {
	$browser = new Browser($url, 'firefox', bidi: true);
	$network = $browser->network();
	foreach (['', 'invalid', 'BYPASS'] as $behavior) expectError(fn () => $network->setCacheBehavior($behavior), 'Expected default or bypass');
	expectError(fn () => $network->setCacheBehavior('bypass', []), 'Expected a nonempty list');
	verify($network->setCacheBehavior('bypass') === $network, 'Session cache policy was not fluent.');
	verify($network->setCacheBehavior('default', [2 => 'window/1', 5 => 'window/2', 8 => 'window/1']) === $network, 'Scoped cache policy was not fluent.');
	expectError(fn () => $network->setCacheBehavior('bypass', [12]), 'invalid argument: context must be a string');
	expectError(fn () => $network->setCacheBehavior('bypass', ['unknown']), 'no such frame: unknown context');
	expectError(fn () => $network->setCacheBehavior('default', contexts: null), 'unsupported operation: cache policy rejected');
	verify($network->setCacheBehavior('default') === $network, 'Rejected cache policy prevented an explicit retry.');
	verify($browser->bidi()->active() && $network->requests() === [] && $network->captureError() === null
		&& $network->interceptionError() === null, 'Cache policy corrupted transport or enabled capture/passive diagnostics.');
	$browser->quit();
	expectError(fn () => $network->setCacheBehavior('default'), 'No active WebDriver BiDi connection');
});

scenario('response body ownership, decoding, explicit removal retry and session cleanup', function ($listener) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	bidiRequest($ws, 1, 'network.addDataCollector', ['dataTypes' => ['response'], 'maxEncodedDataSize' => 128]);
	bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['collector' => 'owned']]);
	foreach ([2 => ['string', "text\0✓"], 3 => ['base64', 'AP8='], 4 => ['base64', '']] as $id => [$type, $value]):
		bidiRequest($ws, $id, 'network.getData', ['request' => 'request', 'dataType' => 'response', 'collector' => 'owned', 'disown' => $id === 4]);
		bidiReply($ws, ['type' => 'success', 'id' => $id, 'result' => ['bytes' => ['type' => $type, 'value' => $value]]]);
	endforeach;
	bidiRequest($ws, 5, 'network.getData', ['request' => 'request', 'dataType' => 'response', 'collector' => 'owned', 'disown' => false]);
	bidiReply($ws, ['type' => 'error', 'id' => 5, 'error' => 'no such network data', 'message' => 'item released']);
	bidiRequest($ws, 6, 'network.removeDataCollector', ['collector' => 'owned']);
	bidiReply($ws, ['type' => 'error', 'id' => 6, 'error' => 'unknown error', 'message' => 'removal rejected']);
	bidiRequest($ws, 7, 'network.removeDataCollector', ['collector' => 'owned']);
	bidiReply($ws, ['type' => 'success', 'id' => 7, 'result' => (object)[]]);
	bidiRequest($ws, 8, 'network.addDataCollector', ['dataTypes' => ['response'], 'maxEncodedDataSize' => 256]);
	bidiReply($ws, ['type' => 'success', 'id' => 8, 'result' => ['collector' => 'replacement']]);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($ws);
}, function ($url) {
	$browser = new Browser($url, 'firefox', bidi: true);
	$network = $browser->network();
	verify($network->stopBodyCollection() === $network, 'Inactive stop was not fluent.');
	expectError(fn () => $network->responseBody('request'), 'not active');
	foreach ([0, -1] as $size) expectError(fn () => $network->startBodyCollection($size), 'positive maxEncodedDataSize');
	verify($network->startBodyCollection(128) === $network, 'Start was not fluent.');
	foreach ([128, 256] as $size) expectError(fn () => $network->startBodyCollection($size), 'already active');
	verify($network->responseBody('request') === "text\0✓", 'String body changed.');
	$body = $network->responseBody('request');
	verify($body === "\0\xff" && $network->responseBody('request', disown: true) === '', 'Binary/empty body changed.');
	expectError(fn () => $network->responseBody('request'), 'no such network data: item released');
	expectError(fn () => $network->stopBodyCollection(), 'removal rejected');
	expectError(fn () => $network->startBodyCollection(128), 'already active');
	verify($network->stopBodyCollection()->stopBodyCollection() === $network, 'Repeated stop was not fluent.');
	expectError(fn () => $network->responseBody('request'), 'not active');
	$network->startBodyCollection(256);
	verify($network->requests() === [] && $network->captureError() === null && $network->interceptionError() === null, 'Body errors contaminated capture/interception.');
	$browser->quit();
	$network->stopBodyCollection();
	expectError(fn () => $network->responseBody('request'), 'not active');
	verify($body === "\0\xff", 'Eager bytes depended on session lifetime.');
});

scenario('malformed collector and body results remain local and propagate ordinary protocol errors', function ($listener) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	foreach ([1 => [], 2 => ['collector' => ''], 3 => ['collector' => 42], 4 => ['collector' => 'owned']] as $id => $result):
		bidiRequest($ws, $id, 'network.addDataCollector', ['dataTypes' => ['response'], 'maxEncodedDataSize' => 128]);
		bidiReply($ws, ['type' => 'success', 'id' => $id, 'result' => (object)$result]);
	endforeach;
	$bad = [[], ['bytes' => null], ['bytes' => 'text'], ['bytes' => ['value' => 'text']],
		['bytes' => ['type' => 'hex', 'value' => '00']], ['bytes' => ['type' => 'string', 'value' => 7]],
		['bytes' => ['type' => 'base64', 'value' => '*invalid*']]];
	$id = 4;
	foreach ($bad as $result):
		bidiRequest($ws, ++$id, 'network.getData', ['request' => 'bad', 'dataType' => 'response', 'collector' => 'owned', 'disown' => true]);
		bidiReply($ws, ['type' => 'success', 'id' => $id, 'result' => (object)$result]);
	endforeach;
	foreach (['no such network data', 'unavailable network data', 'no such network collector', 'invalid argument', 'unknown command', 'unsupported operation'] as $error):
		bidiRequest($ws, ++$id, 'network.getData', ['request' => 'bad', 'dataType' => 'response', 'collector' => 'owned', 'disown' => false]);
		bidiReply($ws, ['type' => 'error', 'id' => $id, 'error' => $error, 'message' => 'native detail']);
	endforeach;
	bidiRequest($ws, ++$id, 'network.removeDataCollector', ['collector' => 'owned']);
	bidiReply($ws, ['type' => 'success', 'id' => $id, 'result' => (object)[]]);
	bidiRequest($ws, ++$id, 'network.addDataCollector', ['dataTypes' => ['response'], 'maxEncodedDataSize' => 128]);
	bidiReply($ws, ['type' => 'error', 'id' => $id, 'error' => 'unsupported operation', 'message' => 'collector unsupported']);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($ws);
}, function ($url) {
	$browser = new Browser($url, 'firefox', bidi: true);
	$network = $browser->network();
	for ($i = 0; $i < 3; $i++) expectError(fn () => $network->startBodyCollection(128), 'collector ID; remote ownership is unknown');
	$network->startBodyCollection(128);
	for ($i = 0; $i < 7; $i++) expectError(fn () => $network->responseBody('bad', true), 'response body bytes');
	foreach (['no such network data', 'unavailable network data', 'no such network collector', 'invalid argument', 'unknown command', 'unsupported operation'] as $error):
		expectError(fn () => $network->responseBody('bad'), $error . ': native detail');
	endforeach;
	$network->stopBodyCollection();
	expectError(fn () => $network->startBodyCollection(128), 'unsupported operation: collector unsupported');
	$network->stopBodyCollection();
	verify($browser->bidi()->active() && $network->captureError() === null && $network->interceptionError() === null, 'Feature errors invalidated transport or polluted passive diagnostics.');
	$browser->quit();
});

scenario('body data-type selection validates locally and preserves native errors without fallback', function ($listener) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	bidiRequest($ws, 1, 'network.addDataCollector', ['dataTypes' => ['request'], 'maxEncodedDataSize' => 128]);
	bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['collector' => 'request-only']]);
	bidiRequest($ws, 2, 'network.getData', ['request' => 'same', 'dataType' => 'response', 'collector' => 'request-only', 'disown' => false]);
	bidiReply($ws, ['type' => 'error', 'id' => 2, 'error' => 'no such network data', 'message' => 'unselected response']);
	bidiRequest($ws, 3, 'network.getData', ['request' => 'same', 'dataType' => 'request', 'collector' => 'request-only', 'disown' => false]);
	bidiReply($ws, ['type' => 'success', 'id' => 3, 'result' => ['bytes' => ['type' => 'string', 'value' => 'AP8=']]]);
	bidiRequest($ws, 4, 'network.removeDataCollector', ['collector' => 'request-only']);
	bidiReply($ws, ['type' => 'success', 'id' => 4, 'result' => (object)[]]);
	bidiRequest($ws, 5, 'network.addDataCollector', ['dataTypes' => ['response', 'request', 'request'], 'maxEncodedDataSize' => 128]);
	bidiReply($ws, ['type' => 'error', 'id' => 5, 'error' => 'unsupported operation', 'message' => 'mixed collection rejected']);
	bidiRequest($ws, 6, 'network.addDataCollector', ['dataTypes' => ['request'], 'maxEncodedDataSize' => 128]);
	bidiReply($ws, ['type' => 'success', 'id' => 6, 'result' => ['collector' => 'next']]);
	foreach ([7 => [], 8 => ['bytes' => ['type' => 'base64', 'value' => '*invalid*']]] as $id => $result):
		bidiRequest($ws, $id, 'network.getData', ['request' => 'bad', 'dataType' => 'request', 'collector' => 'next', 'disown' => true]);
		bidiReply($ws, ['type' => 'success', 'id' => $id, 'result' => (object)$result]);
	endforeach;
	bidiRequest($ws, 9, 'network.removeDataCollector', ['collector' => 'next']);
	bidiReply($ws, ['type' => 'success', 'id' => 9, 'result' => (object)[]]);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($ws);
}, function ($url) {
	$browser = new Browser($url, 'firefox', bidi: true);
	$network = $browser->network();
	expectError(fn () => $network->requestBody('same'), 'not active');
	foreach ([[], ['unknown'], ['request', true], [null], [['request']]] as $types):
		expectError(fn () => $network->startBodyCollection(128, $types), 'nonempty selection');
	endforeach;
	$network->startBodyCollection(128, [4 => 'request']);
	expectError(fn () => $network->startBodyCollection(128, ['response']), 'already active');
	expectError(fn () => $network->responseBody('same'), 'no such network data: unselected response');
	verify($network->requestBody('same') === 'AP8=', 'A string resembling base64 was decoded again.');
	$network->stopBodyCollection();
	expectError(fn () => $network->startBodyCollection(128, ['a' => 'response', 'b' => 'request', 'c' => 'request']), 'unsupported operation: mixed collection rejected');
	$network->stopBodyCollection()->startBodyCollection(128, ['request']);
	expectError(fn () => $network->requestBody('bad', true), 'valid request body bytes');
	expectError(fn () => $network->requestBody('bad', true), 'invalid base64 request body bytes');
	verify($browser->bidi()->active() && $network->captureError() === null && $network->interceptionError() === null, 'Request body errors polluted transport or passive diagnostics.');
	$network->stopBodyCollection();
	$browser->quit();
});

scenario('mixed body reads isolate data types and foreign collector ownership', function ($listener) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	foreach ([1 => 'foreign', 2 => 'owned'] as $id => $collector):
		bidiRequest($ws, $id, 'network.addDataCollector', ['dataTypes' => ['request', 'response'], 'maxEncodedDataSize' => 128]);
		bidiReply($ws, ['type' => 'success', 'id' => $id, 'result' => ['collector' => $collector]]);
	endforeach;
	foreach ([3 => ['request', 'owned', false], 4 => ['request', 'owned', true], 5 => ['response', 'owned', false],
		6 => ['request', 'owned', false], 7 => ['request', 'foreign', false], 8 => ['response', 'owned', true],
		9 => ['response', 'foreign', false]] as $id => [$type, $collector, $disown]):
		bidiRequest($ws, $id, 'network.getData', ['request' => 'same', 'dataType' => $type, 'collector' => $collector, 'disown' => $disown]);
		bidiReply($ws, $id === 6 ? ['type' => 'error', 'id' => $id, 'error' => 'no such network data', 'message' => 'request released']
			: ['type' => 'success', 'id' => $id, 'result' => ['bytes' => $type === 'request' ? ['type' => 'base64', 'value' => 'AP8='] : ['type' => 'string', 'value' => 'response']]]);
	endforeach;
	bidiRequest($ws, 10, 'network.removeDataCollector', ['collector' => 'owned']);
	bidiReply($ws, ['type' => 'success', 'id' => 10, 'result' => (object)[]]);
	bidiRequest($ws, 11, 'network.getData', ['request' => 'same', 'dataType' => 'request', 'collector' => 'foreign', 'disown' => false]);
	bidiReply($ws, ['type' => 'success', 'id' => 11, 'result' => ['bytes' => ['type' => 'base64', 'value' => 'AP8=']]]);
	bidiRequest($ws, 12, 'network.removeDataCollector', ['collector' => 'foreign']);
	bidiReply($ws, ['type' => 'success', 'id' => 12, 'result' => (object)[]]);
	bidiRequest($ws, 13, 'network.addDataCollector', ['dataTypes' => ['request', 'response'], 'maxEncodedDataSize' => 128]);
	bidiReply($ws, ['type' => 'success', 'id' => 13, 'result' => ['collector' => 'next']]);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($ws);
}, function ($url) {
	$browser = new Browser($url, 'firefox', bidi: true);
	$foreign = $browser->command('network.addDataCollector', ['dataTypes' => ['request', 'response'], 'maxEncodedDataSize' => 128])['collector'];
	$network = $browser->network()->startBodyCollection(128, ['request', 'response']);
	$body = $network->requestBody('same');
	verify($body === "\0\xff" && $network->requestBody('same', true) === $body, 'Request byte decoding or repeated read changed.');
	verify($network->responseBody('same') === 'response', 'Request disown affected response routing.');
	expectError(fn () => $network->requestBody('same'), 'request released');
	$readForeign = fn ($type) => $browser->command('network.getData', ['request' => 'same', 'dataType' => $type, 'collector' => $foreign, 'disown' => false])['bytes'];
	verify($readForeign('request')['value'] === 'AP8=', 'Request disown affected another collector.');
	verify($network->responseBody('same', true) === 'response' && $readForeign('response')['value'] === 'response', 'Response disown affected another collector.');
	$network->stopBodyCollection();
	expectError(fn () => $network->requestBody('same'), 'not active');
	expectError(fn () => $network->responseBody('same'), 'not active');
	verify($readForeign('request')['value'] === 'AP8=', 'Managed stop removed foreign ownership.');
	$browser->command('network.removeDataCollector', ['collector' => $foreign]);
	$network->startBodyCollection(128, ['request', 'response']);
	$browser->quit();
	$network->stopBodyCollection();
	expectError(fn () => $network->requestBody('same'), 'not active');
	verify($body === "\0\xff", 'Request bytes depended on session lifetime.');
});

foreach (['success', 'error'] as $repeat):
scenario('standalone body release forwards repeated outcomes and retains the collector: ' . $repeat, function ($listener) use ($repeat) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	bidiRequest($ws, 1, 'network.addDataCollector', ['dataTypes' => ['request', 'response'], 'maxEncodedDataSize' => 128]);
	bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['collector' => 'owned']]);
	foreach ([2, 3] as $id):
		bidiRequest($ws, $id, 'network.disownData', ['request' => 'same', 'dataType' => 'response', 'collector' => 'owned']);
		bidiReply($ws, $id === 3 && $repeat === 'error'
			? ['type' => 'error', 'id' => $id, 'error' => 'no such network data', 'message' => 'item released']
			: ['type' => 'success', 'id' => $id, 'result' => (object)[]]);
	endforeach;
	bidiRequest($ws, 4, 'network.disownData', ['request' => 'same', 'dataType' => 'request', 'collector' => 'owned']);
	bidiReply($ws, ['type' => 'success', 'id' => 4, 'result' => (object)[]]);
	bidiRequest($ws, 5, 'network.getData', ['request' => 'later', 'dataType' => 'response', 'collector' => 'owned', 'disown' => false]);
	bidiReply($ws, ['type' => 'success', 'id' => 5, 'result' => ['bytes' => ['type' => 'string', 'value' => 'later body']]]);
	bidiRequest($ws, 6, 'network.disownData', ['request' => 'later', 'dataType' => 'response', 'collector' => 'owned']);
	bidiReply($ws, ['type' => 'error', 'id' => 6, 'error' => 'unsupported operation', 'message' => 'release rejected']);
	bidiRequest($ws, 7, 'network.removeDataCollector', ['collector' => 'owned']);
	bidiReply($ws, ['type' => 'success', 'id' => 7, 'result' => (object)[]]);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($ws);
}, function ($url) use ($repeat) {
	$browser = new Browser($url, 'firefox', bidi: true);
	$network = $browser->network();
	foreach (['request', 'response'] as $type) expectError(fn () => $network->disownBody('same', $type), 'not active');
	foreach (['', 'invalid', 'REQUEST'] as $type) expectError(fn () => $network->disownBody('same', $type), 'Expected request or response');
	$network->startBodyCollection(128, ['request', 'response']);
	expectError(fn () => $network->disownBody('same', 'invalid'), 'Expected request or response');
	verify($network->disownBody('same') === $network, 'Default response release was not fluent.');
	if ($repeat === 'error') expectError(fn () => $network->disownBody('same'), 'no such network data: item released');
	else verify($network->disownBody('same') === $network, 'Repeated release was suppressed or lost its receiver.');
	verify($network->disownBody('same', dataType: 'request') === $network, 'Request release was not fluent.');
	verify($network->responseBody('later') === 'later body', 'Release discarded the collector.');
	expectError(fn () => $network->disownBody('later'), 'unsupported operation: release rejected');
	verify($browser->bidi()->active() && $network->requests() === [] && $network->captureError() === null
		&& $network->interceptionError() === null, 'Release errors enabled capture or contaminated transport/diagnostics.');
	$network->stopBodyCollection();
	expectError(fn () => $network->disownBody('later'), 'not active');
	$browser->quit();
});
endforeach;

foreach (['request', 'response'] as $dataType):
scenario('pending body reads dispatch response decisions and guard reentry: ' . $dataType, function ($listener) use ($dataType) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	bidiRequest($ws, 1, 'session.subscribe', ['events' => ['network.responseStarted']]);
	bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['subscription' => 'responses']]);
	bidiRequest($ws, 2, 'network.addIntercept', ['phases' => ['responseStarted'], 'urlPatterns' => []]);
	bidiReply($ws, networkEvent('responseStarted', 'early', extra: ['isBlocked' => true, 'intercepts' => ['rule']]));
	bidiReply($ws, ['type' => 'success', 'id' => 2, 'result' => ['intercept' => 'rule']]);
	bidiRequest($ws, 3, 'network.continueResponse', ['request' => 'early']);
	bidiReply($ws, ['type' => 'success', 'id' => 3, 'result' => (object)[]]);
	bidiRequest($ws, 4, 'network.addDataCollector', ['dataTypes' => [$dataType], 'maxEncodedDataSize' => 128]);
	bidiReply($ws, ['type' => 'success', 'id' => 4, 'result' => ['collector' => 'owned']]);
	bidiRequest($ws, 5, 'network.getData', ['request' => 'pending', 'dataType' => $dataType, 'collector' => 'owned', 'disown' => false]);
	bidiReply($ws, networkEvent('responseStarted', 'pending', extra: ['isBlocked' => true, 'intercepts' => ['rule']]));
	bidiRequest($ws, 6, 'network.continueResponse', ['request' => 'pending']);
	bidiReply($ws, ['type' => 'success', 'id' => 5, 'result' => ['bytes' => ['type' => 'string', 'value' => 'complete']]]);
	bidiReply($ws, ['type' => 'success', 'id' => 6, 'result' => (object)[]]);
	bidiRequest($ws, 7, 'network.removeIntercept', ['intercept' => 'rule']);
	bidiReply($ws, ['type' => 'success', 'id' => 7, 'result' => (object)[]]);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($ws);
}, function ($url) use ($dataType) {
	$browser = new Browser($url, 'firefox', bidi: true);
	$network = $browser->network();
	$calls = 0;
	$network->intercept(function () use ($network, &$calls) {
		foreach ([fn () => $network->startBodyCollection(0), fn () => $network->startBodyCollection(128), fn () => $network->startBodyCollection(128, []),
			fn () => $network->requestBody('pending'), fn () => $network->responseBody('pending'), fn () => $network->stopBodyCollection(),
			fn () => $network->disownBody('pending'), fn () => $network->disownBody('pending', 'request'), fn () => $network->disownBody('pending', 'invalid'),
			fn () => $network->setCacheBehavior('bypass'), fn () => $network->setCacheBehavior('invalid'), fn () => $network->setCacheBehavior('default', [])] as $call):
			expectError($call, 'not allowed inside a BiDi event handler');
		endforeach;
		$calls++;
		return NetworkDecision::continueResponse();
	}, phase: 'responseStarted');
	$network->startBodyCollection(128, [$dataType]);
	$reader = $dataType . 'Body';
	verify($network->$reader('pending') === 'complete', 'Pending data did not complete after the response decision.');
	$network->stopInterception();
	verify($calls === 2 && $network->requests() === [] && $network->interceptionError() === null, 'Reentry guards or capture independence failed.');
	$browser->quit();
});
endforeach;

foreach (['request', 'response'] as $dataType):
scenario('body reads preserve raw event reports without attaching retrieved bytes: ' . $dataType, function ($listener) use ($networkMethods, $dataType) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	bidiRequest($ws, 1, 'session.subscribe', ['events' => $networkMethods]);
	bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['subscription' => 'metadata']]);
	bidiRequest($ws, 2, 'network.addDataCollector', ['dataTypes' => [$dataType], 'maxEncodedDataSize' => 128]);
	bidiReply($ws, ['type' => 'success', 'id' => 2, 'result' => ['collector' => 'owned']]);
	bidiRequest($ws, 3, 'network.getData', ['request' => 'observed', 'dataType' => $dataType, 'collector' => 'owned', 'disown' => false]);
	$event = networkEvent('responseCompleted', 'observed');
	$event['params']['request']['goog:postData'] = 'vendor-supplied-body';
	bidiReply($ws, $event);
	bidiReply($ws, ['type' => 'success', 'id' => 3, 'result' => ['bytes' => ['type' => 'string', 'value' => 'body-only-marker']]]);
	bidiRequest($ws, 4, 'network.getData', ['request' => 'missing', 'dataType' => $dataType, 'collector' => 'owned', 'disown' => false]);
	bidiReply($ws, ['type' => 'error', 'id' => 4, 'error' => 'no such network data', 'message' => 'absent item']);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($ws);
}, function ($url) use ($dataType) {
	$browser = new Browser($url, 'firefox', bidi: true);
	$network = $browser->network();
	[$status, $output, $record] = runChecks(['check' => function () use ($network, $dataType) {
		$network->startCapture()->startBodyCollection(128, [$dataType]);
		$reader = $dataType . 'Body';
		verify($network->$reader('observed') === 'body-only-marker', 'Body read lost bytes.');
		$network->$reader('missing');
	}]);
	$attempt = $record['suites'][0]['attempts'][0];
	verify($status === 1 && count($attempt['errors']) === 1 && str_contains($output, 'no such network data: absent item'), 'Synchronous body error lost its ordinary execution attribution.');
	verify($attempt['steps'] === [] && count($attempt['browserNetwork']) === 1
		&& !str_contains(json_encode($record), 'body-only-marker') && !str_contains($output, 'body-only-marker'), 'Body reads created steps or attached body bytes to reports.');
	verify($attempt['browserNetwork'][0]['event']['params']['request']['goog:postData'] === 'vendor-supplied-body', 'Body retrieval filtered existing raw event extensions.');
	verify($network->captureError() === null && $network->interceptionError() === null, 'Synchronous body error became a passive diagnostic.');
	$browser->quit();
});
endforeach;

foreach (['request', 'response'] as $dataType):
scenario('pending body timeout preserves classic session cleanup: ' . $dataType, function ($listener) use ($dataType) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	bidiRequest($ws, 1, 'network.addDataCollector', ['dataTypes' => [$dataType], 'maxEncodedDataSize' => 128]);
	bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['collector' => 'owned']]);
	bidiRequest($ws, 2, 'network.getData', ['request' => 'pending', 'dataType' => $dataType, 'collector' => 'owned', 'disown' => false]);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($ws);
}, function ($url) use ($dataType) {
	$browser = new Browser($url, 'firefox', bidi: true, timeout: 0.2);
	$connection = $browser->bidi();
	$network = $browser->network()->startBodyCollection(128, [$dataType]);
	$reader = $dataType . 'Body';
	expectError(fn () => $network->$reader('pending'), 'timed out');
	verify(!$connection->active(), 'Timed-out body command left the connection active.');
	$browser->quit();
	$network->stopBodyCollection();
	expectError(fn () => $network->$reader('pending'), 'not active');
});
endforeach;

scenario('network capture correlates interleaved hops, preserves raw data and isolates browser errors', function ($listener) use ($networkEvents, $networkMethods) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	bidiRequest($ws, 1, 'session.subscribe', ['events' => ['network.beforeRequestSent']]);
	bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['subscription' => 'raw']]);
	bidiRequest($ws, 2, 'session.subscribe', ['events' => ['log.entryAdded']]);
	bidiReply($ws, ['type' => 'success', 'id' => 2, 'result' => ['subscription' => 'logs']]);
	bidiRequest($ws, 3, 'session.subscribe', ['events' => $networkMethods]);
	foreach (array_slice($networkEvents, 0, 3) as $event) bidiReply($ws, $event);
	bidiReply($ws, ['type' => 'success', 'id' => 3, 'result' => ['subscription' => 'network']]);
	[$http] = acceptRequest($listener);
	foreach (array_slice($networkEvents, 3) as $event) bidiReply($ws, $event);
	usleep(20000);
	$json = json_encode(['value' => ['error' => 'unknown error', 'message' => 'navigation failed']]);
	fwrite($http, "HTTP/1.1 500 Error\r\nContent-Length: " . strlen($json) . "\r\nConnection: close\r\n\r\n" . $json);
	fclose($http);
	bidiRequest($ws, 4, 'browsingContext.getTree');
	bidiReply($ws, ['type' => 'success', 'id' => 4, 'result' => ['contexts' => []]]);
	bidiRequest($ws, 5, 'session.unsubscribe', ['subscriptions' => ['network']]);
	bidiReply($ws, ['type' => 'success', 'id' => 5, 'result' => (object)[]]);
	bidiRequest($ws, 6, 'browsingContext.getTree');
	bidiReply($ws, networkEvent('beforeRequestSent', 'raw-only'));
	bidiReply($ws, ['type' => 'event', 'method' => 'log.entryAdded', 'params' => ['type' => 'console', 'text' => 'still subscribed']]);
	bidiReply($ws, ['type' => 'success', 'id' => 6, 'result' => ['contexts' => []]]);
	bidiRequest($ws, 7, 'session.subscribe', ['events' => $networkMethods]);
	bidiReply($ws, networkEvent('responseCompleted', 'unfinished', extra: ['response' => ['status' => 200, 'headers' => []]]));
	bidiReply($ws, ['type' => 'success', 'id' => 7, 'result' => ['subscription' => 'network2']]);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($ws);
}, function ($url) use ($networkEvents) {
	$browser = new Browser($url, 'firefox', bidi: true);
	$browser->subscribe(['network.beforeRequestSent']);
	$browser->startLogCapture();
	$network = $browser->network();
	verify($network === $browser->network() && $network->requests() === [], 'Network accessor started capture or lost identity.');
	verify($network->startCapture()->startCapture() === $network, 'Capture lost its receiver.');
	$before = $network->requests();
	verify(count($before) === 3 && $before[0]['response'] === null, 'Events before the subscription reply were lost.');
	expectError(fn () => $browser->sessionRequest('POST', '/url', ['url' => 'http://example.test/']), 'navigation failed');
	$hops = $network->requests();
	verify(array_column($hops, 'requestId') === ['redirect', 'partial', 'unfinished', 'redirect'] && array_column($hops, 'redirectCount') === [0, 0, 0, 1], 'Interleaved hops merged or changed order.');
	verify($hops[0]['sessionId'] === 'session/1' && $hops[0]['events'] === [$networkEvents[0], $networkEvents[3]], 'Raw event snapshots changed.');
	verify($hops[1]['response']['status'] === 503 && $hops[1]['events'][1] === $networkEvents[5], 'Partial observation lost response or fetch error.');
	verify($hops[2]['response'] === null && count($hops[2]['events']) === 1 && $before[0]['response'] === null, 'Completion was invented or an earlier value snapshot mutated.');
	$filtered = $network->requests(function ($hop) use ($browser): bool {
		if ($hop['redirectCount'] !== 1) return false;
		$browser->command('browsingContext.getTree');
		return true;
	});
	verify($filtered === [$hops[3]], 'Filter ran in dispatch or changed the matching hop.');
	verify($network->captureError() === null && $browser->logCaptureError() === null, 'Browser errors became collection failures.');
	foreach ($networkEvents as $event) verify($browser->event() === $event, 'Capture consumed or reordered raw events.');
	$network->stopCapture()->stopCapture();
	$stopped = $network->requests();
	$browser->command('browsingContext.getTree');
	verify($browser->event() === networkEvent('beforeRequestSent', 'raw-only'), 'Stop cancelled the raw subscription.');
	verify($network->requests() === $stopped && $browser->logs()[0]['text'] === 'still subscribed', 'Stopped capture continued or stopped log capture.');
	$network->startCapture();
	$restarted = $network->requests();
	verify(count($restarted) === 4 && count($restarted[2]['events']) === 2 && $restarted[2]['response']['status'] === 200, 'Restart lost history or fabricated a new hop.');
	$browser->quit();
	verify($browser->network() === $network && $network->requests() === $restarted && $network->captureError() === null, 'Session cleanup lost network history or identity.');
	$network->stopCapture();
	expectError(fn () => $network->startCapture(), 'No active WebDriver BiDi');
});

scenario('network collection drains bursts and updates latest data without changing earlier events', function ($listener) use ($networkMethods) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	bidiRequest($ws, 1, 'session.subscribe', ['events' => $networkMethods]);
	bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['subscription' => 'network']]);
	[$http] = acceptRequest($listener);
	for ($i = 0; $i < 256; $i++) bidiReply($ws, networkEvent('beforeRequestSent', (string)$i));
	$changed = networkEvent('responseCompleted', '0', extra: ['response' => ['status' => 200, 'headers' => []]]);
	$changed['params']['request']['method'] = 'POST';
	bidiReply($ws, $changed);
	fwrite($http, "HTTP/1.1 200 OK\r\nContent-Length: 16\r\nConnection: close\r\n\r\n{\"value\":\"done\"}");
	fclose($http);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($ws);
}, function ($url) {
	$browser = new Browser($url, 'firefox', bidi: true);
	$network = $browser->network()->startCapture();
	$browser->sessionRequest('GET', '/title');
	$browser->wait(fn () => count($network->requests(fn ($hop) => $hop['response'] !== null)) === 1, timeout: 1000);
	$hops = $network->requests();
	verify(array_column($hops, 'requestId') === array_map(strval(...), range(0, 255)), 'Burst lost or reordered requests.');
	verify($hops[0]['request']['method'] === 'POST' && $hops[0]['events'][0]['params']['request']['method'] === 'GET', 'Latest request replaced an earlier event snapshot.');
	$hops[0]['events'][0]['params']['request']['method'] = 'DELETE';
	verify($network->requests()[0]['events'][0]['params']['request']['method'] === 'GET', 'Returned records mutated retained history.');
	expectError(fn () => $network->requests(fn () => throw new RuntimeException('predicate failed')), 'predicate failed');
	verify($network->captureError() === null, 'Predicate error became a capture failure.');
	$browser->quit();
});

foreach (['missing subscription', 'unsubscribe error', 'transport failure'] as $failure):
	scenario('network capture retains diagnostics and permits cleanup after ' . $failure, function ($listener) use ($networkMethods, $failure) {
		createSession($listener, bidi: true);
		$ws = upgrade($listener);
		bidiRequest($ws, 1, 'session.subscribe', ['events' => $networkMethods]);
		bidiReply($ws, networkEvent('beforeRequestSent', 'retained'));
		bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => $failure === 'missing subscription' ? (object)[] : ['subscription' => 'network']]);
		if ($failure === 'unsubscribe error'):
			bidiRequest($ws, 2, 'session.unsubscribe', ['subscriptions' => ['network']]);
			bidiReply($ws, ['type' => 'error', 'id' => 2, 'error' => 'invalid argument', 'message' => 'unsubscribe failed']);
		elseif ($failure === 'transport failure'):
			bidiRequest($ws, 2, 'browsingContext.getTree');
			bidiReply($ws, ['type' => 'broken']);
		endif;
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
		fclose($ws);
	}, function ($url) use ($failure) {
		$browser = new Browser($url, 'firefox', bidi: true);
		$network = $browser->network();
		if ($failure === 'missing subscription'):
			expectError(fn () => $network->startCapture(), 'subscription ID');
		else:
			$network->startCapture();
			if ($failure === 'unsubscribe error') expectError(fn () => $network->stopCapture(), 'unsubscribe failed');
			else expectError(fn () => $browser->command('browsingContext.getTree'), 'Invalid WebDriver BiDi');
		endif;
		$error = $network->captureError();
		verify($error !== null && $network->requests()[0]['requestId'] === 'retained', 'Failure hid diagnostics or retained entries.');
		verify($browser->logCaptureError() === null, 'Network-only capture failure became a log failure.');
		$browser->quit();
		verify($network->captureError() === $error && $network->requests()[0]['sessionId'] === 'session/1', 'Cleanup lost original capture failure or session identity.');
	});
endforeach;

scenario('network capture can restart after a rejected subscription without duplicate listeners', function ($listener) use ($networkMethods) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	bidiRequest($ws, 1, 'session.subscribe', ['events' => $networkMethods]);
	bidiReply($ws, networkEvent('beforeRequestSent', 'retry'));
	bidiReply($ws, ['type' => 'error', 'id' => 1, 'error' => 'invalid argument', 'message' => 'subscription rejected']);
	bidiRequest($ws, 2, 'session.subscribe', ['events' => $networkMethods]);
	bidiReply($ws, networkEvent('responseCompleted', 'retry', extra: ['response' => ['status' => 200, 'headers' => []]]));
	bidiReply($ws, ['type' => 'success', 'id' => 2, 'result' => ['subscription' => 'network']]);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($ws);
}, function ($url) {
	$browser = new Browser($url, 'firefox', bidi: true);
	$network = $browser->network();
	expectError(fn () => $network->startCapture(), 'subscription rejected');
	verify($network->captureError() !== null, 'Rejected subscription lost its failure.');
	$network->startCapture();
	$hops = $network->requests();
	verify($network->captureError() === null && count($hops) === 1 && count($hops[0]['events']) === 2, 'Restart retained failed listeners, lost history or failed to clear the error.');
	$browser->quit();
});

scenario('last-window close retains network capture without a false transport failure', function ($listener) use ($networkMethods) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	bidiRequest($ws, 1, 'session.subscribe', ['events' => $networkMethods]);
	bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['subscription' => 'network']]);
	exchange($listener, 'GET', '/session/session%2F1/window', null, 'window/1');
	[$http] = acceptRequest($listener);
	bidiReply($ws, networkEvent('beforeRequestSent', 'last-window'));
	fwrite($ws, "\x88\x00");
	usleep(20000);
	fwrite($http, "HTTP/1.1 200 OK\r\nContent-Length: 12\r\n\r\n{\"value\":[]}");
	fclose($http);
	fclose($ws);
}, function ($url) {
	$browser = new Browser($url, 'firefox', bidi: true);
	$network = $browser->network()->startCapture();
	$browser->window()->close();
	verify(!$browser->active() && $network->captureError() === null && $network->requests()[0]['requestId'] === 'last-window', 'Normal closure lost history or became a capture failure.');
	$browser->quit();
});

scenario('network observation requires explicit BiDi', function ($listener) {
	createSession($listener, discover: false);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox');
	expectError(fn () => $browser->network(), 'No active WebDriver BiDi');
	$browser->quit();
});

scenario('network reports preserve actual observation attempts across a case retry', function ($listener) use ($networkMethods) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	bidiRequest($ws, 1, 'session.subscribe', ['events' => $networkMethods]);
	bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['subscription' => 'network']]);
	foreach ([2 => 'beforeRequestSent', 3 => 'responseCompleted'] as $id => $method):
		bidiRequest($ws, $id, 'fixture.attempt');
		bidiReply($ws, networkEvent($method, 'spanning', extra: $id === 3 ? ['response' => ['status' => 404, 'headers' => []]] : []));
		bidiReply($ws, ['type' => 'success', 'id' => $id, 'result' => (object)[]]);
	endforeach;
	bidiRequest($ws, 4, 'fixture.cleanup');
	bidiReply($ws, networkEvent('fetchError', 'cleanup', extra: ['errorText' => 'diagnostic only']));
	bidiReply($ws, ['type' => 'success', 'id' => 4, 'result' => (object)[]]);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($ws);
}, function ($url) {
	$browser = new Browser($url, 'firefox', bidi: true);
	$network = $browser->network()->startCapture();
	[$status, $output, $record] = runChecks(['suite' => function ($test) use ($browser) {
		return new class($test, $browser) extends Suite {
			private int $attempt = 0;
			public function __construct($test, private Browser $browser) { parent::__construct($test); }
			#[SuiteBefore]
			public function setup(): void { $this->browser->network()->startCapture(); }
			#[TestCase('Retry with an unfinished request', retry: 1)]
			public function body(): void {
				$this->browser->command('fixture.attempt');
				$this->test->assert(++$this->attempt === 2, 'second attempt passes');
			}
			#[SuiteAfter]
			public function cleanup(): void { $this->browser->command('fixture.cleanup'); $this->browser->quit(); }
		};
	}]);
	$suite = $record['suites'][0]['attempts'][0];
	$attempts = $suite['cases'][0]['attempts'];
	verify($status === 0 && count($attempts) === 2 && $attempts[0]['status'] === 'FAIL' && $attempts[1]['status'] === 'PASS', 'Network diagnostics changed retry outcomes.');
	foreach ($attempts as $index => $attempt):
		verify(count($attempt['browserNetwork']) === 1, 'Network event duplicated or moved between attempts.');
		$entry = $attempt['browserNetwork'][0];
		verify($entry['phase'] === 'test' && $entry['hook'] === null && $entry['stepId'] === $attempt['steps'][0]['id'], 'Network event lost observation attribution.');
		verify($entry['event'] === networkEvent($index ? 'responseCompleted' : 'beforeRequestSent', 'spanning', extra: $index ? ['response' => ['status' => 404, 'headers' => []]] : []), 'Raw headers, byte values or event envelope changed in report.');
	endforeach;
	verify(count($suite['browserNetwork']) === 1 && $suite['browserNetwork'][0]['phase'] === 'suite-teardown'
		&& $suite['browserNetwork'][0]['hook'] === 'SuiteAfter cleanup', 'Cleanup event lost suite attribution.');
	verify(str_contains($output, 'NETWORK') && str_contains($output, 'HTTP 404') && str_contains($output, 'diagnostic only'), 'Console omitted network diagnostics.');
	verify(count($network->requests()[0]['events']) === 2 && $network->captureError() === null, 'Reporting consumed retained history or failed capture.');
});

foreach (['propagated', 'swallowed', 'stop'] as $mode):
	scenario('network capture failure reports once per retry: ' . $mode, function ($listener) use ($networkMethods, $mode) {
		createSession($listener, bidi: true);
		$ws = upgrade($listener);
		bidiRequest($ws, 1, 'session.subscribe', ['events' => $networkMethods]);
		bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['subscription' => 'network']]);
		if ($mode === 'stop'):
			bidiRequest($ws, 2, 'session.unsubscribe', ['subscriptions' => ['network']]);
			bidiReply($ws, ['type' => 'error', 'id' => 2, 'error' => 'invalid argument', 'message' => 'stop failed']);
		else:
			bidiRequest($ws, 2, 'fixture.failure');
			bidiReply($ws, ['type' => 'broken']);
		endif;
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
		fclose($ws);
	}, function ($url) use ($mode) {
		$browser = new Browser($url, 'firefox', bidi: true);
		$browser->network()->startCapture();
		[$status, , $record] = runChecks(['suite' => function ($test) use ($browser, $mode) {
			return new class($test, $browser, $mode) extends Suite {
				private int $attempt = 0;
				public function __construct($test, private Browser $browser, private string $mode) { parent::__construct($test); }
				#[SuiteBefore]
				public function setup(): void { $this->browser->network()->startCapture(); }
				#[TestCase('Persistent capture failure', retry: 1)]
				public function body(): void {
					if (++$this->attempt !== 1) return;
					if ($this->mode === 'stop') { $this->browser->network()->stopCapture(); return; }
					try { $this->browser->command('fixture.failure'); }
					catch (Throwable $error) { if ($this->mode !== 'swallowed') throw $error; }
				}
				#[SuiteAfter]
				public function cleanup(): void { $this->browser->quit(); }
			};
		}]);
		$suite = $record['suites'][0]['attempts'][0];
		$attempts = $suite['cases'][0]['attempts'];
		verify($status === 1 && count($attempts) === 2, 'Persistent failure did not trigger retry.');
		foreach ($attempts as $attempt) verify($attempt['status'] === 'FAIL' && count($attempt['errors']) === 1, 'Capture failure duplicated or disappeared in retry.');
		verify(count($suite['errors']) === 1, 'Case suppression leaked into suite teardown.');
		if ($mode !== 'stop') verify($attempts[0]['steps'][0]['error'] !== null, 'Attempt deduplication erased failed step details.');
	});
endforeach;

scenario('network reporting resumes after recovery and retains a distinct later operation failure', function ($listener) use ($networkMethods) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	bidiRequest($ws, 1, 'session.subscribe', ['events' => $networkMethods]);
	bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['subscription' => 'first']]);
	bidiRequest($ws, 2, 'session.unsubscribe', ['subscriptions' => ['first']]);
	bidiReply($ws, ['type' => 'error', 'id' => 2, 'error' => 'invalid argument', 'message' => 'first stop failed']);
	bidiRequest($ws, 3, 'session.subscribe', ['events' => $networkMethods]);
	bidiReply($ws, networkEvent('beforeRequestSent', 'recovered'));
	bidiReply($ws, ['type' => 'success', 'id' => 3, 'result' => ['subscription' => 'second']]);
	bidiRequest($ws, 4, 'session.unsubscribe', ['subscriptions' => ['second']]);
	bidiReply($ws, ['type' => 'error', 'id' => 4, 'error' => 'invalid argument', 'message' => 'second stop failed']);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($ws);
}, function ($url) {
	$browser = new Browser($url, 'firefox', bidi: true);
	[$status, , $record] = runChecks(['check' => function ($test) use ($browser) {
		$network = $browser->network()->startCapture();
		expectError(fn () => $network->stopCapture(), 'first stop failed');
		$test->assert(true, 'checkpoint reports swallowed failure');
		$network->startCapture();
		verify($network->captureError() === null, 'Successful restart retained failure.');
		$network->stopCapture();
	}]);
	$attempt = $record['suites'][0]['attempts'][0];
	verify($status === 1 && count($attempt['errors']) === 2, 'Recovery hid distinct failure or duplicated checkpoints.');
	verify(count($attempt['browserNetwork']) === 1 && $attempt['browserNetwork'][0]['event']['params']['request']['request'] === 'recovered', 'Restart did not resume reporting.');
	$browser->quit();
});

scenario('managed interception buffers installation events, orders rules and retains retired ownership', function ($listener) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	bidiRequest($ws, 1, 'session.subscribe', ['events' => ['network.beforeRequestSent']]);
	bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['subscription' => 'intercepts']]);
	bidiRequest($ws, 2, 'network.addIntercept', ['phases' => ['beforeRequestSent'], 'urlPatterns' => []]);
	bidiReply($ws, networkEvent('beforeRequestSent', 'early', extra: ['isBlocked' => true, 'intercepts' => ['a']]));
	bidiReply($ws, ['type' => 'success', 'id' => 2, 'result' => ['intercept' => 'a']]);
	bidiRequest($ws, 3, 'network.continueRequest', ['request' => 'early', 'method' => 'POST',
		'headers' => [['name' => 'X-Bytes', 'value' => ['type' => 'base64', 'value' => '/w==']]], 'body' => ['type' => 'base64', 'value' => '']]);
	bidiReply($ws, ['type' => 'success', 'id' => 3, 'result' => (object)[]]);
	bidiRequest($ws, 4, 'network.addIntercept', ['phases' => ['beforeRequestSent'], 'urlPatterns' => []]);
	bidiReply($ws, ['type' => 'success', 'id' => 4, 'result' => ['intercept' => 'b']]);
	bidiRequest($ws, 5, 'fixture.overlap');
	bidiReply($ws, networkEvent('beforeRequestSent', 'overlap', extra: ['isBlocked' => true, 'intercepts' => ['b', 'a']]));
	bidiReply($ws, networkEvent('beforeRequestSent', 'foreign', extra: ['isBlocked' => true, 'intercepts' => ['foreign']]));
	bidiRequest($ws, 6, 'network.continueRequest', ['request' => 'overlap']);
	bidiReply($ws, ['type' => 'success', 'id' => 6, 'result' => (object)[]]);
	bidiReply($ws, ['type' => 'success', 'id' => 5, 'result' => (object)[]]);
	bidiRequest($ws, 7, 'network.removeIntercept', ['intercept' => 'a']);
	bidiReply($ws, ['type' => 'success', 'id' => 7, 'result' => (object)[]]);
	bidiRequest($ws, 8, 'fixture.afterRemoval');
	bidiReply($ws, networkEvent('beforeRequestSent', 'late', extra: ['isBlocked' => true, 'intercepts' => ['a']]));
	bidiReply($ws, networkEvent('beforeRequestSent', 'mock', extra: ['isBlocked' => true, 'intercepts' => ['a', 'b']]));
	bidiRequest($ws, 9, 'network.continueRequest', ['request' => 'late']);
	bidiRequest($ws, 10, 'network.provideResponse', ['request' => 'mock', 'statusCode' => 201,
		'headers' => [['name' => 'X-Duplicate', 'value' => ['type' => 'string', 'value' => 'a']], ['name' => 'X-Duplicate', 'value' => ['type' => 'string', 'value' => 'b']]],
		'body' => ['type' => 'base64', 'value' => 'AP8=']]);
	foreach ([10, 9, 8] as $id) bidiReply($ws, ['type' => 'success', 'id' => $id, 'result' => (object)[]]);
	bidiRequest($ws, 11, 'network.removeIntercept', ['intercept' => 'b']);
	bidiReply($ws, ['type' => 'success', 'id' => 11, 'result' => (object)[]]);
	bidiRequest($ws, 12, 'fixture.afterStop');
	bidiReply($ws, networkEvent('beforeRequestSent', 'retired', extra: ['isBlocked' => true, 'intercepts' => ['a', 'b']]));
	bidiRequest($ws, 13, 'network.continueRequest', ['request' => 'retired']);
	foreach ([13, 12] as $id) bidiReply($ws, ['type' => 'success', 'id' => $id, 'result' => (object)[]]);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($ws);
}, function ($url) {
	$browser = new Browser($url, 'firefox', bidi: true);
	$network = $browser->network();
	$calls = [];
	$a = $network->intercept(function ($event) use (&$calls) {
		$calls[] = 'a';
		return $event['request']['request'] === 'early' ? NetworkDecision::continueRequest(method: 'POST', headers: [['X-Bytes', "\xff"]], body: '') : NetworkDecision::continueRequest();
	});
	$b = $network->intercept(function () use (&$calls) { $calls[] = 'b'; return NetworkDecision::respond(201, [['X-Duplicate', 'a'], ['X-Duplicate', 'b']], "\0\xff"); });
	$browser->command('fixture.overlap');
	verify($calls === ['a', 'a'], 'Rule order followed protocol ID order, continued fallthrough or decided foreign ownership.');
	verify($browser->event()['params']['request']['request'] === 'early', 'Managed interception consumed raw events.');
	$network->removeIntercept($a)->removeIntercept($a);
	$browser->command('fixture.afterRemoval');
	verify($calls === ['a', 'a', 'b'], 'Retired handler ran or active overlapping handler was skipped.');
	$network->stopInterception()->stopInterception();
	$browser->command('fixture.afterStop');
	verify($calls === ['a', 'a', 'b'] && $network->requests() === [] && $network->interceptionError() === null, 'Stop lost passive ownership or enabled metadata capture.');
	expectError(fn () => $network->intercept(fn () => NetworkDecision::fail(), phase: 'responseCompleted'), 'Only beforeRequestSent, responseStarted and authRequired');
	$browser->quit();
});

expectError(fn () => NetworkDecision::continueResponse(99), 'HTTP status');
expectError(fn () => NetworkDecision::continueResponse(600), 'HTTP status');
$decisionCases = [
	'continueRequest' => [fn () => NetworkDecision::continueRequest(), ['beforeRequestSent'], 'network.continueRequest', []],
	'continueResponse' => [fn () => NetworkDecision::continueResponse(), ['responseStarted'], 'network.continueResponse', []],
	'respond' => [fn () => NetworkDecision::respond(), ['beforeRequestSent', 'responseStarted'], 'network.provideResponse',
		['statusCode' => 200, 'headers' => [], 'body' => ['type' => 'base64', 'value' => '']]],
	'fail' => [fn () => NetworkDecision::fail(), ['beforeRequestSent', 'responseStarted'], 'network.failRequest', []],
	'provideCredentials' => [fn () => NetworkDecision::provideCredentials('user', 'pass'), ['authRequired'], 'network.continueWithAuth',
		['action' => 'provideCredentials', 'credentials' => ['type' => 'password', 'username' => 'user', 'password' => 'pass']]],
	'defaultAuth' => [fn () => NetworkDecision::defaultAuth(), ['authRequired'], 'network.continueWithAuth', ['action' => 'default']],
	'cancelAuth' => [fn () => NetworkDecision::cancelAuth(), ['authRequired'], 'network.continueWithAuth', ['action' => 'cancel']],
];
foreach ($decisionCases as $name => [$factory, $phases, $method, $params]):
	foreach (['beforeRequestSent', 'responseStarted', 'authRequired'] as $phase):
		$valid = in_array($phase, $phases, true);
		scenario("managed decision phase compatibility: $name at $phase", function ($listener) use ($phase, $valid, $method, $params) {
			createSession($listener, bidi: true);
			$ws = upgrade($listener);
			bidiRequest($ws, 1, 'session.subscribe', ['events' => ['network.' . $phase]]);
			bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['subscription' => 'intercepts']]);
			bidiRequest($ws, 2, 'network.addIntercept', ['phases' => [$phase], 'urlPatterns' => []]);
			bidiReply($ws, networkEvent($phase, 'early', extra: ['isBlocked' => true, 'intercepts' => ['a']]));
			bidiReply($ws, ['type' => 'success', 'id' => 2, 'result' => ['intercept' => 'a']]);
			bidiRequest($ws, 3, $valid ? $method : ($phase === 'authRequired' ? 'network.continueWithAuth' : 'network.failRequest'),
				['request' => 'early'] + ($valid ? $params : ($phase === 'authRequired' ? ['action' => 'cancel'] : [])));
			bidiReply($ws, ['type' => 'success', 'id' => 3, 'result' => (object)[]]);
			bidiRequest($ws, 4, 'network.removeIntercept', ['intercept' => 'a']);
			bidiReply($ws, ['type' => 'success', 'id' => 4, 'result' => (object)[]]);
			exchange($listener, 'DELETE', '/session/session%2F1', null, null);
			fclose($ws);
		}, function ($url) use ($phase, $valid, $factory) {
			$browser = new Browser($url, 'firefox', bidi: true);
			$network = $browser->network();
			$network->intercept($factory, phase: $phase);
			if ($valid) $network->stopInterception();
			else expectError(fn () => $network->stopInterception(), 'not valid for ' . $phase);
			verify(($network->interceptionError() === null) === $valid && $browser->bidi()->active(), 'Phase validation lost diagnostics or invalidated transport.');
			$browser->quit();
		});
	endforeach;
endforeach;

scenario('response rules preserve early decisions, precedence, redirects and retired passthrough', function ($listener) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	bidiRequest($ws, 1, 'session.subscribe', ['events' => ['network.responseStarted']]);
	bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['subscription' => 'responses']]);
	bidiRequest($ws, 2, 'network.addIntercept', ['phases' => ['responseStarted'], 'urlPatterns' => []]);
	bidiReply($ws, networkEvent('responseStarted', 'redirect', extra: ['isBlocked' => true, 'intercepts' => ['a']]));
	bidiReply($ws, ['type' => 'success', 'id' => 2, 'result' => ['intercept' => 'a']]);
	bidiRequest($ws, 3, 'network.continueResponse', ['request' => 'redirect', 'statusCode' => 201,
		'headers' => [['name' => 'X-Bytes', 'value' => ['type' => 'base64', 'value' => '/w==']]]]);
	bidiRequest($ws, 4, 'network.addIntercept', ['phases' => ['responseStarted'], 'urlPatterns' => []]);
	bidiReply($ws, ['type' => 'success', 'id' => 4, 'result' => ['intercept' => 'b']]);
	bidiRequest($ws, 5, 'fixture.redirect');
	bidiReply($ws, networkEvent('responseStarted', 'foreign', extra: ['isBlocked' => true, 'intercepts' => ['foreign']]));
	bidiReply($ws, networkEvent('responseStarted', 'redirect', 1, ['isBlocked' => true, 'intercepts' => ['b', 'a']]));
	bidiRequest($ws, 6, 'network.continueResponse', ['request' => 'redirect', 'headers' => []]);
	bidiReply($ws, ['type' => 'success', 'id' => 3, 'result' => (object)[]]);
	bidiReply($ws, ['type' => 'error', 'id' => 6, 'error' => 'unknown error', 'message' => 'redirect response rejected']);
	bidiRequest($ws, 7, 'network.failRequest', ['request' => 'redirect']);
	foreach ([7, 5] as $id) bidiReply($ws, ['type' => 'success', 'id' => $id, 'result' => (object)[]]);
	bidiRequest($ws, 8, 'network.removeIntercept', ['intercept' => 'a']);
	bidiReply($ws, ['type' => 'success', 'id' => 8, 'result' => (object)[]]);
	bidiRequest($ws, 9, 'fixture.removed');
	bidiReply($ws, networkEvent('responseStarted', 'active', extra: ['isBlocked' => true, 'intercepts' => ['a', 'b']]));
	bidiRequest($ws, 10, 'network.provideResponse', ['request' => 'active', 'statusCode' => 202, 'headers' => [], 'body' => ['type' => 'base64', 'value' => 'AP8=']]);
	foreach ([10, 9] as $id) bidiReply($ws, ['type' => 'success', 'id' => $id, 'result' => (object)[]]);
	bidiRequest($ws, 11, 'network.removeIntercept', ['intercept' => 'b']);
	bidiReply($ws, networkEvent('responseStarted', 'retiring', extra: ['isBlocked' => true, 'intercepts' => ['b']]));
	bidiRequest($ws, 12, 'network.continueResponse', ['request' => 'retiring']);
	foreach ([12, 11] as $id) bidiReply($ws, ['type' => 'success', 'id' => $id, 'result' => (object)[]]);
	bidiRequest($ws, 13, 'fixture.stopped');
	bidiReply($ws, networkEvent('responseStarted', 'late', extra: ['isBlocked' => true, 'intercepts' => ['a', 'b']]));
	bidiRequest($ws, 14, 'network.continueResponse', ['request' => 'late']);
	foreach ([14, 13] as $id) bidiReply($ws, ['type' => 'success', 'id' => $id, 'result' => (object)[]]);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($ws);
}, function ($url) {
	$browser = new Browser($url, 'firefox', bidi: true);
	$network = $browser->network();
	$calls = [];
	$a = $network->intercept(function ($event) use (&$calls) {
		$calls[] = 'a';
		return $event['redirectCount'] === 0 ? NetworkDecision::continueResponse(201, [['X-Bytes', "\xff"]]) : NetworkDecision::continueResponse(headers: []);
	}, phase: 'responseStarted');
	$network->intercept(function () use (&$calls) { $calls[] = 'b'; return NetworkDecision::respond(202, body: "\0\xff"); }, phase: 'responseStarted');
	$browser->command('fixture.redirect');
	$network->removeIntercept($a);
	$browser->command('fixture.removed');
	expectError(fn () => $network->stopInterception(), 'redirect response rejected');
	$browser->command('fixture.stopped');
	$failures = $network->interceptionError()->failures();
	verify($calls === ['a', 'a', 'b'] && count($failures) === 1 && $failures[0]['redirectCount'] === 1
		&& $failures[0]['phase'] === 'responseStarted' && $network->requests() === [], 'Response ownership, settlement or capture isolation failed.');
	$browser->quit();
});

foreach (['success', 'error'] as $oldReply):
	scenario('a response pause survives a late request-stage mock reply: ' . $oldReply, function ($listener) use ($oldReply) {
		createSession($listener, bidi: true);
		$ws = upgrade($listener);
		foreach ([1 => 'beforeRequestSent', 3 => 'responseStarted'] as $id => $phase):
			bidiRequest($ws, $id, 'session.subscribe', ['events' => ['network.' . $phase]]);
			bidiReply($ws, ['type' => 'success', 'id' => $id, 'result' => ['subscription' => $phase]]);
			bidiRequest($ws, $id + 1, 'network.addIntercept', ['phases' => [$phase], 'urlPatterns' => []]);
			bidiReply($ws, ['type' => 'success', 'id' => $id + 1, 'result' => ['intercept' => $phase]]);
		endforeach;
		bidiRequest($ws, 5, 'fixture.mocked');
		bidiReply($ws, networkEvent('beforeRequestSent', 'same', extra: ['isBlocked' => true, 'intercepts' => ['beforeRequestSent']]));
		bidiRequest($ws, 6, 'network.provideResponse', ['request' => 'same', 'statusCode' => 200, 'headers' => [], 'body' => ['type' => 'base64', 'value' => '']]);
		bidiReply($ws, networkEvent('responseStarted', 'same', extra: ['isBlocked' => true, 'intercepts' => ['responseStarted']]));
		bidiRequest($ws, 7, 'network.continueResponse', ['request' => 'same']);
		bidiReply($ws, $oldReply === 'success' ? ['type' => 'success', 'id' => 6, 'result' => (object)[]]
			: ['type' => 'error', 'id' => 6, 'error' => 'unknown error', 'message' => 'old mock rejected']);
		bidiReply($ws, ['type' => 'error', 'id' => 7, 'error' => 'unknown error', 'message' => 'current response rejected']);
		bidiRequest($ws, 8, 'network.failRequest', ['request' => 'same']);
		foreach ([8, 5] as $id) bidiReply($ws, ['type' => 'success', 'id' => $id, 'result' => (object)[]]);
		foreach ([9 => 'beforeRequestSent', 10 => 'responseStarted'] as $id => $phase):
			bidiRequest($ws, $id, 'network.removeIntercept', ['intercept' => $phase]);
			bidiReply($ws, ['type' => 'success', 'id' => $id, 'result' => (object)[]]);
		endforeach;
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
		fclose($ws);
	}, function ($url) use ($oldReply) {
		$browser = new Browser($url, 'firefox', bidi: true);
		$network = $browser->network();
		$network->intercept(fn () => NetworkDecision::respond());
		$network->intercept(fn () => NetworkDecision::continueResponse(), phase: 'responseStarted');
		$browser->command('fixture.mocked');
		expectError(fn () => $network->stopInterception(), 'current response rejected');
		verify(array_column($network->interceptionError()->failures(), 'phase') === ($oldReply === 'error'
			? ['beforeRequestSent', 'responseStarted'] : ['responseStarted']) && $browser->bidi()->active(), 'Old mock reply cleared or recovered the newer response pause.');
		$browser->quit();
	});
endforeach;

scenario('authentication owns lazy subscriptions, early events, ordered rules and retired defaults', function ($listener) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	bidiRequest($ws, 1, 'session.subscribe', ['events' => ['network.beforeRequestSent']]);
	bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['subscription' => 'requests']]);
	bidiRequest($ws, 2, 'network.addIntercept', ['phases' => ['beforeRequestSent'], 'urlPatterns' => []]);
	bidiReply($ws, ['type' => 'success', 'id' => 2, 'result' => ['intercept' => 'r']]);
	bidiRequest($ws, 3, 'session.subscribe', ['events' => ['network.authRequired']]);
	bidiReply($ws, ['type' => 'success', 'id' => 3, 'result' => ['subscription' => 'auth']]);
	bidiRequest($ws, 4, 'network.addIntercept', ['phases' => ['authRequired'], 'urlPatterns' => []]);
	bidiReply($ws, networkEvent('authRequired', 'early', extra: ['isBlocked' => true, 'intercepts' => ['a']]));
	bidiReply($ws, ['type' => 'success', 'id' => 4, 'result' => ['intercept' => 'a']]);
	bidiRequest($ws, 5, 'network.continueWithAuth', ['request' => 'early', 'action' => 'provideCredentials',
		'credentials' => ['type' => 'password', 'username' => '', 'password' => '0']]);
	bidiReply($ws, ['type' => 'success', 'id' => 5, 'result' => (object)[]]);
	bidiRequest($ws, 6, 'network.addIntercept', ['phases' => ['authRequired'], 'urlPatterns' => []]);
	bidiReply($ws, ['type' => 'success', 'id' => 6, 'result' => ['intercept' => 'b']]);
	bidiRequest($ws, 7, 'fixture.overlap');
	bidiReply($ws, networkEvent('authRequired', 'foreign', extra: ['isBlocked' => true, 'intercepts' => ['foreign']]));
	bidiReply($ws, networkEvent('authRequired', 'unblocked', extra: ['isBlocked' => false, 'intercepts' => ['a']]));
	bidiReply($ws, networkEvent('authRequired', 'overlap', extra: ['isBlocked' => true, 'intercepts' => ['b', 'a']]));
	bidiRequest($ws, 8, 'network.continueWithAuth', ['request' => 'overlap', 'action' => 'provideCredentials',
		'credentials' => ['type' => 'password', 'username' => '', 'password' => '0']]);
	foreach ([8, 7] as $id) bidiReply($ws, ['type' => 'success', 'id' => $id, 'result' => (object)[]]);
	bidiRequest($ws, 9, 'network.removeIntercept', ['intercept' => 'a']);
	bidiReply($ws, ['type' => 'success', 'id' => 9, 'result' => (object)[]]);
	bidiRequest($ws, 10, 'fixture.removed');
	bidiReply($ws, networkEvent('authRequired', 'retired', extra: ['isBlocked' => true, 'intercepts' => ['a']]));
	bidiReply($ws, networkEvent('authRequired', 'active', extra: ['isBlocked' => true, 'intercepts' => ['a', 'b']]));
	bidiRequest($ws, 11, 'network.continueWithAuth', ['request' => 'retired', 'action' => 'default']);
	bidiRequest($ws, 12, 'network.continueWithAuth', ['request' => 'active', 'action' => 'cancel']);
	foreach ([12, 11, 10] as $id) bidiReply($ws, ['type' => 'success', 'id' => $id, 'result' => (object)[]]);
	foreach ([13 => 'r', 14 => 'b'] as $id => $rule):
		bidiRequest($ws, $id, 'network.removeIntercept', ['intercept' => $rule]);
		bidiReply($ws, ['type' => 'success', 'id' => $id, 'result' => (object)[]]);
	endforeach;
	bidiRequest($ws, 15, 'fixture.stopped');
	bidiReply($ws, networkEvent('beforeRequestSent', 'late-request', extra: ['isBlocked' => true, 'intercepts' => ['r']]));
	bidiReply($ws, networkEvent('authRequired', 'late-auth', extra: ['isBlocked' => true, 'intercepts' => ['b']]));
	bidiRequest($ws, 16, 'network.continueRequest', ['request' => 'late-request']);
	bidiRequest($ws, 17, 'network.continueWithAuth', ['request' => 'late-auth', 'action' => 'default']);
	bidiReply($ws, ['type' => 'success', 'id' => 16, 'result' => (object)[]]);
	bidiReply($ws, ['type' => 'error', 'id' => 17, 'error' => 'unknown error', 'message' => 'default rejected']);
	bidiRequest($ws, 18, 'network.continueWithAuth', ['request' => 'late-auth', 'action' => 'cancel']);
	foreach ([18, 15] as $id) bidiReply($ws, ['type' => 'success', 'id' => $id, 'result' => (object)[]]);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($ws);
}, function ($url) {
	$browser = new Browser($url, 'firefox', bidi: true);
	$network = $browser->network();
	$calls = [];
	$network->intercept(fn () => throw new RuntimeException('Retired request handler ran.'));
	$a = $network->intercept(function () use (&$calls) { $calls[] = 'a'; return NetworkDecision::provideCredentials('', '0'); }, phase: 'authRequired');
	$network->intercept(function () use (&$calls) { $calls[] = 'b'; return NetworkDecision::cancelAuth(); }, phase: 'authRequired');
	$browser->command('fixture.overlap');
	verify($calls === ['a', 'a'] && $browser->event()['method'] === 'network.authRequired', 'Auth precedence, foreign ownership or raw event isolation failed.');
	$network->removeIntercept($a)->removeIntercept($a);
	$browser->command('fixture.removed');
	$network->stopInterception()->stopInterception();
	$browser->command('fixture.stopped');
	expectError(fn () => $network->stopInterception(), 'default rejected');
	$failures = $network->interceptionError()->failures();
	verify($calls === ['a', 'a', 'b'] && count($failures) === 1 && $failures[0]['phase'] === 'authRequired'
		&& $network->requests() === [] && $browser->bidi()->active(), 'Retired auth did not default/recover independently of capture.');
	$browser->quit();
});

foreach (['throw', 'null', 'reentry', 'wrong-phase', 'credentials-rejected', 'default-rejected', 'cancel-rejected', 'no-such-request', 'recovery-rejected'] as $mode):
	scenario('authentication failure uses bounded cancel recovery: ' . $mode, function ($listener) use ($mode) {
		createSession($listener, bidi: true);
		$ws = upgrade($listener);
		bidiRequest($ws, 1, 'session.subscribe', ['events' => ['network.authRequired']]);
		bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['subscription' => 'auth']]);
		bidiRequest($ws, 2, 'network.addIntercept', ['phases' => ['authRequired'], 'urlPatterns' => []]);
		// Replay must enforce the same no-reentry and phase checks as live dispatch.
		bidiReply($ws, networkEvent('authRequired', 'early', extra: ['isBlocked' => true, 'intercepts' => ['a']]));
		bidiReply($ws, ['type' => 'success', 'id' => 2, 'result' => ['intercept' => 'a']]);
		$handlerError = in_array($mode, ['throw', 'null', 'reentry', 'wrong-phase', 'recovery-rejected'], true);
		$action = $handlerError || $mode === 'cancel-rejected' ? 'cancel' : ($mode === 'default-rejected' ? 'default' : 'provideCredentials');
		$params = ['request' => 'early', 'action' => $action];
		if ($action === 'provideCredentials') $params['credentials'] = ['type' => 'password', 'username' => 'user', 'password' => 'credential-value'];
		bidiRequest($ws, 3, 'network.continueWithAuth', $params);
		bidiRequest($ws, 4, 'fixture.resolve');
		bidiReply($ws, $handlerError && $mode !== 'recovery-rejected' ? ['type' => 'success', 'id' => 3, 'result' => (object)[]]
			: ['type' => 'error', 'id' => 3, 'error' => $mode === 'no-such-request' ? 'no such request' : 'unknown error', 'message' => 'auth decision rejected']);
		$next = 5;
		if (in_array($mode, ['credentials-rejected', 'default-rejected'], true)):
			bidiRequest($ws, $next, 'network.continueWithAuth', ['request' => 'early', 'action' => 'cancel']);
			bidiReply($ws, ['type' => 'success', 'id' => $next++, 'result' => (object)[]]);
		endif;
		bidiReply($ws, ['type' => 'success', 'id' => 4, 'result' => (object)[]]);
		bidiRequest($ws, $next, 'network.removeIntercept', ['intercept' => 'a']);
		bidiReply($ws, ['type' => 'success', 'id' => $next, 'result' => (object)[]]);
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
		fclose($ws);
	}, function ($url) use ($mode) {
		$browser = new Browser($url, 'firefox', bidi: true);
		$network = $browser->network();
		$original = new RuntimeException('auth handler failed');
		$network->intercept(fn () => match ($mode) {
			'throw', 'recovery-rejected' => throw $original, 'null' => null,
			'reentry' => $browser->command('must.not.send'), 'wrong-phase' => NetworkDecision::respond(),
			'cancel-rejected' => NetworkDecision::cancelAuth(), 'default-rejected' => NetworkDecision::defaultAuth(),
			default => NetworkDecision::provideCredentials('user', 'credential-value'),
		}, phase: 'authRequired');
		$browser->command('fixture.resolve');
		expectError(fn () => $network->stopInterception(), 'Network interception failed');
		$error = $network->interceptionError();
		verify(count($error->failures()) === ($mode === 'recovery-rejected' ? 2 : 1)
			&& array_all($error->failures(), fn ($failure) => $failure['phase'] === 'authRequired')
			&& !str_contains($error->getMessage(), 'credential-value') && $browser->bidi()->active(), 'Auth recovery lost diagnostics, leaked decision parameters or invalidated transport.');
		if (in_array($mode, ['throw', 'recovery-rejected'], true)) verify($error->getPrevious() === $original, 'Recovery replaced the original auth error.');
		$browser->quit();
	});
endforeach;

foreach (['beforeRequestSent', 'responseStarted'] as $phase):
foreach (['throw', 'null', 'reentry', 'wrong-phase', 'rejected', 'recovery-rejected', 'no-such-request'] as $mode):
	scenario('managed decision failure remains local: ' . $phase . ' ' . $mode, function ($listener) use ($networkMethods, $mode, $phase) {
		createSession($listener, bidi: true);
		$ws = upgrade($listener);
		bidiRequest($ws, 1, 'session.subscribe', ['events' => ['log.entryAdded']]);
		bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['subscription' => 'logs']]);
		bidiRequest($ws, 2, 'session.subscribe', ['events' => $networkMethods]);
		bidiReply($ws, ['type' => 'success', 'id' => 2, 'result' => ['subscription' => 'capture']]);
		bidiRequest($ws, 3, 'session.subscribe', ['events' => ['network.' . $phase]]);
		bidiReply($ws, ['type' => 'success', 'id' => 3, 'result' => ['subscription' => 'intercepts']]);
		bidiRequest($ws, 4, 'network.addIntercept', ['phases' => [$phase], 'urlPatterns' => []]);
		bidiReply($ws, ['type' => 'success', 'id' => 4, 'result' => ['intercept' => 'a']]);
		bidiRequest($ws, 5, 'fixture.trigger');
		bidiReply($ws, networkEvent($phase, 'owned', extra: ['isBlocked' => true, 'intercepts' => ['a']]));
		$handlerError = in_array($mode, ['throw', 'null', 'reentry', 'wrong-phase'], true);
		bidiRequest($ws, 6, $handlerError ? 'network.failRequest' : ($phase === 'responseStarted' ? 'network.continueResponse' : 'network.continueRequest'), ['request' => 'owned']);
		bidiReply($ws, $handlerError ? ['type' => 'success', 'id' => 6, 'result' => (object)[]]
			: ['type' => 'error', 'id' => 6, 'error' => $mode === 'no-such-request' ? 'no such request' : 'invalid argument', 'message' => 'decision rejected']);
		$next = 7;
		if (!$handlerError && $mode !== 'no-such-request'):
			bidiRequest($ws, 7, 'network.failRequest', ['request' => 'owned']);
			bidiReply($ws, $mode === 'recovery-rejected' ? ['type' => 'error', 'id' => 7, 'error' => 'unknown error', 'message' => 'recovery rejected']
				: ['type' => 'success', 'id' => 7, 'result' => (object)[]]);
			$next++;
		endif;
		bidiReply($ws, ['type' => 'success', 'id' => 5, 'result' => (object)[]]);
		bidiRequest($ws, $next, 'network.removeIntercept', ['intercept' => 'a']);
		bidiReply($ws, ['type' => 'success', 'id' => $next, 'result' => (object)[]]);
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
		fclose($ws);
	}, function ($url) use ($mode, $phase) {
		$browser = new Browser($url, 'firefox', bidi: true);
		$browser->startLogCapture();
		$network = $browser->network()->startCapture();
		$original = new RuntimeException('handler failed');
		$network->intercept(fn () => match ($mode) {
			'throw' => throw $original, 'null' => null, 'reentry' => $browser->command('must.not.send'),
			'wrong-phase' => NetworkDecision::provideCredentials('user', 'password'),
			default => $phase === 'responseStarted' ? NetworkDecision::continueResponse() : NetworkDecision::continueRequest(),
		}, phase: $phase);
		$browser->command('fixture.trigger');
		$error = $network->interceptionError();
		verify($browser->bidi()->active() && $network->captureError() === null && $browser->logCaptureError() === null, 'Managed failure invalidated transport or contaminated capture.');
		verify($error !== null && count($error->failures()) === ($mode === 'recovery-rejected' ? 2 : 1), 'Original or recovery diagnostic missing.');
		if ($mode === 'throw') verify($error->getPrevious() === $original, 'Original handler error was replaced.');
		if ($mode === 'reentry') verify(str_contains($error->getMessage(), 'not allowed inside'), 'Handler bypassed reentry guard.');
		expectError(fn () => $network->stopInterception(), 'Network interception failed');
		expectError(fn () => $network->stopInterception(), 'Network interception failed');
		$browser->quit();
		verify($network->interceptionError() === $error && count($network->requests()) === 1, 'Cleanup lost diagnostics or captured history.');
	});
endforeach;
endforeach;

foreach (['beforeRequestSent', 'responseStarted', 'authRequired'] as $phase):
scenario('interception reports later recovery errors without enabling capture or duplicating stop errors: ' . $phase, function ($listener) use ($phase) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	bidiRequest($ws, 1, 'session.subscribe', ['events' => ['network.' . $phase]]);
	bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['subscription' => 'intercepts']]);
	bidiRequest($ws, 2, 'network.addIntercept', ['phases' => [$phase], 'urlPatterns' => []]);
	bidiReply($ws, ['type' => 'success', 'id' => 2, 'result' => ['intercept' => 'a']]);
	bidiRequest($ws, 3, 'fixture.first');
	bidiReply($ws, networkEvent($phase, 'reported', extra: ['isBlocked' => true, 'intercepts' => ['a']]));
	bidiRequest($ws, 4, $phase === 'authRequired' ? 'network.continueWithAuth' : 'network.failRequest',
		['request' => 'reported'] + ($phase === 'authRequired' ? ['action' => 'cancel'] : []));
	bidiReply($ws, ['type' => 'success', 'id' => 3, 'result' => (object)[]]);
	bidiRequest($ws, 5, 'fixture.second');
	bidiReply($ws, ['type' => 'error', 'id' => 4, 'error' => 'unknown error', 'message' => 'late recovery failure']);
	bidiReply($ws, ['type' => 'success', 'id' => 5, 'result' => (object)[]]);
	bidiRequest($ws, 6, 'network.removeIntercept', ['intercept' => 'a']);
	bidiReply($ws, ['type' => 'success', 'id' => 6, 'result' => (object)[]]);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($ws);
}, function ($url) use ($phase) {
	$browser = new Browser($url, 'firefox', bidi: true);
	[$status, , $record] = runChecks(['check' => function () use ($browser, $phase) {
		$browser->network()->intercept(fn () => throw new RuntimeException('original reported failure'), phase: $phase);
		$browser->command('fixture.first');
		$browser->command('fixture.second');
		$browser->network()->stopInterception();
	}]);
	$attempt = $record['suites'][0]['attempts'][0];
	verify($status === 1 && count($attempt['errors']) === 2 && $attempt['browserNetwork'] === [], 'Interception diagnostics lost a late failure, duplicated aggregate or enabled capture.');
	verify(str_contains($attempt['errors'][0]['message'], 'original reported failure') && str_contains($attempt['errors'][1]['message'], 'late recovery failure'), 'Recovery replaced original error.');
	$browser->quit();
});
endforeach;

foreach (['beforeRequestSent', 'authRequired'] as $phase):
scenario('interception stop bounds unsettled replies and preserves HTTP cleanup: ' . $phase, function ($listener) use ($phase) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	bidiRequest($ws, 1, 'session.subscribe', ['events' => ['network.' . $phase]]);
	bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['subscription' => 'intercepts']]);
	bidiRequest($ws, 2, 'network.addIntercept', ['phases' => [$phase], 'urlPatterns' => []]);
	bidiReply($ws, ['type' => 'success', 'id' => 2, 'result' => ['intercept' => 'a']]);
	bidiRequest($ws, 3, 'fixture.pending');
	bidiReply($ws, networkEvent($phase, 'pending', extra: ['isBlocked' => true, 'intercepts' => ['a']]));
	bidiRequest($ws, 4, $phase === 'authRequired' ? 'network.continueWithAuth' : 'network.continueRequest',
		['request' => 'pending'] + ($phase === 'authRequired' ? ['action' => 'default'] : []));
	bidiReply($ws, ['type' => 'success', 'id' => 3, 'result' => (object)[]]);
	bidiRequest($ws, 5, 'network.removeIntercept', ['intercept' => 'a']);
	bidiReply($ws, ['type' => 'success', 'id' => 5, 'result' => (object)[]]);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($ws);
}, function ($url) use ($phase) {
	$browser = new Browser($url, 'firefox', bidi: true, timeout: 0.15);
	$network = $browser->network();
	$network->intercept(fn () => $phase === 'authRequired' ? NetworkDecision::defaultAuth() : NetworkDecision::continueRequest(), phase: $phase);
	$browser->command('fixture.pending');
	$started = hrtime(true);
	expectError(fn () => $network->stopInterception(), 'timed out');
	verify((hrtime(true) - $started) / 1e9 < 0.6 && !$browser->bidi()->active(), 'Stop exceeded its budget or retained ambiguous pending replies.');
	$browser->quit();
});
endforeach;

foreach (['success', 'error'] as $oldReply):
	scenario('cross-phase and repeated authentication pauses survive older replies: ' . $oldReply, function ($listener) use ($oldReply) {
		createSession($listener, bidi: true);
		$ws = upgrade($listener);
		foreach ([1 => 'beforeRequestSent', 3 => 'authRequired'] as $id => $phase):
			bidiRequest($ws, $id, 'session.subscribe', ['events' => ['network.' . $phase]]);
			bidiReply($ws, ['type' => 'success', 'id' => $id, 'result' => ['subscription' => $phase]]);
			bidiRequest($ws, $id + 1, 'network.addIntercept', ['phases' => [$phase], 'urlPatterns' => []]);
			bidiReply($ws, ['type' => 'success', 'id' => $id + 1, 'result' => ['intercept' => $phase]]);
		endforeach;
		bidiRequest($ws, 5, 'fixture.challenges');
		bidiReply($ws, networkEvent('beforeRequestSent', 'same', extra: ['isBlocked' => true, 'intercepts' => ['beforeRequestSent']]));
		bidiRequest($ws, 6, 'network.continueRequest', ['request' => 'same']);
		foreach ([7, 8] as $id):
			bidiReply($ws, networkEvent('authRequired', 'same', extra: ['isBlocked' => true, 'intercepts' => ['authRequired']]));
			bidiRequest($ws, $id, 'network.continueWithAuth', ['request' => 'same', 'action' => 'provideCredentials',
				'credentials' => ['type' => 'password', 'username' => 'user', 'password' => 'secret']]);
		endforeach;
		foreach ([6, 7] as $id) bidiReply($ws, $oldReply === 'success' ? ['type' => 'success', 'id' => $id, 'result' => (object)[]]
			: ['type' => 'error', 'id' => $id, 'error' => 'unknown error', 'message' => 'older decision rejected']);
		bidiReply($ws, ['type' => 'error', 'id' => 8, 'error' => 'unknown error', 'message' => 'current decision rejected']);
		bidiRequest($ws, 9, 'network.continueWithAuth', ['request' => 'same', 'action' => 'cancel']);
		foreach ([9, 5] as $id) bidiReply($ws, ['type' => 'success', 'id' => $id, 'result' => (object)[]]);
		foreach ([10 => 'beforeRequestSent', 11 => 'authRequired'] as $id => $phase):
			bidiRequest($ws, $id, 'network.removeIntercept', ['intercept' => $phase]);
			bidiReply($ws, ['type' => 'success', 'id' => $id, 'result' => (object)[]]);
		endforeach;
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
		fclose($ws);
	}, function ($url) use ($oldReply) {
		$browser = new Browser($url, 'firefox', bidi: true);
		$network = $browser->network();
		$network->intercept(fn () => NetworkDecision::continueRequest());
		$network->intercept(fn () => NetworkDecision::provideCredentials('user', 'secret'), phase: 'authRequired');
		$browser->command('fixture.challenges');
		expectError(fn () => $network->stopInterception(), 'current decision rejected');
		verify(array_column($network->interceptionError()->failures(), 'phase') === ($oldReply === 'error'
			? ['beforeRequestSent', 'authRequired', 'authRequired'] : ['authRequired']) && $browser->bidi()->active(), 'Older replies cleared/recovered the current authentication occurrence.');
		$browser->quit();
	});
endforeach;

scenario('authentication subscription failure preserves request interception and permits retry', function ($listener) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	bidiRequest($ws, 1, 'session.subscribe', ['events' => ['network.beforeRequestSent']]);
	bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['subscription' => 'requests']]);
	bidiRequest($ws, 2, 'network.addIntercept', ['phases' => ['beforeRequestSent'], 'urlPatterns' => []]);
	bidiReply($ws, ['type' => 'success', 'id' => 2, 'result' => ['intercept' => 'r']]);
	bidiRequest($ws, 3, 'session.subscribe', ['events' => ['network.authRequired']]);
	bidiReply($ws, networkEvent('beforeRequestSent', 'existing', extra: ['isBlocked' => true, 'intercepts' => ['r']]));
	bidiRequest($ws, 4, 'network.continueRequest', ['request' => 'existing']);
	bidiReply($ws, ['type' => 'success', 'id' => 4, 'result' => (object)[]]);
	bidiReply($ws, ['type' => 'error', 'id' => 3, 'error' => 'unknown error', 'message' => 'subscription rejected']);
	bidiRequest($ws, 5, 'session.subscribe', ['events' => ['network.authRequired']]);
	bidiReply($ws, ['type' => 'success', 'id' => 5, 'result' => ['subscription' => 'auth']]);
	bidiRequest($ws, 6, 'network.addIntercept', ['phases' => ['authRequired'], 'urlPatterns' => []]);
	bidiReply($ws, networkEvent('authRequired', 'retried', extra: ['isBlocked' => true, 'intercepts' => ['a']]));
	bidiReply($ws, ['type' => 'success', 'id' => 6, 'result' => ['intercept' => 'a']]);
	bidiRequest($ws, 7, 'network.continueWithAuth', ['request' => 'retried', 'action' => 'cancel']);
	bidiReply($ws, ['type' => 'success', 'id' => 7, 'result' => (object)[]]);
	foreach ([8 => 'r', 9 => 'a'] as $id => $rule):
		bidiRequest($ws, $id, 'network.removeIntercept', ['intercept' => $rule]);
		bidiReply($ws, ['type' => 'success', 'id' => $id, 'result' => (object)[]]);
	endforeach;
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($ws);
}, function ($url) {
	$browser = new Browser($url, 'firefox', bidi: true);
	$network = $browser->network();
	$calls = 0;
	$network->intercept(function () use (&$calls) { ++$calls; return NetworkDecision::continueRequest(); });
	expectError(fn () => $network->intercept(fn () => NetworkDecision::cancelAuth(), phase: 'authRequired'), 'subscription rejected');
	$network->intercept(function () use (&$calls) { ++$calls; return NetworkDecision::cancelAuth(); }, phase: 'authRequired');
	$network->stopInterception();
	verify($calls === 2 && $network->interceptionError() === null, 'Failed auth subscription detached request handling or duplicated its own listener.');
	$browser->quit();
});

scenario('rejected registration can retry and provisional replay retains guarded handler and recovery failures', function ($listener) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	bidiRequest($ws, 1, 'session.subscribe', ['events' => ['network.beforeRequestSent']]);
	bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['subscription' => 'intercepts']]);
	bidiRequest($ws, 2, 'network.addIntercept', ['phases' => ['beforeRequestSent'], 'urlPatterns' => []]);
	bidiReply($ws, networkEvent('beforeRequestSent', 'foreign', extra: ['isBlocked' => true, 'intercepts' => ['foreign']]));
	bidiReply($ws, ['type' => 'error', 'id' => 2, 'error' => 'invalid argument', 'message' => 'registration rejected']);
	bidiRequest($ws, 3, 'network.addIntercept', ['phases' => ['beforeRequestSent'], 'urlPatterns' => []]);
	bidiReply($ws, networkEvent('beforeRequestSent', 'early', extra: ['isBlocked' => true, 'intercepts' => ['a']]));
	bidiReply($ws, ['type' => 'success', 'id' => 3, 'result' => ['intercept' => 'a']]);
	bidiRequest($ws, 4, 'network.failRequest', ['request' => 'early']);
	bidiReply($ws, ['type' => 'error', 'id' => 4, 'error' => 'unknown error', 'message' => 'replay recovery rejected']);
	bidiRequest($ws, 5, 'network.removeIntercept', ['intercept' => 'a']);
	bidiReply($ws, ['type' => 'success', 'id' => 5, 'result' => (object)[]]);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($ws);
}, function ($url) {
	$browser = new Browser($url, 'firefox', bidi: true);
	$network = $browser->network();
	expectError(fn () => $network->intercept(fn () => NetworkDecision::fail()), 'registration rejected');
	verify($network->interceptionError() === null, 'Rejected registration poisoned a controller with no owned pause.');
	$network->intercept(fn () => $browser->command('must.not.send'));
	expectError(fn () => $network->stopInterception(), 'not allowed inside');
	verify(count($network->interceptionError()->failures()) === 2 && $browser->bidi()->active(), 'Replay lost original/recovery failures or invalidated transport.');
	$browser->quit();
});

scenario('an unsendable replay decision invalidates BiDi and retains its original failure', function ($listener) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	bidiRequest($ws, 1, 'session.subscribe', ['events' => ['network.beforeRequestSent']]);
	bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['subscription' => 'intercepts']]);
	bidiRequest($ws, 2, 'network.addIntercept', ['phases' => ['beforeRequestSent'], 'urlPatterns' => []]);
	bidiReply($ws, networkEvent('beforeRequestSent', 'early', extra: ['isBlocked' => true, 'intercepts' => ['a']]));
	bidiReply($ws, ['type' => 'success', 'id' => 2, 'result' => ['intercept' => 'a']]);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($ws);
}, function ($url) {
	$browser = new Browser($url, 'firefox', bidi: true);
	$network = $browser->network();
	expectError(fn () => $network->intercept(fn () => NetworkDecision::continueRequest(url: "\xff")), 'UTF-8');
	verify(!$browser->bidi()->active() && $network->interceptionError()->getPrevious() instanceof JsonException, 'Unsent replay left a healthy-looking connection or lost its cause.');
	expectError(fn () => $network->stopInterception(), 'UTF-8');
	$browser->quit();
});

foreach (['old-success', 'old-error'] as $mode):
	scenario('interception settlement preserves newer occurrences: ' . $mode, function ($listener) use ($mode) {
		createSession($listener, bidi: true);
		$ws = upgrade($listener);
		bidiRequest($ws, 1, 'session.subscribe', ['events' => ['network.beforeRequestSent']]);
		bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['subscription' => 'intercepts']]);
		bidiRequest($ws, 2, 'network.addIntercept', ['phases' => ['beforeRequestSent'], 'urlPatterns' => []]);
		bidiReply($ws, ['type' => 'success', 'id' => 2, 'result' => ['intercept' => 'a']]);
		bidiRequest($ws, 3, 'fixture.redirect');
		foreach ([0, 1] as $hop):
			bidiReply($ws, networkEvent('beforeRequestSent', 'same', $hop, extra: ['isBlocked' => true, 'intercepts' => ['a']]));
			bidiRequest($ws, 4 + $hop, 'network.continueRequest', ['request' => 'same']);
		endforeach;
		bidiReply($ws, $mode === 'old-success' ? ['type' => 'success', 'id' => 4, 'result' => (object)[]]
			: ['type' => 'error', 'id' => 4, 'error' => 'invalid argument', 'message' => 'old rejected']);
		bidiReply($ws, $mode === 'old-error' ? ['type' => 'success', 'id' => 5, 'result' => (object)[]]
			: ['type' => 'error', 'id' => 5, 'error' => 'invalid argument', 'message' => 'new rejected']);
		$next = 6;
		if ($mode === 'old-success'):
			bidiRequest($ws, $next, 'network.failRequest', ['request' => 'same']);
			bidiReply($ws, ['type' => 'success', 'id' => $next++, 'result' => (object)[]]);
		endif;
		bidiReply($ws, ['type' => 'success', 'id' => 3, 'result' => (object)[]]);
		bidiRequest($ws, $next, 'network.removeIntercept', ['intercept' => 'a']);
		bidiReply($ws, ['type' => 'success', 'id' => $next, 'result' => (object)[]]);
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
		fclose($ws);
	}, function ($url) use ($mode) {
		$browser = new Browser($url, 'firefox', bidi: true);
		$network = $browser->network();
		$network->intercept(fn () => NetworkDecision::continueRequest());
		$browser->command('fixture.redirect');
		expectError(fn () => $network->stopInterception(), 'rejected');
		$failures = $network->interceptionError()->failures();
		verify(count($failures) === 1 && $failures[0]['redirectCount'] === ($mode === 'old-success' ? 1 : 0)
			&& $browser->bidi()->active(), 'An older reply cleared or recovered the newer pause.');
		$browser->quit();
	});
endforeach;

foreach (['errors', 'deadline'] as $mode):
	scenario('interception stop continues removals under one budget: ' . $mode, function ($listener) use ($mode) {
		createSession($listener, bidi: true);
		$ws = upgrade($listener);
		bidiRequest($ws, 1, 'session.subscribe', ['events' => ['network.beforeRequestSent']]);
		bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['subscription' => 'intercepts']]);
		foreach ([2, 3, 4] as $id):
			bidiRequest($ws, $id, 'network.addIntercept', ['phases' => ['beforeRequestSent'], 'urlPatterns' => []]);
			bidiReply($ws, ['type' => 'success', 'id' => $id, 'result' => ['intercept' => 'rule-' . $id]]);
		endforeach;
		foreach ([5, 6, 7] as $id):
			bidiRequest($ws, $id, 'network.removeIntercept', ['intercept' => 'rule-' . ($id - 3)]);
			if ($mode === 'deadline'):
				if ($id === 7) break;
				usleep(200000);
				bidiReply($ws, ['type' => 'success', 'id' => $id, 'result' => (object)[]]);
			else:
				bidiReply($ws, ['type' => 'error', 'id' => $id, 'error' => 'unknown error', 'message' => 'remove-' . $id]);
			endif;
		endforeach;
		if ($mode === 'errors'):
			bidiRequest($ws, 8, 'fixture.retiring');
			bidiReply($ws, networkEvent('beforeRequestSent', 'retiring', extra: ['isBlocked' => true, 'intercepts' => ['rule-2']]));
			bidiRequest($ws, 9, 'network.continueRequest', ['request' => 'retiring']);
			foreach ([9, 8] as $id) bidiReply($ws, ['type' => 'success', 'id' => $id, 'result' => (object)[]]);
		endif;
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
		fclose($ws);
	}, function ($url) use ($mode) {
		$browser = new Browser($url, 'firefox', bidi: true, timeout: 0.6);
		$network = $browser->network();
		for ($i = 0; $i < 3; $i++) $network->intercept(fn () => throw new RuntimeException('retiring handler ran'));
		$started = hrtime(true);
		expectError(fn () => $network->stopInterception(), $mode === 'errors' ? 'remove-5' : 'timed out');
		if ($mode === 'deadline') verify((hrtime(true) - $started) / 1e9 < 0.85 && !$browser->bidi()->active(), 'Removal renewed the stop budget.');
		else $browser->command('fixture.retiring');
		verify(count($network->interceptionError()->failures()) === ($mode === 'errors' ? 3 : 1), 'Cleanup discarded secondary errors or invoked a retiring handler.');
		$browser->quit();
	});
endforeach;

foreach (['beforeRequestSent', 'responseStarted', 'authRequired'] as $phase):
scenario('interception reports repeated exception objects and retains each failure across retries: ' . $phase, function ($listener) use ($phase) {
	createSession($listener, bidi: true);
	$ws = upgrade($listener);
	bidiRequest($ws, 1, 'session.subscribe', ['events' => ['network.' . $phase]]);
	bidiReply($ws, ['type' => 'success', 'id' => 1, 'result' => ['subscription' => 'intercepts']]);
	bidiRequest($ws, 2, 'network.addIntercept', ['phases' => [$phase], 'urlPatterns' => []]);
	bidiReply($ws, ['type' => 'success', 'id' => 2, 'result' => ['intercept' => 'a']]);
	bidiRequest($ws, 3, 'fixture.failures');
	foreach ([4, 5, 6] as $id):
		bidiReply($ws, networkEvent($phase, 'request-' . $id, extra: ['isBlocked' => true, 'intercepts' => ['a']]));
		bidiRequest($ws, $id, $phase === 'authRequired' ? 'network.continueWithAuth' : 'network.failRequest',
			['request' => 'request-' . $id] + ($phase === 'authRequired' ? ['action' => 'cancel'] : []));
		bidiReply($ws, ['type' => 'success', 'id' => $id, 'result' => (object)[]]);
	endforeach;
	bidiReply($ws, ['type' => 'success', 'id' => 3, 'result' => (object)[]]);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	fclose($ws);
}, function ($url) use ($phase) {
	$browser = new Browser($url, 'firefox', bidi: true);
	[$status, , $record] = runChecks(['suite' => function ($test) use ($browser, $phase) {
		return new class($test, $browser, $phase) extends Suite {
			private int $attempt = 0;
			public function __construct($test, private Browser $browser, private string $phase) { parent::__construct($test); }
			#[SuiteBefore]
			public function setup(): void {
				$shared = new RuntimeException('shared handler failure');
				$this->browser->network()->intercept(fn ($event) => throw ($event['request']['request'] === 'request-5'
					? new RuntimeException('middle handler failure') : $shared), phase: $this->phase);
			}
			#[TestCase('Retained interception failures', retry: 1)]
			public function body(): void { if (++$this->attempt === 1) $this->browser->command('fixture.failures'); }
			#[SuiteAfter]
			public function cleanup(): void { $this->browser->quit(); }
		};
	}]);
	$suite = $record['suites'][0]['attempts'][0];
	$attempts = $suite['cases'][0]['attempts'];
	verify($status === 1 && count($attempts) === 2, 'Interception failure did not trigger retry.');
	foreach ($attempts as $attempt) verify($attempt['status'] === 'FAIL' && array_column($attempt['errors'], 'message')
		=== ['shared handler failure', 'middle handler failure', 'shared handler failure'] && $attempt['browserNetwork'] === [], 'A cursor skipped failures or mixed retries.');
	verify(count($suite['errors']) === 3, 'Case suppression leaked into suite teardown.');
});
endforeach;

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
	$input = $browser->find('#form')->find('#name')->resolve(wait: false);
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
	$button = $tab->find('button')->resolve();
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
	$input = $browser->find('#input')->resolve();
	$root = $input->shadowRoot();
	verify($browser->defaultFrame() === $browser, 'Default frame is not fluent.');
	$top = $browser->find('#top')->resolve();
	expectError(fn() => $browser->script('return arguments[0]', arguments: [$input]), 'another browser session or browsing context');
	expectError(fn() => $top->dragDrop($input), 'another browser session or browsing context');
	expectError(fn() => $browser->switchFrame($input), 'another browser session or browsing context');
	$input->click();
	$top->click();
	$root->find('button')->click(wait: false);
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
		$elements = $receiver->findAll('.ready')->all();
		if (++$calls === 1) $browser->switch('other');
		return $elements[0] ?? null;
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
	$input = $browser->switchFrame($browser->find('iframe'))->find('input')->resolve();
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
	expectError(fn() => $window->find('input')->resolve(), 'iframe replaced');
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
	expectError(fn() => $browser->find('input')->resolve(), 'did not return an element ID');
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
	['visible', ['input/1'], 'GET', '/element/input%2F1/displayed', null, false],
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
		$id = $driver->find('#ready')->resolve(wait: false);
		return $id->visible() && $id->enabled() ? $id : false;
	}, timeout: 2000);
	verify($element instanceof Element && $element->id() === 'ready', 'Wait did not return the callback result.');
	$element->click();
	$driver->quit();
});

scenario('resolve and waiting visibility acquire the first matching target', function ($listener) {
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
	verify($browser->find('//input', 'xpath')->resolve(timeout: 1000)->id() === 'hidden', 'Presence should not require displayedness.');
	verify($browser->window()->find('.ready')->visible(wait: true, timeout: 1000), 'Visibility did not re-find after stale reference.');
	$browser->quit();
});

scenario('selector negative waits track replacements and all matching elements', function ($listener) {
	createSession($listener);
	$selector = ['using' => 'xpath', 'value' => '//aside'];
	foreach (['old', 'replacement', null] as $id):
		exchange($listener, 'POST', '/session/session%2F1/element', $selector,
			$id === null ? ['error' => 'no such element', 'message' => 'absent'] : [Element::REFERENCE => $id], $id === null ? 404 : 200);
		if ($id !== null) exchange($listener, 'GET', '/session/session%2F1/element/' . $id . '/name', null, 'aside');
	endforeach;
	$selector = ['using' => 'css selector', 'value' => '.spinner'];
	foreach ([true, false] as $displayed):
		exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => 'replacement']);
		exchange($listener, 'GET', '/session/session%2F1/element/replacement/displayed', null, $displayed);
	endforeach;
	foreach (range(1, 4) as $ignored):
		exchange($listener, 'POST', '/session/session%2F1/element', $selector, ['error' => 'no such element', 'message' => 'absent'], 404);
	endforeach;
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox', pollInterval: 100);
	verify($browser->find('//aside', 'xpath')->present(wait: true, is: false, timeout: 1000), 'Absence wait failed.');
	verify($browser->find('.spinner')->visible(wait: true, is: false, timeout: 1000), 'Visible replacement was ignored.');
	$window = $browser->window();
	verify($window->find('.spinner')->present(wait: true, is: false), 'Window absence wait failed.');
	verify($window->find('.spinner')->visible(wait: true, is: false), 'Window hidden wait failed.');
	verify($browser->find('.spinner')->present(wait: true, is: false), 'Absent selector should succeed.');
	verify($browser->find('.spinner')->visible(wait: true, is: false), 'Absent selector should be invisible.');
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
		$element = $browser->switchFrame(0)->find('#old')->resolve();
		$browser->switch('other');
		$condition = $endpoint === 'displayed' ? 'visible' : 'present';
		verify($element->$condition(is: false, assert: true, timeout: 1000) === $element, 'Element condition lost original receiver.');
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
	$element = $browser->switchFrame(0)->find('#name')->resolve();
	$browser->defaultFrame();
	verify($element->value() === 'Original' && $element->value(null) === 'Changed', 'Getter did not read the current DOM value.');
	verify($element->value('Jane €')->type(' Doe') === $element, 'Value replacement lost receiver.');
	verify($element->value('') === $element, 'Empty replacement should only clear.');
	verify($element->value('0') === $element, 'Zero should set rather than read.');
	expectError(fn() => $element->value('blocked', wait: false), 'invalid element state');
	$browser->quit();
});

foreach (['click', 'type', 'key', 'clear', 'value'] as $method):
	scenario("$method follows replacements in the captured frame on each operation", function ($listener) use ($method) {
		createSession($listener);
		$selector = ['using' => 'css selector', 'value' => '#action'];
		exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
		exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => null], null);
		exchange($listener, 'POST', '/session/session%2F1/frame', ['id' => 0], null);
		exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => 'old']);
		$endpoint = in_array($method, ['type', 'key', 'value'], true) ? 'value' : $method;
		$body = $endpoint === 'value' ? ['text' => $method === 'key' ? Browser::ENTER : 'new'] : [];
		if ($method === 'value') exchange($listener, 'POST', '/session/session%2F1/element/old/clear', [], null);
		exchange($listener, 'POST', '/session/session%2F1/element/old/' . $endpoint, $body,
			['error' => 'stale element reference', 'message' => 'replaced'], 404);
		exchange($listener, 'POST', '/session/session%2F1/element', $selector, ['error' => 'no such element', 'message' => 'rendering'], 404);
		exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => 'new']);
		if ($method === 'value') exchange($listener, 'POST', '/session/session%2F1/element/new/clear', [], null);
		exchange($listener, 'POST', '/session/session%2F1/element/new/' . $endpoint, $body, null);
		exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => 'new']);
		exchange($listener, 'GET', '/session/session%2F1/element/new/property/value', null, 'new');
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	}, function ($url) use ($method) {
		$browser = new Browser($url, 'firefox');
		$element = $browser->switchFrame(0)->find('#action');
		$browser->defaultFrame();
		$args = in_array($method, ['type', 'key', 'value'], true) ? [$method === 'key' ? Browser::ENTER : 'new'] : [];
		verify($element->$method(...$args, timeout: 1500) === $element
			&& $element->value() === 'new', 'Action did not address the replacement or preserve its receiver.');
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
		verify($element->$method() === $element, 'Selection retry lost receiver.');
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
		if ($shadow):
			exchange($listener, 'POST', '/session/session%2F1/element', $parent, [Element::REFERENCE => 'parent-old']);
			exchange($listener, 'GET', '/session/session%2F1/element/parent-old/shadow', null, [ShadowRoot::REFERENCE => 'root-old']);
		endif;
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
		$child = $scope->find('button');
		verify($child->click() === $child, 'Recovery lost receiver.');
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
	$element = $browser->switchFrame(0)->find('#name')->resolve();
	verify($element->visible(is: false, assert: true, timeout: 1000) === $element, 'Hidden element did not finish wait.');
	$browser->quit();
});

foreach ([[false, false], [true, false], [false, true], [true, true]] as [$visible, $invert]):
	scenario('find timeout and errors: visible=' . (int)$visible . ', invert=' . (int)$invert, function ($listener) use ($visible, $invert) {
		createSession($listener);
		$positive = !$invert;
		foreach (range(1, 2) as $ignored):
			exchange($listener, 'POST', '/session/session%2F1/element',
				['using' => 'css selector', 'value' => '#pending'],
				!$visible && $positive ? ['error' => 'no such element', 'message' => 'not yet'] : [Element::REFERENCE => 'pending'],
				!$visible && $positive ? 404 : 200);
			if ($visible):
				exchange($listener, 'GET', '/session/session%2F1/element/pending/displayed', null, !$positive);
			elseif (!$positive):
				exchange($listener, 'GET', '/session/session%2F1/element/pending/name', null, 'div');
			endif;
		endforeach;
		exchange($listener, 'POST', '/session/session%2F1/element',
			['using' => 'css selector', 'value' => '[broken'], ['error' => 'invalid selector', 'message' => 'bad syntax'], 400);
		exchange($listener, 'DELETE', '/session/session%2F1', null, null);
	}, function ($url) use ($visible, $invert) {
		$browser = new Browser($url, 'firefox', assertionTimeout: 50);
		$condition = $visible ? 'visible' : 'present';
		foreach ([20, null] as $timeout):
			$start = microtime(true);
			verify(!$browser->find('#pending')->$condition(wait: true, is: !$invert, timeout: $timeout), 'Unmet query should return false.');
			$elapsed = microtime(true) - $start;
			verify($elapsed >= ($timeout ?? 50) / 1000 && $elapsed < 0.5, 'Timeout was not milliseconds or constructor default.');
		endforeach;
		expectError(fn() => $browser->find('[broken')->$condition(wait: true, is: !$invert), 'invalid selector');
		foreach ([0, -1, INF, NAN] as $timeout):
			expectError(fn() => $browser->find('#pending')->$condition(wait: true, is: !$invert, timeout: $timeout), 'positive and finite');
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
		$browser = new Browser($url, 'firefox', assertionTimeout: 50);
		$element = $browser->find('#pending')->resolve();
		$condition = $endpoint === 'displayed' ? 'visible' : 'present';
		foreach ([20, null] as $timeout):
			$start = microtime(true);
			verify(!$element->$condition(is: false, wait: true, timeout: $timeout), 'Unmet query should return false.');
			$elapsed = microtime(true) - $start;
			verify($elapsed >= ($timeout ?? 50) / 1000 && $elapsed < 0.5, 'Element timeout units/default changed.');
		endforeach;
		verify($element->$condition(is: false, wait: true, timeout: 1000), 'Missing node should satisfy absence.');
		expectError(fn() => $element->$condition(is: false, wait: true), 'unknown error');
		foreach ([0, -1, INF, NAN] as $timeout):
			expectError(fn() => $element->$condition(is: false, wait: true, timeout: $timeout), 'positive and finite');
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
		$element = $browser->switchFrame(0)->find('#node')->resolve();
		$browser->defaultFrame();
		$condition = $endpoint === 'displayed' ? 'visible' : 'present';
		expectError(fn() => $element->$condition(is: false, wait: true, timeout: 1000), 'frame removed');
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
		$element = $browser->find('#node')->resolve();
		$condition = $visible ? 'visible' : 'present';
		verify($element->$condition(is: !$invert, assert: true, timeout: 1000) === $element, 'Condition lost its original receiver.');
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
	$element = $browser->find('#node')->resolve();
	verify(!$element->present(), 'Stale node should be absent.');
	verify(!$element->visible(), 'Stale node should be invisible.');
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
	$element = $browser->find('#node')->resolve();
	$element->visible(assert: true, timeout: 20);
	verify(count($results) === 1 && $results[0][0] === false && str_contains($results[0][1], 'expected true, got false'), 'Expiry did not report exactly one failure.');
	$throw = true;
	try {
		$element->visible(assert: true, timeout: 1000);
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
	exchange($listener, 'GET', '/session/session%2F1/url', null, 'http://fixture/old');
	exchange($listener, 'GET', '/session/session%2F1/element/node/text', null, 'actual');
	exchange($listener, 'GET', '/session/session%2F1/title', null, 'slow command', 200, 0.12);
	exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
	$browser = new Browser($url, 'firefox', timeout: 0.5, waitTimeout: 40, assertionTimeout: 80);
	$element = new Element($browser->window(), 'node');
	foreach ([
		[fn() => $browser->find('#missing')->resolve(), 40, 'wait timed out'],
		[fn() => $element->visible(assert: true), 80, 'assertion failed'],
		[fn() => $element->click(), 40, 'action timed out'],
		[fn() => $element->textEquals('expected', assert: true), 80, 'assertion failed'],
		[fn() => $browser->findAll('.missing')->countEquals(1, assert: true), 80, 'assertion failed'],
		[fn() => $browser->urlMatches('~/new$~', assert: true), 80, 'assertion failed'],
		[fn() => $element->textEquals('expected', assert: true, timeout: 20), 20, 'assertion failed'],
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

scenario('window target condition restores selected window and frame', function ($listener) {
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
	verify($window->find('//input', 'xpath')->visible(assert: true)->window() === $window, 'Condition lost window context.');
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
	expectError(fn() => $driver->wait(fn($d) => $d->find('[broken')->present(), timeout: 1000), 'invalid selector');
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
	$rows = new ElementSet(...$driver->findAll('tr')->all());
	verify($ids($rows) === ['row/1', 'row/2'], 'Plural lookup lost ID order.');
	verify(count($rows) === 2 && $rows->first() === $rows->get(0), 'Collection count/first failed.');
	verify(iterator_to_array($rows) === $rows->all(), 'Iteration changed membership/order.');
	expectError(fn() => $rows->get(-1), 'out of range');
	expectError(fn() => $rows->get(2), 'out of range');
	$empty = new ElementSet(...$driver->findAll('.absent')->all());
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
