<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

use FormGuard\Guard;
use FormGuard\Rejection;
use FormGuard\RejectionReason;
use FormGuard\Storage\AtomicFileWriter;
use FormGuard\Storage\FileStore;
use FormGuard\Storage\InMemoryStore;
use FormGuard\Storage\State;
use FormGuard\Storage\StorageException;
use FormGuard\Translation\TranslatorInterface;

$checks = [];
$skipped = [];
function vcheck(string $name, bool $ok): void
{
    global $checks;
    $checks[] = compact('name', 'ok');
    if (!$ok) {
        throw new RuntimeException($name);
    }
}
function vreject(string $name, callable $task, RejectionReason $reason): Rejection
{
    try {
        $task();
    } catch (Rejection $error) {
        vcheck($name, $error->reason === $reason && $error->getCode() === $reason->httpStatus());
        return $error;
    }
    throw new RuntimeException('Expected rejection: ' . $name);
}
function vstorage(string $name, callable $task, string $reason): StorageException
{
    try {
        $task();
    } catch (StorageException $error) {
        vcheck($name, $error->reason === $reason);
        return $error;
    }
    throw new RuntimeException('Expected storage failure: ' . $name);
}
function vform(Guard $guard, array &$forms, int $time = 100000): array
{
    $form = $guard->issueForm($forms, ['email'], $time);
    return [$form, ['form_id' => $form['id'], 'csrf_token' => $form['csrf'], $form['fields']['honeypot'] => '', 'as_js' => '0', 'as_trap' => $form['js_trap'], 'as_interactions' => '0']];
}
$root = __DIR__ . '/.runtime/storage-' . bin2hex(random_bytes(5));
mkdir($root, 0700);
$dir = fn (string $label): string => $root . '/' . $label;
$memory = new InMemoryStore('forms');
$forms = [];
[$form, $post] = vform(new Guard([], $memory), $forms);
$english = new Guard([], $memory);
$french = new Guard(['locale' => 'fr'], $memory);
$en = vreject('English structured reason', fn () => $english->validateForm($post, $forms, 100001), RejectionReason::TooFast);
$fr = vreject('French structured reason', fn () => $french->validateForm($post, $forms, 100001), RejectionReason::TooFast);
vcheck('same reason and parameters in both languages', $en->reason === $fr->reason && $en->parameters === $fr->parameters && $fr->parameters['seconds'] === 2);
vcheck('French text and interpolation', str_contains($fr->getMessage(), 'patienter') && str_contains($fr->getMessage(), '2 s') && !str_contains($fr->getMessage(), '{seconds}'));
vcheck('English text', str_contains($en->getMessage(), 'Please wait'));
foreach ([['fr_CA', 'patienter'], ['zz', 'Please wait']] as [$locale, $text]) {
    $g = new Guard(['locale' => $locale], $memory);
    $e = vreject('locale fallback ' . $locale, fn () => $g->validateForm($post, $forms, 100001), RejectionReason::TooFast);
    vcheck('fallback text ' . $locale, str_contains($e->getMessage(), $text));
}
$g = new Guard(['locale' => 'zz', 'fallback_locale' => 'fr'], $memory);
$e = vreject('explicit fallback', fn () => $g->validateForm($post, $forms, 100001), RejectionReason::TooFast);
vcheck('explicit fallback French', str_contains($e->getMessage(), 'patienter'));
$g = new Guard(['locale' => 'de', 'messages' => ['de' => ['too_fast' => 'Bitte {seconds} s warten.']]], $memory);
$e = vreject('custom language', fn () => $g->validateForm($post, $forms, 100001), RejectionReason::TooFast);
vcheck('custom language interpolated', $e->getMessage() === 'Bitte 2 s warten.');
$e = vreject('partial catalogue falls back', fn () => $g->validateForm(array_replace($post, ['csrf_token' => 'bad']), $forms, 100004), RejectionReason::InvalidToken);
vcheck('partial catalogue English fallback', str_contains($e->getMessage(), 'verified'));
$g = new Guard(['locale' => 'fr'], $memory, fn ($key, $parameters, $locale) => $key === 'too_fast' ? $locale . ':' . $parameters['seconds'] : null);
$e = vreject('external callable', fn () => $g->validateForm($post, $forms, 100001), RejectionReason::TooFast);
vcheck('external receives parameters and locale', $e->getMessage() === 'fr:2');
$e = vreject('external missing translation falls back', fn () => $g->validateForm([], $forms, 100004), RejectionReason::FormUnavailable);
vcheck('external fallback is French', str_contains($e->getMessage(), 'indisponible'));
$adapter = new class () implements TranslatorInterface {
    public function translate(string $key, array $parameters, string $locale): string
    {
        return $key;
    }
};
$g = new Guard(['locale' => 'fr'], $memory, $adapter);
$e = vreject('interface adapter fallback', fn () => $g->validateForm($post, $forms, 100001), RejectionReason::TooFast);
vcheck('interface fallback applies', str_contains($e->getMessage(), 'patienter'));
$translationStore = new FileStore($dir('translation-lock'), dirname(__DIR__));
$translatedAfterCommit = false;
$g = new Guard(['client_limit' => 1, 'locale' => 'fr'], $translationStore, function ($key) use ($translationStore, &$translatedAfterCommit) {
    if ($key === 'rate_limited') {
        $translatedAfterCommit = $translationStore->transaction(fn (State $state): bool => max(array_column($state->buckets, 'until')) > 0);
    }
    return null;
});
$g->beginRequest('192.0.2.1', 'a', 's', 110000);
vreject('external translator runs outside committed transaction', fn () => $g->beginRequest('192.0.2.1', 'a', 's', 110001), RejectionReason::RateLimited);
vcheck('external translator can inspect committed ban without deadlock', $translatedAfterCommit);
foreach ([['locale', ''], ['locale', []], ['messages', ['fr' => ['bogus' => 'x']]], ['messages', ['fr' => ['too_fast' => []]]], ['messages', ['fr' => ['too_fast' => '']]], ['clock_tolerance', -1], ['ip_ban', 'false']] as [$key, $value]) {
    try {
        new Guard([$key => $value]);
        throw new RuntimeException('Invalid option accepted.');
    } catch (InvalidArgumentException) {
        vcheck('invalid new option ' . $key . count($checks), true);
    }
}

