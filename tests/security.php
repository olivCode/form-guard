<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

use FormGuard\Http\RequestGuard;
use FormGuard\Http\RequestMetadata;
use FormGuard\Http\RequestRejected;
use FormGuard\Plugin\FormContext;
use FormGuard\Plugin\TurnstilePlugin;
use FormGuard\Challenge\TurnstileTransportInterface;

$count = 0;
$assert = static function (bool $value) use (&$count): void {
    if (!$value) {
        throw new RuntimeException('Security assertion ' . $count . ' failed.');
    } $count++;
};
$policy = new RequestGuard(['https://example.test']);
$request = static fn (array $overrides = []): RequestMetadata => new RequestMetadata(...array_replace([
    'method' => 'POST', 'contentType' => 'application/x-www-form-urlencoded', 'bodyBytes' => 100,
    'origin' => 'https://example.test', 'referer' => null, 'fetchSite' => 'same-origin',
], $overrides));
$policy->check($request(), ['message' => 'hello']);
$assert(true);
$policy->check($request(['origin' => null, 'referer' => 'https://example.test/path?x=1']), []);
$assert(true);
$policy->check($request(['origin' => null, 'fetchSite' => null]), []);
$assert(true);
foreach ([
    [['method' => 'GET'], [], 0, 405], [['bodyBytes' => null], [], 0, 413], [['bodyBytes' => -1], [], 0, 413],
    [['bodyBytes' => 16385], [], 0, 413], [['contentType' => 'text/plain'], [], 0, 415],
    [['contentType' => "application/x-www-form-urlencoded\r\nX: y"], [], 0, 415],
    [[], [], 1, 415], [['fetchSite' => 'cross-site'], [], 0, 403], [['fetchSite' => 'invalid'], [], 0, 403],
    [['origin' => 'https://example.test.attacker.test'], [], 0, 403], [['origin' => 'https://example.test:444'], [], 0, 403],
    [['origin' => 'http://example.test'], [], 0, 403], [['origin' => 'null'], [], 0, 403],
    [['origin' => 'https://user@example.test'], [], 0, 403], [['origin' => 'https://example.test/path'], [], 0, 403],
    [['origin' => 'https://example.test#fragment'], [], 0, 403], [['origin' => ''], [], 0, 403],
    [[], ['field' => str_repeat('x', 8193)], 0, 413], [[], array_fill(0, 33, 'x'), 0, 413],
    [[], ['a' => ['b' => ['c' => ['d' => ['e' => 'x']]]]], 0, 413], [[], ['a' => new stdClass()], 0, 422],
    [[], ['a' => 1], 0, 422], [[], [str_repeat('x', 129) => 'value'], 0, 413],
] as [$overrides, $payload, $files, $status]) {
    try {
        $policy->check($request($overrides), $payload, $files);
        throw new RuntimeException('Unsafe HTTP request accepted.');
    } catch (RequestRejected $error) {
        $assert($error->getCode() === $status && !str_contains($error->getMessage(), 'example.test'));
    }
}
try {
    (new RequestGuard(['https://example.test'], allowMissingOrigin: false))->check($request(['origin' => null]));
    throw new RuntimeException('Missing origin accepted.');
} catch (RequestRejected $error) {
    $assert($error->reason === 'missing_origin');
}
$assert(RequestGuard::origin('HTTPS://EXAMPLE.TEST:443') === 'https://example.test');
$assert(RequestGuard::origin('https://[2001:0db8::1]:443') === 'https://[2001:db8::1]');
foreach (['https://-bad.test', 'https://example..test', 'https://example.test.', 'javascript:alert(1)', '//example.test', 'https://example.test\\@evil.test', "https://example.test\n", 'https://example.test:0', 'https://example.test:65536'] as $origin) {
    try {
        RequestGuard::origin($origin);
        throw new RuntimeException('Unsafe origin accepted.');
    } catch (InvalidArgumentException) {
        $assert(true);
    }
}
// Deterministic malformed-input corpus: every suffix below must remain outside the exact origin.
for ($i = 0; $i < 128; $i++) {
    try {
        $policy->check($request(['origin' => 'https://example.test.' . bin2hex(pack('N', $i))]));
        throw new RuntimeException('Suffix origin accepted.');
    } catch (RequestRejected) {
        $assert(true);
    }
}
$transport = new class () implements TurnstileTransportInterface {
    public array $response = ['success' => true, 'hostname' => 'example.test', 'action' => 'contact'];
    public int $calls = 0;
    public function verify(string $secret, string $token): array
    {
        $this->calls++;
        return $this->response;
    }
};
$plugin = new TurnstilePlugin('synthetic-secret', 'example.test', transport: $transport);
$assert($plugin->evaluate(new FormContext(['challenge' => 'synthetic-token'], 4, true, 2)) === null);
foreach ([[], ['challenge' => ''], ['challenge' => []], ['challenge' => str_repeat('x', 2049)], ['challenge' => "bad\ntoken"]] as $fields) {
    $assert($plugin->evaluate(new FormContext($fields, 4, true, 2))?->code === 'challenge_failed');
}
$assert($transport->calls === 1);
foreach ([[], ['success' => 'true','hostname' => 'example.test','action' => 'contact'], ['success' => true,'hostname' => 'evil.test','action' => 'contact'], ['success' => true,'hostname' => 'example.test','action' => 'other']] as $response) {
    $transport->response = $response;
    $assert($plugin->evaluate(new FormContext(['challenge' => 'token'], 4, true, 2))?->code === 'challenge_failed');
}
$throwing = new class () implements TurnstileTransportInterface {
    public function verify(string $secret, string $token): array
    {
        throw new RuntimeException('synthetic outage');
    }
};
$guard = new FormGuard\Guard(['rule_budget_ms' => 5000], new FormGuard\Storage\InMemoryStore(), plugins: [new TurnstilePlugin('secret', 'example.test', transport: $throwing)]);
$forms = [];
$form = $guard->issueForm($forms, ['challenge'], 1000);
$post = ['form_id' => $form['id'],'csrf_token' => $form['csrf'],$form['fields']['honeypot'] => '', $form['fields']['challenge'] => 'token','as_js' => '0','as_trap' => $form['js_trap'],'as_interactions' => '0'];
try {
    $guard->validateForm($post, $forms, 1003);
    throw new RuntimeException('Provider outage accepted.');
} catch (FormGuard\Plugin\PluginExecutionException $error) {
    $assert(!str_contains($error->getMessage(), 'secret'));
}
$previousTraceSetting = ini_set('zend.exception_ignore_args', '0');
try {
    try {
        new TurnstilePlugin('synthetic private secret', 'example.test');
        throw new RuntimeException('Invalid secret accepted.');
    } catch (InvalidArgumentException $error) {
        $assert(($error->getTrace()[0]['args'][0] ?? null) instanceof SensitiveParameterValue);
    }
    try {
        (new FormGuard\Guard([], new FormGuard\Storage\InMemoryStore()))->validateForm(['form_id' => 'synthetic-private-input'], [], 1000);
        throw new RuntimeException('Invalid form accepted.');
    } catch (FormGuard\Rejection $error) {
        $frames = array_values(array_filter($error->getTrace(), static fn (array $frame): bool => ($frame['function'] ?? '') === 'validateForm'));
        $assert(($frames[0]['args'][0] ?? null) instanceof SensitiveParameterValue);
    }
} finally {
    ini_set('zend.exception_ignore_args', (string) $previousTraceSetting);
}
$short = new FormGuard\Guard(['form_ttl' => 10, 'min_seconds' => 0], new FormGuard\Storage\InMemoryStore());
$long = new FormGuard\Guard(['form_ttl' => 7200, 'min_seconds' => 0], new FormGuard\Storage\InMemoryStore());
$forms = [];
$form = $short->issueForm($forms, ['email'], 1000);
$post = ['form_id' => $form['id'],'csrf_token' => $form['csrf'],$form['fields']['honeypot'] => '','as_js' => '0','as_trap' => $form['js_trap'],'as_interactions' => '0'];
try {
    $long->validateForm($post, $forms, 1011);
    throw new RuntimeException('Policy change extended an issued token.');
} catch (FormGuard\Rejection $error) {
    $assert($error->reason === FormGuard\RejectionReason::FormExpired);
}
foreach ([null, 999] as $expiry) {
    $broken = $forms;
    $broken[$form['id']]['expires'] = $expiry;
    try {
        $long->validateForm($post, $broken, 1004);
        throw new RuntimeException('Invalid stored expiry accepted.');
    } catch (FormGuard\Rejection $error) {
        $assert($error->reason === FormGuard\RejectionReason::FormUnavailable);
    }
}
try {
    $short->issueForm($forms, ['email'], PHP_INT_MAX);
    throw new RuntimeException('Expiry overflow accepted.');
} catch (InvalidArgumentException) {
    $assert(true);
}
foreach (['fr-CA', 'fr_FR', 'de-CH'] as $locale) {
    try {
        (new RequestGuard(['https://example.test'], locale: $locale))->check($request(['origin' => 'https://untrusted.test']));
        throw new RuntimeException('Bad regional request accepted.');
    } catch (RequestRejected $error) {
        $assert(str_starts_with($error->getMessage(), str_starts_with($locale, 'fr') ? 'Cette' : 'This'));
    }
}
try {
    new RequestGuard(['https://example.test'], locale: "fr\ninvalid");
    throw new RuntimeException('Invalid locale accepted.');
} catch (InvalidArgumentException) {
    $assert(true);
}
file_put_contents(dirname(__DIR__) . '/build/security-results.json', json_encode(['checks' => $count,'external_service_called' => false], JSON_PRETTY_PRINT));
echo $count . " HTTP and optional challenge security checks passed; no external service called.\n";
