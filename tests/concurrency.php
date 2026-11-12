<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';
$runtime = __DIR__ . '/.runtime/concurrent-' . bin2hex(random_bytes(5));
mkdir($runtime, 0700);
$pending = [];
$results = [];
$next = 0;
while ($next < 40 || $pending) {
    while ($next < 40 && count($pending) < 8) {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/concurrency-worker.php', $runtime, (string) $next],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start concurrency worker.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $pending[$next++] = ['process' => $process, 'pipes' => $pipes];
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
        if ($status['exitcode'] !== 0 || $err !== '' || !in_array($out, ['200', '429'], true)) {
            throw new RuntimeException('Worker failed: ' . $err . $out);
        }
        $results[] = $out;
        unset($pending[$id]);
    }
    if ($pending) {
        usleep(10000);
    }
}
$summary = ['requests' => 40, 'accepted' => count(array_filter($results, fn ($code) => $code === '200')), 'blocked' => count(array_filter($results, fn ($code) => $code === '429'))];
if ($summary['accepted'] !== 30 || $summary['blocked'] !== 10) {
    throw new RuntimeException('Concurrent limit failed: ' . json_encode($summary));
}
file_put_contents(dirname(__DIR__) . '/build/concurrency-results.json', json_encode($summary, JSON_PRETTY_PRINT));
echo "40 concurrent requests checked: 30 accepted, 10 blocked.\n";
