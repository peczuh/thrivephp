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
        $browser->titleMatches('~^Saved [0-9]+$~')->titleContains('Saved')->titleEquals('Pending', invert: true);
        $browser->urlContains('data:text/html,')->urlEquals('https://example.test/', invert: true);

        $input = $browser->find('#name');
        $browser->script("setTimeout(() => document.querySelector('#name').disabled = false, 100)");
        $input->value('Saved 42')->enabledEquals()->valueContains('Saved')->propertyMatches('value', '~42$~');
        $browser->script("setTimeout(() => document.querySelector('#check').disabled = false, 100)");
        $checkbox->check()->selectedEquals()->uncheck()->selectedEquals(false);
        $browser->script("setTimeout(() => document.querySelector('#select').insertAdjacentHTML('beforeend', '<option value=ready>Ready</option>'), 100)");
        $browser->find('#select')->select('ready')->valueEquals('ready');

        $element = $browser->find('#status');
        $old = $element->id();
        $browser->script(<<<'JS'
            setTimeout(() => {
                document.querySelector('#status').outerHTML = '<div id="status" class="ready saved" data-state="Saved 42" style="display:block">Saved 42<span>!</span></div>';
                document.querySelector('#status').items = ['a', 'b', 'c'];
            }, 100);
            JS);
        $element->hasClass(['ready', 'saved'])->textMatches('~^Saved 42~')->cssEquals('display', 'block')
            ->hasAttribute('data-state')->attributeContains('data-state', 'Saved')->hasDescendants()
            ->propertyContains('items', ['a', 'b'])->propertyEquals('items', ['a', 'b', 'c']);
        check($element->id() !== $old, 'Successful comparison did not adopt the replacement node.');
        $browser->find('#empty')->hasDescendants(invert: true)->hasClass('absent', invert: true)->hasAttribute('absent', invert: true);

        $shadowChild = $browser->find('#host')->shadowRoot()->find('span');
        $browser->script(<<<'JS'
            setTimeout(() => {
                document.querySelector('#host').outerHTML = '<div id="host"></div>';
                document.querySelector('#host').attachShadow({mode:'open'}).innerHTML = '<span class="ready">Saved</span>';
            }, 100);
            JS);
        $shadowChild->hasClass('ready')->textEquals('Saved');

        $browser->switchFrame($browser->find('#frame'));
        $inside = $browser->find('#inside');
        $browser->script("setTimeout(() => document.querySelector('#inside').outerHTML = '<div id=inside class=ready>Saved</div>', 100)");
        $browser->defaultFrame();
        $inside->hasClass('ready')->textEquals('Saved');
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

        $old = $element->id();
        $browser->script("setTimeout(() => document.querySelector('#status').remove(), 100)");
        check($element->wait(invert: true, assert: true) === $element && $element->id() === $old, 'Original-node wait changed identity.');
        raises(fn() => $element->wait(verify: true), 'stale element reference');
        raises(fn() => $browser->wait(fn() => false, timeout: 10), 'wait timed out');
        check($browser->find('#missing', timeout: 10, verify: true) === null, 'Failed verified lookup returned an element.');
        $browser->script("document.querySelector('#empty').hidden = true");
        $hidden = $browser->find('#empty');
        check($hidden->wait(visible: true, timeout: 10, verify: true) === $hidden, 'Verified wait lost receiver.');
        $input->valueEquals('Different', timeout: 10, verify: true);
        $browser->titleEquals('Saved 42', wait: false);
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
