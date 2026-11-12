[English](rules.md) · [Français](rules.fr.md)

# Additional rules

Implement `FormGuard\Rule\RuleInterface`: `id(): string` and `evaluate(RuleContext $context): ?RuleFailure`. Pass ordered instances using Guard's optional fourth/named `rules` argument. IDs must be unique lowercase letters/digits/underscore, starting with a letter, at most 32 characters; at most 32 rules.

```php
$rule = new class implements FormGuard\Rule\RuleInterface {
    public function id(): string { return 'links'; }
    public function evaluate(FormGuard\Rule\RuleContext $context): ?FormGuard\Rule\RuleFailure {
        $message = $context->fields['message'] ?? '';
        if (is_string($message) && substr_count($message, 'https://') > 2) {
            return new FormGuard\Rule\RuleFailure('too_many', ['limit' => 2]);
        }
        return null;
    }
};
$guard = new FormGuard\Guard(['locale' => 'en'], rules: [$rule]);
```

This illustrative rule is not a complete URL/content spam detector. Context exposes untrusted business values under logical field names, age and JS mode. It does not expose form tokens, honeypot, visitor IP or session ID. The application must still validate fields and escape output.

Core checks run first; a refusal stops all later rules. Rules run in supplied order, outside storage transactions. Null passes this rule only; it cannot override a prior refusal. Do not send mail, consume tokens or perform the application action in a rule. Count every POST once through `beginRequest()` before validation.

Failures become HTTP 422 `RejectionReason::CustomRule`, with `ruleId`/`ruleCode`. Codes follow the same identifier syntax. Up to eight named integer parameters, bounded to ±10,000,000, are allowed for presentation. Never place visitor identifiers or message content in metadata. Override `rule.<id>.<code>` in language catalogues or the translator; absent translations use a generic EN/FR refusal.

Exceptions and elapsed budgets produce `RuleExecutionException` (`rule_failed`/`rule_timed_out`), not success. Catch this technical failure at the application boundary and perform no action. The default budget is 100 ms per rule, configurable through `rules.budget_ms` (1–10,000). Budget checks run **after a synchronous call returns**: PHP cannot forcibly preempt a blocking rule. Rules must bound their own I/O; no remote/CAPTCHA integration is bundled or enabled automatically.

The library provides [plugins](plugins.md) with explicit dependencies/conflicts/phases, preserving rules through an adapter. Configuration/extension code remains trusted PHP, not a sandbox.
