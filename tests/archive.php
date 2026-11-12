<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
$archive = $root . '/build/distribution/form-guard.zip';
$zip = new ZipArchive();
if ($zip->open($archive) !== true) {
    throw new RuntimeException('Cannot open Composer archive.');
}
$allowed = ['CHANGELOG.md', 'LICENSE', 'README.md', 'SECURITY.md', 'composer.json',
    'assets/form-guard.js', 'docs/installation.md', 'docs/http-security.md', 'docs/compatibility.md', 'docs/configuration.md', 'docs/integration.md', 'docs/i18n.md', 'docs/storage.md', 'docs/profiles.md', 'docs/rules.md', 'docs/scenarios.md', 'docs/plugins.md', 'examples/index.php'];
foreach ($allowed as $document) {
    if (str_ends_with($document, '.md')) {
        $allowed[] = substr($document, 0, -3) . '.fr.md';
    }
}
foreach (['src', 'bin'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)) as $entry) {
        if (!$entry->isFile() || $entry->isLink() || ($entry->getExtension() !== 'php' && $entry->getFilename() !== 'form-guard')) {
            throw new RuntimeException('Unexpected source file.');
        }
        $allowed[] = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
    }
}
$seen = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if (str_ends_with($name, '/')) {
        continue;
    }
    if (!in_array($name, $allowed, true)) {
        throw new RuntimeException('Unexpected distribution file: ' . $name);
    }
    if ($zip->getFromIndex($i) !== file_get_contents($root . '/' . $name)) {
        throw new RuntimeException('Distribution differs from source: ' . $name);
    }
    $seen[] = $name;
}
$zip->close();
foreach (['composer.json', 'LICENSE', 'README.md', 'assets/form-guard.js', 'src/Guard.php', 'src/Options.php', 'src/Rejection.php'] as $required) {
    if (!in_array($required, $seen, true)) {
        throw new RuntimeException('Missing distribution file: ' . $required);
    }
}
foreach ($allowed as $required) {
    if (!in_array($required, $seen, true)) {
        throw new RuntimeException('Expected file absent from ZIP: ' . $required);
    }
}
sort($seen);
file_put_contents($root . '/build/distribution-manifest.json', json_encode(['files' => $seen, 'sha256' => hash_file('sha256', $archive)], JSON_PRETTY_PRINT));
$consumerFile = $root . '/build/consumer/composer.json';
$consumer = json_decode(file_get_contents($consumerFile), true, 512, JSON_THROW_ON_ERROR);
$consumer['repositories'][0]['package']['dist']['shasum'] = sha1_file($archive);
$consumer['repositories'][0]['package']['dist']['reference'] = hash_file('sha256', $archive);
file_put_contents($consumerFile, json_encode($consumer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo count($seen) . " distribution files checked against the source; no development artifacts.\n";
