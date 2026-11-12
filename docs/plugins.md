[English](plugins.md) · [Français](plugins.fr.md)

# Protection plugins

Plugins isolate optional policies for maintenance; they do not replace core form/session integrity. Configuration is validated at Guard construction, before processing a request. Existing flat/grouped options and `rules:` remain supported.

## Built-in components and order

| ID | Phase | Switch | Purpose |
| --- | --- | --- | --- |
| `request_limits` | Request | `limits.enabled` | Counts each POST once, commits shared/client decisions before returning a refusal |
| `timing` | Form | `forms.timing` | Checks minimum server-held age |
| `browser_behavior` | Form | `browser.enabled` | Optional scoring in declared JS mode; native mode passes |

`beginRequest()` runs the request phase. The built-in limiter runs first; custom request code runs after its lock is released. `validateForm()` first verifies session/scope, expiry, CSRF, honeypot and signal integrity, then runs form plugins. Consume the form immediately before the application action, under the session lock. Plugin activation never replaces these application calls.

Ordering is request phase before form phase, dependency order within each phase, then insertion order among available peers. Built-ins precede extra code when no dependency changes ordering. A rejection/exception stops later components. A null result passes only the current component and cannot override a prior refusal.

## Implementing a form plugin

```php
use FormGuard\Plugin\{FormContext, FormPluginInterface, Phase, Refusal};
use FormGuard\RejectionReason;

final class ContentLinks implements FormPluginInterface
{
    public function id(): string { return 'content_links'; }
    public function phase(): Phase { return Phase::Form; }
    public function requires(): array { return ['request_limits']; }
    public function conflicts(): array { return []; }
    public function evaluate(FormContext $context): ?Refusal
    {
        $message = $context->fields['message'] ?? '';
        if (is_string($message) && substr_count($message, 'https://') > 2) {
            return new Refusal(RejectionReason::CustomRule, ['limit' => 2], code: 'too_many');
        }
        return null;
    }
}
$guard = new FormGuard\Guard(['profile' => 'standard'], plugins: [new ContentLinks()]);
```

Use this **only when a two-link business limit is appropriate**; it is not a general URL/spam detector. It requires local request limits so untrusted content evaluation retains a request budget. Disabling that dependency rejects the composition rather than silently weakening it. Translate `rule.content_links.too_many` through the same catalogue/callback mechanism as rules. Default outward feedback remains generic. Business field validation is still the application's responsibility.

For custom request checks implement `RequestPluginInterface`, return `Phase::Request` and accept `RequestContext`. Its server-verified IP, agent and session are sensitive; never log them. Request plugins may depend only on request plugins. Form plugins may depend on enabled request or form components. Form plugins must not return request-stage rate-limit refusals and vice versa; inconsistent results fail technically.

## Coherent associations

| Association | Recommendation / reason |
| --- | --- |
| Standard + all three built-ins | Default public contact policy; no extension needed merely to use no-JS/clavier/autofill |
| Content constraint + `request_limits` dependency | Appropriate if content work needs an admitted-attempt budget; dependency is explicit |
| Two mutually exclusive providers of the same business policy | Declare a conflict in at least one manifest; refuse both enabled instead of applying contradictory verdicts |
| A rule that must run after normalization/checking by another plugin | Declare `requires`; no configuration-order guessing. Context is immutable, so normalization must be outside the pipeline or expressed as independent validation, never hidden mutation |
| Request plugin depending on form validation | Rejected: request work precedes trusted form data |
| Custom plugin named `timing`, `browser_behavior` or `request_limits` | Rejected even when that built-in is off; configure built-ins through their groups |
| Cyclic, self, missing or disabled dependency | Rejected before requests; no automatic plugin enabling |

See [scenarios](scenarios.md) for why to choose each preset/combination, its false-positive trade-off and verification steps. `Configuration::describe()` shows built-in switches; `$guard->describePlugins()` gives the actual validated order, IDs, phases, dependencies and conflicts, without visitor data or settings. Diagnostics cannot infer plugins your application has not registered.

## Isolation, failure and compatibility

Manifests are snapshotted once. IDs and relationships must use unique lowercase-letter/digit/underscore names, start with a letter and fit 32 characters. Lists/phase interfaces and cycles are validated. At most 32 extra plugins, 32 legacy rules and 64 total components including built-ins are allowed.

Form context is read-only, recursively detached from input references, limited to 16 nested levels and 10,000 values, and accepts scalar/array business values. It excludes form tokens, honeypot, IP and session. No plugin can use Guard's state-changing methods recursively; reentrancy fails closed. Plugin/configuration code is trusted PHP, not a sandbox against malicious code that accesses globals or files directly.

Exceptions, invalid refusal metadata, phase-invalid results and elapsed custom budgets produce `PluginExecutionException`, compatible with the `RuleExecutionException` handler. Core storage failures retain `StorageException`. User-facing responses must stay generic. Refusal parameters are bounded presentation metadata; no visitor content. Rate refusals require coherent delay/scope metadata. Custom refusals require a code and numeric metadata.

The custom budget reuses `rules.budget_ms` and is measured after a synchronous call returns; it cannot preempt blocking I/O. Set explicit service timeouts in custom code. Built-in storage work is not subject to that user-code timer. No remote/CAPTCHA service is auto-discovered or enabled.

Rules use `RuleAdapter`, preserving `rule_failed`/`rule_timed_out`, translation keys and structured rejection fields. Their IDs now share the plugin namespace/reserved names. Request identity is validated even with local limits disabled. Interaction thresholds above the browser's 99,999 counter range are rejected. Core signal checks now precede timing, so a request with multiple simultaneous faults may report a different first reason.
