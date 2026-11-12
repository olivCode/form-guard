<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

$writer = new class ($argv[2]) extends FormGuard\Storage\AtomicFileWriter {
    public function __construct(private readonly string $marker)
    {
    }
    protected function replace(string $temporary, string $path): void
    {
        file_put_contents($this->marker, 'ready-before-rename');
        // Deliberately terminated by the parent test before committing.
        while (true) {
            usleep(10000);
        }
    }
};
$store = new FormGuard\Storage\FileStore($argv[1], 'crash-test', writer: $writer);
$guard = new FormGuard\Guard(['scope' => 'crash-test'], $store);
$guard->beginRequest('192.0.2.100', 'crash-worker', 'interrupted', 100001);
