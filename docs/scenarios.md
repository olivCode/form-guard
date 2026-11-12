[English](scenarios.md) · [Français](scenarios.fr.md)

# Choosing a policy and combining protections

Start with Standard, all optional checks enabled, `ip_ban: false`, and the same trusted configuration for HTTP handling and maintenance. Measure synthetic legitimate retries first, then tune from actual aggregate refusals if your application records them. Never log messages, tokens, sessions or IPs for this diagnostic. A profile is a retry/timing policy, not a promise of bot detection.

| Scenario | Recommended association | Why | Trade-off/check |
| --- | --- | --- | --- |
| Ordinary business contact | Standard + timing + request limits + JS behavior; core always active | Balanced small-form budget and three-second delay | Honest repeated corrections count; explain 429 feedback |
| Frequently reused request form | Tolerant, same components | Larger retry budget without removing checks | More attempts are admitted; validate business fields and submit volume |
| Form receiving concentrated abuse | Reinforced + same components; relevant content rule if justified | Lower client/global budgets and five-second delay | More legitimate retries/shared coworkers can be refused; test that audience |
| Employees sharing a public IP | Standard or Tolerant; rolling IP throttle, `ip_ban: false` | Preserves individual client control without long collective bans | The shared ceiling still applies; size it for concurrent real users |
| Keyboard, screen-reader or no-JS users | Standard + normal components | Native mode is supported; movement/JS are not required | Do not disable behavior merely because JS may be absent |
| Fast autofill on a familiar authenticated form | First test Standard; disable only timing if instant submission is a real requirement | Removes the time heuristic while retaining CSRF/honeypot/rate checks | Faster bots also lose that obstacle; test behavior with prefilled values |
| Existing trusted rate limiter at the application boundary | Keep core/timing/behavior; disable local limits only after verifying that equivalent limit | Avoids contradictory double counting/quotas | Form Guard then stores no limiter attempts; external failure must refuse the action |
| Known business content constraint | Profile + a narrowly scoped rule | Enforces an explainable constraint after integrity checks | Link-count/keyword rules can reject legitimate content; do not enable a generic example globally |
| Large form completed slowly | Standard; adjust `forms.ttl`, not the profile at random | Expiry and minimum age solve different problems | Longer TTL retains usable tokens longer; maintain bounded outstanding forms |

## Complete example: shared business requests

```php
$configuration = FormGuard\Configuration::fromArray([
    'profile' => 'tolerant',
    'scope' => 'business-request',
    'limits' => ['ip' => 90, 'ip_ban' => false],
    'storage' => ['directory' => '/private/app/request-guard'],
    'language' => ['locale' => 'en'],
]);
$guard = new FormGuard\Guard($configuration);
```

This admits up to ten attempts per client and ninety across the IP per ten minutes, retaining a three-second minimum and other controls. Ninety is an **illustrative business choice**, not the default/recommended ceiling for every company. Call `beginRequest()` once at the start of each POST, validate form/business data, then consume before the action while holding the session lock. Schedule `prune --config=...` with that same file.

## What switches do not mean

- Disabling behavior stops only scoring; JS/native trap markers remain structurally checked. Native no-JS mode never needs this switch disabled.
- Disabling timing does not disable expiry, tokens or replay protection.
- Disabling local request limits stops its storage counters, not core validation. A diagnostic must not describe that configuration as a complete local abuse limiter.
- A passing additional rule cannot override a core or earlier refusal. Failure or lateness of a rule is technical refusal, not a positive verdict.
- Group values are validated even when the component is disabled, to avoid an invalid future reactivation. Conflicting ban durations and unknown settings are errors, not silently repaired.

## Integration-specific prerequisites

Use a verified client IP: configure trusted proxies outside the library; never feed a raw `X-Forwarded-For` chain. Use stable scope/private persistent storage and a secure application session. Keep private configuration operator-owned. AJAX must replace every token/name/trap before refreshing the helper; respect the newly issued form's age. Changing policy must not silently erase existing state or bans.

The library's session/token model is for browser form submissions. A machine webhook/API requiring signed requests should use its own authentication and endpoint-specific limits, rather than turning off every browser-form check. Profiles do not make that a supported authentication protocol.

## Verification after any customization

Test native and JS submission, keyboard/prefilling, honest retries, a coworker on the same IP, invalid tokens/honeypot, consumption/replay, 429/retry feedback, storage unavailability and any additional-rule outage. Use the configuration diagnostic to confirm actual sources/values. The shipped combination and pilot suites provide reproducible synthetic examples; they do not establish a real-world false-positive rate.
