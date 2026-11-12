<?php

declare(strict_types=1);

namespace FormGuard\Storage;

class AtomicFileWriter implements AtomicWriterInterface
{
    public function write(string $path, string $contents): void
    {
        $directory = PrivateFiles::directory(dirname($path));
        PrivateFiles::file($path, false);
        $temporary = $directory . '/.form-guard-write-' . bin2hex(random_bytes(12)) . '.tmp';
        $handle = @fopen($temporary, 'x+b');
        if (!$handle) {
            throw new StorageException('write_failed');
        }
        try {
            if (!@chmod($temporary, 0600)) {
                throw new StorageException('unsafe_permissions');
            }
            PrivateFiles::file($temporary);
            $offset = 0;
            while ($offset < strlen($contents)) {
                $written = @fwrite($handle, substr($contents, $offset));
                if ($written === false || $written === 0) {
                    throw new StorageException('write_failed');
                }
                $offset += $written;
            }
            if (!@fflush($handle) || !@fsync($handle)) {
                throw new StorageException('write_failed');
            }
            fclose($handle);
            $handle = null;
            PrivateFiles::file($path, false);
            $this->replace($temporary, $path);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            // Only the unique file created above, never arbitrary directory contents.
            if (is_file($temporary) && !is_link($temporary)) {
                @unlink($temporary);
            }
        }
    }

    protected function replace(string $temporary, string $path): void
    {
        if (!@rename($temporary, $path)) {
            throw new StorageException('replace_failed');
        }
    }
}
