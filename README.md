[English](README.md) · [Français](README.fr.md)

# Form Guard

![PHP](https://img.shields.io/badge/PHP-%5E8.3-777BB4?logo=php&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-optional-F7DF1E?logo=javascript&logoColor=black)
![Composer](https://img.shields.io/badge/Composer-PSR--4-885630?logo=composer&logoColor=white)
[![MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![Tests](https://github.com/olivCode/form-guard/actions/workflows/ci.yml/badge.svg)](https://github.com/olivCode/form-guard/actions/workflows/ci.yml)

Progressive abuse protection for PHP forms, with optional JavaScript signals.

**PHP 1.0 library**, with a [separate Laravel 13 adapter](https://github.com/olivCode/form-guard-laravel). See the [compatibility contract](docs/compatibility.md), [installation guide](docs/installation.md) and [security policy](SECURITY.md).

Form Guard combines session-bound form tokens, randomized field names, a honeypot, server-side timing, request limits, progressive bans and optional browser signals. It does not send email and has no mandatory framework, CAPTCHA provider or external service. Turnstile is an explicit optional plugin.

## Requirements

- PHP 8.3 or later within the PHP 8 series.
- Writable private local storage with reliable file locking.
- A session managed by your application.
- JavaScript is optional. Browser signals are an additional heuristic, not proof that a visitor is human. Node.js is only used for development tests, not PHP runtime.

## Install in your application

Choose [Composer installation or standalone PHP installation](docs/installation.md). Both use the same engine and protections. The manual method needs no Composer executable or `vendor/` directory: copy the PHP library and load its bundled autoloader. The guide covers private storage, optional JavaScript, maintenance and updates.

## Development installation

Clone this repository, then run:

```sh
composer install
composer check
composer test
composer test:concurrency
composer test:browser
composer test:failure
composer test:load
composer test:distribution
npm ci --ignore-scripts
npx playwright install chromium firefox webkit
npm run test:e2e
```

Without a local Composer executable, the test runners can also be invoked directly:

```sh
php tools/check.php
php tests/run.php
php tests/storage.php
php tests/maintenance.php
php tests/http.php
php tests/concurrency.php
php tests/storage-concurrency.php
node tests/browser.mjs
```

`olivcode/form-guard` is the proposed Composer package name, not an existing published package. The development repository is `olivCode/form-guard`. `composer test:distribution` builds and audits the ZIP, then installs it into a fresh local consumer with Packagist disabled. Distribution from a separate public repository will be prepared later.

## Integration overview

```php
use FormGuard\Guard;
use FormGuard\Rejection;

require __DIR__ . '/vendor/autoload.php';

// Start your application's secure session before output.
$_SESSION['contact_forms'] ??= [];
$guard = new Guard([
    'scope' => 'contact',
    'locale' => 'fr',
    // Optional: an absolute PRIVATE storage directory, outside the web root.
    // 'runtime_dir' => '/var/lib/my-app/form-guard',
]);

// On GET: issue a form, render its tokens, fields and signals.
$form = $guard->issueForm($_SESSION['contact_forms'], ['name', 'email', 'message']);

// On POST, BEFORE validating the request body or business fields:
$guard->beginRequest(
    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    $_SERVER['HTTP_USER_AGENT'] ?? '',
    session_id(),
);
$verified = $guard->validateForm($_POST, $_SESSION['contact_forms']);

// Validate email, lengths and other application-specific rules here.
// Consume immediately before performing the action; keep the session locked.
$guard->consumeForm($_SESSION['contact_forms'], $verified['id']);
// Your application can now send its email or process the submission.
```

The GET and POST lines above illustrate separate request branches. For a complete runnable implementation with safe HTML output and error handling, see [examples/index.php](examples/index.php). For AJAX refreshes, use `publicForm()` and then `window.FormGuard.refresh(formElement)` after updating tokens and field names. See [integration details](docs/integration.md).

## Runnable example

```sh
php -S 127.0.0.1:8000 -t examples
```

Open `http://127.0.0.1:8000`. The example can run without Composer and never sends email. Keep it bound to loopback; it is an integration example, not a production contact service.

## Default policy

- Up to 20 outstanding forms per session, expiring after two hours.
- Three-second minimum submission time, computed from server-held state.
- Five POST attempts per IP + user agent + session within ten minutes.
- Thirty POST attempts per IP within ten minutes, across sessions and agents.
- Progressive client bans of 1, 2, 4, 8, 16, 32, then at most 60 minutes.
- Shared-IP limits throttle for the remaining window by default; progressive IP bans are opt-in.
- Every POST attempt counts, including invalid tokens and field errors.
- Form tokens are consumed before an action to prevent replay.

These defaults are intended for low-volume contact forms. Shared connections and repeated honest input errors can hit the same limits; adjust the policy to your actual traffic. See [configuration](docs/configuration.md).

## Boundaries

This is application-level protection. It does not filter email inboxes, guarantee spam-free submissions, provide DDoS protection or replace validation, secure sessions and hosting controls. JavaScript signals can be forged and the native no-JS mode can be simulated. Patient or distributed bots may pass the checks.

The file backend uses validated private files, atomic state replacement and explicit recovery. Workers must share reliable local locking and a private state directory. It is not a general distributed rate limiter. IP, user agent and session identifiers are represented by keyed HMACs; those values are pseudonymous, not anonymous. Expired entries are pruned on later requests, not by a scheduled deletion job.

## Project

- [HTTP security and optional Turnstile](docs/http-security.md)
- [1.0 API and lifecycle compatibility](docs/compatibility.md)
- [Plugins and dependencies](docs/plugins.md)
- [Scenario/component recommendations](docs/scenarios.md)
- [Profiles and grouped configuration](docs/profiles.md)
- [Additional rules](docs/rules.md)
- [Internationalization and structured rejections](docs/i18n.md)
- [Storage and scheduled maintenance](docs/storage.md)
- [Quality, browser tests and load/failure tests](docs/testing.md)
- [Manual accessibility and external developer acceptance](docs/accessibility-testing.md)
- [Development instructions](docs/development.md)
- [Security policy](SECURITY.md)
- [Contributing](CONTRIBUTING.md)

MIT License. Copyright (c) 2026 Olivier Gaillot.
