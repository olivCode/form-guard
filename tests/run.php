<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';
use FormGuard\Guard;
use FormGuard\Rejection;

$checks = [];
function check_as(string $name, bool $ok): void
{
    global $checks;
    $checks[] = compact('name', 'ok');
    if (!$ok) {
        throw new RuntimeException($name);
    }
}
function reject_as(string $name, callable $task, int $code): ?Rejection
{
    try {
        $task();
    } catch (Rejection $e) {
        check_as($name, $e->getCode() === $code);
        return $e;
    }
    check_as($name, false);
    return null;
}
$base = [];
$root = __DIR__ . '/.runtime/run-' . bin2hex(random_bytes(5));
$make = fn (string $label): Guard => new Guard(array_replace($base, ['runtime_dir' => $root . '/' . $label]));
$g = $make('forms');
$forms = [];
$form = $g->issueForm($forms, ['email', 'message'], 200000);
$post = ['form_id' => $form['id'], 'csrf_token' => $form['csrf'], $form['fields']['honeypot'] => '',
    'as_js' => '0', 'as_trap' => $form['js_trap'], 'as_interactions' => '0'];
check_as('native no-JS form accepted', $g->validateForm($post, $forms, 200003)['id'] === $form['id']);
check_as('signals hidden and native mode rendered', str_contains($g->renderSignals($form), 'value="0"') && str_contains($g->renderSignals($form), 'hidden data-antispam-signals'));
foreach ([['csrf_token', 'bad', 403], ['csrf_token', [], 403], ['as_js', '2', 422], ['as_js', [], 422],
    ['as_trap', 'wrong', 422], ['as_trap', [], 422], [$form['fields']['honeypot'], 'bot', 422], [$form['fields']['honeypot'], [], 422]] as [$name, $value, $status]) {
    reject_as('reject malformed ' . $name . ' ' . count($checks), fn () => $g->validateForm(array_replace($post, [$name => $value]), $forms, 200004), $status);
}
$missing = $post;
unset($missing[$form['fields']['honeypot']]);
reject_as('missing honeypot rejected', fn () => $g->validateForm($missing, $forms, 200004), 422);
$missing = $post;
unset($missing['as_js']);
reject_as('missing signals rejected', fn () => $g->validateForm($missing, $forms, 200004), 422);
reject_as('server time blocks immediate submission', fn () => $g->validateForm($post, $forms, 200002), 422);
reject_as('future form rejected', fn () => $g->validateForm($post, $forms, 199999), 403);
reject_as('expired form rejected', fn () => $g->validateForm($post, $forms, 207201), 403);
$js = array_replace($post, ['as_js' => '1', 'as_trap' => '', 'as_interactions' => '1']);
check_as('single keyboard/autofill submit accepted', $g->validateForm($js, $forms, 200004)['id'] === $form['id']);
reject_as('unremoved JS trap rejected', fn () => $g->validateForm(array_replace($js, ['as_trap' => $form['js_trap']]), $forms, 200004), 422);
reject_as('JS without any activity rejected', fn () => $g->validateForm(array_replace($js, ['as_interactions' => '0']), $forms, 200004), 422);
foreach (['-1', '1e3', '1.2', '100000', ['1']] as $bad) {
    reject_as('invalid activity ' . count($checks), fn () => $g->validateForm(array_replace($js, ['as_interactions' => $bad]), $forms, 200004), 422);
}
$disabled = new Guard(array_replace($base, ['behavior_enabled' => false]));
check_as('behavior policy configurable', $disabled->validateForm(array_replace($js, ['as_interactions' => '0']), $forms, 200004)['id'] === $form['id']);
$g->consumeForm($forms, $form['id']);
reject_as('consumed form cannot replay', fn () => $g->validateForm($post, $forms, 200004), 403);
reject_as('double consumption rejected', fn () => $g->consumeForm($forms, $form['id']), 403);
for ($i = 0; $i < 25; $i++) {
    $g->issueForm($forms, ['email'], 200004);
}
check_as('form storage bounded to twenty', count($forms) === 20);
check_as('independent tokens for tabs', count(array_unique(array_column($forms, 'csrf'))) === 20);
$g->issueForm($forms, ['email'], 207205);
check_as('expired forms pruned', count($forms) === 1);

