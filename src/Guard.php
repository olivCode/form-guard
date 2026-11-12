<?php

declare(strict_types=1);

namespace FormGuard;

use FormGuard\Storage\FileStore;
use FormGuard\Storage\State;
use FormGuard\Storage\StoreInterface;
use FormGuard\Translation\CallbackTranslator;
use FormGuard\Translation\CatalogueTranslator;
use FormGuard\Translation\TranslatorInterface;
use FormGuard\Rule\RuleInterface;
use FormGuard\Plugin\BehaviorPlugin;
use FormGuard\Plugin\FormContext;
use FormGuard\Plugin\FormPluginInterface;
use FormGuard\Plugin\PluginExecutionException;
use FormGuard\Plugin\Refusal;
use FormGuard\Plugin\Registry;
use FormGuard\Plugin\RequestContext;
use FormGuard\Plugin\RequestLimitPlugin;
use FormGuard\Plugin\RequestPluginInterface;
use FormGuard\Plugin\RuleAdapter;
use FormGuard\Plugin\TimingPlugin;

final class Guard
{
    /** @var array<string, mixed> */
    private readonly array $settings;
    private readonly StoreInterface $store;
    private readonly TranslatorInterface $translator;
    private readonly RateLimiter $limiter;
    private readonly Registry $plugins;
    private ?string $activePlugin = null;

