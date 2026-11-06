<?php

// Ignore graceful termination so tree recovery must escalate, including for grandchildren.
if (function_exists('pcntl_signal')) pcntl_signal(SIGTERM, SIG_IGN);
if (!isset($argv[2])) {
    $child = proc_open([PHP_BINARY, __FILE__, $argv[1], 'grandchild'],
        [0 => ['null'], 1 => STDOUT, 2 => STDERR], $pipes);
    $name = 'descendant';
} else {
    $name = 'grandchild';
}
file_put_contents($argv[1] . '/' . $name . '.pid', getmypid());
sleep(30);
