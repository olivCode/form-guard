<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

use FormGuard\Guard;
use FormGuard\Plugin\FormContext;
use FormGuard\Plugin\FormPluginInterface;
use FormGuard\Plugin\Phase;
use FormGuard\Plugin\PluginExecutionException;
use FormGuard\Plugin\Refusal;
use FormGuard\Plugin\Registry;
use FormGuard\Plugin\RequestContext;
use FormGuard\Plugin\RequestPluginInterface;
use FormGuard\Plugin\TimingPlugin;
use FormGuard\Rejection;
use FormGuard\RejectionReason;
use FormGuard\Storage\InMemoryStore;

$checks = [];
function pcheck(string $name, bool $ok): void
{
    global $checks;
    $checks[] = compact('name', 'ok');
    if (!$ok) {
        throw new RuntimeException($name);
    }
}
function pfail(string $name, callable $task, string $class): Throwable
{
    try {
        $task();
    } catch (Throwable $e) {
        pcheck($name, $e instanceof $class);
        return $e;
    }
    throw new RuntimeException('Expected failure: ' . $name);
}
class FormProbe implements FormPluginInterface
{
    public int $idCalls = 0;
    public int $phaseCalls = 0;
    public function __construct(public string $name, public array $needed = [], public array $incompatible = [], public ?Closure $callback = null, public Phase $stage = Phase::Form)
    {
    }
    public function id(): string
    {
        $this->idCalls++;
        return $this->name;
    }
    public function phase(): Phase
    {
        $this->phaseCalls++;
        return $this->stage;
    }
    public function requires(): array
    {
        return $this->needed;
    }
    public function conflicts(): array
    {
        return $this->incompatible;
    }
    public function evaluate(FormContext $context): ?Refusal
    {
        return $this->callback === null ? null : ($this->callback)($context);
    }
}
class RequestProbe implements RequestPluginInterface
{
    public function __construct(public string $name, public array $needed = [], public ?Closure $callback = null)
    {
    }
    public function id(): string
    {
        return $this->name;
    }
    public function phase(): Phase
    {
        return Phase::Request;
    }
    public function requires(): array
    {
        return $this->needed;
    }
    public function conflicts(): array
    {
        return [];
    }
    public function evaluate(RequestContext $context): ?Refusal
    {
        return $this->callback === null ? null : ($this->callback)($context);
    }
}
function pform(Guard $guard): array
{
    $forms = [];
    $f = $guard->issueForm($forms, ['email'], 100000);
    $post = ['form_id' => $f['id'], 'csrf_token' => $f['csrf'], $f['fields']['email'] => 'synthetic@example.test', $f['fields']['honeypot'] => '', 'as_js' => '0', 'as_trap' => $f['js_trap'], 'as_interactions' => '0'];
    return [$forms, $f, $post];
}
$registry = new Registry([new FormProbe('last', ['first']), new RequestProbe('request'), new FormProbe('first'), new FormProbe('peer')]);
pcheck('deterministic topological phases', array_column($registry->describe(), 'id') === ['request', 'first', 'peer', 'last']);
foreach ([
    [new FormProbe('a', ['missing'])], [new FormProbe('a', ['b']), new FormProbe('b', ['a'])],
    [new FormProbe('a', ['a'])], [new FormProbe('a'), new FormProbe('a')],
    [new FormProbe('a', [], ['b']), new FormProbe('b')],
    [new FormProbe('a'), new RequestProbe('b', ['a'])], [new FormProbe('a', stage: Phase::Request)],
    [new FormProbe('a', ['b', 'b']), new FormProbe('b')], [new FormProbe('a', [new stdClass()])],
    [new FormProbe('bad-name')], [new FormProbe('timing')], [new FormProbe('request_limits')], [new FormProbe('browser_behavior')], [new stdClass()],
] as $i => $invalid) {
    pfail('incoherent plugin set ' . $i, fn () => new Registry($invalid), InvalidArgumentException::class);
}
pfail('associative plugin list rejected', fn () => new Guard(plugins: ['a' => new FormProbe('a')]), InvalidArgumentException::class);
pfail('builtin replacement rejected', fn () => new Guard(['forms' => ['timing' => false]], plugins: [new TimingPlugin(0)]), InvalidArgumentException::class);
pfail('unreachable behavior threshold rejected', fn () => new Guard(['min_interactions' => 100000]), InvalidArgumentException::class);
for ($bits = 0; $bits < 8; $bits++) {
    $timing = (bool) ($bits & 1);
    $limits = (bool) ($bits & 2);
    $behavior = (bool) ($bits & 4);
    $g = new Guard(['forms' => ['timing' => $timing], 'limits' => ['enabled' => $limits], 'browser' => ['enabled' => $behavior]], new InMemoryStore(), plugins: [new FormProbe('extra')]);
    $ids = array_column($g->describePlugins(), 'id');
    pcheck('activation manifest ' . $bits, in_array('timing', $ids, true) === $timing && in_array('request_limits', $ids, true) === $limits && in_array('browser_behavior', $ids, true) === $behavior);
    [$forms, $f, $post] = pform($g);
    pcheck('plugin combination ' . $bits, $g->validateForm($post, $forms, 100004)['id'] === $f['id']);
    $bad = $post;
    $bad['as_trap'] = 'invalid';
    $e = pfail('signal core preserved ' . $bits, fn () => $g->validateForm($bad, $forms, 100004), Rejection::class);
    pcheck('signal reason ' . $bits, $e instanceof Rejection && $e->reason === RejectionReason::InvalidSignals);
    foreach (['timing' => $timing, 'request_limits' => $limits, 'browser_behavior' => $behavior] as $id => $enabled) {
        $factory = fn () => new Guard(['forms' => ['timing' => $timing], 'limits' => ['enabled' => $limits], 'browser' => ['enabled' => $behavior]], plugins: [new FormProbe('dependent', [$id])]);
        if ($enabled) {
            $factory();
            pcheck('enabled dependency ' . $bits . '/' . $id, true);
        } else {
            pfail('disabled dependency refused ' . $bits . '/' . $id, $factory, InvalidArgumentException::class);
        }
    }
}
$probe = new FormProbe('snapshot');
$g = new Guard([], new InMemoryStore(), plugins: [$probe]);
$probe->name = 'changed';
$probe->needed = ['missing'];
$probe->stage = Phase::Request;
[$forms, $f, $post] = pform($g);
$g->validateForm($post, $forms, 100004);
pcheck('metadata snapshotted exactly once', $probe->idCalls === 1 && $probe->phaseCalls === 1 && in_array('snapshot', array_column($g->describePlugins(), 'id'), true));
$calls = [];
$first = new FormProbe('first', callback: function (FormContext $context) use (&$calls): ?Refusal {
    $calls[] = 'first';
    return new Refusal(RejectionReason::CustomRule, ['limit' => 1], code: 'denied');
});
$second = new FormProbe('second', ['first'], callback: function (FormContext $context) use (&$calls): ?Refusal {
    $calls[] = 'second';
    return null;
});
$g = new Guard(['locale' => 'fr', 'messages' => ['fr' => ['rule.first.denied' => 'Limite {limit}.']]], new InMemoryStore(), plugins: [$second, $first]);
[$forms, $f, $post] = pform($g);
$e = pfail('custom plugin refusal translated', fn () => $g->validateForm($post, $forms, 100004), Rejection::class);
pcheck('first refusal cannot be overridden', $calls === ['first'] && $e instanceof Rejection && $e->getMessage() === 'Limite 1.' && $e->ruleId === 'first');
$calls = [];
$bad = $post;
$bad['csrf_token'] = 'wrong';
pfail('core precedes all form plugins', fn () => $g->validateForm($bad, $forms, 100004), Rejection::class);
pcheck('invalid core invokes no plugin', $calls === []);
$shared = 'original';
$array = ['nested' => &$shared];
$context = new FormContext(['data' => $array], 4, false, 0);
$shared = 'mutated';
pcheck('context deep-copies references', $context->fields['data']['nested'] === 'original');
pfail('context read only', function () use ($context): void {
    $context->fields['data']['nested'] = 'changed';
}, Error::class);
pfail('object context rejected', fn () => new FormContext(['x' => new stdClass()], 4, false, 0), InvalidArgumentException::class);
$cycle = [];
$cycle['self'] = &$cycle;
pfail('recursive context bounded', fn () => new FormContext($cycle, 4, false, 0), InvalidArgumentException::class);
foreach ([new Refusal(RejectionReason::CustomRule, code: 'denied')] as $refusal) {
    pcheck('safe custom refusal constructible', $refusal->code === 'denied');
}
foreach ([fn () => new Refusal(RejectionReason::InvalidToken), fn () => new Refusal(RejectionReason::TooFast), fn () => new Refusal(RejectionReason::RateLimited, ['retry_after' => 1, 'limit_scope' => 'ip'], 2), fn () => new Refusal(RejectionReason::CustomRule, ['text' => 'visitor'], code: 'bad')] as $badFactory) {
    pfail('incoherent refusal metadata rejected', $badFactory, InvalidArgumentException::class);
}
$wrong = new FormProbe('wrong', callback: fn () => new Refusal(RejectionReason::RateLimited, ['retry_after' => 1, 'limit_scope' => 'ip'], 1));
$g = new Guard([], new InMemoryStore(), plugins: [$wrong]);
[$forms, $f, $post] = pform($g);
pfail('cross-phase refusal is technical error', fn () => $g->validateForm($post, $forms, 100004), PluginExecutionException::class);
$reentrant = new FormProbe('reentrant');
$g = new Guard([], new InMemoryStore(), plugins: [$reentrant]);
[$forms, $f, $post] = pform($g);
$reentrant->callback = function () use ($g, $post, $forms): ?Refusal {
    $g->validateForm($post, $forms, 100004);
    return null;
};
$e = pfail('reentrant pipeline rejected', fn () => $g->validateForm($post, $forms, 100004), PluginExecutionException::class);
pcheck('reentrancy reason', $e instanceof PluginExecutionException && $e->reason === 'reentrant_plugin');
$reentrant->callback = null;
pcheck('pipeline reusable after failure', $g->validateForm($post, $forms, 100004)['id'] === $f['id']);
$slow = new FormProbe('slow', callback: function (): ?Refusal {
    // The requested sleep is not a reliable minimum on every platform.
    $deadline = hrtime(true) + 20000000;
    do {
        usleep(1000);
    } while (hrtime(true) < $deadline);
    return null;
});
$g = new Guard(['rule_budget_ms' => 1], new InMemoryStore(), plugins: [$slow]);
[$forms, $f, $post] = pform($g);
$e = pfail('late plugin cannot pass', fn () => $g->validateForm($post, $forms, 100004), PluginExecutionException::class);
pcheck('plugin timeout reason', $e instanceof PluginExecutionException && $e->reason === 'plugin_timed_out');
$runtime = __DIR__ . '/.runtime/plugin-lock-' . bin2hex(random_bytes(5));
$store = new FormGuard\Storage\FileStore($runtime, 'plugins');
$count = 0;
$inspect = new RequestProbe('inspect', ['request_limits'], function (RequestContext $context) use ($store, &$count): ?Refusal {
    $count = $store->transaction(fn (FormGuard\Storage\State $state): int => count($state->buckets));
    return null;
});
$g = new Guard(['scope' => 'plugins'], $store, plugins: [$inspect]);
$g->beginRequest('192.0.2.111', 'plugins', 'session', 100000);
pcheck('request custom code runs after committed limiter without lock', $count === 2);
$attempts = $store->transaction(fn (FormGuard\Storage\State $state): int => array_sum(array_map(fn ($entry) => count($entry['attempts']), $state->buckets)));
pcheck('request counted once per bucket', $attempts === 2);
pfail('request context verified even without limiter', fn () => (new Guard(['rate_limit_enabled' => false]))->beginRequest('unknown', 'test', '', 100000), InvalidArgumentException::class);
$waitingStore = new class () implements FormGuard\Storage\StoreInterface {
    public function transaction(callable $operation): mixed
    {
        usleep(1100000); // Simulate waiting for another worker to commit.
        $state = new FormGuard\Storage\State(random_bytes(32), hash('sha256', 'clock'), clock: time());
        return $operation($state);
    }
};
(new Guard(['clock_tolerance' => 0], $waitingStore))->beginRequest('192.0.2.120', 'clock', 'session');
pcheck('live limiter clock resolved after lock wait', true);
$diagnostic = FormGuard\Configuration::fromArray(['forms' => ['min_seconds' => 0], 'limits' => ['client' => 10, 'ip' => 5], 'browser' => ['minimum_score' => 3]])->describe();
pcheck('neutral/overlapping policies explained', $diagnostic['warnings'] === ['shared_ip_ceiling_below_client', 'timing_has_no_delay', 'behavior_score_always_passes']);
file_put_contents(dirname(__DIR__) . '/build/plugins-results.json', json_encode($checks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo count($checks) . " plugin consistency/isolation/order checks passed.\n";
