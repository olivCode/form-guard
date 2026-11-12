[English](storage.md) · [Français](storage.fr.md)

# Storage, clocks and maintenance

## Store contract

`Guard` accepts an optional `StoreInterface` as its second constructor argument. The default is `FileStore`; `InMemoryStore` is provided for tests and single-process experiments, not cross-request production protection.

`transaction(callable $operation): mixed` invokes its callback exactly once with a working `State` under exclusive access. It commits on a normal return and rolls back on an exception. Rate-limit decisions return from the transaction and are translated/thrown **after commit**, so rejected attempts cannot lose their counters. Callbacks must be short, non-reentrant and must not perform external actions.

Custom implementations must persist a stable secret, validate state, provide the same transaction semantics and share storage across workers. Creating a fresh in-memory store for every HTTP request disables meaningful request limiting. Distributed stores are not bundled with the library; filesystem locks must be reliable on the deployment's local filesystem.

## Files and private paths

The default runtime uses a dedicated system-temporary directory derived from `scope`. Set a stable explicit application scope and a persistent private `runtime_dir` when counters must survive relocations or temporary-directory cleanup. Paths must be absolute local filesystem paths, outside the HTTP root.

The store checks regular files, rejects symbolic links/hard-linked sensitive files and enforces private ownership/permissions on POSIX when available. On Windows, configure a private ACL for the PHP account; PHP mode bits are not an ACL guarantee. Parent filesystem paths and the host account remain part of the trust boundary. Another process with the same privileges can still tamper with application data; this is not an isolation boundary against a compromised host.

Reads and writes use one stable advisory lock. Writes use a random sibling temporary file, full write, flush, fsync, close, then rename over the destination. The previous snapshot remains intact if replacement fails. Interrupted processes may leave a temporary file, ignored by normal reads. This protects against process interruptions; it does not claim guaranteed recovery from hardware/power failures on every filesystem.

JSON/schema errors, oversized files, a missing member of an existing key/state pair, changed keys and changed scopes fail closed with `StorageException`. The envelope includes an HMAC-SHA256 authentication code so a schema-valid modification is also rejected. No request is sent/processed after such a failure. The key is never stored inside the JSON. Key/state reset involves two replacements: an interruption between them leaves a detectable mismatch rather than a silent bypass.

If both files or the entire runtime directory disappear, the store cannot distinguish that from a fresh installation; retain a persistent private directory when history must survive system cleanup. Restoring an older consistent backup can also restore older counters. The envelope is not an external monotonic log and does not protect against an attacker who can read the key or modify application code. Custom backends should use `State::toArray()`/`fromArray()` instead of implementing their own serialization/signature rules.

Default caps are 10,000 limiter records and 8 MiB of JSON. Reaching capacity fails closed rather than evicting active bans. Expired buckets are pruned before evaluating capacity.

## Clocks, sessions and IPs

- Time is server-controlled. Optional timestamps are for deterministic tests only.
- A server clock moving backward by at most `clock_tolerance` seconds (default 5) is clamped to the last limiter time. A larger rollback fails closed with `clock_moved_back`. Fix time synchronization before retrying; do not silently delete counters.
- Forward time naturally expires records. The library cannot distinguish a legitimate long interval from a large clock jump.
- Form timestamps in the future are rejected. Forms are bound to their application scope and server-held session data. A changed locale does not invalidate a form.
- Session-ID rotation changes the client fingerprint while the per-IP limit remains. Preserved session data can keep unconsumed forms valid; lost sessions require a new form. Consumed forms cannot replay through browser history.
- Supply a verified IP and nonempty session ID. Equivalent IPv6 strings are normalized. The library never reads proxy headers; configure trusted proxies outside the package. Do not pass a raw comma-separated forwarding chain or trust an arbitrary `X-Forwarded-For` value.

## Shared networks and false positives

All new POST attempts count, including invalid tokens and ordinary business-field mistakes. Five admitted attempts per client in ten minutes are followed by a progressive client ban. Requests already blocked by that client's ban do not keep consuming coworkers' shared quota or extend the same ban.

The default shared-IP ceiling (30 attempts/10 minutes) now throttles until the oldest slot expires instead of banning all users for an hour. A refusal at the IP ceiling does not itself escalate individual clients. `ip_ban: true` explicitly restores progressive IP bans for deployments that prefer that policy. Existing migrated active bans remain respected until expiry.

The policy still affects shared networks and honest retries; tune it for the protected form. This is not a general login-protection or DDoS system. UA/session rotation can avoid a single-client bucket but not the shared IP ceiling. Distributed, patient bots can still pass checks.

## Scheduled maintenance

The installed Composer binary is `vendor/bin/form-guard`; in a source checkout use `php bin/form-guard`:

```sh
php bin/form-guard status --runtime-dir=/private/form-guard --scope=contact
php bin/form-guard prune --runtime-dir=/private/form-guard --scope=contact
php bin/form-guard migrate --runtime-dir=/private/form-guard --scope=contact
php bin/form-guard reset --runtime-dir=/private/form-guard --scope=contact --confirm-reset
```

An explicit directory is required. Use the same scope as the application. `status` reports counts and format, not keys or identifiers. `prune` removes expired limiter records plus recognized temporary files older than an hour; arbitrary files and recent writes are preserved. Run it through the operating system scheduler to enforce cleanup without visitor traffic. It preserves active bans and strike history until their retention expires.

Normal request pruning does not require a scheduler. A scheduled prune provides a bounded cleanup interval, not automatic proof of a legal retention policy. Operator logs, backups and filesystem copies have their own retention.

Stop workers and back up the original key/state before `migrate` or `reset`. Restore a consistent backed-up pair to recover without losing bans. `reset --confirm-reset` deliberately clears all protection history and rotates the key; use it only when that tradeoff is accepted. Never upload runtime files or copy them between unrelated sites.
