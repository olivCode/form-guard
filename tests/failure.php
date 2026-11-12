<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

$checks = [];
$check = static function (string $name, bool $ok) use (&$checks): void {
    $checks[] = compact('name', 'ok');
    if (!$ok) {
        throw new RuntimeException($name);
    }
};
$runtime = __DIR__ . '/.runtime/crash-' . bin2hex(random_bytes(5));
$marker = $runtime . '-marker';
$guard = new FormGuard\Guard(['scope' => 'crash-test', 'runtime_dir' => $runtime]);
$guard->beginRequest('192.0.2.100', 'crash-worker', 'initial', 100000);
$snapshot = file_get_contents($runtime . '/form-guard-state.json');
$pipes = [];
$process = proc_open(
    [PHP_BINARY, __DIR__ . '/crash-worker.php', $runtime, $marker],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
if (!is_resource($process)) {
    throw new RuntimeException('Cannot start crash worker.');
}
fclose($pipes[0]);
try {
    $deadline = microtime(true) + 10;
    while (!is_file($marker) && microtime(true) < $deadline) {
        usleep(10000);
        clearstatcache(true, $marker);
    }
    $check('worker reached pre-commit barrier', is_file($marker));
    proc_terminate($process);
    $deadline = microtime(true) + 5;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        } usleep(10000);
    } while (microtime(true) < $deadline);
    $check('worker terminated', !$status['running']);
    $check('committed snapshot survives process death', file_get_contents($runtime . '/form-guard-state.json') === $snapshot);
} finally {
    proc_terminate($process);
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($process);
    if (is_file($marker)) {
        unlink($marker);
    }
}
$guard->beginRequest('192.0.2.100', 'crash-worker', 'recovered', 100002);
$store = new FormGuard\Storage\FileStore($runtime, 'crash-test');
$check('lock released and next request committed', $store->transaction(static fn (FormGuard\Storage\State $state): bool => count($state->buckets) === 3));
$leftovers = count(glob($runtime . '/.form-guard-write-*.tmp') ?: []);
foreach (glob($runtime . '/.form-guard-write-*.tmp') ?: [] as $temporary) {
    // Set a deliberate stale cutoff; Windows may defer last-write timestamps after termination.
    if (!touch($temporary, time() - 60)) {
        throw new RuntimeException('Cannot age interrupted temporary file.');
    }
}
$removed = $store->cleanupTemporaryFiles(time());
$check('interrupted temporary files are absent or can be cleaned', $removed === $leftovers && (glob($runtime . '/.form-guard-write-*.tmp') ?: []) === []);

$capacityRuntime = __DIR__ . '/.runtime/capacity-' . bin2hex(random_bytes(5));
$capacity = new FormGuard\Guard(['scope' => 'capacity', 'runtime_dir' => $capacityRuntime, 'max_entries' => 2]);
$capacity->beginRequest('192.0.2.101', 'capacity', 'one', 100000);
$before = file_get_contents($capacityRuntime . '/form-guard-state.json');
try {
    $capacity->beginRequest('192.0.2.102', 'capacity', 'two', 100001);
    throw new RuntimeException('Capacity overflow admitted.');
} catch (FormGuard\Storage\StorageException $error) {
    $check('capacity rejects rather than evicting active clients', $error->reason === 'capacity_exceeded');
}
$check('capacity rejection keeps prior state', file_get_contents($capacityRuntime . '/form-guard-state.json') === $before);
file_put_contents(dirname(__DIR__) . '/build/failure-results.json', json_encode($checks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo count($checks) . " process-death/capacity checks passed.\n";
