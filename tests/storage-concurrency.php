<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';
$runtime = __DIR__ . '/.runtime/maintenance-race-' . bin2hex(random_bytes(5));
mkdir($runtime, 0700);
$jobs = [];
for ($i = 0; $i < 32; $i++) {
    $jobs[] = $i % 4 === 0 ? 'prune' : 'request';
}
$next = 0;
$pending = [];
$results = [];
while ($next < count($jobs) || $pending) {
    while ($next < count($jobs) && count($pending) < 8) {
        $pipes = [];
        $id = $next++;
        $process = proc_open([PHP_BINARY, __DIR__ . '/concurrency-worker.php', $runtime, (string) $id, $jobs[$id]], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start maintenance race worker.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $pending[$id] = compact('process', 'pipes');
    }
    foreach ($pending as $id => $worker) {
        $status = proc_get_status($worker['process']);
        if ($status['running']) {
            continue;
        }
        $out = stream_get_contents($worker['pipes'][1]);
        $err = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        proc_close($worker['process']);
        if ($status['exitcode'] !== 0 || $err !== '') {
            throw new RuntimeException('Maintenance race worker failed: ' . $err);
        }
        $results[] = $out;
        unset($pending[$id]);
    }
    if ($pending) {
        usleep(10000);
    }
}
$state = json_decode(file_get_contents($runtime . '/form-guard-state.json'), true, 64, JSON_THROW_ON_ERROR);
$shared = array_filter($state['buckets'], fn ($key) => str_starts_with($key, 'ip:'), ARRAY_FILTER_USE_KEY);
if (count(array_filter($results, fn ($r) => $r === '200')) !== 24 || count(array_filter($results, fn ($r) => $r === 'PRUNED')) !== 8
    || count($shared) !== 1 || count(array_values($shared)[0]['attempts']) !== 24) {
    throw new RuntimeException('Maintenance race lost counters.');
}
file_put_contents(dirname(__DIR__) . '/build/storage-concurrency-results.json', json_encode(['requests' => 24, 'maintenance_operations' => 8, 'requests_retained' => 24], JSON_PRETTY_PRINT));
echo "32 concurrent request/maintenance operations passed; all 24 request counters retained.\n";
