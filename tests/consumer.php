<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
$consumer = $root . '/build/consumer';
require $consumer . '/vendor/autoload.php';
$library = realpath($consumer . '/vendor/olivcode/form-guard');
$loaded = realpath((new ReflectionClass(FormGuard\Guard::class))->getFileName());
if (!$library || !$loaded || !str_starts_with($loaded, $library . DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Consumer loaded checkout sources instead of the installed package.');
}
$guard = new FormGuard\Guard(['runtime_dir' => $consumer . '/runtime']);
$forms = [];
$form = $guard->issueForm($forms, ['email'], 200000);
$post = ['form_id' => $form['id'], 'csrf_token' => $form['csrf'], $form['fields']['honeypot'] => '',
    'as_js' => '0', 'as_trap' => $form['js_trap'], 'as_interactions' => '0'];
if ($guard->validateForm($post, $forms, 200003)['id'] !== $form['id']) {
    throw new RuntimeException('Installed package rejected native mode.');
}
$guard->consumeForm($forms, $form['id']);
try {
    $guard->validateForm($post, $forms, 200004);
    throw new RuntimeException('Installed package accepted replay.');
} catch (FormGuard\Rejection $error) {
    if ($error->getCode() !== 403) {
        throw $error;
    }
}
for ($i = 0; $i < 5; $i++) {
    $guard->beginRequest('192.0.2.50', 'consumer-test', 'session', 300000 + $i);
}
try {
    $guard->beginRequest('192.0.2.50', 'consumer-test', 'session', 300005);
    throw new RuntimeException('Installed package failed to rate-limit.');
} catch (FormGuard\Rejection $error) {
    if ($error->getCode() !== 429 || $error->retryAfter !== 60) {
        throw $error;
    }
}
if (!is_file($library . '/LICENSE') || !str_contains(file_get_contents($library . '/assets/form-guard.js'), 'window.FormGuard') || !is_file($consumer . '/vendor/bin/form-guard')) {
    throw new RuntimeException('Installed package is incomplete.');
}
$localized = new FormGuard\Guard(['locale' => 'fr'], new FormGuard\Storage\InMemoryStore());
$localizedForms = [];
$localizedForm = $localized->issueForm($localizedForms, ['email'], 200000);
try {
    $localized->validateForm(['form_id' => $localizedForm['id'], 'csrf_token' => 'bad'], $localizedForms, 200004);
    throw new RuntimeException('Expected localized rejection.');
} catch (FormGuard\Rejection $error) {
    if ($error->reason !== FormGuard\RejectionReason::InvalidToken || !str_contains($error->getMessage(), 'formulaire')) {
        throw $error;
    }
}
$pipes = [];
$process = proc_open(
    [PHP_BINARY, $consumer . '/vendor/bin/form-guard', 'status', '--runtime-dir=' . $consumer . '/cli-runtime', '--scope=consumer-cli'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
if (!is_resource($process)) {
    throw new RuntimeException('Installed maintenance binary could not start.');
}
fclose($pipes[0]);
$output = stream_get_contents($pipes[1]);
$errors = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($process) !== 0 || $errors !== '' || json_decode($output, true)['format'] !== 2) {
    throw new RuntimeException('Installed maintenance binary failed.');
}
$plugin = new class () implements FormGuard\Plugin\FormPluginInterface {
    public bool $called = false;
    public function id(): string
    {
        return 'installed_plugin';
    }
    public function phase(): FormGuard\Plugin\Phase
    {
        return FormGuard\Plugin\Phase::Form;
    }
    public function requires(): array
    {
        return ['request_limits'];
    }
    public function conflicts(): array
    {
        return [];
    }
    public function evaluate(FormGuard\Plugin\FormContext $context): ?FormGuard\Plugin\Refusal
    {
        $this->called = true;
        return null;
    }
};
$rule = new class () implements FormGuard\Rule\RuleInterface {
    public function id(): string
    {
        return 'installed_rule';
    }
    public function evaluate(FormGuard\Rule\RuleContext $context): ?FormGuard\Rule\RuleFailure
    {
        return null;
    }
};
$pluginGuard = new FormGuard\Guard([], new FormGuard\Storage\InMemoryStore(), rules: [$rule], plugins: [$plugin]);
$pluginForms = [];
$pluginForm = $pluginGuard->issueForm($pluginForms, ['email'], 400000);
$pluginPost = ['form_id' => $pluginForm['id'], 'csrf_token' => $pluginForm['csrf'], $pluginForm['fields']['honeypot'] => '', $pluginForm['fields']['email'] => 'installed@example.test', 'as_js' => '0', 'as_trap' => $pluginForm['js_trap'], 'as_interactions' => '0'];
$pluginGuard->validateForm($pluginPost, $pluginForms, 400004);
if (!$plugin->called || !in_array('installed_rule', array_column($pluginGuard->describePlugins(), 'id'), true)) {
    throw new RuntimeException('Installed plugin/rule adapter failed.');
}
file_put_contents($root . '/build/consumer-results.json', json_encode(['installed_from_zip' => true, 'psr4' => true, 'native_submission' => true, 'replay_rejected' => true, 'rate_limit' => true, 'browser_asset' => true, 'license' => true, 'i18n' => true, 'installed_cli' => true, 'plugins' => true, 'rule_adapter' => true], JSON_PRETTY_PRINT));
echo "Fresh Composer consumer passed: installed ZIP, PSR-4, native form, replay, limits, asset, license, i18n and installed CLI.\n";
