<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP\Test;

/** Builds the same report tree from in-process events and worker messages. */
final class Record
{
    public const TOTALS = ['checksPassed' => 0, 'checksFailed' => 0, 'checksSkipped' => 0,
        'casesPassed' => 0, 'casesFailed' => 0, 'casesSkipped' => 0,
        'assertionsPassed' => 0, 'assertionsFailed' => 0];
    private array $suites = [];
    private float $started;
    /**
     * Monotonic origins remain internal; serialized timestamps use local wall time.
     * Scope clocks by suite so nested file IDs cannot collide with another suite's case IDs.
     */
    private array $clocks = [];
    private array $run;

    public function __construct(array $environment)
    {
        $this->started = hrtime(true) / 1e9;
        $this->run = ['schemaVersion' => 1, 'generatedAt' => null, 'startedAt' => self::now(),
            'endedAt' => null, 'duration' => 0, 'status' => 'RUN', 'environment' => $environment,
            'totals' => self::TOTALS, 'errors' => [], 'suites' => []];
    }

    /** Startup failures belong to the run, without inventing suite or assertion outcomes. */
    public function error(array $error): void
    {
        $this->run['errors'][] = $error;
    }

    /** Complete environment details as configuration, discovery, and browser selection succeed. */
    public function environment(array $values): void
    {
        $this->run['environment'] = array_replace($this->run['environment'], $values);
    }

