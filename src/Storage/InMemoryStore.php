<?php

declare(strict_types=1);

namespace FormGuard\Storage;

/** Ephemeral storage for tests/single-process use; never a cross-request limiter. */
final class InMemoryStore implements StoreInterface
{
    private State $state;
    private bool $active = false;

    public function __construct(string $scope = 'memory')
    {
        if (trim($scope) === '' || str_contains($scope, "\0")) {
            throw new \InvalidArgumentException('Invalid memory store scope.');
        }
        $this->state = new State(random_bytes(32), hash('sha256', $scope));
    }

    public function transaction(callable $operation): mixed
    {
        if ($this->active) {
            throw new StorageException('nested_transaction');
        }
        $this->active = true;
        $working = clone $this->state;
        try {
            $result = $operation($working);
            State::fromArray($working->toArray(), $working->secret, $working->scopeId);
            $this->state = $working;
            return $result;
        } finally {
            $this->active = false;
        }
    }
}
