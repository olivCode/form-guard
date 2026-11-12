<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';
try {
    $guard = new FormGuard\Guard(['runtime_dir' => $argv[1]]);
    if (($argv[3] ?? '') === 'prune') {
        $guard->prune(600000);
        echo 'PRUNED';
        exit;
    }
    $guard->beginRequest('192.0.2.100', 'worker-' . $argv[2], 'session-' . $argv[2], 600000);
    echo '200';
} catch (FormGuard\Rejection $error) {
    echo $error->getCode();
}
