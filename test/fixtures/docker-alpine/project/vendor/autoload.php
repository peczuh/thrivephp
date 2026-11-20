<?php

// Load the read-only checkout as the application's Composer install would.
spl_autoload_register(static function (string $class): void {
    $prefix = 'ThriveData\\ThrivePHP\\';
    if (str_starts_with($class, $prefix)) {
        $file = '/repo/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) require $file;
    }
});
