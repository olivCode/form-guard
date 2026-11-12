<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

$runtime = __DIR__ . '/.runtime/load-' . bin2hex(random_bytes(5));
mkdir($runtime, 0700);
$workers = [];
$started = hrtime(true);
try {
    for ($id = 0; $id < 8; $id++) {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/load-worker.php', $runtime, (string) $id],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start load worker.');
        }
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $latencies = [];
    $peak = 0;
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || $error !== '') {
            throw new RuntimeException('Load worker failed: ' . $error);
        }
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $latencies = [...$latencies, ...$result['latencies_ms']];
        $peak = max($peak, $result['peak_memory_bytes']);
    }
    $workers = [];
    $store = new FormGuard\Storage\FileStore($runtime, 'load-test');
    $buckets = $store->transaction(static fn (FormGuard\Storage\State $state): array => $state->buckets);
    $requests = 0;
    $clients = 0;
    foreach ($buckets as $key => $bucket) {
        if (str_starts_with($key, 'client:')) {
            $clients++;
            $requests += count($bucket['attempts']);
        }
    }
    if (count($latencies) !== 256 || $requests !== 256 || $clients !== 256 || count($buckets) !== 257) {
        throw new RuntimeException('Concurrent load lost state or requests.');
    }
    sort($latencies);
    $report = ['workers' => 8, 'requests' => 256, 'retained_clients' => $clients,
        'wall_ms' => round((hrtime(true) - $started) / 1e6, 2),
        'latency_p50_ms' => round($latencies[127], 2), 'latency_p95_ms' => round($latencies[243], 2),
        'worker_peak_memory_bytes' => $peak, 'state_bytes' => filesize($runtime . '/form-guard-state.json'),
        'php' => PHP_VERSION, 'os' => PHP_OS_FAMILY];
    file_put_contents(dirname(__DIR__) . '/build/load-results.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo '256 requests across 8 workers passed; no lost counters. ' . json_encode($report, JSON_THROW_ON_ERROR) . "\n";
} finally {
    foreach ($workers as [$process, $pipes]) {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }
}
