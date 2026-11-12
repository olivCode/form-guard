<?php

declare(strict_types=1);

// Portable local runner: does not depend on an installed Composer executable.
spl_autoload_register(static function (string $class): void {
    $prefix = 'FormGuard\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
foreach ([__DIR__ . '/.runtime', dirname(__DIR__) . '/build'] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create test directory.');
    }
}
