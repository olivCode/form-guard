<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';
$checks = [];
function hcheck(string $name, bool $ok): void
{
    global $checks;
    $checks[] = compact('name', 'ok');
    if (!$ok) {
        throw new RuntimeException($name);
    }
}
function hform(string $html): array
{
    $values = [];
    $email = null;
    preg_match_all('/<input\b[^>]*>/i', $html, $tags);
    foreach ($tags[0] as $tag) {
        preg_match_all('/([a-zA-Z0-9_-]+)="([^"]*)"/', $tag, $matches, PREG_SET_ORDER);
        $attrs = [];
        foreach ($matches as $match) {
            $attrs[$match[1]] = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (!isset($attrs['name'])) {
            continue;
        }
        $values[$attrs['name']] = $attrs['value'] ?? '';
        if (($attrs['type'] ?? '') === 'email') {
            $email = $attrs['name'];
        }
    }
    if ($email === null || !isset($values['form_id'])) {
        throw new RuntimeException('No example form.');
    }
    $values[$email] = 'visitor@example.test';
    return $values;
}
function hfresh(array $form, string $js = '0'): array
{
    return ['form_id' => $form['id'], 'csrf_token' => $form['csrf'], 'as_js' => $js, 'as_interactions' => $js === '1' ? '1' : '0',
        'as_trap' => $js === '1' ? '' : $form['js_trap'], $form['fields']['honeypot'] => '', $form['fields']['email'] => 'visitor@example.test'];
}
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$socket) {
    throw new RuntimeException('Cannot reserve HTTP test port.');
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$root = dirname(__DIR__);
$runtime = __DIR__ . '/.runtime/http-' . bin2hex(random_bytes(5));
$env = getenv();
$env['FORM_GUARD_EXAMPLE_RUNTIME'] = $runtime;
$env['FORM_GUARD_EXAMPLE_ORIGIN'] = 'http://' . $address;
$log = $root . '/build/http-example.log';
$pipes = [];
$server = proc_open([PHP_BINARY, '-S', $address, '-t', $root . '/examples'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, $env);
if (!is_resource($server)) {
    throw new RuntimeException('Cannot start local HTTP server.');
}
fclose($pipes[0]);
$request = static function (string &$cookie, string $path = '/', ?array $data = null, bool $json = false) use ($address): array {
    $headers = ['Accept: ' . ($json ? 'application/json' : 'text/html')];
    if ($cookie !== '') {
        $headers[] = 'Cookie: ' . $cookie;
    }
    if ($data !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Content-Length: ' . strlen(http_build_query($data));
    }
    $context = stream_context_create(['http' => ['method' => $data === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers), 'content' => $data === null ? '' : http_build_query($data), 'ignore_errors' => true, 'timeout' => 10, 'follow_location' => 0]]);
    $stream = fopen('http://' . $address . $path, 'rb', false, $context);
    if ($stream === false) {
        throw new RuntimeException('HTTP test server is unavailable.');
    }
    try {
        $body = stream_get_contents($stream);
        $responseHeaders = stream_get_meta_data($stream)['wrapper_data'] ?? [];
    } finally {
        fclose($stream);
    }
    preg_match('/\s(\d{3})\s/', $responseHeaders[0], $match);
    foreach ($responseHeaders as $header) {
        if (stripos($header, 'Set-Cookie: ') === 0) {
            $cookie = explode(';', substr($header, 12))[0];
        }
    }
    return [(int) $match[1], $body, $responseHeaders];
};
try {
    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $error, .1);
        if ($probe) {
            fclose($probe);
            $ready = true;
            break;
        } usleep(20000);
    }
    hcheck('isolated HTTP server ready', $ready);
    $native = '';
    $ajax = '';
    $expiredSession = '';
    $blocked = '';
    [$status, $html, $headers] = $request($native);
    hcheck('native form renders with no warnings', $status === 200 && !str_contains($html, 'Warning:') && str_contains($html, 'data-antispam'));
    hcheck('native tokens not cached', in_array('Cache-Control: no-store', $headers, true));
    $n = hform($html);
    [$status, $html] = $request($ajax, '/?lang=fr');
    $j = hform($html);
    hcheck('French example markup localized', $status === 200 && str_contains($html, 'lang="fr"') && str_contains($html, 'Adresse e-mail'));
    [$status, $body] = $request($native, '/', $n);
    hcheck('native fast submission rejected', $status === 422 && str_contains($body, 'wait another'));
    sleep(3);
    [$status, $body] = $request($native, '/', $n);
    hcheck('native no-JS submission accepted', $status === 200 && str_contains($body, 'Submission accepted.'));
    [$status] = $request($native, '/', $n);
    hcheck('native back/replay rejected', $status === 403);
    $j['as_js'] = '1';
    $j['as_trap'] = '';
    $j['as_interactions'] = '1';
    [$status, $body] = $request($ajax, '/?lang=fr', $j, true);
    $result = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    hcheck('AJAX success localized', $status === 200 && $result['ok'] && str_contains($result['message'], 'acceptée'));
    hcheck('AJAX token renewed', $result['form']['id'] !== $j['form_id']);
    [$status, $body] = $request($expiredSession, '/?lang=fr', hfresh($result['form']), true);
    $lost = json_decode($body, true);
    hcheck('another session cannot use AJAX token', $status === 403 && $lost['reason'] === 'form_unavailable');
    [$status, $body] = $request($ajax, '/?lang=fr', hfresh($result['form'], '1'), true);
    $fast = json_decode($body, true);
    hcheck('AJAX renewal enforces server delay and structured reason', $status === 422 && $fast['reason'] === 'too_fast' && $fast['parameters']['seconds'] > 0 && str_contains($fast['message'], 'patienter'));
    sleep(3);
    [$status, $body] = $request($ajax, '/?lang=fr', hfresh($fast['form'], '1'), true);
    hcheck('renewed AJAX form accepted after delay', $status === 200 && json_decode($body, true)['ok']);
    for ($i = 0; $i < 5; $i++) {
        [$status] = $request($blocked, '/?lang=fr', [], true);
        hcheck('invalid HTTP attempt counted ' . $i, $status === 403);
    }
    [$status, $body, $headers] = $request($blocked, '/?lang=fr', [], true);
    $limit = json_decode($body, true);
    hcheck('HTTP ban localized and structured', $status === 429 && $limit['reason'] === 'rate_limited' && str_contains($limit['message'], 'Réessayez') && $limit['parameters']['limit_scope'] === 'client');
    hcheck('HTTP actual Retry-After header', in_array('Retry-After: 60', $headers, true));
    [$status, $body] = $request($native, '/?asset=script');
    hcheck('browser script endpoint available', $status === 200 && str_contains($body, 'window.FormGuard'));
    file_put_contents($root . '/build/http-results.json', json_encode($checks, JSON_PRETTY_PRINT));
    echo count($checks) . " isolated HTTP checks passed; no email sent.\n";
} finally {
    proc_terminate($server);
    proc_close($server);
}
