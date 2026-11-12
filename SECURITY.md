[English](SECURITY.md) · [Français](SECURITY.fr.md)

# Security policy

The latest 1.0.x patch line receives security fixes. No contractual response SLA or independent audit is promised.

## Reporting a vulnerability

Use [GitHub private vulnerability reporting](https://github.com/olivCode/form-guard/security/advisories/new). Do not disclose exploit details in a public issue or pull request. If the issue also affects the other Form Guard package, mention it in one report.

Include the version, environment and a minimal synthetic reproduction. Never include SMTP credentials, keys, session tokens/cookies, real messages or visitor identifiers.

## Scope

Browser signals are forgeable. Native no-JS submission remains supported. Rate limits can affect legitimate users behind shared IP addresses. Protection depends on secure sessions, application-level validation, correct server IP handling and reliable private storage. The file backend is not a distributed service.

The PHP application must decide how to display errors, log failures and handle a rejected or uncertain action. Never continue an action after a protection error. Never use untrusted proxy headers as a visitor IP without configuring trusted proxies at the server/application boundary.

In production set `display_errors=Off` and `zend.exception_ignore_args=On`. Never log request bodies, tokens, secrets or full plugin contexts. Sensitive entry parameters are marked with PHP `SensitiveParameter`, but application/custom-plugin code must apply the same policy; attributes do not redact arbitrary globals or object dumps.
