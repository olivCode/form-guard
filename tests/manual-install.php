<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$workspace = $root . '/build/manual-install-' . bin2hex(random_bytes(6));
$zip = new ZipArchive();
if ($zip->open($root . '/build/distribution/form-guard.zip') !== true) {
    throw new RuntimeException('Build and audit the distribution ZIP before testing manual installation.');
}

foreach (['.md', '.fr.md'] as $suffix) {
    $app = $workspace . ($suffix === '.md' ? '/en' : '/fr');
    $library = $app . '/lib/form-guard';
    foreach ([$library, $app . '/private', $app . '/public/assets', $app . '/var'] as $directory) {
        if (!mkdir($directory, 0700, true)) {
            throw new RuntimeException('Cannot prepare the standalone application.');
        }
    }
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = $zip->getNameIndex($index);
        if ($name === false || str_ends_with($name, '/')) {
            continue;
        }
        // Copy only the documented runtime files; no vendor, tests or checkout autoloader.
        if ($name !== 'LICENSE' && !preg_match('~^(?:src|bin|assets)/~', $name)) {
            continue;
        }
        if (str_contains($name, '..') || str_contains($name, '\\') || str_contains($name, ':')) {
            throw new RuntimeException('Unsafe archive entry.');
        }
        $path = $library . '/' . $name;
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true)) {
            throw new RuntimeException('Cannot create a runtime directory.');
        }
        $contents = $zip->getFromIndex($index);
        if ($contents === false || file_put_contents($path, $contents) !== strlen($contents)) {
            throw new RuntimeException('Cannot copy a distribution file.');
        }
    }
    $guide = file_get_contents($root . '/docs/installation' . $suffix);
    preg_match_all('/```php\s*\n(.*?)```/s', $guide, $blocks);
    $bootstrap = $blocks[1][1] ?? '';
    if (!str_contains($bootstrap, '/lib/form-guard/bin/bootstrap.php')) {
        throw new RuntimeException('Missing documented manual bootstrap.');
    }
    file_put_contents($app . '/private/form-guard.php', $bootstrap);
    if (!copy($library . '/assets/form-guard.js', $app . '/public/assets/form-guard.js')) {
        throw new RuntimeException('Cannot copy the optional public asset.');
    }

    $probe = <<<'PHP'
<?php
declare(strict_types=1);

$app = dirname(__DIR__);
if (class_exists('FormGuard\Guard', false) || spl_autoload_functions() !== [] || is_dir($app . '/vendor')) {
    throw new RuntimeException('The manual consumer was not isolated.');
}
$guard = require $app . '/private/form-guard.php';
$library = realpath($app . '/lib/form-guard');
$loaded = realpath((new ReflectionClass($guard))->getFileName());
if (!$library || !$loaded || !str_starts_with($loaded, $library . DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('The manual consumer loaded sources outside its own copy.');
}
$forms = [];
$form = $guard->issueForm($forms, ['message'], 200000);
$post = [
    'form_id' => $form['id'], 'csrf_token' => $form['csrf'],
    $form['fields']['honeypot'] => '', $form['fields']['message'] => 'Standalone PHP',
    'as_js' => '0', 'as_trap' => $form['js_trap'], 'as_interactions' => '0',
];
$guard->beginRequest('192.0.2.70', 'manual-install-test', 'session', 200003);
$verified = $guard->validateForm($post, $forms, 200003);
if ($verified['id'] !== $form['id'] || $post[$verified['fields']['message']] !== 'Standalone PHP') {
    throw new RuntimeException('The documented manual installation rejected a valid native form.');
}
$guard->consumeForm($forms, $verified['id']);
try {
    $guard->validateForm($post, $forms, 200004);
    throw new RuntimeException('The manual installation accepted a replay.');
} catch (FormGuard\Rejection $error) {
    if ($error->getCode() !== 403) {
        throw $error;
    }
}
$runtime = realpath($app . '/var/form-guard');
if (!$runtime || realpath($guard->runtimeDir()) !== $runtime
    || !is_file($runtime . '/form-guard-state.json')) {
    throw new RuntimeException('The documented private persistent storage was not used.');
}
if (hash_file('sha256', $app . '/public/assets/form-guard.js') !== hash_file('sha256', $library . '/assets/form-guard.js')) {
    throw new RuntimeException('The public helper differs from the installed asset.');
}
foreach (get_included_files() as $included) {
    $resolved = realpath($included);
    if (!$resolved || !str_starts_with($resolved, realpath($app) . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('A PHP file outside the standalone application was included.');
    }
}
echo "standalone-ok\n";
PHP;
    file_put_contents($app . '/public/probe.php', $probe);
    $commands = [
        [[PHP_BINARY, '-n', $app . '/public/probe.php'], 'probe'],
        [[PHP_BINARY, '-n', $library . '/bin/form-guard', 'status', '--runtime-dir=' . $app . '/var/form-guard', '--scope=contact'], 'cli'],
    ];
    foreach ($commands as [$command, $kind]) {
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $app);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot run the standalone PHP process.');
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0 || $errors !== '') {
            throw new RuntimeException('Standalone ' . $kind . ' failed: ' . $output . $errors);
        }
        if ($kind === 'probe' && $output !== "standalone-ok\n") {
            throw new RuntimeException('Unexpected standalone probe output.');
        }
        if ($kind === 'cli' && (json_decode($output, true, 512, JSON_THROW_ON_ERROR)['format'] ?? null) !== 2) {
            throw new RuntimeException('The manually installed maintenance CLI failed.');
        }
    }
}
$zip->close();
echo "EN/FR manual installation examples passed in isolated PHP processes without Composer or vendor: form lifecycle, replay rejection, private storage, asset and maintenance CLI.\n";
