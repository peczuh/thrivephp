<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP;

/** Parse CLI options, locate the consuming project and dispatch framework commands. */
final class CLI
{
    /** Parse before loading the application so help and usage errors need no project. */
    private static function arguments(array $arguments): array
    {
        $options = ['root' => null, 'casepath' => null, 'savepath' => null, 'browser' => null, 'headless' => null, 'workers' => null,
            'title' => null, 'sets' => [], 'console' => [], 'quiet' => false, 'selectors' => [], 'command' => null, 'help' => false];
        $positional = false;
        for ($index = 0; $index < count($arguments); $index++) {
            $argument = $arguments[$index];
            if (!$positional && $argument === '--') {
                $positional = true;
                continue;
            }
            if (!$positional && $argument === '--help') {
                $options['help'] = true;
                return $options;
            }
            if (!$positional && in_array($argument, ['--headless', '--headed'], true)) {
                $options['headless'] = $argument === '--headless';
                continue;
            }
            if (!$positional && $argument === '--quiet') {
                $options['quiet'] = true;
                continue;
            }
            if (!$positional && preg_match('/^--(no-)?(run|pass|fail|skip|screenshots)$/', $argument, $match)) {
                $path = $match[2] === 'screenshots' ? 'screenshots.enabled' : $match[2];
                $options['console'][$path] = $match[1] === '';
                continue;
            }
            if (!$positional && str_starts_with($argument, '--')) {
                $name = substr($argument, 2);
                if (!in_array($name, ['root', 'casepath', 'savepath', 'browser', 'workers', 'title', 'set'], true)) {
                    throw new \InvalidArgumentException('Unknown option: ' . $argument);
                }
                $value = $arguments[++$index] ?? null;
                if ($value === null || str_starts_with($value, '--')) {
                    throw new \InvalidArgumentException('Missing value for ' . $argument);
                }
                if ($name === 'workers') {
                    $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($value === false) throw new \InvalidArgumentException('--workers requires a positive integer.');
                }
                if ($name === 'set') {
                    $parts = explode('=', $value, 2);
                    if (count($parts) !== 2 || $parts[0] === '') {
                        throw new \InvalidArgumentException('--set requires <path>=<json>.');
                    }
                    try {
                        $options['sets'][] = [$parts[0], json_decode($parts[1], flags: JSON_THROW_ON_ERROR)];
                    } catch (\JsonException $error) {
                        throw new \InvalidArgumentException('Invalid JSON for --set ' . $parts[0] . ': ' . $error->getMessage(), previous: $error);
                    }
                } else {
                    $options[$name] = $value;
                }
            } elseif ($options['command'] === null) {
                $options['command'] = $argument;
            } else {
                $options['selectors'][] = $argument;
            }
        }
        if ($options['command'] === null || $options['command'] === 'help') $options['help'] = true;
        if (!$options['help'] && $options['command'] !== 'test') {
            throw new \InvalidArgumentException('Unknown command: ' . $options['command']);
        }
        return $options;
    }

    /** Relative marker paths use the marker directory; explicit --root paths use CWD. */
    private static function absolutePath(string $path, string $base): string
    {
        if ($path === '') throw new \InvalidArgumentException('The root path must not be empty.');
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('~^[a-zA-Z]:[\\\\/]~', $path)
            ? $path : $base . '/' . $path;
    }

    /** Check CWD inclusively when it is an ancestor; otherwise stop at the filesystem root. */
    private static function discoverRoot(string $directory, string $cwd): string
    {
        while (true) {
            $file = $directory . '/settings.json';
            if (is_file($file)) {
                $settings = json_decode(file_get_contents($file), flags: JSON_THROW_ON_ERROR);
                if ($settings instanceof \stdClass && property_exists($settings, 'root')) {
                    if (!is_string($settings->root) || $settings->root === '') {
                        throw new \RuntimeException('The top-level root in ' . $file . ' must be a nonempty string.');
                    }
                    return self::absolutePath($settings->root, $directory);
                }
            }
            $parent = dirname($directory);
            if ($directory === $cwd || $parent === $directory) {
                throw new \RuntimeException('No settings.json with a top-level root found before discovery stopped at '
                    . $directory . '. Use --root <dir> to select a project.');
            }
            $directory = $parent;
        }
    }

