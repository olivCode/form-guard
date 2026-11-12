[English](configuration.md) · [Français](configuration.fr.md)

# Configuration

Pass options explicitly to `new FormGuard\Guard([...])`. The library does not read environment variables or start sessions, emit HTTP headers or send messages.

| Option | Default | Meaning |
| --- | --- | --- |
| `scope` | Package installation path | Namespace for the default private runtime directory; set explicitly per application |
| `runtime_dir` | Empty | Explicit private directory, otherwise derived in the system temporary directory |
| `window` | 600 | Rolling request window in seconds |
| `client_limit` | 5 | Attempts per IP + user agent + session |
| `ip_limit` | 30 | Attempts per IP across all clients |
| `base_ban` | 60 | First ban in seconds |
| `max_ban` | 3600 | Maximum ban duration |
| `strike_decay` | 86400 | Quiet period before resetting progression |
| `min_seconds` | 3 | Minimum server-measured form age |
| `form_ttl` | 7200 | Maximum form age |
| `max_forms` | 20 | Outstanding forms per session |
| `max_entries` | 10000 | Stored limiter buckets before failing closed |
| `behavior_enabled` | true | Check the score when the browser declares JS mode |
| `minimum_behavior_score` | 4 | Minimum accepted score in JS mode, out of 5 |
| `min_interactions` | 2 | Activity count yielding an additional score point |
| `max_interactions_per_second` | 20 | Ratio threshold for an additional score point |
| `locale` | en | Explicit rejection message locale |
| `fallback_locale` | en | Catalogue fallback locale |
| `messages` | [] | Locale => reason key => plain-text overrides |
| `clock_tolerance` | 5 | Seconds of small backwards clock movement to clamp |
| `ip_ban` | false | Opt into progressive shared-IP bans instead of a rolling throttle |
| `max_state_bytes` | 8388608 | Maximum persisted/read JSON bytes |

Unknown options, wrong scalar types, empty scope, invalid integer ranges and inconsistent durations are rejected. Disabling behavior scoring does not disable CSRF, timing, honeypot, JS trap checks, limits or replay prevention.

Changing `scope` changes the default runtime directory. Changing the configured private directory starts with different counters unless state is deliberately migrated. A directory already bound to a different scope or key fails closed. See `storage.md`. Do not share state or keys across unrelated sites. On multi-worker deployments, all workers for the same protected endpoint need the same runtime directory with reliable locks.

State files contain keyed pseudonymous bucket identifiers, timestamps and counters. The private key must not be published. No form content or raw browser events are stored. Inactive entries are removed on later requests; use the scheduled maintenance CLI when cleanup must also happen without traffic.

During an active ban, that bucket's expiry is not extended. A different bucket (for example the shared IP ceiling) can independently hit its own limit. Set limits with shared networks and honest retries in mind.

## Simplified configuration available

Profiles and grouped configuration are available: see [profiles](profiles.md). New flat options: `timing_enabled` (true), `rate_limit_enabled` (true), `rule_budget_ms` (100). Disabling scoring keeps signal integrity. See [rules](rules.md) for extensions.
