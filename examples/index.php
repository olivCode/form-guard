<?php
declare(strict_types=1);

use FormGuard\Guard;
use FormGuard\Rejection;

// Fixed asset endpoint for this local example; never serves arbitrary paths.
if (($_GET['asset'] ?? '') === 'script') {
    header('Content-Type: text/javascript; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    readfile(dirname(__DIR__) . '/assets/form-guard.js');
    exit;
}

require dirname(__DIR__) . '/bin/bootstrap.php';
$locale = is_string($_GET['lang'] ?? null) && $_GET['lang'] === 'fr' ? 'fr' : 'en';

ini_set('zlib.output_compression', '0');
ini_set('session.use_strict_mode', '1');
session_name('form_guard_example');
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax',
    'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$_SESSION['forms'] ??= [];
$guard = new Guard(['scope' => __DIR__, 'locale' => $locale, 'runtime_dir' => getenv('FORM_GUARD_EXAMPLE_RUNTIME') ?: '']);
$copy = $locale === 'fr' ? [
    'intro' => 'Exemple local expérimental, sans envoi d’e-mail. Patientez au moins trois secondes avant l’envoi. Le formulaire fonctionne aussi sans JavaScript.',
    'trap' => 'Laissez vide', 'email' => 'Adresse e-mail', 'submit' => 'Tester l’envoi',
    'success' => 'Demande acceptée. Cette démonstration n’envoie et ne conserve aucun e-mail.',
    'invalid_email' => 'Indiquez une adresse e-mail valide.', 'too_large' => 'La demande est trop volumineuse.',
    'failure' => 'La démonstration est temporairement indisponible. Merci de réessayer plus tard.',
    'note' => 'Chaque tentative POST est comptée. La sixième tentative du même visiteur en dix minutes entraîne un blocage temporaire. Consultez la configuration avant de multiplier les essais.',
] : [
    'intro' => 'Experimental local example. It sends no email. Wait at least three seconds before submitting. The form also works with JavaScript disabled.',
    'trap' => 'Leave empty', 'email' => 'Email address', 'submit' => 'Test submission',
    'success' => 'Submission accepted. This demonstration does not send or store any email.',
    'invalid_email' => 'Enter a valid email address.', 'too_large' => 'Request too large.',
    'failure' => 'The demonstration is temporarily unavailable. Please try again later.',
    'note' => 'All POST attempts count. The sixth attempt from the same client within ten minutes triggers a temporary ban. See the configuration before repeated testing.',
];
$message = '';
$invalidEmail = false;
$status = 200;
$ok = false;
$reason = null;
$parameters = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $guard->beginRequest($_SERVER['REMOTE_ADDR'] ?? 'unknown', $_SERVER['HTTP_USER_AGENT'] ?? '', session_id());
        $length = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $http = new FormGuard\Http\RequestGuard([getenv('FORM_GUARD_EXAMPLE_ORIGIN') ?: 'http://127.0.0.1:8000'], locale: $locale);
        $http->check(new FormGuard\Http\RequestMetadata(
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['CONTENT_TYPE'] ?? '',
            $length === false ? null : $length,
            $_SERVER['HTTP_ORIGIN'] ?? null,
            $_SERVER['HTTP_REFERER'] ?? null,
            $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null,
        ), $_POST, count($_FILES));
        $verified = $guard->validateForm($_POST, $_SESSION['forms']);
        $email = $_POST[$verified['fields']['email']] ?? null;
        if (!is_string($email) || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $invalidEmail = true;
            throw new DomainException($copy['invalid_email'], 422);
        }
        $guard->consumeForm($_SESSION['forms'], $verified['id']);
        $message = $copy['success'];
        $ok = true;
    } catch (Rejection $error) {
        $status = $error->getCode();
        $message = $error->getMessage();
        $reason = $error->reason->value;
        $parameters = $error->parameters;
        if ($status === 429) {
            header('Retry-After: ' . max(1, $error->retryAfter));
        }
    } catch (DomainException $error) {
        $status = $error->getCode();
        $message = $error->getMessage();
    } catch (Throwable) {
        $status = 503;
        $message = $copy['failure'];
    }
}
http_response_code($status);
$form = $guard->issueForm($_SESSION['forms'], ['email']);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $ok, 'message' => $message, 'reason' => $reason, 'parameters' => $parameters, 'form' => $guard->publicForm($form)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="<?= $escape($locale) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $status >= 400 ? ($locale === 'fr' ? 'Demande à vérifier — ' : 'Check your submission — ') : ($ok ? ($locale === 'fr' ? 'Demande acceptée — ' : 'Submission accepted — ') : '') ?>Form Guard — <?= $locale === 'fr' ? 'Exemple' : 'Example' ?></title>
    <style>
        *{box-sizing:border-box}body{font:1rem/1.6 system-ui,sans-serif;max-width:42rem;padding:2rem 1.25rem;margin:auto;color:#202b32;background:#fafafa;overflow-wrap:anywhere}button{max-width:100%}
        form{padding:1.5rem;background:white;border:1px solid #bccbd1;border-radius:1rem}label,input{display:block}input[type=email]{box-sizing:border-box;width:100%;padding:.7rem;margin:.4rem 0 1rem;font:inherit}button{padding:.7rem 1.2rem;font:inherit;cursor:pointer}a{color:#14547d}:focus-visible{outline:3px solid #14547d;outline-offset:3px}.feedback{border-left:4px solid #14547d;padding:1rem;background:#edf6fa}
    </style>
    <script src="?asset=script" defer></script>
</head>
<body>
  <main>
    <h1>Form Guard</h1>
    <p><a href="?lang=en" lang="en">English</a> · <a href="?lang=fr" lang="fr">Français</a></p>
    <p id="form-help"><?= $escape($copy['intro']) ?></p>
    <?php if ($message !== ''): ?><p id="form-feedback" class="feedback" role="status"><?= $escape($message) ?></p><?php endif; ?>
    <form method="post" action="?lang=<?= $escape($locale) ?>" data-antispam>
        <input type="hidden" name="form_id" value="<?= $escape($form['id']) ?>">
        <input type="hidden" name="csrf_token" value="<?= $escape($form['csrf']) ?>">
        <?= $guard->renderSignals($form) ?>
        <div hidden aria-hidden="true"><label><?= $escape($copy['trap']) ?><input name="<?= $escape($form['fields']['honeypot']) ?>" tabindex="-1" autocomplete="off"></label></div>
        <label for="email"><?= $escape($copy['email']) ?></label>
        <input id="email" type="email" name="<?= $escape($form['fields']['email']) ?>" autocomplete="email" maxlength="254" aria-describedby="form-help<?= $message !== '' ? ' form-feedback' : '' ?>"<?= $invalidEmail ? ' aria-invalid="true"' : '' ?> required>
        <button type="submit"><?= $escape($copy['submit']) ?></button>
    </form>
    <p><?= $escape($copy['note']) ?></p>
  </main>
</body>
</html>
