<?php

// Run with: php test/test-selectors.php.
require __DIR__ . '/fixtures/test-runner.php';

use ThriveData\ThrivePHP\Test as TestRunner;
use ThriveData\ThrivePHP\Test\Selector;
use ThriveData\ThrivePHP\Test\Suite;
use ThriveData\ThrivePHP\Test\SuiteConfig;
use ThriveData\ThrivePHP\Test\Test;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

foreach (['', '/'] as $prefix) {
    $selector = new Selector($prefix . 'folderA/(folder1|folder2)!!/archive/::^case:!Deprecated$##^estimates#!^slow$');
    check($selector->file('folderA/folder1/check') && $selector->file('nested/folderA/folder2/check')
        && !$selector->file('folderA/folder1/archive/check') && !$selector->file('folderB/check'), 'File conditions changed.');
    check($selector->test('caseCreate3', ['estimates', 'smoke'])
        && !$selector->test('caseDeprecated', ['estimates'])
        && !$selector->test('other', ['estimates'])
        && !$selector->test('caseCreate3', ['smoke'])
        && !$selector->test('caseCreate3', ['estimates', 'slow']), 'Case/tag conditions changed.');
}
check((new Selector('//folder'))->file('a/folder') && !(new Selector('//folder'))->file('folder'), 'Strip exactly one slash.');
check((new Selector('folder/'))->file('folder/check') && !(new Selector('folder/'))->file('folder'), 'Trailing slash is regex text.');
check((new Selector('^check$'))->file('check') && !(new Selector('^check$'))->file('nested/check'), 'Anchors changed.');
check((new Selector('(?i)^CHECK$'))->file('check'), 'Inline regex options failed.');
check((new Selector('^\Qfolder/~#@%\E$'))->file('folder/~#@%'), 'Quoted punctuation was rewritten.');
check((new Selector('^folder/\x23\x23tag$'))->file('folder/##tag'), 'Encoded structural delimiter failed.');
echo "PASS: six selector components, optional leading slash, anchors, inline options, and raw regex punctuation\n";

foreach (['!!', '::', ':!', '##', '#!'] as $delimiter) {
    $selector = new Selector($delimiter . 'a' . $delimiter . 'b');
    $value = 'a' . $delimiter . 'b';
    $matched = match ($delimiter) {
        '!!' => !$selector->file($value) && $selector->file('other'),
        '::' => $selector->test($value, []) && !$selector->test('a', []),
        ':!' => !$selector->test($value, []) && $selector->test('a', []),
        '##' => $selector->test('method', [$value]) && !$selector->test('method', ['a']),
        '#!' => !$selector->test('method', [$value]) && $selector->test('method', ['a']),
    };
    check($matched, 'Repeated delimiter was consumed: ' . $delimiter);
}
check((new Selector('::^a!!b$'))->test('a!!b', []), 'Earlier delimiter in case include was split.');
check(!(new Selector(':!^a::b!!c$'))->test('a::b!!c', []), 'Earlier delimiters in case exclude were split.');
check((new Selector('##^a:!b::c!!d$'))->test('method', ['a:!b::c!!d']), 'Earlier delimiters in tag include were split.');
check(!(new Selector('#!^a##b:!c::d!!e$'))->test('method', ['a##b:!c::d!!e']), 'Earlier delimiters in tag exclude were split.');
check((new Selector('##^tag#!^tag1$'))->test('method', ['tag2'])
    && !(new Selector('##^tag#!^tag1$'))->test('method', ['tag1', 'tag2']), 'Exclusion must inspect every tag.');
check((new Selector('!!archive:!old#!slow'))->test(null, [])
    && !(new Selector('::.*'))->test(null, []) && !(new Selector('##.*'))->test(null, []), 'Callable selection changed.');
echo "PASS: each delimiter splits once, earlier delimiters remain regex text, and exclusions reject any matching tag\n";

foreach (['', '/', '!!', '::', ':!', '##', '#!', 'file::##tag', '[', '!![', '::[', ':![', '##[', '#!['] as $invalid) {
    try {
        new Selector($invalid);
        throw new RuntimeException('Invalid selector accepted: ' . $invalid);
    } catch (InvalidArgumentException $error) {
        check(str_contains($error->getMessage(), 'selector'), $error->getMessage());
    }
}
try {
    (new Selector('::(*LIMIT_MATCH=1)(*NO_JIT)^(a+)+$'))->test(str_repeat('a', 100) . 'b', []);
    throw new RuntimeException('Runtime PCRE failure became a nonmatch.');
} catch (InvalidArgumentException $error) {
    check(str_contains($error->getMessage(), 'cases include') && str_contains($error->getMessage(), 'Backtrack'), $error->getMessage());
}
$loaded = false;
foreach ([['^check$', '##['], [1], [[]], ['']] as $selectors) {
    [$status, $output] = runChecks(['check' => function () use (&$loaded) { $loaded = true; }], selectors: $selectors);
    check($status === 1 && !$loaded && str_contains($output, 'ERROR'), $output);
}
echo "PASS: empty components, invalid types/patterns, and runtime regex failures report errors before execution\n";

