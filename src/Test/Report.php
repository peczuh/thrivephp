<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP\Test;

/** Console progress and file renderers share one event-built record. */
final class Report
{
    public readonly Record $record;
    private Diagnostic $diagnostic;
    private array $shownImages = [];
    private array $announced = [];

    /** Screenshot columns affect terminal placement only; null retains the image's natural display size. */
    public function __construct(private string $directory, array $environment, private bool $steps = false,
        private ?int $screenshotColumns = null)
    {
        if ($screenshotColumns !== null && $screenshotColumns < 1) {
            throw new \InvalidArgumentException('test.console.screenshots.columns must be a positive integer or null.');
        }
        $this->record = new Record($environment);
        $this->diagnostic = new Diagnostic($environment['root']);
    }

    public function event(string $name, array $event): void
    {
        $this->record->accept($name, $event);
        $suite = $this->record->suite($name);
        $attempt = end($suite['attempts']);
        $context = $this->context($suite, $attempt, $event['method'] ?? null);
        if ($event['type'] === 'suite_started' && !isset($this->announced[$attempt['id']])) {
            $this->announced[$attempt['id']] = true;
            $this->line('RUN', 'SUITE', $context . (isset($event['worker']) ? ' (worker ' . $event['worker'] . ')' : ''));
        } elseif ($event['type'] === 'browser_log') {
            $log = $event['log'];
            $entry = $log['entry'];
            $this->line(strtoupper($entry['level'] ?? 'info'), 'BROWSER', $context . ' [' . ($entry['type'] ?? 'unknown') . '] '
                . ($entry['text'] ?? self::json($entry['args'] ?? [])));
            echo '    Observed during: ' . $log['phase'] . ($log['hook'] !== null ? ' / ' . $log['hook'] : '') . PHP_EOL;
            foreach (['source', 'args', 'stackTrace'] as $field) {
                if (isset($entry[$field])) echo '    ' . $field . ': ' . self::json($entry[$field]) . PHP_EOL;
            }
        } elseif ($event['type'] === 'browser_network') {
            $network = $event['network'];
            $entry = $network['event'];
            $params = $entry['params'];
            $this->line('INFO', 'NETWORK', $context . ' [' . $entry['method'] . '] '
                . ($params['request']['method'] ?? '') . ' ' . ($params['request']['url'] ?? '')
                . (isset($params['response']['status']) ? ' HTTP ' . $params['response']['status'] : '')
                . (isset($params['errorText']) ? ' ' . $params['errorText'] : ''));
            echo '    Observed during: ' . $network['phase'] . ($network['hook'] !== null ? ' / ' . $network['hook'] : '')
                . ($network['stepId'] !== null ? ' / ' . $network['stepId'] : '') . PHP_EOL;
        } elseif ($event['type'] === 'step_finished') {
            $this->step($context, $event['step']);
        } elseif ($event['type'] === 'error') {
            $node = isset($event['method']) ? end($attempt['cases'][$event['method']]['attempts']) : $attempt;
            $lastStep = $node['steps'] ? end($node['steps']) : null;
            // A thrown step error can propagate to the case or hook; its diagnostics were already printed.
            if ($lastStep === null || $lastStep['error'] !== array_diff_key($event['error'], ['phase' => true, 'hook' => true])) {
                $this->line('ERROR', isset($event['method']) ? 'CASE' : 'SUITE', $context . ' [' . $event['error']['phase'] . ']');
                if ($event['error']['hook'] !== null) echo '    Hook: ' . $event['error']['hook'] . PHP_EOL;
                $this->error($event['error']);
            }
        } elseif ($event['type'] === 'case_finished') {
            $this->caseResult($suite, $attempt, $attempt['cases'][$event['method']]);
        } elseif ($event['type'] === 'retry') {
            if ($event['scope'] === 'case') {
                $case = $attempt['cases'][$event['method']];
                $this->screenshots(end($case['attempts'])['screenshots'], true);
            } else {
                $this->screenshots($attempt['screenshots'], true);
            }
            $this->line('RETRY', strtoupper($event['scope']), $context);
        } elseif ($event['type'] === 'case_started') {
            $this->line('RUN', 'CASE', $context);
        } elseif ($event['type'] === 'result' && $suite['status'] !== 'FILTERED') {
            $this->suiteResult($suite, $attempt, $this->record->counts($name));
        }
    }

