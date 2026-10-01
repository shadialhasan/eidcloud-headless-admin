<?php

declare(strict_types=1);

// PSR-4 Autoloader fallback (Zero external dependencies)
spl_autoload_register(function ($class) {
    $prefix = 'EidCloud\\HeadlessAdmin\\';
    $baseDir = __DIR__ . '/../src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
