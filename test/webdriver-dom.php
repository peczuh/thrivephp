<?php

// Run with: php test/webdriver-dom.php [chrome|firefox]. Uses an installed browser and isolated documents.
spl_autoload_register(function ($class) {
	$prefix = 'ThriveData\\ThrivePHP\\WebDriver\\';
	if (str_starts_with($class, $prefix)):
		require dirname(__DIR__) . '/src/WebDriver/' . substr($class, strlen($prefix)) . '.php';
	endif;
});

use ThriveData\ThrivePHP\WebDriver\WebDriver;

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

$driver = new WebDriver(browser: $argv[1] ?? 'chrome');
try {
	$browser = $driver->browser(headless: true);
	$browser->navigate('data:text/html,' . rawurlencode(<<<'HTML'
		<!doctype html><html><body>
		<form id="form">
			<input id="required" required>
			<input name="submit"><input name="requestSubmit"><input name="form">
			<span id="descendant">Descendant</span>
			<button id="submitter" name="button" value="sent" formnovalidate>Submit</button>
			<input id="orphan" form="missing">
			<input id="otherOwner" form="otherForm">
		</form>
		<form id="otherForm"></form><input id="external" form="form">
		<input id="checkbox" type="checkbox">
		<input id="radio" type="radio" name="choice"><input id="radio2" type="radio" name="choice">
		<input id="value" value="original">
		<div id="container">text<!-- comment --><span id="first">first</span>between<span id="last">last</span>tail</div>
		<div id="empty">text<!-- comment --></div>
		<iframe id="frame" srcdoc="<div id='inside'><span id='child'>child</span></div>"></iframe>
		</body></html>
		HTML));
	$browser->script(<<<'JS'
		window.events = {submit: [], invalid: 0, input: 0, change: 0, click: 0};
		document.addEventListener('submit', event => {
			event.preventDefault();
			events.submit.push([event.target.id, event.submitter && event.submitter.id]);
		});
		document.addEventListener('invalid', () => events.invalid++, true);
		for (const type of ['input', 'change', 'click']) {
			document.addEventListener(type, () => events[type]++);
		}
		JS);

	$form = $browser->find('#form');
	verify($form->submit() === $form, 'Submission lost its receiver.');
	verify($browser->script('return [events.invalid, events.submit];') === [1, []], 'Submission bypassed required validation.');
	$browser->find('#required')->property('value', 'valid');
	foreach (['#form', '#required', '#descendant', '#external', '#submitter', '#otherOwner'] as $selector):
		$browser->find($selector)->submit();
	endforeach;
	verify($browser->script('return events.submit;') === [
		['form', null], ['form', null], ['form', null], ['form', null], ['form', null], ['otherForm', null],
	], 'Submission ignored form ownership, cancellation, or implicit submitter semantics.');
	$browser->find('#required')->property('value', '');
	$browser->find('#submitter')->submit();
	verify($browser->script('return [events.invalid, events.submit.length];') === [2, 6], 'Submission applied button validation overrides.');
	$form->attribute('novalidate', '')->submit();
	verify($browser->script('return events.submit.length;') === 7, 'Submission ignored form novalidate.');
	foreach (['#empty', '#orphan'] as $selector):
		expectError(fn() => $browser->find($selector)->submit(), 'No applicable form');
	endforeach;
	echo "PASS: form validation, cancellation, external ownership, and masked native methods\n";

	$input = $browser->find('#value');
	verify($input->property(key: 'value', value: 'edited') === $input, 'Property setter lost receiver.');
	verify($input->property('value') === 'edited' && $input->attribute('value') === 'original', 'Property and attribute values were conflated.');
	verify($input->attribute(key: 'value', value: 'attribute') === $input, 'Attribute setter lost receiver.');
	verify($input->attribute('value') === 'attribute' && $input->property('value') === 'edited', 'Attribute setter overwrote dirty value.');
	$input->property('custom', false);
	verify($input->property('custom') === false, 'False property assignment failed.');
	$input->property('custom', null);
	verify($browser->script('return Object.hasOwn(arguments[0], "custom") && arguments[0].custom === null;', arguments: [$input]), 'Null was treated as a getter.');
	$input->property('custom', ['related' => $form]);
	verify($browser->script('return arguments[0].custom.related === arguments[1];', arguments: [$input, $form]), 'Nested element property value was not marshalled.');
	$input->attribute('disabled', '');
	verify($input->attribute('disabled') === 'true' && !$input->enabled(), 'Empty boolean attribute did not disable input.');
	$input->attribute('disabled', null);
	verify($input->attribute('disabled') === null && $input->enabled(), 'Null attribute assignment did not remove it.');
	verify($browser->script('return [events.input, events.change];') === [0, 0], 'Setters dispatched events.');
	echo "PASS: property and attribute setters, explicit values, and event behavior\n";

	$checkbox = $browser->find('#checkbox');
	verify($checkbox->check()->check() === $checkbox && $checkbox->selected(), 'Check failed.');
	verify($checkbox->uncheck()->uncheck() === $checkbox && !$checkbox->selected(), 'Uncheck failed.');
	$radio = $browser->find('#radio')->check()->check();
	$browser->find('#radio2')->check()->check();
	verify(!$radio->selected() && $browser->find('#radio2')->selected(), 'Native radio group behavior changed.');
	verify($browser->script('return [events.click, events.input, events.change];') === [4, 4, 4], 'Check actions did not click exactly when needed.');
	expectError(fn() => $radio->uncheck(), 'requires a checkbox input');
	expectError(fn() => $input->check(), 'requires a checkbox or radio input');
	expectError(fn() => $form->uncheck(), 'requires a checkbox input');
	echo "PASS: idempotent checkbox/radio actions and native events\n";

	$container = $browser->find('#container');
	$first = $container->firstChild();
	$last = $container->lastChild();
	verify($first->attribute('id') === 'first' && $last->attribute('id') === 'last', 'Child traversal included non-elements.');
	verify($first->nextSibling()->id() === $last->id() && $last->previousSibling()->id() === $first->id(), 'Sibling traversal included non-elements.');
	verify($first->previousSibling() === null && $last->nextSibling() === null, 'Missing siblings did not return null.');
	$empty = $browser->find('#empty');
	verify($empty->firstChild() === null && $empty->lastChild() === null && !$first->canRefresh(), 'Empty traversal or lookup retention changed.');
	foreach (['beforebegin', 'afterbegin', 'beforeend', 'afterend'] as $where):
		verify($container->inject('<b id="' . $where . '">inserted</b>', $where) === $container, 'HTML injection lost receiver.');
	endforeach;
	verify($container->previousSibling()->attribute('id') === 'beforebegin'
		&& $container->firstChild()->attribute('id') === 'afterbegin'
		&& $container->lastChild()->attribute('id') === 'beforeend'
		&& $container->nextSibling()->attribute('id') === 'afterend', 'HTML insertion position changed.');
	$container->inject('<button id="injected" type="button">Injected</button><script>window.markupRan = true;</script>');
	verify($browser->script('return window.markupRan === undefined;'), 'Injected script markup executed.');
	// Drivers classify this DOM SyntaxError differently; both must propagate the failure.
	expectError(fn() => $container->inject('', 'invalid'), 'WebDriver HTTP');
	verify($browser->script('document.querySelector("#injected").addEventListener("click", () => window.listenerRan = true);', inject: true) === null, 'Script injection did not return null.');
	$browser->find('#injected')->click();
	verify($browser->script('return window.listenerRan;') === true, 'Inline injection did not register its listener.');
	echo "PASS: element-only traversal, HTML positions, inert markup, and executable script injection\n";

	$window = $browser->window();
	$inside = $browser->switchFrame($browser->find('#frame'))->find('#inside');
	$browser->open();
	$child = $inside->firstChild();
	verify($child->attribute('id') === 'child' && !$child->canRefresh(), 'Traversal lost captured window/frame.');
	$browser->defaultFrame();
	$inside->attribute('data-context', 'frame')->property('custom', null)->inject('<span id="appended"></span>');
	verify($inside->lastChild()->attribute('id') === 'appended', 'Mutation lost captured frame.');
	verify($window->script('window.frameInjected = true;', inject: true) === null, 'Window script injection failed.');
	verify($window->script('return window.frameInjected && window !== window.top;'), 'Script injection executed outside selected frame.');
	$window->script('arguments[0].remove();', arguments: [$child]);
	expectError(fn() => $child->nextSibling(), 'stale element reference');
	echo "PASS: captured window/frame context and stale traversal errors\n";

	$browser->navigate('data:text/html,' . rawurlencode('<!doctype html><meta http-equiv="Content-Security-Policy" content="script-src \'none\'"><div>CSP</div>'));
	$browser->script('window.cspInjected = true;', inject: true);
	verify($browser->script('return window.cspInjected === undefined && document.scripts.length === 1;'), 'Script injection did not respect CSP.');
	echo "PASS: inline injection respects CSP\n";
} finally {
	$driver->stop();
}
