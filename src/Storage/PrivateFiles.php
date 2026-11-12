<?php

declare(strict_types=1);

namespace FormGuard\Storage;

/** @internal Local filesystem checks; Windows ACLs remain an operator responsibility. */
final class PrivateFiles
{
    public static function directory(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || !preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path)) {
            throw new StorageException('invalid_directory');
        }
        clearstatcache(true, $path);
        self::rejectDirectoryLink($path);
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            throw new StorageException('directory_unavailable');
        }
        $real = realpath($path);
        self::rejectDirectoryLink($path);
        if ($real === false) {
            throw new StorageException('unsafe_directory');
        }
        self::permissions($real, true);
        return $real;
    }

    private static function rejectDirectoryLink(string $path): void
    {
        for ($current = $path; $current !== dirname($current); $current = dirname($current)) {
            clearstatcache(true, $current);
            if (is_link($current)) {
                throw new StorageException('unsafe_directory');
            }
        }
    }

    public static function file(string $path, bool $required = true): bool
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) {
            if ($required) {
                throw new StorageException('missing_file');
            }
            return false;
        }
        if (is_link($path) || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] > 1) {
            throw new StorageException('unsafe_file');
        }
        self::permissions($path, false);
        return true;
    }

    private static function permissions(string $path, bool $directory): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        $stat = @lstat($path);
        if (!$stat || ($stat['mode'] & 0077) !== 0) {
            throw new StorageException('unsafe_permissions');
        }
        if (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid()) {
            throw new StorageException('unsafe_owner');
        }
    }
}