foreach ([['(*LIMIT_MATCH=1)(*NO_JIT)^s.*e$'], ['^suite$', '(*LIMIT_MATCH=1)(*NO_JIT)^s.*e$']] as $selectors) {
    [$status, $output] = runChecks(['suite' => function () { throw new RuntimeException('File loaded after regex failure.'); }], selectors: $selectors);
    check($status === 1 && str_contains($output, 'Backtrack limit exhausted')
        && !str_contains($output, 'File loaded after regex failure.'), $output);
}
echo "PASS: runtime file regex failures are reported even after an earlier alternative selected the file\n";

$make = function ($test) {
    return new #[Test('Suite description', tags: ['suite'])] class($test) extends Suite {
        protected function configure(SuiteConfig $config): void { $GLOBALS['selectorTrace'][] = 'configure'; }
        #[Test('A long form description', tags: ['smoke'])]
        public function create3(): void { $GLOBALS['selectorTrace'][] = 'create3'; }
        #[Test('Another description', tags: ['slow'])]
        public function createDeprecated(): void { $GLOBALS['selectorTrace'][] = 'createDeprecated'; }
    };
};
foreach ([
    [['::^create.*[0-9]$'], ['configure', 'create3']],
    [['::^create:!Deprecated$##^suite$#!^slow$'], ['configure', 'create3']],
    [['::^A long form description$'], []],
    [['::^create3$', '::^create3$##smoke'], ['configure', 'create3']],
    [['::^create#!slow', '::Deprecated$'], ['configure', 'create3', 'createDeprecated']],
    [['##missing'], []],
] as [$selectors, $expected]) {
    $GLOBALS['selectorTrace'] = [];
    [$status, $output] = runChecks(['suite' => $make], selectors: $selectors);
    check($status === 0 && $GLOBALS['selectorTrace'] === $expected, $output . json_encode($GLOBALS['selectorTrace']));
}
echo "PASS: method names are independent of descriptions, overlapping selectors run once, and exclusions stay local\n";

$root = sys_get_temp_dir() . '/thrive-selectors-' . bin2hex(random_bytes(6));
mkdir($root . '/checks/folderA/archive', 0700, true);
try {
    file_put_contents($root . '/checks/folderA/one.php', '<?php return ($GLOBALS["selectorFactory"])($test);');
    file_put_contents($root . '/checks/folderA/archive/two.php', '<?php throw new RuntimeException("Excluded file loaded.");');
    $GLOBALS['selectorFactory'] = $make;
    $test = new FixtureTest($root, '/checks');
    foreach ([
        [[], ['^folderA/!!/archive/::^create3$'], ['configure', 'create3']],
        [['folderA/one'], ['::Deprecated$'], ['configure', 'createDeprecated']],
        [['folderA/one'], ['^folderA/archive/'], []],
        [[], ['^missing$'], []],
    ] as [$names, $selectors, $expected]) {
        $GLOBALS['selectorTrace'] = [];
        ob_start();
        try {
            $status = $test->run(names: $names, workers: 1, selectors: $selectors);
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        check($status === 0 && $GLOBALS['selectorTrace'] === $expected, $output);
        if (!$expected) check(str_contains($output, 'Suites: 0 passed, 0 failed, 0 skipped'), $output);
    }
    ob_start();
    try {
        $empty = new FixtureTest($root, '/checks/folderA/archive/empty');
        mkdir($root . '/checks/folderA/archive/empty');
        $status = $empty->run(workers: 1, selectors: ['.*']);
        $output = ob_get_contents();
    } finally {
        ob_end_clean();
        rmdir($root . '/checks/folderA/archive/empty');
    }
    check($status === 0 && str_contains($output, 'Suites: 0 passed, 0 failed, 0 skipped'), $output);
} finally {
    unlink($root . '/checks/folderA/one.php');
    unlink($root . '/checks/folderA/archive/two.php');
    rmdir($root . '/checks/folderA/archive');
    rmdir($root . '/checks/folderA');
    rmdir($root . '/checks');
    rmdir($root);
    unset($GLOBALS['selectorFactory'], $GLOBALS['selectorTrace']);
}
echo "PASS: relative path filters exclude files before loading, intersect exact names, and allow zero checks\n";
