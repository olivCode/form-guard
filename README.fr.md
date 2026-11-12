[English](README.md) · [Français](README.fr.md)

# Form Guard

![PHP](https://img.shields.io/badge/PHP-%5E8.3-777BB4?logo=php&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-optional-F7DF1E?logo=javascript&logoColor=black)
![Composer](https://img.shields.io/badge/Composer-PSR--4-885630?logo=composer&logoColor=white)
[![MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![Tests](https://github.com/olivCode/form-guard/actions/workflows/ci.yml/badge.svg)](https://github.com/olivCode/form-guard/actions/workflows/ci.yml)

Protection progressive des formulaires PHP contre les abus, avec signaux JavaScript facultatifs.

**Bibliothèque PHP 1.0**, avec un [adaptateur Laravel 13 distinct](https://github.com/olivCode/form-guard-laravel). Consulter le [contrat de compatibilité](docs/compatibility.fr.md), le [guide d’installation](docs/installation.fr.md) et la [politique de sécurité](SECURITY.fr.md).

Form Guard associe des jetons liés à la session, des noms de champs aléatoires, un honeypot, un délai mesuré côté serveur, des limites de tentatives, des bannissements progressifs et des signaux navigateur facultatifs. Il n’envoie aucun e-mail et n’a aucune dépendance obligatoire à un framework, CAPTCHA ou service externe. Turnstile est un plugin explicitement facultatif.

## Prérequis

- PHP 8.3 ou supérieur dans la série PHP 8.
- Stockage local privé et inscriptible avec verrouillage fiable.
- Session sécurisée gérée par l’application.
- JavaScript facultatif. Ses signaux sont des indices, pas la preuve d’une présence humaine.
- Node 22 ou supérieur pour les tests navigateur uniquement ; aucune dépendance Node en production PHP.

## Installation dans votre application

Choisir [l’installation avec Composer ou en PHP pur sans Composer](docs/installation.fr.md). Les deux utilisent le même moteur et les mêmes protections. L’installation manuelle ne nécessite ni exécutable Composer ni dossier `vendor/` : copier la bibliothèque PHP et charger son autoloader fourni. Le guide détaille le stockage privé, le JavaScript facultatif, l’entretien et les mises à jour.

## Installation de développement

Cloner ce dépôt, puis exécuter :

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

Sans exécutable Composer local, les contrôles de base peuvent être lancés directement avec `php tools/check.php`, `php tests/run.php`, `php tests/storage.php`, `php tests/maintenance.php`, `php tests/http.php`, `php tests/concurrency.php`, `php tests/storage-concurrency.php` et `node tests/browser.mjs`. PHPStan et PHP CS Fixer nécessitent l’installation des dépendances de développement.

`olivcode/form-guard` est le nom Composer prévu, sans package publié à ce jour. Le développement reste dans `olivCode/form-guard`. `composer test:distribution` crée et contrôle un ZIP, puis l’installe dans un consommateur local vierge avec Packagist désactivé. Le dépôt public distinct sera préparé ultérieurement.

## Principe d’intégration

```php
use FormGuard\Guard;

require __DIR__ . '/vendor/autoload.php';

// Démarrer la session sécurisée de l’application avant toute sortie.
$_SESSION['contact_forms'] ??= [];
$guard = new Guard([
    'scope' => 'contact',
    'locale' => 'fr',
    // Répertoire absolu PRIVÉ, hors de la racine HTTP :
    // 'runtime_dir' => '/var/lib/my-app/form-guard',
]);

// Branche GET : générer le formulaire et afficher ses champs/jetons/signaux.
$form = $guard->issueForm($_SESSION['contact_forms'], ['name', 'email', 'message']);

// Branche POST : compter la tentative AVANT toute validation du contenu.
$guard->beginRequest(
    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    $_SERVER['HTTP_USER_AGENT'] ?? '',
    session_id(),
);
$verified = $guard->validateForm($_POST, $_SESSION['contact_forms']);

// Valider ensuite les champs métier ; conserver le verrou de session.
$guard->consumeForm($_SESSION['contact_forms'], $verified['id']);
// Effectuer maintenant l’action prévue par l’application.
```

GET et POST représentent deux branches distinctes. L’[exemple complet](examples/index.php) montre l’échappement HTML et le traitement des erreurs. En AJAX, renvoyer `publicForm()`, actualiser les champs et jetons, puis appeler `window.FormGuard.refresh(formElement)`. Voir [l’intégration](docs/integration.fr.md).

## Exemple exécutable

```sh
php -S 127.0.0.1:8000 -t examples
```

Ouvrir `http://127.0.0.1:8000` et choisir la langue. L’exemple fonctionne sans Composer et n’envoie aucun e-mail. Le conserver sur l’interface locale : il sert à l’intégration et ne constitue pas un service de contact de production.

## Politique par défaut

- 20 formulaires en attente par session, valides deux heures.
- Délai minimal de trois secondes, calculé sur un état conservé côté serveur.
- Cinq tentatives POST par IP + navigateur + session sur dix minutes.
- Trente tentatives POST par IP sur dix minutes, toutes sessions confondues.
- Bannissement client progressif : 1, 2, 4, 8, 16, 32, puis au maximum 60 minutes.
- Limite IP partagée à fenêtre glissante ; bannissement progressif global uniquement sur choix explicite.
- Chaque POST compte, y compris un jeton invalide ou une erreur de saisie.
- Consommation du jeton avant l’action pour empêcher son rejeu.

Ces réglages visent les formulaires de contact peu sollicités. Les réseaux partagés et les erreurs répétées peuvent atteindre ces limites : adapter la [configuration](docs/configuration.fr.md) au trafic réel.

## Périmètre et limites

Form Guard protège l’application ; il ne filtre pas la boîte e-mail et ne garantit pas zéro spam. Il ne remplace ni la validation, ni la sécurité des sessions et de l’hébergement, ni une protection DDoS. Les signaux JavaScript et le mode natif peuvent être imités ; des robots patients ou distribués peuvent passer les contrôles.

Le backend fichiers exige un stockage local privé et des verrous fiables partagés entre les workers. Les écritures sont atomiques et la récupération explicite ; ce n’est pas un limiteur distribué. Les identifiants IP/navigateur/session sont représentés par des HMAC : ils sont pseudonymes, pas anonymes. La purge se fait lors des requêtes ; une commande planifiée permet aussi l’entretien sans trafic.

## Documentation

- [Sécurité HTTP et Turnstile facultatif](docs/http-security.fr.md)
- [API 1.0 et compatibilité du cycle de vie](docs/compatibility.fr.md)
- [Plugins et dépendances](docs/plugins.fr.md)
- [Choisir les scénarios et associations](docs/scenarios.fr.md)
- [Profils et configuration regroupée](docs/profiles.fr.md)
- [Règles supplémentaires](docs/rules.fr.md)
- [Configuration](docs/configuration.fr.md) et [intégration](docs/integration.fr.md)
- [Traductions et codes de rejet](docs/i18n.fr.md)
- [Stockage et entretien](docs/storage.fr.md)
- [Qualité et tests](docs/testing.fr.md)
- [Recette accessibilité et regard extérieur](docs/accessibility-testing.fr.md)
- [Sécurité](SECURITY.fr.md) et [contribution](CONTRIBUTING.fr.md)

Licence MIT. Copyright (c) 2026 Olivier Gaillot. Le texte anglais de [LICENSE](LICENSE) fait référence.
