<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

use FormGuard\Configuration;
use FormGuard\Guard;
use FormGuard\Options;
use FormGuard\ProtectionLevel;
use FormGuard\Rejection;
use FormGuard\RejectionReason;
use FormGuard\Rule\RuleContext;
use FormGuard\Rule\RuleExecutionException;
use FormGuard\Rule\RuleFailure;
use FormGuard\Rule\RuleInterface;
use FormGuard\Storage\InMemoryStore;

$checks = [];
function qcheck(string $name, bool $ok): void
{
    global $checks;
    $checks[] = compact('name', 'ok');
    if (!$ok) {
        throw new RuntimeException($name);
    }
}
function qthrows(string $name, callable $action, string $class): Throwable
{
    try {
        $action();
    } catch (Throwable $error) {
        qcheck($name, $error instanceof $class);
        return $error;
    }
    throw new RuntimeException('Expected failure: ' . $name);
}
function qpost(array $f, string $js = '0'): array
{
    return ['form_id' => $f['id'], 'csrf_token' => $f['csrf'], $f['fields']['email'] => 'visitor@example.test', $f['fields']['honeypot'] => '',
        'as_js' => $js, 'as_trap' => $js === '0' ? $f['js_trap'] : '', 'as_interactions' => $js === '0' ? '0' : '1'];
}
qcheck('legacy defaults unchanged', Configuration::fromArray()->options() === Options::resolve([]));
qcheck('grouped standard matches legacy', Configuration::fromArray(['profile' => 'standard'])->options() === Options::resolve([]));
foreach (ProtectionLevel::cases() as $level) {
    $config = Configuration::fromArray(['profile' => $level, 'scope' => 'policy', 'language' => ['locale' => 'fr']]);
    $settings = $config->options();
    qcheck('preset ' . $level->value, [$settings['client_limit'], $settings['ip_limit'], $settings['min_seconds']] === match ($level) {
        ProtectionLevel::Tolerant => [10, 60, 3], ProtectionLevel::Standard => [5, 30, 3], ProtectionLevel::Reinforced => [3, 20, 5]
    });
    qcheck('environment/language do not change policy label ' . $level->value, !$config->customized);
    $g = new Guard($config, new InMemoryStore());
    foreach (['0', '1'] as $js) {
        $forms = [];
        $f = $g->issueForm($forms, ['email'], 100000);
        qcheck('keyboard/native preset ' . $level->value . '/' . $js, $g->validateForm(qpost($f, $js), $forms, 100000 + $settings['min_seconds'])['id'] === $f['id']);
        $g->consumeForm($forms, $f['id']);
        qthrows('replay never disabled ' . $level->value . '/' . $js, fn () => $g->validateForm(qpost($f, $js), $forms, 100010), Rejection::class);
    }
    for ($i = 0; $i < $settings['client_limit']; $i++) {
        $g->beginRequest('192.0.2.50', 'policy', 'visitor', 100000 + $i);
    }
    $e = qthrows('client ceiling ' . $level->value, fn () => $g->beginRequest('192.0.2.50', 'policy', 'visitor', 100020), Rejection::class);
    qcheck('client status ' . $level->value, $e->getCode() === 429);
    $g->beginRequest('192.0.2.50', 'policy', 'coworker', 100021);
    qcheck('coworker still admitted ' . $level->value, true);
}
$custom = Configuration::fromArray(['profile' => 'reinforced', 'limits' => ['client' => 7], 'forms' => ['min_seconds' => 4],
    'scope' => 'private-path-secret', 'storage' => ['directory' => __DIR__ . '/.runtime/private-secret'],
    'language' => ['locale' => 'fr', 'messages' => ['fr' => ['too_fast' => 'private-message-secret']]]]);