    public static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.uP');
    }

    private static function attempt(string $id, int $index, string $time): array
    {
        return ['id' => $id, 'index' => $index, 'status' => 'RUN', 'startedAt' => $time,
            'endedAt' => null, 'duration' => 0, 'steps' => [], 'errors' => [], 'screenshots' => [], 'browserLogs' => [], 'browserNetwork' => [],
            'assertionsPassed' => 0, 'assertionsFailed' => 0];
    }

    /** Events carry method identities separately from display names, including skipped cases. */
    public function accept(string $name, array $event): void
    {
        $type = $event['type'];
        $time = $event['time'] ?? self::now();
        $suite = &$this->suites[$name];
        $suite ??= ['id' => $name, 'name' => $name, 'file' => $event['file'] ?? null,
            'status' => 'RUN', 'duration' => 0, 'maxAttempts' => 1, 'attempts' => []];
        $index = $event['suiteAttempt'] ?? max(1, count($suite['attempts']));
        $attempt = &$suite['attempts'][$index];
        $attempt ??= self::attempt($suite['id'] . '/a' . $index, $index, $time) + ['session' => null, 'cases' => []];
        $this->clocks[$name][$attempt['id']] ??= $event['clock'] ?? hrtime(true) / 1e9;
        if (isset($event['maxAttempts'])) $suite['maxAttempts'] = $event['maxAttempts'];
        if ($type === 'configured') {
            $suite['name'] = $event['name'];
            $suite['location'] = $event['location'] ?? null;
            foreach ($event['definitions'] ?? [] as $definition) {
                $method = $definition['method'];
                $attempt['cases'][$method] = ['id' => $attempt['id'] . '/' . $method,
                    'method' => $method, 'name' => $definition['name'], 'location' => $definition['location'],
                    'status' => 'PENDING', 'duration' => 0, 'maxAttempts' => $definition['maxAttempts'], 'attempts' => []];
            }
        }
        $node = &$attempt;
        if (isset($event['method'])) {
            $method = $event['method'];
            $case = &$attempt['cases'][$method];
            $case ??= ['id' => $attempt['id'] . '/' . $method, 'method' => $method,
                'name' => $event['name'] ?? $method, 'status' => 'RUN', 'duration' => 0, 'maxAttempts' => 1, 'attempts' => []];
            if ($type === 'case_finished') {
                $case['status'] = $event['status'];
                $case['duration'] = $event['duration'] ?? 0;
                $case['reason'] = $event['reason'] ?? null;
                return;
            }
            if ($type === 'case_started') {
                $case['status'] = 'RUN';
                return;
            }
            $caseIndex = $event['caseAttempt'] ?? 1;
            $node = &$case['attempts'][$caseIndex];
            $node ??= self::attempt($case['id'] . '/a' . $caseIndex, $caseIndex, $time);
            $this->clocks[$name][$node['id']] ??= $event['clock'] ?? hrtime(true) / 1e9;
        }
        switch ($type) {
            case 'browser_log':
                $node['browserLogs'][] = $event['log'];
                break;
            case 'browser_network':
                $node['browserNetwork'][] = $event['network'];
                break;
            case 'session':
                $attempt['session'] = $event['session'];
                $capabilities = $event['session']['capabilities'];
                $this->run['environment']['browser'] ??= $capabilities['browserName'] ?? null;
                $this->run['environment']['browserVersion'] ??= $capabilities['browserVersion'] ?? null;
                $this->run['environment']['platform'] ??= $capabilities['platformName'] ?? null;
                break;
            case 'step_started':
            case 'step_updated':
            case 'step_finished':
                $node['steps'][$event['step']['id']] = $event['step'];
                $this->clocks[$name][$event['step']['id']] ??= $event['clock'] ?? hrtime(true) / 1e9;
                break;
            case 'assertion':
                $node[$event['passed'] ? 'assertionsPassed' : 'assertionsFailed']++;
                break;
            case 'error':
                $node['errors'][] = $event['error'];
                break;
            case 'screenshot':
                $node['screenshots'][] = $event['screenshot'];
                break;
            case 'case_attempt_finished':
            case 'suite_attempt_finished':
                $node['status'] = $event['status'];
                $node['endedAt'] = $time;
                $node['duration'] = $event['duration'];
                break;
            case 'result':
                $result = $event['result'];
                $suite['status'] = ($result['filtered'] ?? false) ? 'FILTERED'
                    : ($result['checksFailed'] ? 'FAIL' : ($result['checksSkipped'] ? 'SKIP' : 'PASS'));
                $suite['duration'] = $result['duration'] ?? $attempt['duration'];
                $suite['reason'] = $suite['status'] === 'SKIP' ? 'marked skip' : null;
                break;
        }
    }

    /** Complete partial nodes from received events when a worker cannot deliver its result. */
    public function interrupt(string $name, string $message): void
    {
        $this->accept($name, ['type' => 'error', 'error' => ['class' => 'WorkerFailure',
            'message' => $message, 'location' => null, 'stack' => '']]);
        $suite = &$this->suites[$name];
        $suite['status'] = 'FAIL';
        $attempt = &$suite['attempts'][array_key_last($suite['attempts'])];
        $attempt['status'] = 'FAIL';
        $attempt['endedAt'] = self::now();
        $clock = hrtime(true) / 1e9;
        $attempt['duration'] = $clock - $this->clocks[$name][$attempt['id']];
        $suite['duration'] = array_sum(array_column($suite['attempts'], 'duration'));
        foreach ($attempt['steps'] as &$step) {
            if ($step['endedAt'] === null) {
                $step['status'] = 'FAIL';
                $step['error'] ??= end($attempt['errors']);
                $step['endedAt'] = $attempt['endedAt'];
                $step['duration'] = $clock - $this->clocks[$name][$step['id']];
            }
        }
        unset($step);
        foreach ($attempt['cases'] as &$case) {
            if ($case['status'] === 'PENDING') {
                $case['status'] = 'SKIP';
                $case['reason'] = 'worker failed';
            } elseif ($case['status'] === 'RUN') {
                $case['status'] = 'FAIL';
                if ($case['attempts']) {
                    $last = &$case['attempts'][array_key_last($case['attempts'])];
                    $last['status'] = 'FAIL';
                    $last['endedAt'] = $attempt['endedAt'];
                    $last['duration'] = $clock - $this->clocks[$name][$last['id']];
                    $case['duration'] = array_sum(array_column($case['attempts'], 'duration'));
                    $last['errors'][] = end($attempt['errors']);
                    foreach ($last['steps'] as &$step) {
                        if ($step['endedAt'] === null) {
                            $step['status'] = 'FAIL';
                            $step['endedAt'] = $last['endedAt'];
                            $step['error'] ??= end($last['errors']);
                            $step['duration'] = $clock - $this->clocks[$name][$step['id']];
                        }
                    }
                    unset($step, $last);
                }
            }
        }
    }

    public function suite(string $name): array
    {
        return $this->suites[$name];
    }

    /** Counts derive only from the final suite and case attempts, including incomplete workers. */
    public function counts(string $name): array
    {
        $suite = $this->suites[$name] ?? null;
        $counts = self::TOTALS;
        if ($suite === null || $suite['status'] === 'FILTERED') {
            return $counts;
        }
        $key = ['PASS' => 'checksPassed', 'FAIL' => 'checksFailed', 'SKIP' => 'checksSkipped'][$suite['status']] ?? null;
        if ($key !== null) {
            $counts[$key]++;
        }
        $attempt = end($suite['attempts']);
        $counts['assertionsPassed'] = $attempt['assertionsPassed'];
        $counts['assertionsFailed'] = $attempt['assertionsFailed'];
        foreach ($attempt['cases'] as $case) {
            $key = ['PASS' => 'casesPassed', 'FAIL' => 'casesFailed', 'SKIP' => 'casesSkipped'][$case['status']] ?? null;
            if ($key !== null) {
                $counts[$key]++;
            }
            if ($case['attempts']) {
                $last = end($case['attempts']);
                $counts['assertionsPassed'] += $last['assertionsPassed'];
                $counts['assertionsFailed'] += $last['assertionsFailed'];
            }
        }
        return $counts;
    }

    /** Freeze run timing once; normalize keyed construction maps into ordered JSON lists. */
    public function finish(): array
    {
        if ($this->run['endedAt'] === null) {
            $this->run['endedAt'] = self::now();
            $this->run['duration'] = hrtime(true) / 1e9 - $this->started;
        }
        $this->run['generatedAt'] = $this->run['endedAt'];
        $this->run['totals'] = self::TOTALS;
        $suites = $this->suites;
        ksort($suites, SORT_STRING);
        foreach ($suites as $name => &$suite) {
            foreach ($this->counts($name) as $key => $count) {
                $this->run['totals'][$key] += $count;
            }
            foreach ($suite['attempts'] as &$attempt) {
                $attempt['steps'] = array_values($attempt['steps']);
                foreach ($attempt['cases'] as &$case) {
                    foreach ($case['attempts'] as &$caseAttempt) {
                        $caseAttempt['steps'] = array_values($caseAttempt['steps']);
                    }
                    unset($caseAttempt);
                    $case['attempts'] = array_values($case['attempts']);
                }
                unset($case);
                $attempt['cases'] = array_values($attempt['cases']);
            }
            unset($attempt);
            $suite['attempts'] = array_values($suite['attempts']);
        }
        unset($suite);
        $this->run['suites'] = array_values($suites);
        $this->run['status'] = $this->run['errors'] || $this->run['totals']['checksFailed'] ? 'FAIL' : 'PASS';
        return $this->run;
    }
}