// Forms: tabs, native/AJAX metadata, back navigation, session expiry and schema failures.
$g = new Guard([], $memory);
$tabs = [];
[$first, $firstPost] = vform($g, $tabs);
[$second, $secondPost] = vform($g, $tabs, 100001);
vreject('form cannot cross application scopes', fn () => (new Guard(['scope' => 'other-endpoint'], $memory))->validateForm($firstPost, $tabs, 100005), RejectionReason::FormUnavailable);
vcheck('locale change preserves valid form', $french->validateForm($firstPost, $tabs, 100005)['id'] === $first['id']);
vcheck('tabs have independent CSRF', $first['csrf'] !== $second['csrf']);
$g->validateForm($firstPost, $tabs, 100005);
$g->consumeForm($tabs, $first['id']);
vcheck('one tab consumption preserves another', $g->validateForm($secondPost, $tabs, 100005)['id'] === $second['id']);
vreject('back navigation cannot replay consumed form', fn () => $g->validateForm($firstPost, $tabs, 100005), RejectionReason::FormUnavailable);
vreject('session loss rejects saved HTML', fn () => $g->validateForm($secondPost, [], 100005), RejectionReason::FormUnavailable);
$migratedSession = $tabs;
vcheck('session ID rotation can preserve form data', $g->validateForm($secondPost, $migratedSession, 100005)['id'] === $second['id']);
vreject('long-open form expires', fn () => $g->validateForm($secondPost, $tabs, 107202), RejectionReason::FormExpired);
vreject('future form rejected', fn () => $g->validateForm($secondPost, $tabs, 100000), RejectionReason::FormExpired);
$g->consumeForm($tabs, $second['id']);
[$renewed, $renewPost] = vform($g, $tabs, 100006);
$public = $g->publicForm($renewed);
vcheck('AJAX metadata complete', count($public) === 4 && $public['id'] !== $second['id'] && $public['fields']['email'] !== $second['fields']['email']);
vreject('AJAX renewal keeps minimum delay', fn () => $g->validateForm($renewPost, $tabs, 100007), RejectionReason::TooFast);
$renewPost['as_js'] = '1';
$renewPost['as_trap'] = '';
$renewPost['as_interactions'] = '1';
vcheck('AJAX renewed form accepted with one keyboard action', $g->validateForm($renewPost, $tabs, 100009)['id'] === $renewed['id']);
$broken = $tabs;
$broken[$renewed['id']]['created'] = [];
vreject('malformed server-held form fails safely', fn () => $g->validateForm($renewPost, $broken, 100010), RejectionReason::FormUnavailable);
try {
    $g->issueForm($tabs, [['bad']]);
    throw new RuntimeException('Invalid field name accepted');
} catch (InvalidArgumentException) {
    vcheck('logical field validation', true);
}

