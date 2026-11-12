<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

$guard = new FormGuard\Guard(['scope' => 'load-test', 'runtime_dir' => $argv[1], 'ip_limit' => 1000]);
$latencies = [];
for ($i = 0; $i < 32; $i++) {
    $start = hrtime(true);
    $guard->beginRequest('192.0.2.99', 'synthetic-load', $argv[2] . '-' . $i, 100000);
    $latencies[] = (hrtime(true) - $start) / 1e6;
}
echo json_encode(['latencies_ms' => $latencies, 'peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR);