    private static function printHelp(): void
    {
        echo <<<'HELP'
        Usage: php cli.php test [selectors...] [options]

        Selectors use the runner's existing regex syntax, including ::cases, ##tags,
        and exclusions. Multiple selectors combine with OR; matching cases run once.
        No selectors runs the complete suite. Use ^folder/suite$::^case$ for exact matches.

        Options may appear before or after the command and selectors:
          --root <dir>         Override discovery; relative paths use the working directory.
          --casepath <dir>     Override test.casepath; relative paths use the project root.
          --savepath <dir>     Override test.savepath; relative paths use the project root.
          --browser <name>     Select the browser.
          --headless           Enable headless browser execution.
          --headed             Show the browser window instead of running headless.
          --workers <count>    Use a positive number of workers; omitted uses runner defaults.
          --title <text>       Set the report title.
          --run / --no-run     Show or hide suite and case start announcements.
          --pass / --no-pass   Show or hide passing results and their details.
          --fail / --no-fail   Show or hide failures; execution errors remain visible.
          --skip / --no-skip   Show or hide skipped results.
          --screenshots / --no-screenshots
                              Show or hide console screenshot paths and inline images.
          --quiet             Print only failures and errors, without screenshots or summary.
          --set <path>=<json>   Override a setting after bootstrap; repeatable, in order.
          --help              Show help without loading a project.
          --                  Treat remaining arguments as positional selectors.

        Discovery starts in the actual script directory and walks upward to a
        settings.json with a top-level "root" key. Relative roots use that file's
        directory. CWD is checked but never crossed when encountered during traversal.

        Without --headless or --headed, test.browser.headless applies and defaults to true.
        Display switches override test.console settings; repeated switches use the last value.
        --quiet takes precedence over all display switches, regardless of argument order.

        Examples:
          php cli.php test
          php cli.php test 'folder1/suite1::caseA' 'folder2/suite2::caseB'
          php cli.php --root . test --browser firefox --headless --workers 2
          php cli.php test --set test.browser.capabilities.acceptInsecureCerts=true
          php cli.php test --set 'test.project="Sunnyside development"'

        HELP;
    }

    /** Bootstrap once, then apply runtime settings before dispatching the test command. */
    private static function main(array $arguments, string $directory): int
    {
        $options = self::arguments($arguments);
        if ($options['help']) {
            self::printHelp();
            return 0;
        }
        $cwd = getcwd();
        if ($cwd === false) throw new \RuntimeException('Could not determine the working directory.');
        $path = $options['root'] === null ? self::discoverRoot($directory, $cwd) : self::absolutePath($options['root'], $cwd);
        $root = realpath($path);
        if ($root === false || !is_dir($root)) throw new \RuntimeException('Project root is not a directory: ' . $path);
        $autoload = $root . '/vendor/autoload.php';
        if (!is_file($autoload)) throw new \RuntimeException('Project autoloader not found: ' . $autoload);
        require_once $autoload;
        $test = new Test($root, quiet: $options['quiet']);
        $test->bootstrap();
        foreach ($options['sets'] as [$path, $value]) Settings::set($path, $value);
        if ($options['casepath'] !== null) Settings::set('test.casepath', $options['casepath']);
        if ($options['savepath'] !== null) Settings::set('test.savepath', $options['savepath']);
        foreach ($options['console'] as $path => $value) Settings::set('test.console.' . $path, $value);
        return $test->run(workers: $options['workers'], browser: $options['browser'], headless: $options['headless'],
            selectors: $options['selectors'], title: $options['title']);
    }

    /** Run arguments excluding the script name, using the launcher's directory as the discovery start. */
    public static function run(array $arguments, string $directory): int
    {
        try {
            return self::main($arguments, $directory);
        } catch (\Throwable $error) {
            fwrite(STDERR, 'ERROR ' . $error->getMessage() . PHP_EOL);
            return 1;
        }
    }
}
