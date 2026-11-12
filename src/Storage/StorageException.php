<?php

declare(strict_types=1);

namespace FormGuard\Storage;

final class StorageException extends \RuntimeException
{
    public function __construct(public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct('Form protection storage is unavailable.', 0, $previous);
    }
}