    /** Record and print failures that occur before a suite can produce events. */
    public function runError(\Throwable $error, string $phase): void
    {
        $diagnostic = $this->diagnostic->error($error) + ['phase' => $phase];
        $this->record->error($diagnostic);
        $this->line('ERROR', 'RUN', '[' . $phase . ']');
        $this->error($diagnostic);
    }

    public function interrupt(string $name, string $message): void
    {
        $before = $this->record->suite($name);
        $this->record->interrupt($name, $message);
        $suite = $this->record->suite($name);
        $attempt = end($suite['attempts']);
        $old = end($before['attempts']);
        $context = $this->context($suite, $attempt);
        $reportedFailure = $this->interruptedSteps($context, $old, $attempt, $message);
        foreach ($attempt['cases'] as $method => $case) {
            if ($old['cases'][$method]['status'] === 'RUN' && $case['attempts']) {
                $reportedFailure = $this->interruptedSteps($this->context($suite, $attempt, $method),
                    end($old['cases'][$method]['attempts']), end($case['attempts']), $message) || $reportedFailure;
            }
        }
        if (!$reportedFailure) {
            $this->line('ERROR', 'SUITE', $context);
            $this->error(end($attempt['errors']));
        }
        foreach ($attempt['cases'] as $method => $case) {
            if (in_array($old['cases'][$method]['status'], ['RUN', 'PENDING'], true)) {
                $this->caseResult($suite, $attempt, $case);
            }
        }
        $this->suiteResult($suite, $attempt, $this->record->counts($name));
    }

    /** Print only steps completed by worker recovery; ordinary completed steps were emitted live. */
    private function interruptedSteps(string $context, array $before, array $after, string $message): bool
    {
        $reportedFailure = false;
        foreach ($after['steps'] as $id => $step) {
            if (($before['steps'][$id]['endedAt'] ?? null) === null) {
                $this->step($context, $step);
                $reportedFailure = $reportedFailure || (($step['error']['class'] ?? null) === 'WorkerFailure'
                    && $step['error']['message'] === $message);
            }
        }
        return $reportedFailure;
    }

    /** Identify suites by file and cases by method, with descriptions and retry attempts. */
    private function context(array $suite, array $attempt, ?string $method = null): string
    {
        $context = $suite['id'] . ($method === null && $suite['name'] !== $suite['id'] ? ': ' . $suite['name'] : '')
            . ($suite['maxAttempts'] > 1 ? ' (attempt ' . $attempt['index'] . '/' . $suite['maxAttempts'] . ')' : '');
        if ($method !== null) {
            $case = $attempt['cases'][$method];
            $last = $case['attempts'] ? end($case['attempts']) : null;
            $context .= ' > ' . $case['method'] . ($case['name'] !== $case['method'] ? ': ' . $case['name'] : '')
                . ($case['maxAttempts'] > 1 && ($last !== null || $case['status'] === 'RUN')
                ? ' (attempt ' . ($last['index'] ?? 1) . '/' . $case['maxAttempts'] . ')' : '');
        }
        return $context;
    }

    private function line(string $status, string $kind, string $message): void
    {
        echo sprintf('%s %-5s %s', $this->statusLabel($status, 5), $kind, $message) . PHP_EOL;
    }

