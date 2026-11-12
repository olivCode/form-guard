<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
$count = 0;
foreach (['src', 'examples', 'tests', 'tools', 'bin'] as $folder) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $folder, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (($file->getExtension() !== 'php' && $file->getFilename() !== 'form-guard') || str_contains($file->getPathname(), '.runtime')) {
            continue;
        }
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-l', $file->getPathname()], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start PHP lint.');
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException($out . $err);
        }
        $count++;
    }
}
$metadata = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
if ($metadata['autoload']['psr-4']['FormGuard\\'] !== 'src/' || $metadata['license'] !== 'MIT') {
    throw new RuntimeException('Unexpected package metadata.');
}
echo $count . " PHP files linted; package metadata checked.\n";
