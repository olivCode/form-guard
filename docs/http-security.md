[English](http-security.md) · [Français](http-security.fr.md)

# HTTP boundaries and optional Turnstile

## Recommended default

Keep session-bound tokens, timing and request limits enabled for contact forms. Use `FormGuard\Http\RequestGuard` before form validation as defense in depth: explicit trusted origins, POST only, a 16 KiB body ceiling, bounded field count/depth/length, and no file uploads. `examples/index.php` contains the full wiring. Its trusted origin defaults to `http://127.0.0.1:8000`; set `FORM_GUARD_EXAMPLE_ORIGIN` when serving the local example at another origin. Never derive trusted origins from a visitor's Host or forwarded header.

`RequestMetadata` receives the method, media type, known body byte count and optional Origin, Referer and Sec-Fetch-Site values from the server/framework. Unknown lengths fail closed. Origin matching includes scheme and port, not suffix matching. Missing headers are allowed by default for compatibility, but still require the subsequent session/CSRF checks; use `allowMissingOrigin: false` if every supported client supplies an origin/referrer. Explicit cross-site requests are rejected by default. These headers do not prove a human visitor.

The policy validates already-parsed data. Configure the web server body ceiling, PHP `post_max_size`, `max_input_vars`, `max_input_nesting_level`, upload policy and timeouts too: a PHP library cannot prevent work already performed by the server/parser. If enabling a challenge, allow its token within the bounded payload, not unlimited requests. The default HTTP policy accepts URL-encoded and multipart forms without files. JSON must be explicitly enabled and still accepts only the policy's string/array field model. It is not a general API validator.

## Optional external challenge

`FormGuard\Plugin\TurnstilePlugin` is opt-in. Default profiles do not contact Cloudflare and support forms without JavaScript. Consider Turnstile for sustained abuse that survives local protections; it adds a third party, JavaScript, privacy/accessibility considerations and an availability dependency. Do not silently bypass a failed challenge. Provide a separate accessible contact path when needed.

Create the plugin with a server-held secret, a fixed expected hostname and action, then pass it in the `Guard` constructor's `plugins` argument. Add `challenge` to `issueForm()` logical fields. The widget's `data-response-field-name` must use `$form['fields']['challenge']`, and its `data-action` must match the configured action. Configure the client-side site key separately; never expose the secret in HTML. Escape every dynamic attribute. Reset the widget after errors/renewal because tokens expire.

The bundled transport posts only to `https://challenges.cloudflare.com/turnstile/v0/siteverify`, verifies TLS, refuses redirects, bounds the response to 8 KiB, and uses a three-second network deadline with a two-second initial timeout. It requires OpenSSL, a working CA trust store and `allow_url_fopen`. A custom `TurnstileTransportInterface` implementation must retain TLS verification, bounded I/O and a fixed destination. Configure `rule_budget_ms` to 5000 for this network plugin; the 100 ms default is intended for local plugins. The plugin budget is checked after return and cannot interrupt arbitrary PHP/network code.

Success must be the boolean `true` and match both hostname and action. Missing/oversized tokens and failed verification return `rule.turnstile.challenge_failed`; network/format errors stop processing as technical failures. Do not log tokens, secrets, visitor content or provider responses. Local tests use injected responses, not production credentials.

Turnstile's reuse detection is eventually consistent, not an atomic application lock. Keep Form Guard token consumption and session locking regardless of the challenge. See [Cloudflare server validation](https://developers.cloudflare.com/turnstile/get-started/server-side-validation/) and [widget settings](https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/widget-configurations/).

## Ban policy from 1.0

Default escalation is 60, 120, 240, 480, 960, 1920, then at most 3600 seconds. Strikes decay after 86400 quiet seconds. Blocked retries do not extend a current ban. Shared IPs throttle within their window; long shared-IP bans remain opt-in. Existing stored bans are retained during upgrade, not silently shortened. Adjust thresholds for real traffic; patient or distributed bots can still submit.

HTTP messages accept the same locale syntax as the core: French regional variants fall back to French, other unsupported languages to English. The stream timeout/deadline bounds response I/O, but cannot forcibly interrupt an operating-system DNS resolver; use a transport with an asynchronous resolver and a total timeout if a strict wall-clock network bound is required.