// Storage contract and atomicity: callback failures roll back and release locks.
foreach ([new InMemoryStore('contract'), new FileStore($dir('contract'), 'contract')] as $store) {
    $r = new Guard([], $store);
    $r->beginRequest('192.0.2.1', 'agent', 'session', 200000);
    $before = $store->transaction(fn (State $state) => $state->toArray());
    try {
        $store->transaction(function (State $state): void {
            $state->buckets = [];
            throw new LogicException('rollback');
        });
    } catch (LogicException) {
    }
    vcheck('transaction rollback ' . get_class($store), $store->transaction(fn (State $state) => $state->toArray()) === $before);
    vstorage('nested transaction rejected ' . get_class($store), fn () => $store->transaction(fn () => $store->transaction(fn () => null)), 'nested_transaction');
    $r->beginRequest('192.0.2.1', 'agent', 'session', 200001);
    vcheck('lock released after callback exception ' . get_class($store), true);
}
$r = new Guard(['runtime_dir' => $dir('atomic')]);
$r->beginRequest('192.0.2.1', 'agent', 'session', 300000);
$stateFile = $dir('atomic') . '/form-guard-state.json';
$hash = hash_file('sha256', $stateFile);
$writer = new class () extends AtomicFileWriter {
    protected function replace(string $temporary, string $path): void
    {
        throw new StorageException('simulated_interruption');
    }
};
$failed = new Guard([], new FileStore($dir('atomic'), dirname(__DIR__), 8388608, $writer));
vstorage('interrupted replacement fails closed', fn () => $failed->beginRequest('192.0.2.1', 'agent', 'session', 300001), 'simulated_interruption');
vcheck('interrupted replacement preserves committed snapshot', hash_file('sha256', $stateFile) === $hash);
vcheck('interrupted replacement cleans its temporary file', count(glob($dir('atomic') . '/.form-guard-write-*.tmp')) === 0);
$r->beginRequest('192.0.2.1', 'agent', 'session', 300002);
vcheck('request works after failed writer removed', true);
$store = new FileStore($dir('atomic'), dirname(__DIR__));
$stale = $dir('atomic') . '/.form-guard-write-' . str_repeat('a', 24) . '.tmp';
$recent = $dir('atomic') . '/.form-guard-write-' . str_repeat('b', 24) . '.tmp';
foreach ([$stale, $recent, $dir('atomic') . '/unrelated.txt'] as $file) {
    file_put_contents($file, 'partial');
    chmod($file, 0600);
}
$wall = time();
touch($stale, $wall - 7200);
touch($recent, $wall - 30);
vcheck('stale temporary file cleanup is selective', $store->cleanupTemporaryFiles($wall - 3600) === 1 && !file_exists($stale) && file_exists($recent) && file_exists($dir('atomic') . '/unrelated.txt'));

