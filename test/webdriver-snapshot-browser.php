<?php

// Run with: php -d short_open_tag=1 test/webdriver-snapshot-browser.php [chrome|firefox|safaripreview|safari].
require __DIR__ . '/fixtures/test-runner.php';

use ThriveData\ThrivePHP\WebDriver\WebDriver;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = sys_get_temp_dir() . '/thrive-snapshot-' . bin2hex(random_bytes(8));
mkdir($root, 0700, true);
$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Résumé €</title><base href="assets/"><base href="ignored/"><style>p { color: green; }</style></head>
<body><p id="state">Original</p><a id="relative" href="next.html" onclick="return false">Next</a>
<iframe id="frame" srcdoc="<!DOCTYPE html><title>Child</title><p>Frame contents</p>"></iframe>
<script>document.querySelector('#state').textContent = 'Live DOM €'; window.snapshotMarker = 42;</script>
</body></html>
HTML;
file_put_contents($root . '/source.html', $html);
$name = $argv[1] ?? 'chrome';
$driver = new WebDriver(browser: $name);
try {
    $browser = $driver->browser(headless: !in_array($name, ['safari', 'safaripreview'], true));
    $browser->navigate('file://' . $root . '/source.html');
    $before = $browser->source();
    $base = $browser->script('return document.baseURI');
    $snapshot = $browser->snapshot();
    check($browser->source() === $before && $browser->script('return window.snapshotMarker') === 42, 'Snapshot changed the live document.');
    check(str_starts_with($snapshot->data(), '<!DOCTYPE html>') && str_contains($snapshot->data(), 'Live DOM €')
        && !str_contains($snapshot->data(), '<script') && substr_count($snapshot->data(), '<base ') === 1
        && str_contains($snapshot->data(), 'href="' . $base . '"'), 'Snapshot lost doctype, live content, script removal or resolved base URL.');

    $window = $browser->window();
    $window->switchFrame($browser->find('#frame'));
    $browser->open();
    $frame = $window->snapshot();
    check(str_contains($frame->data(), 'Frame contents') && !str_contains($frame->data(), 'Live DOM €'), 'Snapshot did not restore its window/frame.');
    $window->defaultFrame();
    $browser->script("document.querySelector('#state').textContent = 'Changed after capture'");
    check(str_contains($snapshot->data(), 'Live DOM €') && !str_contains($snapshot->data(), 'Changed after capture'), 'Snapshot was not immutable.');
    $browser->quit();
    check($snapshot->save($root . '/snapshot.html') === $snapshot && file_get_contents($root . '/snapshot.html') === $snapshot->data(), 'Post-session persistence changed HTML.');
    $frame->save($root . '/frame.html');

    $browser = $driver->browser(headless: !in_array($name, ['safari', 'safaripreview'], true));
    $browser->navigate('file://' . $root . '/snapshot.html');
    check($browser->title() === 'Résumé €'
        && $browser->script('return document.querySelector("#relative").href') === $base . 'next.html'
        && $browser->script('return document.scripts.length') === 0
        && $browser->script('return typeof window.snapshotMarker') === 'undefined'
        && $browser->script('return document.querySelector("#relative").getAttribute("onclick")') === 'return false',
        'Reopened snapshot lost encoding/URL resolution or retained removed scripts.');

    file_put_contents($root . '/plain.html', '<!DOCTYPE html><meta charset="utf-8"><title>No base</title><a href="next.html">Next</a>');
    $browser->navigate('file://' . $root . '/plain.html');
    $base = $browser->script('return document.baseURI');
    $plain = $browser->window()->snapshot();
    check(str_contains($plain->data(), 'href="' . $base . '"'), 'A page without a base element lost its document URL.');
    $plain->save($root . '/plain-snapshot.html');
    $browser->navigate('file://' . $root . '/plain-snapshot.html');
    check($browser->script('return document.querySelector("a").href') === substr($base, 0, strrpos($base, '/') + 1) . 'next.html', 'Document-relative URL resolution changed.');
    foreach (['<meta charset="windows-1252">', '<meta http-equiv="Content-Type" content="text/html; charset=windows-1252">'] as $encoding) {
        file_put_contents($root . '/legacy.html', '<!DOCTYPE html>' . $encoding . "<title>R\xe9sum\xe9 \x80</title>");
        $browser->navigate('file://' . $root . '/legacy.html');
        check($browser->title() === 'Résumé €', 'Legacy encoding fixture did not load correctly.');
        $browser->snapshot()->save($root . '/legacy-snapshot.html');
        check($browser->script('return document.characterSet') === 'windows-1252', 'Snapshot changed live document encoding.');
        $browser->navigate('file://' . $root . '/legacy-snapshot.html');
        check($browser->title() === 'Résumé €' && $browser->script('return document.characterSet') === 'UTF-8', 'Serialized Unicode retained a conflicting legacy charset.');
    }
    echo "PASS: native $name HTML snapshots preserve DOM, contexts, URLs and independent capture lifetime\n";
} finally {
    $driver->stop();
    foreach (glob($root . '/*') as $file) unlink($file);
    rmdir($root);
}
