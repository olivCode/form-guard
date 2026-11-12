<?php

declare(strict_types=1);

namespace FormGuard;

/** @internal Configuration is explicit: no environment variables or HTTP side effects. */
final class Options
{
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function resolve(array $options): array
    {
        $defaults = [
            'scope' => dirname(__DIR__),
            'runtime_dir' => '',
            'window' => 600,
            'client_limit' => 5,
            'ip_limit' => 30,
            'base_ban' => 60,
            'max_ban' => 3600,
            'strike_decay' => 86400,
            'min_seconds' => 3,
            'form_ttl' => 7200,
            'max_forms' => 20,
            'max_entries' => 10000,
            'behavior_enabled' => true,
            'timing_enabled' => true,
            'rate_limit_enabled' => true,
            'rule_budget_ms' => 100,
            'minimum_behavior_score' => 4,
            'min_interactions' => 2,
            'max_interactions_per_second' => 20,
            'locale' => 'en',
            'fallback_locale' => 'en',
            'messages' => [],
            'clock_tolerance' => 5,
            'ip_ban' => false,
            'max_state_bytes' => 8388608,
        ];
        if (array_diff_key($options, $defaults)) {
            throw new \InvalidArgumentException('Unknown Form Guard option.');
        }
        $resolved = array_replace($defaults, $options);
        foreach (['scope', 'runtime_dir'] as $key) {
            if (!is_string($resolved[$key]) || str_contains($resolved[$key], "\0")) {
                throw new \InvalidArgumentException('Invalid option: ' . $key);
            }
        }
        if (trim($resolved['scope']) === '') {
            throw new \InvalidArgumentException('Scope must not be empty.');
        }
        foreach (['behavior_enabled', 'timing_enabled', 'rate_limit_enabled'] as $key) {
            if (!is_bool($resolved[$key])) {
                throw new \InvalidArgumentException($key . ' must be a boolean.');
            }
        }
        if (!is_int($resolved['rule_budget_ms']) || $resolved['rule_budget_ms'] < 1 || $resolved['rule_budget_ms'] > 10000) {
            throw new \InvalidArgumentException('Invalid rule time budget.');
        }
        if (!is_bool($resolved['ip_ban'])) {
            throw new \InvalidArgumentException('ip_ban must be a boolean.');
        }
        if (!is_int($resolved['clock_tolerance']) || $resolved['clock_tolerance'] < 0 || $resolved['clock_tolerance'] > 60) {
            throw new \InvalidArgumentException('Invalid clock tolerance.');
        }
        if (!is_int($resolved['max_state_bytes']) || $resolved['max_state_bytes'] < 1024 || $resolved['max_state_bytes'] > 67108864) {
            throw new \InvalidArgumentException('Invalid state size limit.');
        }
        foreach (['locale', 'fallback_locale'] as $key) {
            if (!is_string($resolved[$key]) || !preg_match('/^[a-zA-Z]{2,8}(?:[-_][a-zA-Z0-9]{1,8})*$/D', $resolved[$key])) {
                throw new \InvalidArgumentException('Invalid locale.');
            }
        }
        if (!is_array($resolved['messages'])) {
            throw new \InvalidArgumentException('Messages must be a catalogue array.');
        }
        foreach ($resolved['messages'] as $locale => $catalogue) {
            if (!is_string($locale) || !preg_match('/^[a-zA-Z]{2,8}(?:[-_][a-zA-Z0-9]{1,8})*$/D', $locale) || !is_array($catalogue)) {
                throw new \InvalidArgumentException('Invalid message catalogue.');
            }
            foreach ($catalogue as $key => $message) {
                if (!is_string($key) || (RejectionReason::tryFrom($key) === null && !preg_match('/^rule\.[a-z][a-z0-9_]{0,31}\.[a-z][a-z0-9_]{0,31}$/D', $key)) || !is_string($message) || trim($message) === '' || strlen($message) > 4096) {
                    throw new \InvalidArgumentException('Invalid message override.');
                }
            }
        }
        foreach (['window', 'client_limit', 'ip_limit', 'base_ban', 'max_ban', 'strike_decay', 'form_ttl', 'max_forms', 'max_entries', 'minimum_behavior_score', 'min_interactions', 'max_interactions_per_second'] as $key) {
            if (!is_int($resolved[$key]) || $resolved[$key] < 1 || $resolved[$key] > 10000000) {
                throw new \InvalidArgumentException('Invalid positive integer: ' . $key);
            }
        }
        if (!is_int($resolved['min_seconds']) || $resolved['min_seconds'] < 0 || $resolved['min_seconds'] > $resolved['form_ttl']) {
            throw new \InvalidArgumentException('Invalid minimum submission time.');
        }
        if ($resolved['base_ban'] > $resolved['max_ban'] || $resolved['max_ban'] > $resolved['strike_decay']) {
            throw new \InvalidArgumentException('Expected base_ban <= max_ban <= strike_decay.');
        }
        if ($resolved['client_limit'] > 100000 || $resolved['ip_limit'] > 100000 || $resolved['max_forms'] > 1000) {
            throw new \InvalidArgumentException('Configured limits exceed supported storage bounds.');
        }
        if ($resolved['minimum_behavior_score'] > 5 || $resolved['max_entries'] < 2) {
            throw new \InvalidArgumentException('Invalid behavior score or storage capacity.');
        }
        if ($resolved['min_interactions'] > 99999) {
            throw new \InvalidArgumentException('Interaction threshold exceeds the browser signal range.');
        }
        return $resolved;
    }
}
