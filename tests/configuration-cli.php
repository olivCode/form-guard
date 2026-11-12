<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';
$runtime = __DIR__ . '/.runtime/configuration-' . bin2hex(random_bytes(5));
mkdir($runtime, 0700);
$checks = [];
$check = static function (string $name, bool $ok) use (&$checks): void {
    $checks[] = compact('name', 'ok');
    if (!$ok) {
        throw new RuntimeException($name);
    }
};
$run = static function (array $args, string $stdin = ''): array {
    $pipes = [];
    $p = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/form-guard', ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) {
        throw new RuntimeException('Cannot start CLI.');
    }
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($p), $out, $err];
};
foreach (['en', 'fr'] as $locale) {
    $file = $runtime . '/' . $locale . '.php';
    [$code, $out, $err] = $run(['init', '--output=' . $file, '--profile=reinforced', '--locale=' . $locale]);
    $check('localized init ' . $locale, $code === 0 && $err === '' && json_decode($out, true)['created'] === true);
    [$code, $out, $err] = $run(['config:check', '--config=' . $file, '--locale=' . $locale]);
    $check('generated configuration usable ' . $locale, $code === 0 && json_decode($out, true)['valid'] === true);
    $before = file_get_contents($file);
    [$code, $out, $err] = $run(['init', '--output=' . $file, '--locale=' . $locale]);
    $check('no overwrite ' . $locale, $code === 2 && $before === file_get_contents($file));
    $check('error localization ' . $locale, str_contains($err, $locale === 'fr' ? 'refusée' : 'rejected'));
}
$file = $runtime . '/private.php';
file_put_contents($file, '<?php echo "private-echo-secret"; return ' . var_export(['profile' => 'tolerant', 'scope' => 'private-scope-secret', 'storage' => ['directory' => $runtime . '/private-path-secret'], 'language' => ['messages' => ['en' => ['too_fast' => 'private-message-secret']]]], true) . ';');
[$code, $out, $err] = $run(['config:show', '--config=' . $file]);
$check('diagnostic redacts private values and config echo', $code === 0 && !str_contains($out . $err, 'secret') && json_decode($out, true)['profile'] === 'tolerant');
$check('diagnostic does not create storage', !is_dir($runtime . '/private-path-secret'));
[$code, $out, $err] = $run(['status', '--config=' . $file]);
$check('maintenance shares configuration', $code === 0 && json_decode($out, true)['format'] === 2);
[$code, $out, $err] = $run(['status', '--config=' . $file, '--scope=conflicting']);
$check('conflicting maintenance scope refused', $code === 2);
$interactive = $runtime . '/interactive.php';
[$code, $out, $err] = $run(['init', '--interactive', '--locale=fr'], "tolerant\ncontact\n" . $interactive . "\n");
$check('interactive initializer localized', $code === 0 && is_file($interactive) && str_contains($err, 'Profil'));
foreach ([['init', '--profile=unknown', '--output=' . $runtime . '/bad.php'], ['config:show'], ['init', '--scope=a', '--scope=b'], ['init', '--interactive', '--output=' . $runtime . '/eof.php']] as $args) {
    [$code] = $run($args);
    $check('invalid args/input refused', $code === 2);
}
file_put_contents($runtime . '/broken.php', '<?php throw new RuntimeException("private-exception-secret");');
[$code, $out, $err] = $run(['config:show', '--config=' . $runtime . '/broken.php']);
$check('configuration exception generic', $code === 2 && !str_contains($out . $err, 'private-exception-secret'));
file_put_contents(dirname(__DIR__) . '/build/configuration-cli-results.json', json_encode($checks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo count($checks) . " configuration CLI checks passed.\n";
