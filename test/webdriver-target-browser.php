<?php

// Run with: php test/webdriver-target-browser.php [chrome|firefox]. Uses isolated data: pages.
require __DIR__ . '/fixtures/test-runner.php';

use ThriveData\ThrivePHP\WebDriver\ElementSet;
use ThriveData\ThrivePHP\WebDriver\WebDriver;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$driver = new WebDriver(browser: $argv[1] ?? 'chrome');
try {
    $browser = $driver->browser(headless: true, waitTimeout: 1000, assertionTimeout: 1000, pollInterval: 20);
    $browser->navigate('data:text/html,' . rawurlencode(<<<'HTML'
        <!doctype html><title>Targets</title>
        <div id="rows"></div>
        HTML));

    $later = $browser->find('#later');
    check(!$later->present() && !$later->visible() && $later->visible(is: false), 'Missing target truth values changed.');
    $browser->script("setTimeout(() => document.body.insertAdjacentHTML('beforeend', '<div id=later>first</div>'), 80)");
    check($later->text() === 'first', 'Value reader did not wait for presence.');
    $original = $later->resolve();
    check($original->resolve() === $original, 'Resolving an exact node changed identity.');
    $browser->script("document.querySelector('#later').outerHTML = '<div id=later>replacement</div>'");
    check($later->textEquals('replacement') && !$original->present() && $original->visible(is: false), 'Search and exact-node identities were mixed.');
    check($browser->script('return arguments[0] === arguments[1].node;', arguments: [$later, (object)['node' => $later]]) === true,
        'Repeated script targets did not identify the same node.');
    $missing = $browser->find('#missing');
    foreach ([true, false] as $is) {
        check(!$missing->textEquals('text', is: $is, wait: true, timeout: 30), 'Missing target satisfied a node-requiring condition.');
        try {
            $missing->enabled(is: $is);
            throw new LogicException('A one-shot node-requiring condition must throw on absence.');
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), 'no such element'), $error->getMessage());
        }
    }

    $rows = $browser->find('#rows')->findAll('.row');
    $first = $rows->first();
    check($rows->count() === 0 && !$first->present(), 'Lazy first target required a match at construction.');
    $browser->script("document.querySelector('#rows').innerHTML = '<p class=row>one</p><p class=row>two</p>'");
    check($rows->countEquals(2) && $first->text() === 'one', 'Collection did not query current membership.');
    $snapshot = new ElementSet(...$rows->all());
    $browser->script("document.querySelector('#rows').firstElementChild.remove()");
    check($rows->count() === 1 && $first->text() === 'two' && $snapshot->count() === 2 && !$snapshot->first()->present(),
        'Positional targets and materialized snapshots lost their identity rules.');
    foreach ($rows as $row) $browser->script('arguments[0].remove()', arguments: [$row]);
    check($rows->countEquals(0), 'Iteration did not materialize exact nodes.');

    echo 'PASS: native ' . ($argv[1] ?? 'chrome') . ' lazy targets and exact snapshots' . PHP_EOL;
} finally {
    $driver->stop();
}
