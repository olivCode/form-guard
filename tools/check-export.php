<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
$manifest = json_decode(file_get_contents($root . '/build/distribution-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$path = $root . '/build/git-export-' . bin2hex(random_bytes(5)) . '.zip';
$pipes = [];
$process = proc_open(['git', '-c', 'safe.directory=' . str_replace('\\', '/', $root), 'archive', '--format=zip', '--output=' . $path, 'HEAD'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
if (!is_resource($process)) {
    throw new RuntimeException('Cannot inspect Git distribution.');
}
fclose($pipes[0]);
$output = stream_get_contents($pipes[1]);
$error = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($process) !== 0) {
    throw new RuntimeException('Git archive failed: ' . $output . $error);
}
$zip = new ZipArchive();
if ($zip->open($path) !== true) {
    throw new RuntimeException('Cannot open Git distribution.');
}
$seen = [];
for ($index = 0; $index < $zip->numFiles; $index++) {
    $name = $zip->getNameIndex($index);
    if (!str_ends_with($name, '/')) {
        $seen[] = $name;
        if ($zip->getFromIndex($index) !== str_replace("\r\n", "\n", file_get_contents($root . '/' . $name))) {
            throw new RuntimeException('Git distribution content differs: ' . $name);
        }
    }
}
$zip->close();
$expected = $manifest['files'];
sort($seen);
sort($expected);
if ($seen !== $expected) {
    throw new RuntimeException('Git distribution file set differs from the audited Composer archive: ' . json_encode(array_merge(array_diff($seen, $expected), array_diff($expected, $seen))));
}
echo count($seen) . " Git archive files match the audited runtime distribution; developer files are excluded.\n";
