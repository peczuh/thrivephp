<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP\Test;

/** Parent-owned terminal footer; report text scrolls above its reserved bottom row. */
final class Progress
{
    private static bool $visible = false;
    private int $rows = 0;
    private int $columns = 0;
    private array $suites = [];
    private string $drawn = '';
    private bool $resized = false;
    private bool $writing = false;
    private bool $image = false;
    private array $signals = [];
    private ?bool $async = null;

    public function __construct(private int $total, bool $serial)
    {
        if (!$total || self::$visible || !stream_isatty(STDOUT) || PHP_OS_FAMILY === 'Windows'
            || getenv('TERM') === 'dumb' || !function_exists('pcntl_async_signals')) return;
        [$this->rows, $this->columns] = $this->size();
        if ($this->rows < 3 || $this->columns < 20) {
            $this->rows = 0;
            return;
        }
        self::$visible = true;
        $reference = \WeakReference::create($this);
        register_shutdown_function(static fn () => $reference->get()?->finish());
        $this->async = pcntl_async_signals(true);
        // Parallel owns its interrupt handlers; serial runs need terminal cleanup too.
        foreach ($serial ? [SIGWINCH, SIGINT, SIGTERM] : [SIGWINCH] as $signal) {
            $this->signals[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, static function (int $signal) use ($reference): void {
                if ($progress = $reference->get()) {
                    if ($signal === SIGWINCH) {
                        $progress->resized = true;
                        if (!$progress->writing) $progress->refreshSize();
                    } else $progress->finish();
                }
                if ($signal !== SIGWINCH) exit(128 + $signal);
            });
        }
        $this->reserve();
        $this->draw();
    }

    /** Defer resize drawing while a report block or chunked PNG is being written. */
    public function beforeOutput(): void
    {
        $this->refreshSize();
        $this->writing = true;
    }

    public function afterOutput(): void
    {
        $this->writing = false;
        $this->refreshSize();
        $this->draw();
    }

    private function refreshSize(): void
    {
        if (!$this->rows || !$this->resized) return;
        $this->resized = false;
        [$rows, $columns] = $this->size();
        if ($rows === $this->rows && $columns === $this->columns) return;
        $this->finish();
        if ($rows < 3 || $columns < 20) return;
        $this->rows = $rows;
        $this->columns = $columns;
        self::$visible = true;
        $this->reserve();
        $this->draw();
    }

    /** Replace each suite's current-attempt counts so retries and duplicate start events do not accumulate. */
    public function update(array $suite): void
    {
        if (!$this->rows) return;
        $attempt = end($suite['attempts']);
        $counts = array_count_values(array_column($attempt['cases'], 'status'));
        $this->suites[$suite['id']] = [
            'done' => in_array($suite['status'], ['PASS', 'FAIL', 'SKIP', 'FILTERED'], true),
            'total' => count($attempt['cases']),
            'pass' => $counts['PASS'] ?? 0, 'fail' => $counts['FAIL'] ?? 0, 'skip' => $counts['SKIP'] ?? 0,
        ];
    }

    private function draw(): void
    {
        if (!$this->rows) return;
        $done = $pass = $fail = $skip = 0;
        $active = [];
        foreach ($this->suites as $name => $suite) {
            $done += (int)$suite['done'];
            $pass += $suite['pass'];
            $fail += $suite['fail'];
            $skip += $suite['skip'];
            if (!$suite['done']) $active[] = $name;
        }
        $total = $this->total;
        $unit = 'suites';
        if ($total === 1 && ($suite = reset($this->suites)) && $suite['total']) {
            $done = $pass + $fail + $skip;
            $total = $suite['total'];
            $unit = 'cases';
        }
        $counts = " $done/$total $unit | PASS $pass FAIL $fail SKIP $skip";
        $width = max(3, min(20, $this->columns - strlen($counts) - 3));
        $filled = (int)floor($width * $done / $total);
        $bar = '[' . str_repeat('█', $filled) . str_repeat('-', $width - $filled) . ']';
        $text = $counts;
        if ($active) $text .= ' | ' . implode(', ', $active);
        // Each block occupies one column; truncate only the ASCII suffix by its remaining columns.
        $text = $bar . substr($text, 0, $this->columns - $width - 3);
        if ($text === $this->drawn) return;
        $this->drawn = $text;
        echo "\0337\033[{$this->rows};1H\033[2K" . $text . "\0338";
    }

    /** Keep the output position, moving it above the footer if it was on the last row. */
    private function reserve(): void
    {
        $bottom = $this->rows - 1;
        echo "\n\033[1A\0337\033[1;{$bottom}r\0338";
        $this->drawn = '';
    }

    /** Kitty placements need the full scrolling area to move a tall image above the cursor in Rex. */
    public function beforeImage(): void
    {
        $this->image = $this->rows > 0;
        if ($this->image) $this->finish();
    }

    public function afterImage(): void
    {
        if (!$this->image) return;
        $this->image = false;
        [$this->rows, $this->columns] = $this->size();
        if ($this->rows < 3 || $this->columns < 20) {
            $this->rows = 0;
            return;
        }
        self::$visible = true;
        $this->reserve();
        $this->draw();
    }

    /** Restore the full scrolling region before summaries, errors, or process shutdown. */
    public function finish(): void
    {
        if (!$this->rows) return;
        echo "\0337\033[{$this->rows};1H\033[2K\033[r\0338";
        $this->rows = 0;
        self::$visible = false;
    }

    public function __destruct()
    {
        $this->finish();
        foreach ($this->signals as $signal => $handler) pcntl_signal($signal, $handler);
        if ($this->async !== null) pcntl_async_signals($this->async);
    }

    /** Query the actual stdout terminal, including when stdin belongs to a pipe or is closed. */
    private function size(): array
    {
        $process = proc_open(['stty', 'size'], [0 => STDOUT, 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) return [0, 0];
        $size = sscanf(stream_get_contents($pipes[1]), '%d %d');
        fclose($pipes[1]);
        $code = proc_close($process);
        return $code === 0 && isset($size[0], $size[1]) ? $size : [0, 0];
    }
}