qcheck('override wins', $custom->options()['client_limit'] === 7 && $custom->options()['ip_limit'] === 20);
$description = $custom->describe();
qcheck('customized labeled', $custom->customized && $description['groups']['limits']['client']['source'] === 'override');
qcheck('profile origin recorded', $description['groups']['limits']['ip']['source'] === 'profile:reinforced');
qcheck('default origin recorded', $description['groups']['forms']['ttl']['source'] === 'default');
$encoded = json_encode($description, JSON_THROW_ON_ERROR);
qcheck('diagnostic omits paths/scope/messages', !str_contains($encoded, 'secret'));
foreach ([['profile' => null], ['profile' => 'unknown'], ['forms' => null], ['forms' => ['unknown' => 1]], ['profile' => 'standard', 'client_limit' => 3],
    ['limits' => ['client' => 0]], ['limits' => ['enabled' => 'false']], ['rules' => ['budget_ms' => 0]], ['core' => false], ['csrf' => false]] as $bad) {
    qthrows('invalid configuration ' . json_encode($bad), fn () => Configuration::fromArray($bad), InvalidArgumentException::class);
}
for ($bits = 0; $bits < 8; $bits++) {
    $timing = (bool) ($bits & 1);
    $rate = (bool) ($bits & 2);
    $behavior = (bool) ($bits & 4);
    $g = new Guard(['forms' => ['timing' => $timing], 'limits' => ['enabled' => $rate], 'browser' => ['enabled' => $behavior]], new InMemoryStore());
    $forms = [];
    $f = $g->issueForm($forms, ['email'], 100000);
    foreach (['0', '1'] as $js) {
        qcheck('combination accepted ' . $bits . '/' . $js, $g->validateForm(qpost($f, $js), $forms, 100004)['id'] === $f['id']);
    }
    $invalid = qpost($f);
    $invalid['csrf_token'] = 'bad';
    qthrows('CSRF kept ' . $bits, fn () => $g->validateForm($invalid, $forms, 100004), Rejection::class);
    $invalid = qpost($f);
    $invalid[$f['fields']['honeypot']] = 'filled';
    qthrows('honeypot kept ' . $bits, fn () => $g->validateForm($invalid, $forms, 100004), Rejection::class);
    if (!$timing) {
        qcheck('timing independently disabled ' . $bits, $g->validateForm(qpost($f), $forms, 100000)['id'] === $f['id']);
    }
    if (!$rate) {
        for ($i = 0; $i < 8; $i++) {
            $g->beginRequest('192.0.2.60', 'test', 'same', 100000);
        } qcheck('limits explicitly disabled ' . $bits, true);
    }
}
$calls = [];
$rule = new class ($calls) implements RuleInterface {
    public function __construct(public array &$calls)
    {
    }
    public function id(): string
    {
        return 'content_policy';
    }
    public function evaluate(RuleContext $context): ?RuleFailure
    {
        $this->calls[] = $context;
        return new RuleFailure('too_many_links', ['limit' => 2]);
    }
};
$g = new Guard(['language' => ['locale' => 'fr', 'messages' => ['fr' => ['rule.content_policy.too_many_links' => 'Limite : {limit} liens.']]]], new InMemoryStore(), rules: [$rule]);
$forms = [];
$f = $g->issueForm($forms, ['email'], 100000);
$post = qpost($f);
$e = qthrows('rule localized refusal', fn () => $g->validateForm($post, $forms, 100004), Rejection::class);
qcheck('structured rule rejection', $e instanceof Rejection && $e->reason === RejectionReason::CustomRule && $e->ruleId === 'content_policy' && $e->ruleCode === 'too_many_links' && $e->getMessage() === 'Limite : 2 liens.');
qcheck('rule context only business fields', count($calls) === 1 && $calls[0]->fields === ['email' => 'visitor@example.test'] && $calls[0]->ageSeconds === 4);
$bad = $post;
$bad['csrf_token'] = 'bad';
qthrows('core precedes custom rules', fn () => $g->validateForm($bad, $forms, 100004), Rejection::class);
qcheck('core rejection stops rules', count($calls) === 1);
qthrows('duplicate rule ID', fn () => new Guard([], rules: [$rule, $rule]), InvalidArgumentException::class);
qthrows('invalid rule object', fn () => new Guard([], rules: [new stdClass()]), InvalidArgumentException::class);
foreach (['bad-code', str_repeat('a', 33)] as $badCode) {
    qthrows('invalid rule code', fn () => new RuleFailure($badCode), InvalidArgumentException::class);
}
qthrows('metadata strings cannot leak content', fn () => new RuleFailure('bad', ['content' => 'visitor text']), InvalidArgumentException::class);
qthrows('numeric metadata bounded', fn () => new RuleFailure('bad', ['count' => PHP_INT_MAX]), InvalidArgumentException::class);
$throwing = new class () implements RuleInterface {
    public function id(): string
    {
        return 'unavailable';
    }
    public function evaluate(RuleContext $context): ?RuleFailure
    {
        throw new RuntimeException('private technical detail');
    }
};
$g = new Guard([], new InMemoryStore(), rules: [$throwing]);
$e = qthrows('rule outage fails closed', fn () => $g->validateForm($post, $forms, 100004), RuleExecutionException::class);
qcheck('technical error generic', $e instanceof RuleExecutionException && $e->reason === 'rule_failed' && !str_contains($e->getMessage(), 'private'));
$slow = new class () implements RuleInterface {
    public function id(): string
    {
        return 'slow';
    }
    public function evaluate(RuleContext $context): ?RuleFailure
    {
        // The requested sleep is not a reliable minimum on every platform.
        $deadline = hrtime(true) + 20000000;
        do {
            usleep(1000);
        } while (hrtime(true) < $deadline);
        return null;
    }
};
$g = new Guard(['rules' => ['budget_ms' => 1]], new InMemoryStore(), rules: [$slow]);
$e = qthrows('late success cannot pass', fn () => $g->validateForm($post, $forms, 100004), RuleExecutionException::class);
qcheck('timeout reason', $e instanceof RuleExecutionException && $e->reason === 'rule_timed_out');
file_put_contents(dirname(__DIR__) . '/build/configuration-results.json', json_encode($checks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo count($checks) . " profile/configuration/rule checks passed.\n";
