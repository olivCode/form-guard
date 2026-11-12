[English](installation.md) · [Français](installation.fr.md)

# Installation: Composer or standalone PHP

Both methods use the same PHP engine, configuration and protections. Composer provides autoloading and dependency management; it is not required at runtime for a manual installation. PHP 8.3 or later within PHP 8 is required. Neither method needs Node.js or npm in production.

## With Composer

Install the package from Packagist:

```sh
composer require olivcode/form-guard:^1.0
```

Assuming the application directory contains `vendor/`, `private/` and `public/`, put this bootstrap in `private/form-guard.php`:

```php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

return new FormGuard\Guard([
    'scope' => 'contact',
    'locale' => 'en',
    'runtime_dir' => dirname(__DIR__) . '/var/form-guard',
]);
```

If using the optional browser helper, copy `vendor/olivcode/form-guard/assets/form-guard.js` to `public/assets/form-guard.js`. Serve that copy, not the `vendor/` directory.

## Without Composer: copy the PHP files

Download an archive of the selected revision or release. Extract it locally, then copy at least these items into `lib/form-guard/` in your application:

- `src/`, with all subdirectories;
- `bin/`, including `bootstrap.php` and `form-guard`;
- `assets/`, if using the browser helper;
- `LICENSE`, keeping the MIT notice with redistributed copies.

No `vendor/`, Composer executable, generated Composer autoloader or development dependencies are needed. Keep the documentation with your project for configuration and maintenance. Use the complete set of files from one revision: do not mix classes from different releases.

Example layout, with the web server's document root set to **`public/`**:

```text
my-app/
├── lib/form-guard/
│   ├── src/
│   ├── bin/
│   ├── assets/
│   └── LICENSE
├── private/form-guard.php
├── var/form-guard/          # Persistent private runtime, created by the library
└── public/
    ├── index.php
    └── assets/form-guard.js # Optional public copy
```

Put this bootstrap in `private/form-guard.php`. The bundled `bin/bootstrap.php` registers the library's standalone autoloader:

```php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/form-guard/bin/bootstrap.php';

return new FormGuard\Guard([
    'scope' => 'contact',
    'locale' => 'en',
    'runtime_dir' => dirname(__DIR__) . '/var/form-guard',
]);
```

If using JavaScript, copy `lib/form-guard/assets/form-guard.js` to `public/assets/form-guard.js`. No build step is needed. Include it in your HTML:

```html
<script src="/assets/form-guard.js" defer></script>
```

Adjust the URL if your application is served from a subdirectory. The helper is optional; the native no-JavaScript mode remains supported by the default configuration.

## Connect the form, with either installation

In `public/index.php`, load the configured instance with `$guard = require dirname(__DIR__) . '/private/form-guard.php';`. Your application must also start a secure session before output, issue and render the form tokens, count POST attempts, validate the submission and consume its token immediately before the action while holding the session lock.

Loading the library does **not** automatically protect a form. Follow the [integration guide](integration.md) and the [complete PHP example](../examples/index.php) for the GET/POST branches, field names, HTML escaping and rejection handling. The example sends no email; sending and business-field validation remain application responsibilities.

Keep `lib/`, `private/`, `vendor/` and `var/` outside the public document root. The PHP worker needs write access to the dedicated runtime directory, with private permissions (0700 directories and 0600 files on POSIX, equivalent private ACLs on Windows). Never use 0777. If your host has a fixed document root, place these directories alongside it and adjust the absolute paths. Do not rely on unguessable filenames to hide keys or state.

## Maintenance and updates

With Composer, run the maintenance CLI from the application directory:

```sh
php vendor/bin/form-guard status --runtime-dir=/absolute/private/path/my-app/var/form-guard --scope=contact
```

With the manual installation, use:

```sh
php lib/form-guard/bin/form-guard status --runtime-dir=/absolute/private/path/my-app/var/form-guard --scope=contact
```

Replace the example path with the **same** runtime directory used by the application. See [storage and maintenance](storage.md) for scheduled pruning and custom configuration.

For an update, review the changelog and migration instructions, test on a copy of the application, then deploy a consistent library revision and update the public JavaScript copy if used. Composer users update the package and deploy from the application lockfile; manual users replace the library files together. Preserve the persistent runtime, its key and state, application configuration and sessions. Never delete the runtime to perform a routine code update: that would reset protection history. A documented storage migration or reset needs its own procedure.

`composer test:distribution` verifies both installation paths in isolated consumers, including a manual copy with no `vendor/` directory and no Composer autoloader.
