<?php

// Run with: php test/test-attributes.php.
require __DIR__ . '/fixtures/test-runner.php';

use ThriveData\ThrivePHP\Test\Test;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function runAttributes(string $mode, array $selectors = []): array
{
    $GLOBALS['attributeTrace'] = $GLOBALS['attributeAttempts'] = [];
    return runChecks(['suite' => function ($test) use ($mode) {
        $name = 'suite';
        return require __DIR__ . '/fixtures/test-attributes-suite.php';
    }], selectors: $selectors);
}

foreach (['case-retry', 'case-error', 'case-before', 'case-after', 'case-deadline'] as $mode) {
    [$status, $output] = runAttributes('attribute-' . $mode);
    check($status === 0 && str_contains($output, 'RETRY Attribute suite > first (attempt 2)')
        && str_contains($output, 'PASS Attribute suite > first (attempt 2)')
        && str_contains($output, 'Cases: 2 passed, 0 failed, 1 skipped')
        && str_contains($output, 'Assertions: 4 passed, 0 failed'), $output);
    $events = array_column($GLOBALS['attributeTrace'], 'event');
    check(array_count_values($events)['prepare'] === 3 && array_count_values($events)['clean'] === 3
        && array_count_values($events)['setup'] === 1 && array_count_values($events)['cleanup'] === 1,
        'Case attempts did not repeat their hooks independently: ' . json_encode($events));
}
echo "PASS: case retries repeat case hooks, handle assertions/errors/deadlines, and count only the final attempt\n";

foreach (['suite-retry', 'suite-before', 'suite-after', 'config', 'suite-deadline'] as $mode) {
    [$status, $output] = runAttributes('attribute-' . $mode);
    check($status === 0 && str_contains($output, "RETRY Attribute suite (attempt 2)\n")
        && str_contains($output, "PASS Attribute suite (attempt 2)\n")
        && str_contains($output, 'Cases: 2 passed, 0 failed, 1 skipped')
        && str_contains($output, 'Assertions: 4 passed, 0 failed'), $output);
    $configuration = array_values(array_filter($GLOBALS['attributeTrace'], fn ($event) => $event['event'] === 'configure'));
    check(count($configuration) === 2 && $configuration[1]['attempt'] === 2
        && $configuration[1]['prepared'] === 0 && $configuration[1]['bodies'] === 0,
        'Suite retry retained instance state: ' . json_encode($configuration));
    check(count(array_filter($GLOBALS['attributeTrace'], fn ($event) => $event['event'] === 'cleanup')) === 2,
        'A suite attempt missed cleanup.');
}
echo "PASS: whole-suite retries reload fresh instances after configuration, setup, body, cleanup, and deadline failures\n";

[$status, $output] = runAttributes('attribute-always-fail');
check($status === 1 && substr_count($output, 'RETRY Attribute suite > first') === 2
    && substr_count($output, "RETRY Attribute suite (attempt 2)\n") === 1
    && str_contains($output, 'Cases: 1 passed, 1 failed, 1 skipped')
    && str_contains($output, 'Assertions: 3 passed, 1 failed'), $output);
check(count(array_filter($GLOBALS['attributeTrace'], fn ($event) => $event['event'] === 'first')) === 4,
    'Nested suite/case retry limits changed.');
echo "PASS: exhausted case retries precede suite retries and final failures are counted once\n";

foreach ([
    [[], ['first', 'second'], 1],
    [['##^smoke$'], ['first'], 0],
    [['##^(smoke|slow)$'], ['first', 'second'], 0],
    [['##^suite$#!^slow$'], ['first'], 1],
    [['#!^(smoke|skip)$'], ['second'], 0],
] as [$selectors, $bodies, $skips]) {
    [$status, $output] = runAttributes('attribute-normal', $selectors);
    check($status === 0 && str_contains($output, 'Cases: ' . count($bodies) . " passed, 0 failed, $skips skipped"), $output);
    $actual = array_values(array_filter(array_column($GLOBALS['attributeTrace'], 'event'), fn ($event) => in_array($event, ['first', 'second'], true)));
    check($actual === $bodies, 'Tag selection ran incorrect cases: ' . json_encode($actual));
    check(count(array_filter($GLOBALS['attributeTrace'], fn ($event) => $event['event'] === 'prepare')) === count($bodies),
        'Filtered/skipped cases ran hooks.');
}
foreach ([['##^Smoke$'], ['#!^suite$'], ['##^smoke$#!^smoke$']] as $selectors) {
    [$status, $output] = runAttributes('attribute-normal', $selectors);
    check($status === 0 && $GLOBALS['attributeTrace'] === []
        && str_contains($output, 'Checks: 0 passed, 0 failed, 0 skipped')
        && !str_contains($output, 'Attribute suite'), $output);
}
echo "PASS: suite/case tag union and regex inclusion/exclusion filter before hooks\n";

foreach ([['attribute-skip', [], 1], ['attribute-normal', ['##^skip$'], 1]] as [$mode, $selectors, $skips]) {
    [$status, $output] = runAttributes($mode, $selectors);
    check($status === 0 && $GLOBALS['attributeTrace'] === []
        && str_contains($output, 'Checks: 0 passed, 0 failed, 1 skipped')
        && str_contains($output, "Cases: 0 passed, 0 failed, $skips skipped")
        && !str_contains($output, 'RETRY') && !str_contains($output, 'No assertions'), $output);
}
echo "PASS: explicit suite skips and all-skipped selections avoid configuration and every lifecycle hook\n";

$ran = false;
$checks = ['check' => function () use (&$ran) { $ran = true; }];
[$status, $output] = runChecks($checks, selectors: ['##smoke']);
check($status === 0 && !$ran && str_contains($output, 'Checks: 0 passed, 0 failed, 0 skipped'), $output);
[$status, $output] = runChecks($checks, selectors: ['#!slow']);
check($status === 0 && $ran && str_contains($output, 'PASS check (No assertions)'), $output);
[$status, $output] = runChecks(['suite' => function ($test) {
    $test->verify(false, 'file loading failed');
    return static function () { throw new RuntimeException('Filtered callable ran.'); };
}], selectors: ['##smoke']);
check($status === 1 && str_contains($output, 'file loading failed')
    && str_contains($output, 'Checks: 0 passed, 1 failed, 0 skipped')
    && !str_contains($output, 'Filtered callable ran.'), $output);
foreach ([[''], [1], [[]]] as $tags) {
    try {
        new Test('invalid', tags: $tags);
        throw new RuntimeException('Invalid attribute tags accepted.');
    } catch (InvalidArgumentException) {}
}
try {
    new Test('invalid', retry: -1);
    throw new RuntimeException('Negative retries accepted.');
} catch (InvalidArgumentException) {}
check((new Test(description: 'A long form description'))->description === 'A long form description', 'Description argument changed.');
echo "PASS: untagged callable checks respect inclusion filters and attribute metadata rejects invalid values\n";