// State corruption, missing files, replacement keys and scopes never silently reset bans.
foreach (['json', 'empty', 'key', 'missing-key', 'missing-state', 'scope', 'oversized', 'version', 'entry', 'tamper'] as $case) {
    $path = $dir('damage-' . $case);
    $r = new Guard(['runtime_dir' => $path]);
    $r->beginRequest('192.0.2.1', 'a', 's', 400000);
    $state = $path . '/form-guard-state.json';
    $key = $path . '/form-guard.key';
    $expected = '';
    switch ($case) {
        case 'json': file_put_contents($state, '{invalid');
            $expected = 'invalid_json';
            break;
        case 'empty': file_put_contents($state, '');
            $expected = 'invalid_json';
            break;
        case 'key': file_put_contents($key, random_bytes(32));
            $expected = 'key_changed';
            break;
        case 'missing-key': unlink($key);
            $expected = 'missing_key';
            break;
        case 'missing-state': unlink($state);
            $expected = 'missing_state';
            break;
        case 'scope': $r = new Guard(['runtime_dir' => $path, 'scope' => 'different-app']);
            $expected = 'scope_changed';
            break;
        case 'oversized': $r = new Guard(['runtime_dir' => $path, 'max_state_bytes' => 1024]);
            file_put_contents($state, str_repeat(' ', 1025));
            $expected = 'state_too_large';
            break;
        case 'version': $data = json_decode(file_get_contents($state), true);
            $data['version'] = 99;
            file_put_contents($state, json_encode($data));
            $expected = 'invalid_state';
            break;
        case 'entry': $data = json_decode(file_get_contents($state), true);
            $firstKey = array_key_first($data['buckets']);
            $data['buckets'][$firstKey]['last'] = 'not-an-integer';
            file_put_contents($state, json_encode($data));
            $expected = 'invalid_state';
            break;
        case 'tamper': $data = json_decode(file_get_contents($state), true);
            $data['buckets'] = [];
            file_put_contents($state, json_encode($data));
            $expected = 'signature_invalid';
            break;
    }
    vstorage('state damage ' . $case, fn () => $r->beginRequest('192.0.2.1', 'a', 's', 400001), $expected);
    $repair = new FileStore($path, dirname(__DIR__));
    $repair->reset();
    $fresh = new Guard(['runtime_dir' => $path]);
    $fresh->beginRequest('192.0.2.1', 'a', 's', 400002);
    vcheck('explicit reset recovers ' . $case, true);
}

// Clock rollback, normalized IPs, shared networks and a separate opt-in legacy policy.
$r = new Guard([], new InMemoryStore());
$r->beginRequest('192.0.2.1', 'a', 's', 500000);
$r->beginRequest('192.0.2.1', 'a', 's', 499998);
vcheck('small clock rollback clamped', true);
vstorage('large clock rollback blocks action', fn () => $r->beginRequest('192.0.2.1', 'a', 's', 499990), 'clock_moved_back');
$r->beginRequest('192.0.2.1', 'a', 's', 500001);
vcheck('clock rollback does not corrupt state', true);
foreach ([['192.0.2.1, 192.0.2.2', 's'], ['unknown', 's'], ['192.0.2.1', '']] as [$ip, $session]) {
    try {
        $r->beginRequest($ip, 'a', $session, 500002);
        throw new RuntimeException('Invalid identity accepted.');
    } catch (InvalidArgumentException) {
        vcheck('unverified identity rejected ' . count($checks), true);
    }
}
$r = new Guard([], new InMemoryStore());
for ($i = 0; $i < 5; $i++) {
    $r->beginRequest('2001:db8::1', 'a', 's', 510000 + $i);
}
vreject('IPv6 equivalent forms share limits', fn () => $r->beginRequest('2001:0db8:0:0:0:0:0:1', 'a', 's', 510005), RejectionReason::RateLimited);
$r = new Guard(['client_limit' => 2, 'ip_limit' => 4, 'window' => 60], new InMemoryStore());
$r->beginRequest('192.0.2.10', 'a', 'one', 520000);
$r->beginRequest('192.0.2.10', 'a', 'one', 520001);
$e = vreject('client banned separately from shared IP', fn () => $r->beginRequest('192.0.2.10', 'a', 'one', 520002), RejectionReason::RateLimited);
vcheck('client ban reports scope', $e->parameters['limit_scope'] === 'client');
for ($i = 0; $i < 10; $i++) {
    vreject('blocked client cannot consume shared quota ' . $i, fn () => $r->beginRequest('192.0.2.10', 'a', 'one', 520003 + $i), RejectionReason::RateLimited);
}
$r->beginRequest('192.0.2.10', 'a', 'coworker', 520014);
vcheck('coworker still allowed after repeated blocked requests', true);
$e = vreject('shared IP ceiling throttles', fn () => $r->beginRequest('192.0.2.10', 'a', 'other', 520015), RejectionReason::RateLimited);
vcheck('shared ceiling is short remaining window, not one-hour ban', $e->retryAfter === 45 && $e->parameters['limit_scope'] === 'ip');
$r->beginRequest('192.0.2.10', 'a', 'other', 520060);
vcheck('shared IP recovers at first slot expiration', true);
$r = new Guard(['ip_limit' => 1, 'ip_ban' => true], new InMemoryStore());
$r->beginRequest('192.0.2.1', 'a', 's1', 530000);
$e = vreject('opt-in progressive IP ban', fn () => $r->beginRequest('192.0.2.1', 'a', 's2', 530001), RejectionReason::RateLimited);
vcheck('opt-in IP ban starts at one minute', $e->retryAfter === 60);

