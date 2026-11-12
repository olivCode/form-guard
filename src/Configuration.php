<?php

declare(strict_types=1);

namespace FormGuard;

final class Configuration
{
    private const GROUPS = [
        'forms' => ['timing' => 'timing_enabled', 'min_seconds' => 'min_seconds', 'ttl' => 'form_ttl', 'max_pending' => 'max_forms'],
        'limits' => ['enabled' => 'rate_limit_enabled', 'window' => 'window', 'client' => 'client_limit', 'ip' => 'ip_limit', 'ban_initial' => 'base_ban', 'ban_max' => 'max_ban', 'strike_decay' => 'strike_decay', 'ip_ban' => 'ip_ban'],
        'browser' => ['enabled' => 'behavior_enabled', 'minimum_score' => 'minimum_behavior_score', 'minimum_interactions' => 'min_interactions', 'max_interactions_per_second' => 'max_interactions_per_second'],
        'storage' => ['directory' => 'runtime_dir', 'max_entries' => 'max_entries', 'max_bytes' => 'max_state_bytes', 'clock_tolerance' => 'clock_tolerance'],
        'language' => ['locale' => 'locale', 'fallback' => 'fallback_locale', 'messages' => 'messages'],
        'rules' => ['budget_ms' => 'rule_budget_ms'],
    ];

    /**
     * @param array<string, mixed> $settings
     * @param array<string, string> $sources
     */
    private function __construct(
        private readonly array $settings,
        public readonly ProtectionLevel $level,
        public readonly bool $customized,
        private readonly array $sources,
    ) {
    }

    /**
     * Flat legacy options and grouped settings are supported, but cannot be mixed.
     * @param array<string, mixed> $input
     */
    public static function fromArray(array $input = []): self
    {
        $grouped = array_key_exists('profile', $input) || (bool) array_intersect_key($input, self::GROUPS);
        if (!$grouped) {
            return self::resolve($input, ProtectionLevel::Standard);
        }
        if (array_diff_key($input, self::GROUPS + ['profile' => '', 'scope' => ''])) {
            throw new \InvalidArgumentException('Unknown or mixed flat/grouped configuration key.');
        }
        $profile = array_key_exists('profile', $input) ? $input['profile'] : 'standard';
        $level = $profile instanceof ProtectionLevel ? $profile : (is_string($profile) ? ProtectionLevel::tryFrom($profile) : null);
        if ($level === null) {
            throw new \InvalidArgumentException('Unknown protection profile.');
        }
        $overrides = array_intersect_key($input, ['scope' => '']);
        foreach (self::GROUPS as $group => $mapping) {
            if (!array_key_exists($group, $input)) {
                continue;
            }
            if (!is_array($input[$group]) || array_diff_key($input[$group], $mapping)) {
                throw new \InvalidArgumentException('Invalid configuration group: ' . $group);
            }
            foreach ($input[$group] as $key => $value) {
                $overrides[$mapping[$key]] = $value;
            }
        }
        return self::resolve($overrides, $level);
    }

    /** @param array<string, mixed> $overrides */
    private static function resolve(array $overrides, ProtectionLevel $level): self
    {
        $base = Options::resolve($level->overrides());
        $settings = Options::resolve(array_replace($base, $overrides));
        $sources = array_fill_keys(array_keys($base), 'default');
        foreach ($level->overrides() as $key => $_) {
            $sources[$key] = 'profile:' . $level->value;
        }
        $customized = false;
        foreach ($overrides as $key => $value) {
            $sources[$key] = 'override';
            if (!in_array($key, ['scope', 'runtime_dir', 'locale', 'fallback_locale', 'messages'], true) && $value !== $base[$key]) {
                $customized = true;
            }
        }
        return new self($settings, $level, $customized, $sources);
    }

    /**
     * Resolved runtime configuration: contains private paths/message text; do not log.
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->settings;
    }

    /**
     * Safe diagnostic: no scope value, private directory, message content or visitor data.
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $groups = [];
        foreach (self::GROUPS as $group => $mapping) {
            foreach ($mapping as $name => $key) {
                $value = match ($key) {
                    'runtime_dir' => $this->settings[$key] === '' ? 'system-private-default' : 'explicit-private-directory',
                    'messages' => count($this->settings[$key]),
                    default => $this->settings[$key],
                };
                $groups[$group][$name] = ['value' => $value, 'source' => $this->sources[$key]];
            }
        }
        $warnings = [];
        if (!$this->settings['rate_limit_enabled']) {
            $warnings[] = 'local_limits_disabled';
        }
        if ($this->settings['rate_limit_enabled'] && $this->settings['ip_limit'] < $this->settings['client_limit']) {
            $warnings[] = 'shared_ip_ceiling_below_client';
        }
        if ($this->settings['timing_enabled'] && $this->settings['min_seconds'] === 0) {
            $warnings[] = 'timing_has_no_delay';
        }
        if ($this->settings['behavior_enabled'] && $this->settings['minimum_behavior_score'] <= 3) {
            $warnings[] = 'behavior_score_always_passes';
        }
        if ($this->settings['timing_enabled'] && $this->settings['min_seconds'] === $this->settings['form_ttl']) {
            $warnings[] = 'minimum_age_equals_expiry';
        }
        return ['profile' => $this->level->value, 'customized' => $this->customized, 'warnings' => $warnings,
            'components' => ['request_limits' => $this->settings['rate_limit_enabled'], 'timing' => $this->settings['timing_enabled'], 'browser_behavior' => $this->settings['behavior_enabled']],
            'core' => ['session_tokens' => true, 'csrf' => true, 'honeypot' => true, 'consume_before_action' => true], 'groups' => $groups];
    }
}
