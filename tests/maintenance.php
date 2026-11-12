<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';
use FormGuard\Guard;

$checks = [];
function mcheck(string $name, bool $ok): void
{
    global $checks;
    $checks[] = compact('name', 'ok');
    if (!$ok) {
        throw new RuntimeException($name);
    }
}
function cli_m(array $arguments): array
{
    $pipes = [];
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/form-guard', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start maintenance CLI.');
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out, $err];
}
$directory = __DIR__ . '/.runtime/maintenance-' . bin2hex(random_bytes(5));
$scope = 'maintenance-test';
$guard = new Guard(['runtime_dir' => $directory, 'scope' => $scope]);
$now = time();
$guard->beginRequest('192.0.2.1', 'synthetic', 'session', $now - 700);
$args = ['--runtime-dir=' . $directory, '--scope=' . $scope];
[$code, $out, $err] = cli_m(['status', ...$args]);
mcheck('status lists only safe metadata', $code === 0 && $err === '' && json_decode($out, true)['buckets'] === 2 && !str_contains($out, '192.0.2.1') && !str_contains($out, $directory));
[$code, $out, $err] = cli_m(['prune', ...$args]);
mcheck('scheduled prune removes expired buckets', $code === 0 && $err === '' && json_decode($out, true)['removed_buckets'] === 2);
$state = $directory . '/form-guard-state.json';
$before = hash_file('sha256', $state);
[$code] = cli_m(['reset', ...$args]);
mcheck('reset requires explicit confirmation', $code === 2 && hash_file('sha256', $state) === $before);
$oldKey = file_get_contents($directory . '/form-guard.key');
[$code, $out, $err] = cli_m(['reset', ...$args, '--confirm-reset']);
mcheck('confirmed reset clears state and rotates key', $code === 0 && $err === '' && json_decode($out, true)['reset'] && file_get_contents($directory . '/form-guard.key') !== $oldKey);
file_put_contents($state, '{invalid');
[$code, $out, $err] = cli_m(['status', ...$args]);
mcheck('corruption gives safe nonzero diagnostic', $code === 1 && $out === '' && str_contains($err, 'invalid_json') && !str_contains($err, $directory));
[$code] = cli_m(['reset', ...$args, '--confirm-reset']);
mcheck('operator can recover corrupt state explicitly', $code === 0);
foreach ([['prune'], ['unknown'], ['status', ...$args, '--unknown'], ['status', ...$args, '--scope=x']] as $arguments) {
    [$code] = cli_m($arguments);
    mcheck('invalid CLI arguments ' . count($checks), $code === 2);
}
[$code, $out] = cli_m(['help']);
mcheck('help does not require a runtime', $code === 0 && str_contains($out, '--confirm-reset'));

$legacyDir = __DIR__ . '/.runtime/maintenance-legacy-' . bin2hex(random_bytes(5));
mkdir($legacyDir, 0700);
$secret = random_bytes(32);
$key = 'client:' . hash_hmac('sha256', '192.0.2.1|a|s', $secret);
$legacy = [$key => ['attempts' => [$now], 'last' => $now, 'until' => $now + 3600, 'strikes' => 1, 'incident' => $now]];
foreach (['form-guard.key' => $secret, 'form-guard-state.json' => json_encode($legacy)] as $file => $contents) {
    file_put_contents($legacyDir . '/' . $file, $contents);
    chmod($legacyDir . '/' . $file, 0600);
}
$args = ['--runtime-dir=' . $legacyDir, '--scope=' . $scope];
[$code, $out, $err] = cli_m(['status', ...$args]);
mcheck('legacy status requires migration', $code === 1 && str_contains($err, 'legacy_state'));
[$code, $out, $err] = cli_m(['migrate', ...$args]);
mcheck('CLI migration succeeds without dropping bans', $code === 0 && $err === '' && json_decode($out, true)['migrated_buckets'] === 1);
mcheck('CLI migration preserves key', file_get_contents($legacyDir . '/form-guard.key') === $secret);
try {
    (new Guard(['runtime_dir' => $legacyDir, 'scope' => $scope]))->beginRequest('192.0.2.1', 'a', 's');
    throw new RuntimeException('Migration lost ban.');
} catch (FormGuard\Rejection $e) {
    mcheck('CLI migrated ban remains effective', $e->retryAfter > 3500);
}
file_put_contents(dirname(__DIR__) . '/build/maintenance-results.json', json_encode($checks, JSON_PRETTY_PRINT));
echo count($checks) . " maintenance CLI checks passed.\n";
