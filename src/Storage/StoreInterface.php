<?php

declare(strict_types=1);

namespace FormGuard\Storage;

interface StoreInterface
{
    /**
     * Invoke the callback once under an exclusive transaction, commit on normal
     * return and roll back on exceptions. A rejection decision is returned, not
     * thrown, inside the transaction so counters can be committed first.
     * @param callable(State): mixed $operation
     */
    public function transaction(callable $operation): mixed;
}
