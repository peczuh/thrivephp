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
    private ?Progress $progress = null;
    /** Terminal placement width only; null retains the image's natural display size. */
    private ?int $screenshotColumns;

    /** Console settings filter display only; quiet retains failure/error diagnostics even during bootstrap. */
    public function __construct(private string $directory, array $environment, private array $console = [],
        private bool $quiet = false)
    {
        $this->screenshotColumns = $console['screenshots']['columns'] ?? null;
        if ($this->screenshotColumns !== null && $this->screenshotColumns < 1) {
            throw new \InvalidArgumentException('test.console.screenshots.columns must be a positive integer or null.');
        }
        $this->record = new Record($environment);
        $this->diagnostic = new Diagnostic($environment['root']);
    }

    /** Start after selection; workers only send events and never own this footer. */
    public function startProgress(int $total, bool $serial): void
    {
        $this->progress = new Progress($total, $serial);
    }

    public function event(string $name, array $event): void
    {
        $this->progress?->beforeOutput();
        $this->record->accept($name, $event);
        $suite = $this->record->suite($name);
        $attempt = end($suite['attempts']);
        $context = $this->context($suite, $attempt, $event['method'] ?? null);
        if ($event['type'] === 'suite_started' && !isset($this->announced[$attempt['id']])) {
            $this->announced[$attempt['id']] = true;
            $this->line('RUN', 'SUITE', $context . (isset($event['worker']) ? ' (worker ' . $event['worker'] . ')' : ''));
        } elseif ($event['type'] === 'browser_log' && $this->visible(strtoupper($event['log']['entry']['level'] ?? 'info'))) {
            $log = $event['log'];
            $entry = $log['entry'];
            $this->line(strtoupper($entry['level'] ?? 'info'), 'BROWSER', $context . ' [' . ($entry['type'] ?? 'unknown') . '] '
                . ($entry['text'] ?? self::json($entry['args'] ?? [])));
            echo '    Observed during: ' . $log['phase'] . ($log['hook'] !== null ? ' / ' . $log['hook'] : '') . PHP_EOL;
            foreach (['source', 'args', 'stackTrace'] as $field) {
                if (isset($entry[$field])) echo '    ' . $field . ': ' . self::json($entry[$field]) . PHP_EOL;
            }
        } elseif ($event['type'] === 'browser_network' && !$this->quiet) {
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
        } elseif ($event['type'] === 'error' && ($event['error']['class'] !== 'AssertionFailure' || $this->visible('FAIL'))) {
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
        } elseif ($event['type'] === 'retry' && !$this->quiet) {
            if ($this->visible('FAIL') && $event['scope'] === 'case') {
                $case = $attempt['cases'][$event['method']];
                $this->screenshots(end($case['attempts'])['screenshots'], true);
            } elseif ($this->visible('FAIL')) {
                $this->screenshots($attempt['screenshots'], true);
            }
            $this->line('RETRY', strtoupper($event['scope']), $context);
        } elseif ($event['type'] === 'case_started') {
            $this->line('RUN', 'CASE', $context);
        } elseif ($event['type'] === 'result' && $suite['status'] !== 'FILTERED') {
            $this->suiteResult($suite, $attempt, $this->record->counts($name));
        }
        if (in_array($event['type'], ['suite_started', 'configured', 'case_started', 'case_finished', 'retry', 'result'], true)) {
            $this->progress?->update($suite);
        }
        $this->progress?->afterOutput();
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
        $this->progress?->beforeOutput();
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
        $this->progress?->update($suite);
        $this->progress?->afterOutput();
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

    /** Errors remain visible; quiet overrides configured status switches. */
    private function visible(string $status): bool
    {
        if ($status === 'ERROR') return true;
        if ($this->quiet) return $status === 'FAIL';
        return (bool)($this->console[strtolower($status)] ?? true);
    }

    private function line(string $status, string $kind, string $message): void
    {
        if (!$this->visible($status)) return;
        if ($status === 'FAIL') {
            echo $this->statusText($status, sprintf('%-5s %-5s %s', $status, $kind, $message)) . PHP_EOL;
        } else {
            echo sprintf('%s %-5s %s', $this->statusText($status, str_pad($status, 5)), $kind, $message) . PHP_EOL;
        }
    }

    /** Color status text without adding escapes to redirected logs. */
    private function statusText(string $status, string $text): string
    {
        $color = match ($status) {
            'FAIL' => 31,
            'PASS' => 32,
            'SKIP' => 33,
            'RUN' => 34,
            default => null,
        };
        return stream_isatty(STDOUT) && $color !== null ? "\033[{$color}m" . $text . "\033[39m" : $text;
    }

    private function suiteResult(array $suite, array $attempt, array $counts): void
    {
        if (!$this->visible($suite['status'])) return;
        $context = $this->context($suite, $attempt);
        $this->line($suite['status'], 'SUITE', $context
            . ($suite['status'] === 'PASS' && $counts['assertionsPassed'] + $counts['assertionsFailed'] === 0 ? ' (No assertions)' : '')
            . sprintf(' [%.3fs]', $suite['duration']));
        $this->screenshots($attempt['screenshots'], $suite['status'] === 'FAIL');
    }

    private function caseResult(array $suite, array $attempt, array $case): void
    {
        if (!$this->visible($case['status'])) return;
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

    /** Filter complete step blocks, retaining execution errors even when failure output is disabled. */
    private function step(string $context, array $step): void
    {
        $failure = $step['status'] === 'FAIL';
        $status = $step['status'];
        if (!$this->visible($status)) {
            if ($step['error'] === null || $step['error']['class'] === 'AssertionFailure') return;
            $status = 'ERROR';
        }
        $context .= ' > ' . $step['label'];
        if ($failure || ($this->console['steps'] ?? false)) {
            $this->line($status, 'STEP', $context . sprintf(' [%s, %.3fs]', $step['phase'], $step['duration']));
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
        if ($this->quiet || !($this->console['screenshots']['enabled'] ?? true)) return;
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
            $this->progress?->beforeImage();
            foreach ($chunks as $index => $chunk) {
                echo "\033_G" . ($index === 0 ? 'a=T,f=100,t=d,q=2,'
                    . ($this->screenshotColumns !== null ? 'c=' . $this->screenshotColumns . ',' : '') : '')
                    . 'm=' . ($index < count($chunks) - 1 ? '1' : '0') . ';' . $chunk . "\033\\";
            }
            echo PHP_EOL;
            $this->progress?->afterImage();
        }
    }

    public function summary(): int
    {
        $this->progress?->finish();
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
        if ($this->quiet) return $run['status'] === 'FAIL' ? 1 : 0;
        $t = $run['totals'];
        if ($run['errors']) echo PHP_EOL . $this->statusText('FAIL', 'Run: FAIL (' . count($run['errors']) . ' run error(s))') . PHP_EOL;
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
