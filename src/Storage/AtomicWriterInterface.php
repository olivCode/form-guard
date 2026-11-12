<?php

declare(strict_types=1);

namespace FormGuard\Storage;

interface AtomicWriterInterface
{
    /** Replace one regular file atomically on the same filesystem; preserve it on failure. */
    public function write(string $path, string $contents): void;
}
