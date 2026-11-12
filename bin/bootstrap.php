<?php

declare(strict_types=1);

// Composer's bin proxy supplies this path; source examples also work standalone.
if (isset($_composer_autoload_path) && is_string($_composer_autoload_path)) {
    require_once $_composer_autoload_path;
} else {
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
}
