<?php

declare(strict_types=1);

namespace FormGuard;

use FormGuard\Storage\State;
use FormGuard\Storage\StorageException;

/** @internal Decisions contain no IP, session, key or translated message. */
final class RateLimiter
{
    /** @param array{max_ban: int, clock_tolerance: int, window: int, strike_decay: int, ip_limit: int, ip_ban: bool, max_entries: int, client_limit: int, base_ban: int} $options Validated by Options::resolve(). */
    public function __construct(private readonly array $options)
    {
    }

    private function effectiveTime(State $state, int $now): int
    {
        if ($now < 0 || $now > PHP_INT_MAX - $this->options['max_ban']) {
            throw new \InvalidArgumentException('Invalid server time.');
        }
        if ($now < $state->clock - $this->options['clock_tolerance']) {
            throw new StorageException('clock_moved_back');
        }
        return $state->clock = max($state->clock, $now);
    }

    public function prune(State $state, int $now): int
    {
        $now = $this->effectiveTime($state, $now);
        $before = count($state->buckets);
        foreach ($state->buckets as $key => $entry) {
            $entry['attempts'] = array_values(array_filter($entry['attempts'], fn (int $at): bool => $at > $now - $this->options['window']));
            if ($entry['incident'] <= $now - $this->options['strike_decay']) {
                $entry['incident'] = 0;
                $entry['strikes'] = 0;
            }
            if (!$entry['attempts'] && $entry['until'] <= $now && $entry['incident'] === 0) {
                unset($state->buckets[$key]);
            } else {
                $state->buckets[$key] = $entry;
            }
        }
        return $before - count($state->buckets);
    }

    /** @return array{retry_after: int, limit_scope: 'client'|'ip'}|null */
    public function attempt(State $state, string $ip, string $agent, string $sessionId, int $now): ?array
    {
        $packed = @inet_pton($ip);
        if ($packed === false || $sessionId === '' || str_contains($sessionId, "\0")) {
            throw new \InvalidArgumentException('A server-verified IP and non-empty session ID are required.');
        }
        $ip = inet_ntop($packed);
        $this->prune($state, $now);
        $now = $state->clock;
        $ipKey = 'ip:' . hash_hmac('sha256', $ip, $state->secret);
        $clientKey = 'client:' . hash_hmac('sha256', $ip . '|' . substr($agent, 0, 1024) . '|' . $sessionId, $state->secret);
        $empty = ['attempts' => [], 'last' => $now, 'until' => 0, 'strikes' => 0, 'incident' => 0];
        $client = $state->buckets[$clientKey] ?? $empty;
        $shared = $state->buckets[$ipKey] ?? $empty;
        if ($client['until'] > $now) {
            return ['retry_after' => $client['until'] - $now, 'limit_scope' => 'client'];
        }
        if ($shared['until'] > $now) {
            return ['retry_after' => $shared['until'] - $now, 'limit_scope' => 'ip'];
        }
        if (count($shared['attempts']) >= $this->options['ip_limit']) {
            $retry = max(1, min($shared['attempts']) + $this->options['window'] - $now);
            if ($this->options['ip_ban']) {
                $this->ban($shared, $now);
                $state->buckets[$ipKey] = $shared;
                $retry = $shared['until'] - $now;
            }
            return ['retry_after' => $retry, 'limit_scope' => 'ip'];
        }
        $newKeys = (int) !isset($state->buckets[$ipKey]) + (int) !isset($state->buckets[$clientKey]);
        if (count($state->buckets) + $newKeys > $this->options['max_entries']) {
            throw new StorageException('capacity_exceeded');
        }
        $shared['attempts'][] = $now;
        $shared['last'] = $now;
        $state->buckets[$ipKey] = $shared;
        $client['last'] = $now;
        if (count($client['attempts']) >= $this->options['client_limit']) {
            $this->ban($client, $now);
            $state->buckets[$clientKey] = $client;
            return ['retry_after' => $client['until'] - $now, 'limit_scope' => 'client'];
        }
        $client['attempts'][] = $now;
        $state->buckets[$clientKey] = $client;
        return null;
    }

    /** @param array{attempts: list<int>, last: int, until: int, strikes: int, incident: int} $entry */
    private function ban(array &$entry, int $now): void
    {
        $entry['strikes'] = max(1, min(20, $entry['strikes'] + 1));
        $duration = min($this->options['max_ban'], $this->options['base_ban'] * 2 ** ($entry['strikes'] - 1));
        $entry['until'] = $now + $duration;
        $entry['last'] = $entry['incident'] = $now;
        $entry['attempts'] = [];
    }
}
