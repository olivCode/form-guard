<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
// This pilot must load the installed ZIP, not checkout sources.
$root = dirname(__DIR__);
require $root . '/build/consumer/vendor/autoload.php';
$loaded = (new ReflectionClass(FormGuard\Guard::class))->getFileName();
if (!str_contains($loaded, 'consumer' . DIRECTORY_SEPARATOR . 'vendor')) {
    throw new RuntimeException('Pilot is not using the installed dependency.');
}
$results = [];
foreach (FormGuard\ProtectionLevel::cases() as $level) {
    $directory = __DIR__ . '/.runtime/pilot-' . $level->value . '-' . bin2hex(random_bytes(5));
    $config = FormGuard\Configuration::fromArray(['profile' => $level, 'scope' => 'pilot', 'storage' => ['directory' => $directory], 'language' => ['locale' => 'fr']]);
    $guard = new FormGuard\Guard($config);
    $forms = [];
    $accepted = 0;
    foreach (['0', '1'] as $js) {
        $f = $guard->issueForm($forms, ['email', 'message'], 200000);
        $guard->beginRequest('192.0.2.180', 'pilot', 'client-' . $js, 200006);
        $post = ['form_id' => $f['id'], 'csrf_token' => $f['csrf'], $f['fields']['honeypot'] => '', $f['fields']['email'] => 'pilot@example.test', $f['fields']['message'] => 'Synthetic pilot', 'as_js' => $js, 'as_trap' => $js === '0' ? $f['js_trap'] : '', 'as_interactions' => $js === '0' ? '0' : '1'];
        $verified = $guard->validateForm($post, $forms, 200006);
        $guard->consumeForm($forms, $verified['id']);
        $accepted++;
        // AJAX application: replace metadata with a newly issued form, keeping the locale and policy.
        $next = $guard->issueForm($forms, ['email', 'message'], 200006);
        $public = $guard->publicForm($next);
        if (array_keys($public) !== ['id', 'csrf', 'fields', 'js_trap'] || $public['id'] === $f['id']) {
            throw new RuntimeException('Pilot AJAX metadata failed.');
        }
        try {
            $guard->validateForm($post, $forms, 200010);
            throw new RuntimeException('Pilot accepted replay.');
        } catch (FormGuard\Rejection $error) {
            if ($error->reason !== FormGuard\RejectionReason::FormUnavailable) {
                throw $error;
            }
        }
    }
    $limit = $config->options()['client_limit'];
    for ($i = 0; $i < $limit; $i++) {
        $guard->beginRequest('192.0.2.181', 'synthetic-abuse', 'abuse', 200010 + $i);
    }
    try {
        $guard->beginRequest('192.0.2.181', 'synthetic-abuse', 'abuse', 200030);
        throw new RuntimeException('Pilot ceiling failed.');
    } catch (FormGuard\Rejection $error) {
        if ($error->getCode() !== 429) {
            throw $error;
        }
    }
    $guard->beginRequest('192.0.2.181', 'coworker', 'honest-coworker', 200031);
    $results[] = ['profile' => $level->value, 'synthetic_honest_forms_accepted' => $accepted, 'replay_refused' => true, 'burst_refused' => true, 'coworker_accepted' => true, 'ajax_metadata_renewed' => true];
}
file_put_contents($root . '/build/pilot-results.json', json_encode(['type' => 'synthetic_installed_dependency', 'profiles' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Three installed-dependency pilot policies passed (synthetic native/JS, AJAX metadata, replay, burst and coworker).\n";
