<?php

// Run with: php test/webdriver-target-browser.php [chrome|firefox]. Uses isolated data: pages.
require __DIR__ . '/fixtures/test-runner.php';

use ThriveData\ThrivePHP\WebDriver\Browser;
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
        <input id="first"><input id="second"><button id="opener">Open</button>
        <dialog id="dialog"><input id="dialog-input" autofocus><button id="done">Done</button></dialog>
        <div id="rows"></div><div id="host"></div>
        <script>
        window.events = [];
        for (const type of ['keydown', 'keyup']) document.addEventListener(type, event => {
            window.events.push({type, key: event.key, meta: event.metaKey, shift: event.shiftKey});
            if (event.metaKey && event.shiftKey && event.key.toLowerCase() === 'a') event.preventDefault();
        });
        const root = document.querySelector('#host').attachShadow({mode: 'open'});
        root.innerHTML = '<div id="inner"></div>';
        const closed = root.querySelector('#inner').attachShadow({mode: 'closed'});
        closed.innerHTML = '<input id="deep">';
        window.deep = closed.querySelector('input');
        document.querySelector('#opener').onclick = () => document.querySelector('#dialog').showModal();
        document.querySelector('#done').onclick = () => document.querySelector('#dialog').close('done');
        window.dialogCloses = 0;
        document.querySelector('#dialog').addEventListener('close', () => window.dialogCloses++);
        </script>
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

    $firstInput = $browser->find('#first');
    $secondInput = $browser->find('#second');
    check($firstInput->focus() === $firstInput && $firstInput->active(), 'focus()/active() failed.');
    $browser->tap(Browser::TAB);
    check($secondInput->active() && $firstInput->active(is: false), 'Tab did not preserve and advance focus.');
    $browser->window()->tap(Browser::SHIFT, Browser::TAB);
    check($firstInput->active(), 'Shift+Tab did not move focus back.');
    $browser->script('window.events = []');
    check($browser->tap(Browser::META, Browser::SHIFT, 'a') === $browser, 'tap() lost browser receiver.');
    $events = $browser->script('return window.events');
    check(count($events) === 6 && array_column($events, 'type') === ['keydown', 'keydown', 'keydown', 'keyup', 'keyup', 'keyup'],
        'Chord did not press in order and release in reverse.');
    check(strtolower($events[2]['key']) === 'a' && $events[2]['meta'] && $events[2]['shift']
        && strtolower($events[3]['key']) === 'a' && $events[3]['meta'] && $events[3]['shift'], 'Letter events lost held modifiers.');
    $browser->tap('b');
    $events = $browser->script('return window.events');
    check(!$events[6]['meta'] && !$events[6]['shift'] && $firstInput->value() === 'b', 'Chord cleanup left a modifier held.');

    $deep = $browser->script('return window.deep');
    check($deep->focus()->active() && $browser->find('#host')->active(), 'Nested closed shadow focus was not recognized.');
    $firstInput->focus();
    check($deep->active(is: false), 'Inactive shadow descendant remained active.');

    $dialog = $browser->find('#dialog');
    $dialogInput = $dialog->find('#dialog-input');
    check($dialog->open() === $dialog && $dialog->visible() && $dialogInput->active(), 'Modal opening lost focus or visibility.');
    check($browser->script('return arguments[0].matches(":modal")', arguments: [$dialog]), 'open() was not modal by default.');
    check($dialog->close('saved') === $dialog && $dialog->visible(is: false) && $dialog->property('returnValue') === 'saved',
        'Dialog close lost its return value or receiver.');
    $browser->wait(fn() => $browser->script('return window.dialogCloses') === 1);
    $dialog->open(modal: false);
    check(!$browser->script('return arguments[0].matches(":modal")', arguments: [$dialog]), 'Nonmodal opening became modal.');
    $dialog->close();
    check($dialog->property('returnValue') === 'saved', 'Null return value should leave the previous dialog value.');
    $dialog->open()->close('');
    check($dialog->property('returnValue') === '', 'Empty return value was omitted.');
    $browser->find('#opener')->focus();
    $browser->tap(Browser::ENTER);
    $dialogInput->active(assert: true);
    $browser->tap(Browser::ESCAPE);
    $dialog->visible(is: false, assert: true);
    $browser->find('#opener')->active(assert: true);
    echo 'PASS: native ' . ($argv[1] ?? 'chrome') . ' lazy targets, exact snapshots, focus, chords and dialogs' . PHP_EOL;
} finally {
    $driver->stop();
}