// Explicit legacy migration preserves the original HMAC key and active bans.
$legacyDir = $dir('legacy');
mkdir($legacyDir, 0700);
$secret = random_bytes(32);
$key = 'client:' . hash_hmac('sha256', '192.0.2.1|a|s', $secret);
$legacy = [$key => ['attempts' => [600000], 'last' => 600000, 'until' => 603600, 'strikes' => 1, 'incident' => 600000]];
file_put_contents($legacyDir . '/form-guard.key', $secret);
chmod($legacyDir . '/form-guard.key', 0600);
file_put_contents($legacyDir . '/form-guard-state.json', json_encode($legacy));
chmod($legacyDir . '/form-guard-state.json', 0600);
$r = new Guard(['runtime_dir' => $legacyDir]);
vstorage('legacy format requires explicit migration', fn () => $r->beginRequest('192.0.2.1', 'a', 's', 600001), 'legacy_state');
$store = new FileStore($legacyDir, dirname(__DIR__));
vcheck('legacy migration preserves bucket count', $store->migrate(600001) === 1);
vcheck('legacy migration retains original key', file_get_contents($legacyDir . '/form-guard.key') === $secret);
$e = vreject('migrated ban retained', fn () => $r->beginRequest('192.0.2.1', 'a', 's', 600002), RejectionReason::RateLimited);
vcheck('migrated ban has remaining duration', $e->retryAfter === 3598);
vcheck('migration is idempotent', $store->migrate(600003) === 0);
vcheck('prune preserves active ban', $r->prune(600004) === 0);
vcheck('scheduled prune removes expired history', $r->prune(686401) === 1);
vcheck('prune remains idempotent', $r->prune(686402) === 0);

if (DIRECTORY_SEPARATOR === '/') {
    $path = $dir('permissions');
    mkdir($path, 0755);
    vstorage('public runtime permissions rejected', fn () => (new FileStore($path, 'test'))->runtimeDir(), 'unsafe_permissions');
} else {
    $skipped[] = 'POSIX ownership/permission enforcement: requires Linux/macOS; Windows ACLs must be configured by the operator.';
}
$path = $dir('links');
mkdir($path, 0700);
$outside = $dir('outside.txt');
file_put_contents($outside, 'do-not-touch');
chmod($outside, 0600);
if (@symlink($outside, $path . '/form-guard.key')) {
    vstorage('symlink key rejected', fn () => (new Guard(['runtime_dir' => $path]))->beginRequest('192.0.2.1', 'a', 's', 700000), 'unsafe_file');
    vcheck('symlink target unchanged', file_get_contents($outside) === 'do-not-touch');
} else {
    $skipped[] = 'Symlink security test: symlink creation unavailable for this local user.';
}
file_put_contents(dirname(__DIR__) . '/build/storage-results.json', json_encode(['checks' => $checks, 'skipped' => $skipped], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo count($checks) . ' v0.2 checks passed; ' . count($skipped) . " platform-specific checks deferred.\n";