    /** Pad before adding terminal colors so status columns remain aligned. */
    private function statusLabel(string $status, int $width = 0): string
    {
        $label = str_pad($status, $width);
        $color = match ($status) {
            'FAIL' => 31,
            'PASS' => 32,
            'SKIP' => 33,
            'RUN' => 34,
            default => null,
        };
        return stream_isatty(STDOUT) && $color !== null ? "\033[{$color}m" . $label . "\033[39m" : $label;
    }

    private function suiteResult(array $suite, array $attempt, array $counts): void
    {
        $context = $this->context($suite, $attempt);
        $this->line($suite['status'], 'SUITE', $context
            . ($suite['status'] === 'PASS' && $counts['assertionsPassed'] + $counts['assertionsFailed'] === 0 ? ' (No assertions)' : '')
            . sprintf(' [%.3fs]', $suite['duration']));
        $this->screenshots($attempt['screenshots'], $suite['status'] === 'FAIL');
    }

    private function caseResult(array $suite, array $attempt, array $case): void
    {
        $last = $case['attempts'] ? end($case['attempts']) : null;
        $context = $this->context($suite, $attempt, $case['method']);
        $this->line($case['status'], 'CASE', $context
            . ($case['status'] === 'SKIP' ? ' (' . $case['reason'] . ')' : '')
            . ($case['status'] === 'PASS' && $last['assertionsPassed'] + $last['assertionsFailed'] === 0 ? ' (No assertions)' : '')
            . sprintf(' [%.3fs, %d assertions]', $case['duration'], ($last['assertionsPassed'] ?? 0) + ($last['assertionsFailed'] ?? 0)));
        if ($last !== null) {
            $this->screenshots($last['screenshots'], $case['status'] === 'FAIL');
        }
    }

