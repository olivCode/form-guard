[English](CONTRIBUTING.md) · [Français](CONTRIBUTING.fr.md)

# Contributing

Contributions are welcome: open an issue to discuss a change or submit a focused pull request. Report vulnerabilities through the confidential channel described in SECURITY.

- Keep changes focused and add tests for observable behavior.
- Run `composer check`, `composer test`, `composer test:concurrency`, `composer test:browser`, `composer test:failure`, `composer test:load`, `npm run test:e2e` and `composer test:distribution`.
- Do not add runtime files, credentials, real messages or customer information.
- Preserve keyboard and no-JS submission. Browser signals must remain optional.
- Discuss changes to public method signatures and defaults before treating them as stable.
- Use the security reporting process for sensitive findings.
- Update English and French documentation together, preserving language navigation and relative links.

Tests must not send real email or depend on an external service. The project is licensed under MIT.