    /**
     * @param array<string, mixed>|Configuration $options
     * @param array<array-key, mixed> $rules Validated list of rule instances.
     * @param array<array-key, mixed> $plugins Validated list of additional plugins.
     */
    public function __construct(#[\SensitiveParameter] array|Configuration $options = [], ?StoreInterface $store = null, TranslatorInterface|callable|null $translator = null, array $rules = [], #[\SensitiveParameter] array $plugins = [])
    {
        $this->settings = ($options instanceof Configuration ? $options : Configuration::fromArray($options))->options();
        $directory = $this->settings['runtime_dir'] ?: sys_get_temp_dir() . '/form-guard-' . substr(hash('sha256', $this->settings['scope']), 0, 16);
        $this->store = $store ?? new FileStore($directory, $this->settings['scope'], $this->settings['max_state_bytes']);
        $fallback = new CatalogueTranslator($this->settings['messages'], $this->settings['fallback_locale']);
        $this->translator = $translator === null ? $fallback : new CallbackTranslator($translator instanceof TranslatorInterface ? [$translator, 'translate'] : $translator, $fallback);
        $this->limiter = new RateLimiter($this->settings);
        if (count($rules) > 32) {
            throw new \InvalidArgumentException('Too many additional rules.');
        }
        if (!array_is_list($rules) || !array_is_list($plugins) || count($plugins) > 32) {
            throw new \InvalidArgumentException('Invalid plugin/rule list.');
        }
        $configured = [];
        if ($this->settings['rate_limit_enabled']) {
            $configured[] = new RequestLimitPlugin($this->store, $this->limiter);
        }
        if ($this->settings['timing_enabled']) {
            $configured[] = new TimingPlugin($this->settings['min_seconds']);
        }
        if ($this->settings['behavior_enabled']) {
            $configured[] = new BehaviorPlugin($this->settings['minimum_behavior_score'], $this->settings['min_interactions'], $this->settings['max_interactions_per_second']);
        }
        foreach ($rules as $rule) {
            if (!$rule instanceof RuleInterface) {
                throw new \InvalidArgumentException('Invalid additional rule.');
            }
            $configured[] = new RuleAdapter($rule);
        }
        foreach ($plugins as $plugin) {
            if ($plugin instanceof RequestLimitPlugin || $plugin instanceof TimingPlugin || $plugin instanceof BehaviorPlugin) {
                throw new \InvalidArgumentException('Built-in protections are configured through their groups.');
            }
        }
        $this->plugins = new Registry([...$configured, ...$plugins]);
    }

    private function assertIdle(): void
    {
        if ($this->activePlugin !== null) {
            throw new PluginExecutionException('reentrant_plugin', $this->activePlugin);
        }
    }

    /** @return list<array{id: string, phase: string, requires: list<string>, conflicts: list<string>}> */
    public function describePlugins(): array
    {
        return $this->plugins->describe();
    }

    private function runPlugins(#[\SensitiveParameter] RequestContext|FormContext $context): void
    {
        $this->assertIdle();
        $metadata = $this->plugins->describe();
        foreach ($this->plugins->ordered() as $index => $plugin) {
            $entry = $metadata[$index];
            if ($context instanceof RequestContext && !$plugin instanceof RequestPluginInterface || $context instanceof FormContext && !$plugin instanceof FormPluginInterface) {
                continue;
            }
            $builtin = $plugin instanceof RequestLimitPlugin || $plugin instanceof TimingPlugin || $plugin instanceof BehaviorPlugin;
            $legacy = $plugin instanceof RuleAdapter;
            $this->activePlugin = $entry['id'];
            $start = hrtime(true);
            try {
                if ($context instanceof RequestContext) {
                    $refusal = $plugin->evaluate($context);
                } else {
                    $refusal = $plugin->evaluate($context);
                }
            } catch (\Throwable $error) {
                if ($builtin || $error instanceof PluginExecutionException) {
                    throw $error;
                }
                throw new PluginExecutionException($legacy ? 'rule_failed' : 'plugin_failed', $entry['id'], $error);
            } finally {
                $this->activePlugin = null;
            }
            if (!$builtin && (hrtime(true) - $start) / 1e6 > $this->settings['rule_budget_ms']) {
                throw new PluginExecutionException($legacy ? 'rule_timed_out' : 'plugin_timed_out', $entry['id']);
            }
            if ($refusal !== null) {
                if ($context instanceof RequestContext && !in_array($refusal->reason, [RejectionReason::RateLimited, RejectionReason::CustomRule], true)
                    || $context instanceof FormContext && $refusal->reason === RejectionReason::RateLimited) {
                    throw new PluginExecutionException('invalid_refusal_phase', $entry['id']);
                }
                $this->rejectPlugin($refusal, $entry['id']);
            }
        }
    }

    private function rejectPlugin(Refusal $refusal, string $id): never
    {
        if ($refusal->reason !== RejectionReason::CustomRule) {
            $this->reject($refusal->reason, $refusal->parameters, $refusal->retryAfter);
        }
        $key = 'rule.' . $id . '.' . $refusal->code;
        $message = $this->translator->translate($key, $refusal->parameters, $this->settings['locale']);
        if ($message === $key) {
            $message = $this->translator->translate(RejectionReason::CustomRule->value, $refusal->parameters, $this->settings['locale']);
        }
        throw new Rejection(RejectionReason::CustomRule, $message, $refusal->parameters, ruleId: $id, ruleCode: $refusal->code);
    }

    /** @param array<string, int|string> $parameters */
    private function reject(RejectionReason $reason, array $parameters = [], int $retryAfter = 0): never
    {
        throw new Rejection($reason, $this->translator->translate($reason->value, $parameters, $this->settings['locale']), $parameters, $retryAfter);
    }

    /**
     * @param array<string, mixed> $forms Session-held forms; invalid entries are discarded.
     * @param list<mixed> $fieldNames Logical names, validated before use.
     * @return array{id: string, csrf: string, created: int, expires: int, scope_id: string, js_trap: string, fields: array<string, string>}
     */
    public function issueForm(#[\SensitiveParameter] array &$forms, array $fieldNames, ?int $now = null): array
    {
        $this->assertIdle();
        $now ??= time();
        if ($now < 0 || $now > PHP_INT_MAX - $this->settings['form_ttl']) {
            throw new \InvalidArgumentException('Invalid server time.');
        }
        foreach ($fieldNames as $name) {
            if (!is_string($name) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $name)) {
                throw new \InvalidArgumentException('Invalid logical field name.');
            }
        }
        foreach ($forms as $id => $saved) {
            if (!is_array($saved) || !$this->validForm($saved) || (isset($saved['expires']) ? $now > $saved['expires'] : $saved['created'] < $now - $this->settings['form_ttl'])) {
                unset($forms[$id]);
            }
        }
        while (count($forms) >= $this->settings['max_forms']) {
            array_shift($forms);
        }
        $form = ['id' => bin2hex(random_bytes(16)), 'csrf' => bin2hex(random_bytes(32)), 'created' => $now, 'expires' => $now + $this->settings['form_ttl'], 'scope_id' => hash('sha256', $this->settings['scope']),
            'js_trap' => bin2hex(random_bytes(12)), 'fields' => []];
        foreach (array_unique([...$fieldNames, 'honeypot']) as $name) {
            $form['fields'][$name] = ($name === 'honeypot' ? 'hp_' : 'f_') . bin2hex(random_bytes(6));
        }
        return $forms[$form['id']] = $form;
    }

    /** @param array<string, mixed> $form */
    private function validForm(array $form): bool
    {
        if (!is_string($form['id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $form['id'])
            || !is_string($form['csrf'] ?? null) || strlen($form['csrf']) !== 64
            || !is_int($form['created'] ?? null) || $form['created'] < 0
            || (array_key_exists('expires', $form) && (!is_int($form['expires']) || $form['expires'] < $form['created'] || $form['expires'] - $form['created'] > 10000000))
            || !is_string($form['js_trap'] ?? null) || strlen($form['js_trap']) !== 24
            || !is_string($form['scope_id'] ?? null) || !hash_equals(hash('sha256', $this->settings['scope']), $form['scope_id'])
            || !is_array($form['fields'] ?? null) || !isset($form['fields']['honeypot'])) {
            return false;
        }
        foreach ($form['fields'] as $field) {
            if (!is_string($field) || !preg_match('/^(f_|hp_)[a-f0-9]{12}$/D', $field)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    public function publicForm(array $form): array
    {
        if (!$this->validForm($form)) {
            throw new \InvalidArgumentException('Invalid server-held form.');
        }
        return ['id' => $form['id'], 'csrf' => $form['csrf'], 'fields' => $form['fields'], 'js_trap' => $form['js_trap']];
    }

    /** @param array<string, mixed> $form */
    public function renderSignals(array $form): string
    {
        $this->publicForm($form);
        $trap = htmlspecialchars($form['js_trap'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<span hidden data-antispam-signals><input type="hidden" name="as_js" value="0">'
            . '<input type="hidden" name="as_interactions" value="0">'
            . '<input type="hidden" name="as_trap" data-antispam-trap value="' . $trap . '"></span>';
    }

    /**
     * @param array<string, mixed> $post Untrusted request data.
     * @param array<string, mixed> $forms Session-held forms.
     * @return array<string, mixed>
     */
    public function validateForm(#[\SensitiveParameter] array $post, #[\SensitiveParameter] array $forms, ?int $now = null): array
    {
        $this->assertIdle();
        $now ??= time();
        if ($now < 0) {
            throw new \InvalidArgumentException('Invalid server time.');
        }
        $id = is_string($post['form_id'] ?? null) ? $post['form_id'] : '';
        $form = $forms[$id] ?? null;
        if (!is_array($form) || !$this->validForm($form) || $form['id'] !== $id) {
            $this->reject(RejectionReason::FormUnavailable);
        }
        if ($now < $form['created'] || (isset($form['expires']) ? $now > $form['expires'] : $now - $form['created'] > $this->settings['form_ttl'])) {
            $this->reject(RejectionReason::FormExpired);
        }
        if (!is_string($post['csrf_token'] ?? null) || !hash_equals($form['csrf'], $post['csrf_token'])) {
            $this->reject(RejectionReason::InvalidToken);
        }
        $honeypot = $post[$form['fields']['honeypot']] ?? null;
        if (!is_string($honeypot) || $honeypot !== '') {
            $this->reject(RejectionReason::Honeypot);
        }
        $duration = $now - $form['created'];
        $interactions = 0;
        $js = $post['as_js'] ?? null;
        $trap = $post['as_trap'] ?? null;
        if (!is_string($js) || !in_array($js, ['0', '1'], true) || !is_string($trap)) {
            $this->reject(RejectionReason::InvalidSignals);
        }
        if ($js === '0') {
            if (!hash_equals($form['js_trap'], $trap)) {
                $this->reject(RejectionReason::InvalidSignals);
            }
        } else {
            $raw = $post['as_interactions'] ?? null;
            if ($trap !== '' || !is_string($raw) || !preg_match('/^(0|[1-9][0-9]{0,4})$/D', $raw)) {
                $this->reject(RejectionReason::InvalidSignals);
            }
            $interactions = (int) $raw;
        }
        $fields = [];
        foreach ($form['fields'] as $name => $field) {
            if ($name !== 'honeypot') {
                $fields[$name] = $post[$field] ?? null;
            }
        }
        try {
            $context = new FormContext($fields, $duration, $js === '1', $interactions);
        } catch (\InvalidArgumentException $error) {
            throw new PluginExecutionException('invalid_context', 'core', $error);
        }
        $this->runPlugins($context);
        return $form;
    }

    /** @param array<string, mixed> $forms */
    public function consumeForm(#[\SensitiveParameter] array &$forms, string $id): void
    {
        $this->assertIdle();
        if (!isset($forms[$id])) {
            $this->reject(RejectionReason::FormUnavailable);
        }
        unset($forms[$id]);
    }

    public function runtimeDir(): string
    {
        if (!$this->store instanceof FileStore) {
            throw new \LogicException('The configured store has no filesystem runtime.');
        }
        return $this->store->runtimeDir();
    }

    /** Call once at the start of every POST, before validating any submitted data. */
    public function beginRequest(#[\SensitiveParameter] string $ip, #[\SensitiveParameter] string $userAgent, #[\SensitiveParameter] string $sessionId, ?int $now = null): void
    {
        $this->assertIdle();
        $this->runPlugins(new RequestContext($ip, $userAgent, $sessionId, $now ?? time(), $now === null));
    }

    public function prune(?int $now = null): int
    {
        $this->assertIdle();
        return $this->store->transaction(fn (State $state): int => $this->limiter->prune($state, $now ?? time()));
    }
}