    /** Completed steps print once, with failures always visible and successful steps opt-in. */
    private function step(string $context, array $step): void
    {
        $failure = $step['status'] === 'FAIL';
        $context .= ' > ' . $step['label'];
        if ($failure || $this->steps) {
            $this->line($step['status'], 'STEP', $context . sprintf(' [%s, %.3fs]', $step['phase'], $step['duration']));
            if ($step['target'] !== null) echo '    Target: ' . $step['target'] . PHP_EOL;
            if ($step['hook'] !== null) echo '    Hook: ' . $step['hook'] . PHP_EOL;
            if ($step['values']) echo '    Values: ' . self::json($step['values']) . PHP_EOL;
            foreach ($step['assertions'] as $assertion) {
                echo '    ' . ($assertion['passed'] ? '' : 'AssertionFailure: ') . $assertion['message'] . PHP_EOL;
                if (array_key_exists('expected', $assertion)) {
                    echo '    Expected: ' . self::json($assertion['expected']) . PHP_EOL
                        . '    Actual: ' . self::json($assertion['actual']) . PHP_EOL;
                }
                if (!$assertion['passed']) $this->location($assertion['location']);
            }
            if ($failure) {
                if (!array_any($step['assertions'], static fn ($assertion) => !$assertion['passed']
                    && $assertion['location'] === $step['location'])) $this->location($step['location']);
                if ($step['error'] !== null) $this->error($step['error']);
            }
        }
        $this->screenshots($step['screenshots'], $failure);
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private function error(array $error): void
    {
        echo '  ' . ($error['class'] === 'AssertionFailure' ? '' : 'Execution error: ')
            . $error['class'] . ': ' . str_replace("\n", "\n  ", $error['message']) . PHP_EOL;
        $this->location($error['location'] ?? null);
        if ($error['stack'] ?? '') {
            echo '  ' . str_replace("\n", "\n  ", $error['stack']) . PHP_EOL;
        }
        if ($error['previous'] ?? null) {
            $this->error($error['previous']);
        }
    }

    private function location(?array $location): void
    {
        if ($location === null) {
            return;
        }
        echo '    ' . $location['file'] . ':' . $location['line'] . PHP_EOL;
        foreach ($location['source'] as $line) {
            echo ($line['line'] === $location['line'] ? '  > ' : '    ') . $line['line'] . ' | ' . $line['text'] . PHP_EOL;
        }
    }

    private function screenshots(array $screenshots, bool $failure): void
    {
        foreach ($screenshots as $image) {
            if (isset($image['diagnostic'])) {
                echo '  Screenshot unavailable: ' . $image['diagnostic'] . PHP_EOL;
                continue;
            }
            if (isset($this->shownImages[$image['path']])) continue;
            $this->shownImages[$image['path']] = true;
            $path = $this->directory . '/' . $image['path'];
            $label = $this->diagnostic->path($path);
            if (stream_isatty(STDOUT) && ($absolute = realpath($path)) !== false) {
                $urlPath = str_replace(['%2F', '%3A'], ['/', ':'], rawurlencode(str_replace(DIRECTORY_SEPARATOR, '/', $absolute)));
                $url = str_starts_with($urlPath, '//') ? 'file:' . $urlPath
                    : 'file://' . (str_starts_with($urlPath, '/') ? '' : '/') . $urlPath;
                $label = "\033]8;;" . $url . "\033\\\033[34;4m" . $label . "\033[39;24m\033]8;;\033\\";
            }
            echo '  Screenshot: ' . $label . PHP_EOL;
            if (!$failure || $image['status'] !== 'FAIL' || !stream_isatty(STDOUT)
                || !in_array(getenv('TERM_PROGRAM'), ['ghostty', 'rex'], true)) {
                continue;
            }
            $bytes = @file_get_contents($this->directory . '/' . $image['path']);
            if ($bytes === false) {
                continue;
            }
            $chunks = str_split(base64_encode($bytes), 4096);
            foreach ($chunks as $index => $chunk) {
                echo "\033_G" . ($index === 0 ? 'a=T,f=100,t=d,q=2,'
                    . ($this->screenshotColumns !== null ? 'c=' . $this->screenshotColumns . ',' : '') : '')
                    . 'm=' . ($index < count($chunks) - 1 ? '1' : '0') . ';' . $chunk . "\033\\";
            }
            echo PHP_EOL;
        }
    }

    public function summary(): int
    {
        $run = $this->record->finish();
        $json = json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
        $html = str_replace('{{REPORT_DATA}}', json_encode($run, JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            file_get_contents(__DIR__ . '/report.html'));
        foreach (['report.json' => $json, 'report.html' => $html] as $file => $contents) {
            if (@file_put_contents($this->directory . '/' . $file, $contents) !== strlen($contents)) {
                echo 'ERROR Could not write complete report: ' . $this->diagnostic->path($this->directory . '/' . $file) . PHP_EOL;
                throw new \RuntimeException('Could not write complete report: ' . $file);
            }
        }
        $t = $run['totals'];
        if ($run['errors']) echo PHP_EOL . 'Run: ' . $this->statusLabel('FAIL') . ' (' . count($run['errors']) . ' run error(s))' . PHP_EOL;
        echo PHP_EOL . "Suites: {$t['checksPassed']} passed, {$t['checksFailed']} failed, {$t['checksSkipped']} skipped" . PHP_EOL;
        if (array_any($run['suites'], fn ($suite) => array_any($suite['attempts'], fn ($attempt) => $attempt['cases'] !== []))) {
            echo "Cases: {$t['casesPassed']} passed, {$t['casesFailed']} failed, {$t['casesSkipped']} skipped" . PHP_EOL;
        }
        echo "Assertions: {$t['assertionsPassed']} passed, {$t['assertionsFailed']} failed" . PHP_EOL;
        echo 'Environment: ' . self::json($run['environment']) . PHP_EOL;
        echo sprintf('Duration: %.3fs', $run['duration']) . PHP_EOL . 'Reports: '
            . $this->diagnostic->path($this->directory) . PHP_EOL;
        return $run['status'] === 'FAIL' ? 1 : 0;
    }
}
