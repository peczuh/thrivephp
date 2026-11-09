<?php

// Included by webdriver.php after its protocol helpers are declared.
use ThriveData\ThrivePHP\WebDriver\Browser;
use ThriveData\ThrivePHP\WebDriver\Element;
use ThriveData\ThrivePHP\WebDriver\ElementSet;
use ThriveData\ThrivePHP\WebDriver\Step;

foreach (require __DIR__ . '/webdriver-assertions.php' as $method => [$arguments, $path, $good, $bad]):
    $document = in_array($path, ['/url', '/title'], true);
    foreach ($document ? ['browser', 'window'] : ['element'] as $scope):
        scenario("$scope $method boolean queries do not report or record steps", function ($listener) use ($document, $path, $good, $bad) {
            createSession($listener);
            foreach ([$good, $bad, $bad, $good, $bad, $good, $bad] as $value):
                if (!$document) exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#target'], [Element::REFERENCE => 'node']);
                comparisonExchange($listener, $path, $value, $document, 'node');
            endforeach;
            exchange($listener, 'DELETE', '/session/session%2F1', null, null);
        }, function ($url) use ($scope, $method, $arguments) {
            $browser = new Browser($url, 'firefox', pollInterval: 10);
            $target = match ($scope) { 'browser' => $browser, 'window' => $browser->window(), default => $browser->find('#target') };
            [$status, $output, $record] = runChecks(['check' => function () use ($target, $method, $arguments) {
                verify($target->$method(...$arguments) === true, 'Matching default query did not return true.');
                verify($target->$method(...$arguments) === false, 'Mismatching default query did not return false.');
                verify($target->$method(...[...$arguments, 'is' => false]) === true, 'Negated query failed.');
                verify($target->$method(...[...$arguments, 'is' => false]) === false, 'Negated query matched.');
                verify($target->$method(...[...$arguments, 'wait' => true, 'timeout' => 300]) === true, 'Waiting query did not retry.');
                verify($target->$method(...[...$arguments, 'wait' => true, 'timeout' => 1]) === false, 'Expired query did not return false.');
            }]);
            verify($status === 0 && $record['totals']['assertionsPassed'] === 0 && $record['totals']['assertionsFailed'] === 0
                && $record['suites'][0]['attempts'][0]['steps'] === [], 'Boolean queries recorded results: ' . $output);
            $browser->quit();
        });
    endforeach;
endforeach;

scenario('targets stay lazy, exact nodes stay fixed, and command arguments resolve once', function ($listener) {
    createSession($listener);
    exchange($listener, 'GET', '/session/session%2F1/title', null, 'Before lookup');
    $selector = ['using' => 'css selector', 'value' => '#target'];
    exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => 'original']);
    exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => 'replacement']);
    exchange($listener, 'GET', '/session/session%2F1/element/replacement/text', null, 'new');
    exchange($listener, 'GET', '/session/session%2F1/element/original/name', null, ['error' => 'stale element reference', 'message' => 'removed'], 404);
    exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => 'script']);
    scriptExchange($listener, [[Element::REFERENCE => 'script'], ['node' => [Element::REFERENCE => 'script']]], true);
    exchange($listener, 'POST', '/session/session%2F1/element', $selector, [Element::REFERENCE => 'action']);
    exchange($listener, 'POST', '/session/session%2F1/actions', ['actions' => [[
        'type' => 'pointer', 'id' => 'mouse', 'actions' => [
            ['type' => 'pointerMove', 'origin' => [Element::REFERENCE => 'action'], 'x' => 0, 'y' => 0],
            ['type' => 'pointerMove', 'origin' => [Element::REFERENCE => 'action'], 'x' => 1, 'y' => 1],
        ],
    ]]], null);
    exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
    $browser = new Browser($url, 'firefox');
    $target = $browser->find('#target');
    $child = $target->find('.child');
    $rows = $child->findAll('.row');
    $rows->get(50);
    Step::value([$target, $child, $rows]);
    verify($browser->title() === 'Before lookup', 'Construction or reporting searched the DOM.');
    $original = $target->resolve();
    verify($original->resolve(wait: true, timeout: -1) === $original && $original->id() === 'original', 'Exact resolve performed I/O or validated an unused timeout.');
    verify($target->text() === 'new' && !$original->present(), 'Search identity was cached or exact identity changed.');
    verify($browser->script('return arguments[0] === arguments[1].node', arguments: [$target, (object)['node' => $target]]), 'Script result changed.');
    $browser->actions([['type' => 'pointer', 'id' => 'mouse', 'actions' => [
        ['type' => 'pointerMove', 'origin' => $target, 'x' => 0, 'y' => 0],
        ['type' => 'pointerMove', 'origin' => $target, 'x' => 1, 'y' => 1],
    ]]]);
    $browser->quit();
});

scenario('nested target resolution shares the condition deadline', function ($listener) {
    createSession($listener);
    exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#parent'], [Element::REFERENCE => 'parent'], 200, 0.05);
    exchange($listener, 'POST', '/session/session%2F1/element/parent/element', ['using' => 'css selector', 'value' => '.child'], ['error' => 'no such element', 'message' => 'missing'], 404);
    exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
    $browser = new Browser($url, 'firefox', waitTimeout: 2000, pollInterval: 10);
    verify(!$browser->find('#parent')->find('.child')->textEquals('text', is: false, wait: true, timeout: 30),
        'Nested lookup exceeded the shared retry budget or matched absence.');
    $browser->quit();
});

scenario('actions retry a missing child under an exact parent', function ($listener) {
    createSession($listener);
    $selector = ['using' => 'css selector', 'value' => '.child'];
    exchange($listener, 'POST', '/session/session%2F1/element/parent/element', $selector, ['error' => 'no such element', 'message' => 'not yet'], 404);
    exchange($listener, 'POST', '/session/session%2F1/element/parent/element', $selector, [Element::REFERENCE => 'child']);
    exchange($listener, 'POST', '/session/session%2F1/element/child/click', [], null);
    exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
    $browser = new Browser($url, 'firefox', waitTimeout: 300, pollInterval: 10);
    $child = (new Element($browser->window(), 'parent'))->find('.child');
    verify($child->click() === $child, 'Missing child was not retried under its exact parent.');
    $browser->quit();
});

scenario('script arguments share one acquisition deadline before dispatch', function ($listener) {
    createSession($listener);
    exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#first'], [Element::REFERENCE => 'first'], 200, 0.05);
    exchange($listener, 'POST', '/session/session%2F1/element', ['using' => 'css selector', 'value' => '#second'], ['error' => 'no such element', 'message' => 'missing'], 404);
    exchange($listener, 'DELETE', '/session/session%2F1', null, null);
}, function ($url) {
    $browser = new Browser($url, 'firefox', waitTimeout: 30, pollInterval: 10);
    expectError(fn() => $browser->script('return true', arguments: [$browser->find('#first'), $browser->find('#second')]), 'wait timed out resolving command arguments');
    $browser->quit();
});
