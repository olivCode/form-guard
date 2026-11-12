<?php

declare(strict_types=1);

namespace FormGuard\Storage;

final class FileStore implements StoreInterface
{
    private bool $active = false;
    private readonly AtomicWriterInterface $writer;

    public function __construct(
        private readonly string $directory,
        private readonly string $scope,
        private readonly int $maxBytes = 8388608,
        ?AtomicWriterInterface $writer = null,
    ) {
        if ($scope === '' || str_contains($scope, "\0") || $maxBytes < 1024) {
            throw new \InvalidArgumentException('Invalid file store configuration.');
        }
        $this->writer = $writer ?? new AtomicFileWriter();
    }

    public function runtimeDir(): string
    {
        return PrivateFiles::directory($this->directory);
    }

    public function transaction(callable $operation): mixed
    {
        return $this->locked(function (string $directory) use ($operation): mixed {
            $state = $this->load($directory);
            $before = $this->encode($state);
            $result = $operation($state);
            $after = $this->encode($state);
            if ($after !== $before || !is_file($directory . '/form-guard-state.json')) {
                $this->writer->write($directory . '/form-guard-state.json', $after);
            }
            return $result;
        });
    }

    private function locked(callable $operation): mixed
    {
        if ($this->active) {
            throw new StorageException('nested_transaction');
        }
        $directory = $this->runtimeDir();
        $path = $directory . '/form-guard.lock';
        clearstatcache(true, $path);
        if (!file_exists($path) && !is_link($path)) {
            $created = @fopen($path, 'x+b');
            if ($created) {
                @chmod($path, 0600);
                fclose($created);
            }
        }
        // 0.1's lock file held no data and could be 0644. Tighten only our regular lock.
        $stat = @lstat($path);
        if ($stat && !is_link($path) && $stat['nlink'] === 1 && ($stat['mode'] & 0170000) === 0100000
            && (!function_exists('posix_geteuid') || $stat['uid'] === posix_geteuid())) {
            @chmod($path, 0600);
        }
        PrivateFiles::file($path);
        $lock = @fopen($path, 'r+b');
        if (!$lock) {
            throw new StorageException('lock_failed');
        }
        try {
            $opened = fstat($lock);
            $current = @lstat($path);
            if (!$opened || !$current || $opened['ino'] !== $current['ino'] || $opened['dev'] !== $current['dev']) {
                throw new StorageException('unsafe_file');
            }
            if (!flock($lock, LOCK_EX)) {
                throw new StorageException('lock_failed');
            }
            $this->active = true;
            return $operation($directory);
        } finally {
            $this->active = false;
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function read(string $path): string
    {
        PrivateFiles::file($path);
        $size = @filesize($path);
        if ($size === false || $size > $this->maxBytes) {
            throw new StorageException('state_too_large');
        }
        $contents = @file_get_contents($path, false, null, 0, $this->maxBytes + 1);
        if (!is_string($contents) || strlen($contents) > $this->maxBytes) {
            throw new StorageException('read_failed');
        }
        return $contents;
    }

    private function secret(string $directory): string
    {
        $key = $directory . '/form-guard.key';
        $hasState = PrivateFiles::file($directory . '/form-guard-state.json', false);
        if (!PrivateFiles::file($key, false)) {
            if ($hasState) {
                throw new StorageException('missing_key');
            }
            $this->writer->write($key, random_bytes(32));
        }
        $secret = $this->read($key);
        if (strlen($secret) !== 32) {
            throw new StorageException('invalid_key');
        }
        return $secret;
    }

    /** @return array<array-key, mixed> */
    private function decode(string $contents): array
    {
        try {
            $data = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new StorageException('invalid_json', $error);
        }
        if (!is_array($data)) {
            throw new StorageException('invalid_state');
        }
        return $data;
    }

    private function load(string $directory): State
    {
        if (PrivateFiles::file($directory . '/form-guard.key', false) && !PrivateFiles::file($directory . '/form-guard-state.json', false)) {
            throw new StorageException('missing_state');
        }
        $secret = $this->secret($directory);
        $scope = hash('sha256', $this->scope);
        $path = $directory . '/form-guard-state.json';
        if (PrivateFiles::file($path, false)) {
            return State::fromArray($this->decode($this->read($path)), $secret, $scope);
        }
        $initial = new State($secret, $scope);
        $this->writer->write($path, $this->encode($initial));
        return $initial;
    }

    private function encode(State $state): string
    {
        $data = $state->toArray();
        State::fromArray($data, $state->secret, hash('sha256', $this->scope));
        try {
            $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new StorageException('invalid_state', $error);
        }
        if (strlen($encoded) > $this->maxBytes) {
            throw new StorageException('state_too_large');
        }
        return $encoded;
    }

    /** Explicit offline conversion: the operator must retain the ORIGINAL 0.1 key. */
    public function migrate(?int $now = null): int
    {
        return $this->locked(function (string $directory) use ($now): int {
            $secret = $this->secret($directory);
            $path = $directory . '/form-guard-state.json';
            $data = PrivateFiles::file($path, false) ? $this->decode($this->read($path)) : [];
            if (array_key_exists('version', $data)) {
                State::fromArray($data, $secret, hash('sha256', $this->scope));
                return 0;
            }
            State::validateBuckets($data);
            $clock = $now ?? time();
            if ($clock < 0) {
                throw new \InvalidArgumentException('Invalid time.');
            }
            foreach ($data as $entry) {
                $clock = max($clock, $entry['last'], $entry['incident']);
            }
            $state = new State($secret, hash('sha256', $this->scope), $data, $clock);
            $this->writer->write($path, $this->encode($state));
            return count($data);
        });
    }

    /** Destructive operator action. Resets all limits and rotates the private key. */
    public function reset(): void
    {
        $this->locked(function (string $directory): void {
            PrivateFiles::file($directory . '/form-guard.key', false);
            PrivateFiles::file($directory . '/form-guard-state.json', false);
            $state = new State(random_bytes(32), hash('sha256', $this->scope));
            // A failure between these writes remains fail-closed (key mismatch).
            $this->writer->write($directory . '/form-guard.key', $state->secret);
            $this->writer->write($directory . '/form-guard-state.json', $this->encode($state));
        });
    }

    /** Remove only our own stale, regular temporary files, never arbitrary files. */
    public function cleanupTemporaryFiles(int $olderThan): int
    {
        if ($olderThan < 0) {
            throw new \InvalidArgumentException('Invalid cutoff.');
        }
        return $this->locked(function (string $directory) use ($olderThan): int {
            $removed = 0;
            foreach (new \DirectoryIterator($directory) as $entry) {
                if (!preg_match('/^\.form-guard-write-[a-f0-9]{24}\.tmp$/D', $entry->getFilename())) {
                    continue;
                }
                $path = $entry->getPathname();
                PrivateFiles::file($path);
                if ($entry->getMTime() < $olderThan) {
                    if (!@unlink($path)) {
                        throw new StorageException('cleanup_failed');
                    }
                    $removed++;
                }
            }
            return $removed;
        });
    }
}
