<?php

// Run with: php -d short_open_tag=1 test/webdriver-contract-browser.php [chrome|firefox]. Only isolated data: pages are used.
require __DIR__ . '/fixtures/test-runner.php';

use ThriveData\ThrivePHP\WebDriver\WebDriver;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function raises(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), $message), $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected failure: ' . $message);
}

$driver = new WebDriver(browser: $argv[1] ?? 'chrome');
try {
    $browser = $driver->browser(headless: true, waitTimeout: 1500, assertionTimeout: 1500, pollInterval: 25);
    $browser->navigate('data:text/html,' . rawurlencode(<<<'HTML'
        <!doctype html><title>Pending</title>
        <input id="name" disabled><input id="check" type="checkbox" disabled>
        <select id="select"><option value="old">Old</option></select>
        <button id="button" onclick="window.clicks++">Click</button>
        <div id="status" class="pending">Pending</div><div id="empty"></div>
        <div id="host"></div><iframe id="frame" srcdoc="<div id='inside'>Pending</div>"></iframe>
        <script>window.clicks = 0; document.querySelector('#host').attachShadow({mode:'open'}).innerHTML = '<span>Pending</span>';</script>
        HTML));
    $checkbox = $browser->find('#check');
    raises(fn() => $checkbox->check(wait: false), 'disabled');
    [$status, $console, $record] = runChecks(['suite' => fn ($test) => function () use ($test, $browser, $checkbox) {
        $test->browserSession($browser->session(), fn () => $browser->screenshot());
        $browser->script("setTimeout(() => document.title = 'Saved 42', 100)");
        $browser->titleMatches('~^Saved [0-9]+$~', assert: true)->titleContains('Saved', assert: true)->titleEquals('Pending', is: false, assert: true);
        $browser->urlContains('data:text/html,', assert: true)->urlEquals('https://example.test/', is: false, assert: true);

        $input = $browser->find('#name');
        $browser->script("setTimeout(() => document.querySelector('#name').disabled = false, 100)");
        $input->value('Saved 42')->enabled(assert: true)->valueContains('Saved', assert: true)->propertyMatches('value', '~42$~', assert: true);
        $browser->script("setTimeout(() => document.querySelector('#check').disabled = false, 100)");
        $checkbox->check()->selected(assert: true)->uncheck()->selected(is: false, assert: true);
        $browser->script("setTimeout(() => document.querySelector('#select').insertAdjacentHTML('beforeend', '<option value=ready>Ready</option>'), 100)");
        $browser->find('#select')->select('ready')->valueEquals('ready', assert: true);

        $element = $browser->find('#status');
        $old = $element->id();
        $browser->script(<<<'JS'
            setTimeout(() => {
                document.querySelector('#status').outerHTML = '<div id="status" class="ready saved" data-state="Saved 42" style="display:block">Saved 42<span>!</span></div>';
                document.querySelector('#status').items = ['a', 'b', 'c'];
            }, 100);
            JS);
        $element->hasClass(['ready', 'saved'], assert: true)->textMatches('~^Saved 42~', assert: true)->cssEquals('display', 'block', assert: true)
            ->hasAttribute('data-state', assert: true)->attributeContains('data-state', 'Saved', assert: true)->hasDescendants(assert: true)
            ->propertyContains('items', ['a', 'b'], assert: true)->propertyEquals('items', ['a', 'b', 'c'], assert: true);
        check($element->id() !== $old, 'Selector did not follow the replacement node.');
        $browser->find('#empty')->hasDescendants(is: false, assert: true)->hasClass('absent', is: false, assert: true)->hasAttribute('absent', is: false, assert: true);

        $shadowChild = $browser->find('#host')->shadowRoot()->find('span');
        $browser->script(<<<'JS'
            setTimeout(() => {
                document.querySelector('#host').outerHTML = '<div id="host"></div>';
                document.querySelector('#host').attachShadow({mode:'open'}).innerHTML = '<span class="ready">Saved</span>';
            }, 100);
            JS);
        $shadowChild->hasClass('ready', assert: true)->textEquals('Saved', assert: true);

        $browser->switchFrame($browser->find('#frame'));
        $inside = $browser->find('#inside');
        $browser->script("setTimeout(() => document.querySelector('#inside').outerHTML = '<div id=inside class=ready>Saved</div>', 100)");
        $browser->defaultFrame();
        $inside->hasClass('ready', assert: true)->textEquals('Saved', assert: true);
        $browser->defaultFrame();

        $button = $browser->find('#button');
        $browser->script(<<<'JS'
            const overlay = document.createElement('div');
            overlay.style.cssText = 'position:fixed;inset:0;z-index:999;background:white';
            document.body.append(overlay);
            setTimeout(() => overlay.remove(), 150);
            JS);
        $button->click();
        check($browser->script('return window.clicks') === 1, 'Native action was replayed after success.');

        $original = $element->resolve();
        $old = $original->id();
        $browser->script("setTimeout(() => document.querySelector('#status').remove(), 100)");
        check($original->present(is: false, assert: true) === $original && $original->id() === $old, 'Original-node condition changed identity.');
        raises(fn() => $original->textEquals('Saved'), 'stale element reference');
        raises(fn() => $browser->wait(fn() => false, timeout: 10), 'wait timed out');
        $browser->find('#missing')->present(assert: true, stop: false, timeout: 10);
        $browser->script("document.querySelector('#empty').hidden = true");
        $hidden = $browser->find('#empty');
        check($hidden->visible(assert: true, stop: false, timeout: 10) === $hidden, 'Verified condition lost receiver.');
        $input->valueEquals('Different', assert: true, stop: false, timeout: 10);
        $browser->titleEquals('Saved 42', assert: true, wait: false);
    }]);
    check($status === 1 && $record['totals']['assertionsFailed'] === 3, $console);
    $steps = $record['suites'][0]['attempts'][0]['steps'];
    $failures = array_filter($steps, fn ($step) => $step['assertions'] && !$step['assertions'][0]['passed']);
    check(count($failures) === 3, 'Verification did not record three final failures.');
    foreach ($failures as $step) {
        check($step['error'] === null && count($step['screenshots']) === 1
            && $step['location'] === $step['assertions'][0]['location'], 'Verification diagnostics or captures were duplicated.');
    }
    check(end($steps)['status'] === 'PASS', 'Execution did not continue after verification failures.');
    echo 'PASS: native ' . ($argv[1] ?? 'chrome') . ' assertion, verification, wait, retry and context contracts' . PHP_EOL;
} finally {
    $driver->stop();
}
