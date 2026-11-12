<?php

declare(strict_types=1);

namespace FormGuard\Storage;

final class State
{
    /** @param array<string, array{last: int, attempts: list<int>, until: int, strikes: int, incident: int}> $buckets */
    public function __construct(
        public readonly string $secret,
        public readonly string $scopeId,
        public array $buckets = [],
        public int $clock = 0,
    ) {
    }

    /**
     * @param array<array-key, mixed> $buckets Untrusted decoded state.
     */
    public static function validateBuckets(array $buckets): void
    {
        foreach ($buckets as $key => $entry) {
            if (!is_string($key) || !preg_match('/^(ip|client):[a-f0-9]{64}$/D', $key) || !is_array($entry)
                || array_diff(['last', 'attempts', 'until', 'strikes', 'incident'], array_keys($entry))
                || array_diff(array_keys($entry), ['last', 'attempts', 'until', 'strikes', 'incident'])) {
                throw new StorageException('invalid_state');
            }
            foreach (['last', 'until', 'strikes', 'incident'] as $field) {
                if (!is_int($entry[$field]) || $entry[$field] < 0) {
                    throw new StorageException('invalid_state');
                }
            }
            if ($entry['strikes'] > 20 || !is_array($entry['attempts']) || !array_is_list($entry['attempts']) || count($entry['attempts']) > 100000) {
                throw new StorageException('invalid_state');
            }
            foreach ($entry['attempts'] as $at) {
                if (!is_int($at) || $at < 0 || $at > $entry['last']) {
                    throw new StorageException('invalid_state');
                }
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        if (strlen($this->secret) !== 32 || $this->clock < 0) {
            throw new StorageException('invalid_state');
        }
        self::validateBuckets($this->buckets);
        $data = ['version' => 2, 'key_id' => hash('sha256', $this->secret), 'scope_id' => $this->scopeId, 'clock' => $this->clock, 'buckets' => $this->buckets];
        return $data + ['mac' => hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), $this->secret)];
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data, string $secret, string $scopeId): self
    {
        if (!array_key_exists('version', $data)) {
            throw new StorageException('legacy_state');
        }
        if ($data['version'] !== 2 || !is_array($data['buckets'] ?? null) || !is_int($data['clock'] ?? null) || $data['clock'] < 0) {
            throw new StorageException('invalid_state');
        }
        if (!is_string($data['key_id'] ?? null) || !hash_equals(hash('sha256', $secret), $data['key_id'])) {
            throw new StorageException('key_changed');
        }
        if (!is_string($data['scope_id'] ?? null) || !hash_equals($scopeId, $data['scope_id'])) {
            throw new StorageException('scope_changed');
        }
        self::validateBuckets($data['buckets']);
        foreach ($data['buckets'] as $entry) {
            if ($entry['last'] > $data['clock'] || $entry['incident'] > $data['clock']) {
                throw new StorageException('invalid_state');
            }
        }
        if (array_diff(array_keys($data), ['version', 'key_id', 'scope_id', 'clock', 'buckets', 'mac'])) {
            throw new StorageException('invalid_state');
        }
        $payload = ['version' => 2, 'key_id' => $data['key_id'], 'scope_id' => $data['scope_id'], 'clock' => $data['clock'], 'buckets' => $data['buckets']];
        if (!is_string($data['mac'] ?? null) || !hash_equals(hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $secret), $data['mac'])) {
            throw new StorageException('signature_invalid');
        }
        return new self($secret, $scopeId, $data['buckets'], $data['clock']);
    }
}
