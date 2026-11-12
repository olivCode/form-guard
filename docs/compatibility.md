[English](compatibility.md) · [Français](compatibility.fr.md)

# 1.0 API, lifecycle and compatibility

The 1.0 scope is the PHP core plus its separate Laravel 13 adapter. TypeScript/Node and C#/ASP.NET ports are planned after 1.0, with shared behavior vectors; they are not implemented or dependencies of this release.

## Public contracts

The documented public contracts are `Guard`, `Configuration`, `ProtectionLevel`, `Rejection`, `RejectionReason`; storage `StoreInterface`, `InMemoryStore`, `FileStore`, `State` and documented storage exceptions/writer interfaces; translation interfaces/catalogues; rule interfaces/context/results; plugin interfaces, phases, contexts, refusals and documented execution exceptions; the optional `TurnstilePlugin`/transport interface; HTTP `RequestGuard`, `RequestMetadata`, `RequestRejected`; and documented CLI commands. Follow the signatures in [integration](integration.md), [plugins](plugins.md), [storage](storage.md), [i18n](i18n.md) and [HTTP security](http-security.md). Implementation helpers such as `Options`, `RateLimiter`, registry ordering and private filesystem helpers are not extension points.

The 1.x line preserves documented method signatures, rejection enum values, option meanings and the state format. Minor releases may add opt-in features, enum cases and diagnostic fields; use named checks and tolerate unknown diagnostic fields. Breaking changes require a major release and migration instructions. Security fixes may tighten acceptance of malformed input; check release notes. PHP support may be extended in minor releases, not dropped within 1.x without a documented security necessity.

## Integration and persistent state

Set a stable `scope` and persistent private runtime directory. Preserve the key, state and session forms together during updates. Forms carry an immutable `expires` timestamp: changing `form_ttl` affects newly issued forms and cannot revive expired forms.

`RequestGuard` is an explicit integration layer; installing the package does not automatically protect HTTP boundaries. Turnstile remains opt-in and requires provider setup, widget integration and appropriate network deadlines.

## Update and removal

Test on a staging copy, deploy a consistent code revision and matching public asset, and retain the runtime outside the replaced library. Never reset/delete persistent protection state as part of a routine update. Back up key/state together and restore with workers stopped; restoring an old consistent snapshot cannot be detected as such by the file backend.

Before uninstalling, remove or replace protected route/form integration intentionally. Removing the dependency alone must not turn failures into accepted submissions. Composer removal leaves application-owned runtime/session data intact. Manually delete retained data only after deciding the retention/recovery policy and stopping relevant workers; no uninstall hook deletes application data automatically.
