[English](testing.md) · [Français](testing.fr.md)

# Testing and quality

## Reproducible development tools

The repository tracks `composer.lock` and `package-lock.json` for its development tooling. These locks do not impose dependencies on applications consuming the PHP library. PHPStan, PHP CS Fixer, Playwright and axe are development tools only. Node 22 or newer is needed for browser tests; the PHP runtime has no Node dependency.

```sh
composer install
npm ci --ignore-scripts
npx playwright install chromium firefox webkit
composer validate --strict
composer check
composer test
composer test:concurrency
composer test:failure
composer test:load
composer test:browser
npm run test:e2e
composer test:distribution
composer audit --locked
```

`composer check` runs PHP syntax checks, PHPStan level 6 on all `src/` code without a baseline, PSR-12 checks on PHP sources/examples/tests/tools/CLI, and bilingual documentation checks. Use `composer cs:fix` to apply formatting. `composer analyse` and `composer cs:check` can run independently.

The browser launcher uses `php` from PATH. Set `PHP_BINARY` to your CLI executable when needed; no MAMP path is committed. `FORM_GUARD_TEST_PORT` overrides the loopback-only test port (9473). The launcher creates an isolated runtime for every run and never sends email. Existing servers are not reused.

## Installation checks

`composer test:distribution` audits the distribution ZIP and installs it in a fresh Composer consumer. It also runs `tests/manual-install.php`: only the documented PHP files, assets and license are copied from the ZIP into fresh applications. The exact EN/FR manual bootstraps from the [installation guide](installation.md) run in separate PHP processes with `-n`, without `vendor/`, a Composer autoloader or checkout source includes. The test checks a native form submission, replay rejection, persistent private storage, the public asset copy and the standalone maintenance CLI.

## Browser matrix

Playwright runs the same scenarios on Chromium, Firefox and WebKit. Each CI job installs its own engine and launches a fresh server. Tests use default protection settings and real minimum delays, not weakened test-only protection options.

Scenarios include EN/FR labels and language, keyboard submission, JavaScript enabled/disabled, native validation, prefilled email without input events, AJAX error feedback and token refresh, history/replay, lost session, and 320-pixel reflow with enlarged text. Automated axe checks cover the rendered examples when JavaScript is enabled. Axe itself requires JavaScript; no-JS submission is tested separately.

Programmatic prefilling models missing input/change events; it does not automate native browser or password-manager suggestion UI. Engine tests do not establish compatibility with every Safari release or mobile operating system. See [manual accessibility testing](accessibility-testing.md).

CI runs six PHP jobs (Linux/Windows × PHP 8.3/8.4/8.5) and three browser jobs (Windows). Browser runners use bundled platform dependencies; Ubuntu package mirrors failed DNS resolution during initial CI setup. Linux browser behavior is not validated by the Windows engine matrix. Synthetic reports and failure traces are retained for seven days. Do not attach real visitor content to these reports.

## Bounded load test

`composer test:load` creates a private temporary runtime, starts eight PHP workers and processes 256 requests from distinct synthetic sessions sharing one IP. A higher explicit IP ceiling isolates transaction correctness from throttling; this is not a default-policy acceptance test. It asserts 256 client buckets plus the IP bucket and that every admitted attempt survives concurrency.

`build/load-results.json` records the platform/PHP version, wall time, per-operation p50/p95 latency, worker peak memory and state size. Timing is diagnostic, not a promised throughput or a CI performance gate. It includes filesystem/lock contention on one local machine; it is not an HTTP capacity, DDoS, distributed storage or long-duration benchmark. Existing concurrency tests separately verify rejection boundaries with default limits.

## Failure tests

`composer test:failure` terminates a test worker after flushing a new snapshot but before renaming it. It checks that the previous committed state remains readable, that the lock is released, and that the next request commits. Stale private temporary files can then be cleaned. It also verifies that exceeding capacity fails closed without evicting active clients or changing committed counters.

The storage suite also covers write exceptions, malformed/signed-tampered/oversized states, missing keys/files, schema/scope changes, clock rollback, transaction rollback and migration with active bans. POSIX permissions and symlink checks run conditionally and report skips.

This does not simulate physical power loss, filesystem controller cache failure, a full hosting platform outage, or unreliable network filesystem locking. Operators must back up a consistent key/state pair and test recovery on their own hosting. Always stop workers before migration/reset.
