[English](integration.md) · [Français](integration.fr.md)

# Integration

Start with the [installation guide](installation.md): Composer or manual PHP installation, with the same API in both cases.

## Application responsibilities

Use a stable application-specific `scope`, with a dedicated server-held form pool. Start a secure session before output, using an appropriate cookie path, HttpOnly, SameSite and HTTPS settings. Do not cache responses containing form tokens. Avoid response compression for pages carrying secrets alongside reflected user input. Keep the session locked between form validation and consumption.

On every POST, call `beginRequest()` exactly once before rejecting invalid tokens, oversized requests or invalid business fields. Protect the endpoint from oversized bodies at the server boundary too: PHP may parse the body before application code can inspect it. Do not call the limiter on GET or on asset requests.

The application validates email addresses, field lengths, encoding, allowed choices and any business requirements. It escapes output, selects the fixed email recipient and decides whether an action is sent. Form Guard does not know those rules or depend on a mailer.

## Form markup

Use `method="post"` and add `data-antispam` to the form element. Render:

1. Hidden `form_id` and `csrf_token`, using the values from `issueForm()`.
2. The output of `renderSignals($form)`.
3. A visually hidden honeypot named `$form['fields']['honeypot']`, empty, excluded from focus and hidden from assistive technology.
4. Visible fields named with `$form['fields'][...]`, with ordinary labels and appropriate autocomplete attributes.

The `as_js`, `as_trap` and `as_interactions` names are reserved for the helper. Escape any dynamic HTML value with `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.

Copy `assets/form-guard.js` to a public asset location when installing through Composer. Do not expose all of `vendor/` to serve this one script. Load it with `defer` before your AJAX handler. If it is unavailable, the server-rendered native mode remains valid.

## Handling errors

`FormGuard\Rejection` extends `DomainException`. Its code is the suggested status: 403 for invalid/expired form tokens, 422 for invalid form signals/timing, 429 for a rate limit or active ban. Send `retryAfter` as the `Retry-After` header on a 429 response. Exceptions expose a stable `reason` enum, `parameters` and a localized message (EN/FR or a custom translator). Applications should match the reason, not parse the text. See `i18n.md`.

Storage errors are technical failures, not user validation errors. Catch them at the application boundary, display a generic failure and do not send/process the request. Avoid putting exception details into the HTTP response.

For an HTML POST, your application may use a 303 redirect with session feedback. For JSON, return explicit success/failure and appropriate status codes. Never infer delivery from submission alone. Form Guard does not guarantee exactly-once external delivery after a network failure.

## AJAX refresh

After consuming a token, issue a new form and include its `publicForm()` representation in the JSON response. Update `form_id`, `csrf_token`, all randomized field names and `as_trap` from `js_trap`. Then call:

```js
window.FormGuard?.refresh(formElement);
```

This resets only the helper's activity counter and native/JS signals. Your application updates the business fields and messages. Newly issued forms still have the minimum server-side delay.

## Current API

- `new Guard(array|Configuration $options = [], ?StoreInterface $store = null, TranslatorInterface|callable|null $translator = null, array $rules = [], array $plugins = [])`
- `issueForm(array &$forms, array $fieldNames, ?int $now = null): array`
- `publicForm(array $form): array`
- `renderSignals(array $form): string`
- `beginRequest(string $ip, string $userAgent, string $sessionId, ?int $now = null): void`
- `validateForm(array $post, array $forms, ?int $now = null): array`
- `consumeForm(array &$forms, string $id): void`
- `runtimeDir(): string` (file backend only)
- `prune(?int $now = null): int`

The optional time arguments support deterministic tests. Production applications should leave them unset and use the server clock. The 1.x compatibility commitments are described in the [compatibility contract](compatibility.md).

Customizable [profiles](profiles.md), additional [rules](rules.md) and dependency-checked [plugins](plugins.md) are implemented; see [scenarios](scenarios.md) for recommended associations.
