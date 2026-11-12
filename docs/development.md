[English](development.md) · [Français](development.fr.md)

# Development

The PHP core and Laravel adapter are maintained in separate repositories. The core does not depend on Laravel. Public contributions are checked by repository CI.

```sh
composer install
composer check
composer test
composer test:concurrency
composer test:failure
composer test:load
composer test:distribution
npm ci --ignore-scripts
npx playwright install chromium firefox webkit
npm run test:e2e
```

These checks require PHP 8.3+ and Node 22+; Node is development-only. Use synthetic data. Never commit customer messages, credentials or runtime directories. Preserve development-tool lock files and update EN/FR documentation together.
