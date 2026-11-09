<?php

// Run with: php -d short_open_tag=1 test/settings.php.
require __DIR__ . '/../src/Exception.php';
require __DIR__ . '/../src/Settings.php';

use ThriveData\ThrivePHP\Settings;
use ThriveData\ThrivePHP\SettingsException;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = sys_get_temp_dir() . '/thrive-settings-' . bin2hex(random_bytes(6));
mkdir($root . '/local', 0700, true);
define('PATH_ROOT', $root);
try {
    file_put_contents($root . '/settings.json', <<<'JSON'
        {"test":{"basepath":"test","browser":{"capabilities":{"acceptInsecureCerts":false,"options":{"args":["one","two"],"keep":true}}}},
         "list":[1,2],"clear":[1],"object":{"keep":1},"toList":{"old":1},"toObject":[1],
         "numeric":{"0":{"keep":1,"replace":1}},"null":1,"false":true,"zero":1,"empty":"old"}
        JSON);
    file_put_contents($root . '/local/settings.json', <<<'JSON'
        {"test":{"browser":{"capabilities":{"acceptInsecureCerts":true,"options":{"args":[]}}}},
         "list":[3],"clear":[],"object":{},"toList":[],"toObject":{},
         "numeric":{"0":{"replace":2}},"null":null,"false":false,"zero":0,"empty":""}
        JSON);
    Settings::init();
    check(Settings::get('test.basepath') === 'test', 'Local overrides must preserve project siblings.');
    check(Settings::get('test.browser.capabilities') === [
        'acceptInsecureCerts' => true, 'options' => ['args' => [], 'keep' => true],
    ], 'Local nested overrides or empty list replacement failed.');
    check(Settings::get('list') === [3] && Settings::get('clear') === [], 'Lists must replace, not merge by index.');
    check(Settings::get('object') === ['keep' => 1] && Settings::get('toList') === [] && Settings::get('toObject') === [], 'Object/list changes failed.');
    check(Settings::get('numeric.0') === ['keep' => 1, 'replace' => 2], 'Numeric object keys must merge as objects.');
    check(Settings::fetch('null') === null && Settings::get('false') === false
        && Settings::get('zero') === 0 && Settings::get('empty') === '', 'Falsy overrides were lost.');
    foreach (['null', 'missing', 'zero.child'] as $path) {
        check(Settings::fetch($path) === null, 'fetch must return null for missing/null paths.');
        try {
            Settings::get($path);
            throw new RuntimeException('get accepted a missing/null path.');
        } catch (SettingsException) {}
    }

    file_put_contents($root . '/later.json', '{"toList":{"new":1},"toObject":{"new":2},"object":[],"numeric":{"1":"next"}}');
    Settings::load($root . '/later.json', false);
    check(Settings::get('toList') === ['new' => 1] && Settings::get('toObject') === ['new' => 2]
        && Settings::get('object') === [] && Settings::get('numeric.0.keep') === 1, 'Subsequent loads must retain JSON types.');
    Settings::set('', []);
    Settings::init($root . '/local/settings.json', $root . '/settings.json');
    check(Settings::get('test.browser.capabilities.acceptInsecureCerts') === false, 'Explicit file order determines precedence.');

    Settings::set('test.browser.capabilities.acceptInsecureCerts', true);
    Settings::set('test.browser.capabilities.options', ['args' => ['cli']]);
    check(Settings::get('test.basepath') === 'test' && Settings::get('test.browser.capabilities.options') === ['args' => ['cli']], 'set must preserve siblings and replace the chosen subtree.');
    Settings::set('created.deep.value', null);
    Settings::set('zero.child', false);
    Settings::set('list.0', 42);
    check(Settings::get('created.deep') === ['value' => null] && Settings::get('zero.child') === false
        && Settings::get('list.0') === 42, 'set must create intermediates and support existing list indices.');
    $object = (object)['child' => (object)['value' => 1]];
    Settings::set('copy', $object);
    $object->child->value = 2;
    $copy = Settings::get('copy');
    $copy['child']['value'] = 3;
    check(Settings::get('copy.child.value') === 1, 'Callers must not mutate internal objects through references.');
    Settings::set('numeric', (object)['0' => (object)['keep' => true]]);
    Settings::load($root . '/settings.json', false);
    check(Settings::get('numeric.0.keep') === 1, 'Explicit numeric object keys must survive set and load.');
    Settings::set('', ['only' => false]);
    check(Settings::get('only') === false && Settings::fetch('test') === null, 'Root replacement must remove previous settings.');
    Settings::set('', []);
    Settings::load($root . '/absent.json', true);
    check(Settings::fetch('only') === null, 'Root reset failed.');
    Settings::set('object', []);
    Settings::set('object.local', true);
    Settings::set('numeric', ['first']);
    Settings::set('numeric.2', 'third');
    Settings::set('list', ['first']);
    Settings::set('list.1', 'second');
    Settings::load($root . '/settings.json', false);
    check(Settings::get('object') === ['local' => true, 'keep' => 1]
        && Settings::get('numeric.2') === 'third', 'Named and sparse list writes must become mergeable objects.');
    check(Settings::get('list') === [1, 2], 'Sequential list writes must retain list replacement semantics.');
    echo "PASS: settings file precedence, deep merges, JSON types, typed runtime overrides and lookup isolation\n";
} finally {
    foreach (glob($root . '/local/*') as $file) unlink($file);
    rmdir($root . '/local');
    foreach (glob($root . '/*') as $file) unlink($file);
    rmdir($root);
}
