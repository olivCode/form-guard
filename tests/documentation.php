<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';
$root = dirname(__DIR__);
$count = 0;
foreach (['profiles', 'rules', 'plugins', 'scenarios'] as $guide) {
    foreach (['.md', '.fr.md'] as $suffix) {
        $text = file_get_contents($root . '/docs/' . $guide . $suffix);
        if (!preg_match('/```php\s*\n(.*?)```/s', $text, $match)) {
            throw new RuntimeException('Missing documented example.');
        }
        $path = $root . '/build/documented-example-' . bin2hex(random_bytes(5)) . '.php';
        $code = '<?php declare(strict_types=1); require ' . var_export(__DIR__ . '/bootstrap.php', true) . ";\n" . $match[1];
        if ($guide === 'plugins') {
            $code .= "\n\$probe = new ContentLinks();\n\$context = new FormGuard\\Plugin\\FormContext(['message'=>'https://a https://b https://c'], 5, false, 0);\nif (\$probe->evaluate(\$context)?->code !== 'too_many') { throw new RuntimeException('Documented plugin failed.'); }\n";
        }
        file_put_contents($path, $code);
        $pipes = [];
        $process = proc_open([PHP_BINARY, $path], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot execute documentation example.');
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || $out !== '' || $err !== '') {
            throw new RuntimeException('Documented example failed: ' . $guide . $suffix . ' ' . $out . $err);
        }
        $count++;
    }
}
echo $count . " documented EN/FR PHP examples executed without external effects.\n";