$r = new Guard(['runtime_dir' => $root . '/invalid-requests', 'ip_limit' => 1000]); // Isolate client escalation from the shared-IP window.
$now = 300000;
for ($i = 0; $i < 5; $i++) {
    $r->beginRequest('192.0.2.1', 'QA-agent', 'QA-session', $now + $i);
    reject_as('invalid POST counted ' . $i, fn () => $r->validateForm([], [], $now + $i), 403);
}
$e = reject_as('sixth invalid POST blocked', fn () => $r->beginRequest('192.0.2.1', 'QA-agent', 'QA-session', $now + 5), 429);
check_as('first ban one minute', $e->retryAfter === 60);
$state = json_decode(file_get_contents($r->runtimeDir() . '/form-guard-state.json'), true)['buckets'];
check_as('ban persisted before rejection', max(array_column($state, 'until')) === $now + 5 + 60);
$e = reject_as('same client remains blocked', fn () => $r->beginRequest('192.0.2.1', 'QA-agent', 'QA-session', $now + 10), 429);
check_as('active ban is not extended', $e->retryAfter === 55);
$r->beginRequest('192.0.2.1', 'QA-agent', 'other-session', $now + 11);
check_as('another session allowed below IP ceiling', true);
$r->beginRequest('192.0.2.2', 'QA-agent', 'QA-session', $now + 12);
check_as('another IP unaffected', true);
$time = $now + 65;
$r->beginRequest('192.0.2.1', 'QA-agent', 'QA-session', $time);
check_as('ban expires at its exact deadline', true);
foreach ([120, 240, 480, 960, 1920, 3600, 3600] as $expected) {
    // First request at $time is already counted.
    for ($i = 1; $i < 5; $i++) {
        $r->beginRequest('192.0.2.1', 'QA-agent', 'QA-session', $time + $i);
    }
    $e = reject_as('progressive ban ' . $expected, fn () => $r->beginRequest('192.0.2.1', 'QA-agent', 'QA-session', $time + 5), 429);
    check_as('progressive duration ' . $expected, $e->retryAfter === $expected);
    $time += 5 + $expected;
    $r->beginRequest('192.0.2.1', 'QA-agent', 'QA-session', $time);
}
$text = file_get_contents($r->runtimeDir() . '/form-guard-state.json');
check_as('no IP agent or session stored in clear', !str_contains($text, '192.0.2.') && !str_contains($text, 'QA-agent') && !str_contains($text, 'QA-session'));
$time += 86401;
for ($i = 0; $i < 5; $i++) {
    $r->beginRequest('192.0.2.1', 'QA-agent', 'QA-session', $time + $i);
}
$e = reject_as('ban after quiet period', fn () => $r->beginRequest('192.0.2.1', 'QA-agent', 'QA-session', $time + 5), 429);
check_as('quiet period resets progression', $e->retryAfter === 60);

$r = $make('ip-ceiling');
for ($i = 0; $i < 30; $i++) {
    $r->beginRequest('2001:db8::1', 'agent-' . $i, 'session-' . $i, 400000 + $i);
}
$e = reject_as('IP ceiling resists session and agent rotation', fn () => $r->beginRequest('2001:db8::1', 'new-agent', 'new-session', 400031), 429);
check_as('IP ban returns actual retry', $e->retryAfter === 569);
reject_as('rotated client remains blocked by IP', fn () => $r->beginRequest('2001:db8::1', 'another-agent', 'another-session', 400032), 429);
$r->beginRequest('2001:db8::2', 'agent', 'session', 400033);
check_as('another IPv6 address unaffected', true);
file_put_contents($r->runtimeDir() . '/form-guard-state.json', '{invalid');
try {
    $r->beginRequest('192.0.2.1', 'agent', 'session', 400040);
    check_as('corrupt storage fails closed', false);
} catch (FormGuard\Storage\StorageException $e) {
    check_as('corrupt storage fails closed', true);
}
$full = $make('bounded');
$settings = array_replace($base, ['runtime_dir' => $full->runtimeDir(), 'max_entries' => 2]);
$full = new Guard($settings);
$full->beginRequest('192.0.2.1', 'agent', 'session', 500000);
try {
    $full->beginRequest('192.0.2.2', 'agent', 'session', 500001);
    check_as('storage capacity fails closed', false);
} catch (RuntimeException $e) {
    check_as('storage capacity fails closed', true);
}
foreach ([['unknown', true], ['client_limit', 0], ['scope', ''], ['runtime_dir', []],
    ['behavior_enabled', 'false'], ['base_ban', 100000], ['minimum_behavior_score', 6], ['min_seconds', -1]] as [$option, $value]) {
    try {
        new Guard([$option => $value]);
        check_as('invalid configuration ' . $option, false);
    } catch (InvalidArgumentException) {
        check_as('invalid configuration ' . $option, true);
    }
}
file_put_contents(__DIR__ . '/../build/test-results.json', json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo count($checks) . " Form Guard checks passed; no email sent.\n";
