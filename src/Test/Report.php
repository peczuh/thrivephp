<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP\Test;

/** @internal Shared text rendering and totals for serial results and parallel worker results. */
final class Report
{
    private array $totals = ['checksPassed' => 0, 'checksFailed' => 0, 'casesPassed' => 0,
        'casesFailed' => 0, 'casesSkipped' => 0, 'assertionsPassed' => 0, 'assertionsFailed' => 0];
    private bool $hasSuites = false;

    public function caseResult(string $suite, array $result): void
    {
        echo $result['status'] . ' ' . $suite . ' > ' . $result['name']
            . ($result['status'] === 'SKIP' ? ' (' . $result['reason'] . ')' : '')
            . ($result['status'] === 'PASS' && ($result['noAssertions'] ?? false) ? ' (No assertions)' : '') . PHP_EOL;
        self::failures($result['failures'] ?? []);
    }

    /** Aggregate only terminal results; incremental events are for progress and crash recovery. */
    public function result(string $suite, array $result): void
    {
        foreach ($this->totals as $key => &$count) {
            $count += $result[$key];
        }
        unset($count);
        $this->hasSuites = $this->hasSuites || $result['hasSuites'];
        echo ($result['checksFailed'] ? 'FAIL ' : 'PASS ') . $suite
            . (!$result['checksFailed'] && $result['assertionsPassed'] + $result['assertionsFailed'] === 0 ? ' (No assertions)' : '') . PHP_EOL;
        self::failures($result['failures']);
    }

    public function summary(): int
    {
        $t = $this->totals;
        echo PHP_EOL . "Checks: {$t['checksPassed']} passed, {$t['checksFailed']} failed" . PHP_EOL;
        if ($this->hasSuites) {
            echo "Cases: {$t['casesPassed']} passed, {$t['casesFailed']} failed, {$t['casesSkipped']} skipped" . PHP_EOL;
        }
        echo "Assertions: {$t['assertionsPassed']} passed, {$t['assertionsFailed']} failed" . PHP_EOL;
        return $t['checksFailed'] ? 1 : 0;
    }

    private static function failures(array $messages): void
    {
        foreach ($messages as $message) {
            echo '  ' . str_replace("\n", "\n  ", $message) . PHP_EOL;
        }
    }
}
