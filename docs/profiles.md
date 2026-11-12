[English](profiles.md) · [Français](profiles.fr.md)

# Profiles and grouped configuration

```php
$configuration = FormGuard\Configuration::fromArray([
    'profile' => 'standard',
    'scope' => 'contact',
    'forms' => ['min_seconds' => 3],
    'limits' => ['client' => 7],
    'browser' => ['enabled' => true],
    'storage' => ['directory' => '/private/my-app/form-guard'],
    'language' => ['locale' => 'fr', 'fallback' => 'en'],
    'rules' => ['budget_ms' => 100],
]);
$guard = new FormGuard\Guard($configuration);
```

| Profile | Attempts/client/10 min | Attempts/IP/10 min | Minimum age |
| --- | --- | --- | --- |
| `tolerant` | 10 | 60 | 3 s |
| `standard` | 5 | 30 | 3 s |
| `reinforced` | 3 | 20 | 5 s |

Standard retains previous defaults. Every profile keeps session/scope tokens, CSRF, honeypot, signal integrity and token consumption before the application action. All accept the native no-JS path. Profiles retain the same progressive client bans and rolling shared-IP policy; none silently enables prolonged shared-IP bans. These are starting policies for contact forms, not a guarantee against spam.

Precedence is defaults → profile → explicit overrides. `describe()` reports the selected profile, whether its policy is customized and the source of each setting. Scope, paths and message content are redacted. `options()` contains the full runtime settings and must not be logged.

## Groups

- `forms`: `timing`, `min_seconds`, `ttl`, `max_pending`.
- `limits`: `enabled`, `window`, `client`, `ip`, `ban_initial`, `ban_max`, `strike_decay`, `ip_ban`.
- `browser`: `enabled`, `minimum_score`, `minimum_interactions`, `max_interactions_per_second`.
- `storage`: `directory`, `max_entries`, `max_bytes`, `clock_tolerance`.
- `language`: `locale`, `fallback`, `messages`.
- `rules`: `budget_ms` for each [additional rule](rules.md).

Unknown names, invalid types/durations and mixed grouped/flat keys are rejected. Profiles can also use the `ProtectionLevel` enum. Existing flat options still work unchanged, through the same resolver. Do not mix flat keys with grouped keys in one array. `scope` is the shared top-level key.

The optional timing, rate-limiting and behavior checks can be disabled individually using their boolean switches; this is explicit policy customization. Disabling behavior does not disable signal/trap integrity or require JS. Disabling timing keeps expiry and replay checks. Disabling rate limiting stops counter updates; diagnostics reflect that loss of protection. Validation still rejects invalid settings in a disabled group, avoiding latent misconfiguration when re-enabled.

## CLI

```sh
php bin/form-guard init --profile=standard --locale=fr --scope=contact --output=contact.config.php
php bin/form-guard init --interactive --locale=fr
php bin/form-guard config:check --config=contact.config.php --locale=fr
php bin/form-guard config:show --config=contact.config.php
php bin/form-guard prune --config=contact.config.php
```

The initializer creates a new commented PHP file and never overwrites an existing path. Set its persistent private `storage.directory` before maintenance. Use the **same trusted configuration** for Guard and maintenance so pruning respects customized retention/windows. Conflicting explicit maintenance scope/directory arguments are refused.

Configuration files execute trusted PHP, like application config: load only operator-owned files outside the web root, never uploads or visitor-selected paths. Config diagnostics do not initialize storage, show paths, dump messages or print configuration echoes. EN/FR CLI messages apply to initialization/configuration commands; legacy maintenance diagnostic codes remain stable.

```php
$config = require '/private/my-app/contact.config.php';
$guard = new FormGuard\Guard($config);
```

Keep the previous session/POST integration contract. The pilot suite installs the actual ZIP and tests all three policies with synthetic legitimate submissions, abuse bursts, shared coworkers, replay and AJAX metadata. It is not observation of real customer traffic. Existing sites are unchanged.

Diagnostics also flag neutral/overlapping policies: zero delay (`timing_has_no_delay`), always-passing score (`behavior_score_always_passes`), IP ceiling below client ceiling (`shared_ip_ceiling_below_client`), disabled local limits (`local_limits_disabled`) and minimum age equal to expiry (`minimum_age_equals_expiry`). These explain valid but intentional combinations; dependency conflicts are errors, not warnings.
