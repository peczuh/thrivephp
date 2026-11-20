<?php

require __DIR__ . '/project/vendor/autoload.php';

use ThriveData\ThrivePHP\WebDriver\DriverProcess;
use ThriveData\ThrivePHP\WebDriver\WebDriver;

$mode = $argv[1];
echo '== WebDriver check: Google Chrome ', $mode === 'stock' ? 'on stock Alpine' : 'with gcompat', ' ==', PHP_EOL;
$inventory = WebDriver::bootstrap();
$chrome = $inventory->browsers->chrome;
echo json_encode(['platform' => $inventory->platform, 'browser' => $chrome], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
if ($inventory->platform->distribution !== 'alpine') throw new RuntimeException('Expected an Alpine container.');

foreach ([
    '/opt/chrome-for-testing/chrome/chrome' => $chrome,
    '/opt/chrome-for-testing/chromedriver/chromedriver' => $chrome->driver,
] as $binary => $component) {
    if ($component->path !== $binary || !is_executable($binary)) {
        throw new RuntimeException("Did not discover the installed Google binary: $binary.");
    }
    $elf = DriverProcess::run(['readelf', '-lW', $binary]);
    if ($elf['exitCode'] !== 0 || !preg_match('~\[Requesting program interpreter: (/[^]\s]+)]~', $elf['output'], $match)) {
        throw new RuntimeException("Could not verify the required interpreter for $binary.");
    }
    $loader = $match[1];
    if (is_file($loader) !== ($mode === 'compat')) {
        throw new RuntimeException("Unexpected loader state in $mode: $loader.");
    }
    $message = $component->message ?? '';
    if ($mode === 'stock') {
        // A native kernel rejects exec; a userspace emulator may exit and name the missing loader.
        $expected = ($component->ready === 'unknown' && $component->reason === 'probe_failed'
                && str_contains($message, 'Could not start executable: ' . $binary))
            || ($component->ready === 'notready' && $component->reason === 'version_failed'
                && str_contains($message, $loader));
    } elseif ($inventory->platform->architecture === 'x64') {
        // On AMD64 with gcompat, ChromeDriver reports a version, but Chrome exits with SIGSEGV.
        if ($component->name === 'chromedriver') {
            $expected = $component->ready === true;
        } else {
            $probe = DriverProcess::run([$binary, '--version']);
            echo "$binary exited with status {$probe['exitCode']}", PHP_EOL;
            $expected = $component->ready === 'notready' && $component->reason === 'version_failed'
                && $probe['exitCode'] === 139;
        }
    } else {
        $expected = $component->ready === 'notready' && $component->reason === 'version_failed'
            && str_contains($message, 'unsupported relocation type 1032');
    }
    if (!$expected) throw new RuntimeException("Unexpected Chrome for Testing startup result in $mode: $binary.");
}
echo "PASS: Chrome for Testing and ChromeDriver reported the expected startup results in $mode Alpine", PHP_EOL;
