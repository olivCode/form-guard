<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
$files = [...glob($root . '/*.md'), ...glob($root . '/docs/*.md')];
$count = 0;
foreach ($files as $path) {
    $name = basename($path);
    $english = str_replace('.fr.md', '.md', $name);
    $french = substr($english, 0, -3) . '.fr.md';
    $content = str_replace("\r\n", "\n", file_get_contents($path));
    $navigation = '[English](' . $english . ') · [Français](' . $french . ')';
    if (!is_file(dirname($path) . '/' . $english) || !is_file(dirname($path) . '/' . $french) || !str_starts_with($content, $navigation . "\n\n")) {
        throw new RuntimeException('Missing language pair/navigation: ' . $name);
    }
    $withoutCode = preg_replace('/```.*?```/s', '', $content);
    preg_match_all('/\[[^\]]+\]\(([^\s)]+)\)/', $withoutCode, $links);
    foreach ($links[1] as $target) {
        if (preg_match('~^(?:[a-z]+:|#)~i', $target)) {
            continue;
        }
        $relative = rawurldecode(explode('#', $target, 2)[0]);
        if ($relative !== '' && !file_exists(dirname($path) . '/' . $relative)) {
            throw new RuntimeException('Broken documentation link in ' . $name . ': ' . $target);
        }
    }
    $count++;
}
echo $count . " bilingual Markdown documents checked; language pairs and local links valid.\n";
