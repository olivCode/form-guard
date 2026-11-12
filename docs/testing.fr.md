[English](testing.md) · [Français](testing.fr.md)

# Qualité et tests

## Outils reproductibles

Le dépôt versionne `composer.lock` et `package-lock.json` pour ses outils de développement. Ils n’imposent pas de dépendances aux applications consommatrices. PHPStan, PHP CS Fixer, Playwright et axe sont réservés au développement. Node 22 ou supérieur est nécessaire aux tests navigateur, sans dépendance Node pour l’exécution PHP.

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

`composer check` contrôle la syntaxe PHP, PHPStan niveau 6 sur tout `src/` sans baseline, les conventions PSR-12 des sources/exemples/tests/outils/CLI et les documents bilingues. `composer cs:fix` applique le formatage ; `composer analyse` et `composer cs:check` s’exécutent séparément.

Le lanceur navigateur utilise `php` dans le PATH. Définir `PHP_BINARY` si nécessaire ; aucun chemin MAMP n’est versionné. `FORM_GUARD_TEST_PORT` change le port local de test, 9473 par défaut. Chaque exécution crée son runtime isolé et n’envoie aucun e-mail. Un serveur existant n’est pas réutilisé.

## Vérification des installations

`composer test:distribution` contrôle le ZIP de distribution et l’installe dans un consommateur Composer vierge. Il lance également `tests/manual-install.php` : seuls les fichiers PHP, assets et licence documentés sont copiés du ZIP vers des applications vierges. Les bootstraps manuels exacts du [guide d’installation](installation.fr.md), en français et en anglais, sont exécutés dans des processus PHP distincts avec `-n`, sans `vendor/`, autoloader Composer ou inclusion des sources du dépôt. Le test vérifie une soumission native, le refus d’un rejeu, le stockage privé persistant, la copie publique du JavaScript et la commande d’entretien autonome.

## Matrice navigateur

Les mêmes scénarios Playwright tournent sur Chromium, Firefox et WebKit. Chaque job CI installe son moteur et démarre un serveur neuf. Les réglages de protection par défaut et les délais réels restent actifs.

Scénarios : langues/labels EN/FR, clavier, avec et sans JS, validation native, e-mail prérempli sans événement, erreur AJAX et renouvellement du jeton, historique/rejeu, session perdue, affichage 320 pixels et texte agrandi. Axe analyse automatiquement les exemples avec JS. Axe lui-même exige JavaScript ; le fonctionnement sans JS est vérifié séparément.

Le préremplissage programmatique simule l’absence d’événements input/change, sans piloter les suggestions natives ou un gestionnaire de mots de passe. Les moteurs testés ne couvrent pas toutes les versions Safari et systèmes mobiles. Voir [la recette manuelle](accessibility-testing.fr.md).

La CI lance six jobs PHP (Linux/Windows × 8.3/8.4/8.5) et trois jobs navigateur Windows. Les dépendances de plateforme y sont fournies avec les navigateurs ; les miroirs de paquets Ubuntu ont rencontré une erreur DNS pendant la mise en place initiale. Cette matrice Windows ne valide pas le comportement des navigateurs sous Linux. Rapports synthétiques et traces d’échec sont conservés sept jours. Ne jamais y joindre de vrais messages visiteurs.

## Charge bornée

`composer test:load` crée un runtime privé, lance huit workers PHP et traite 256 requêtes de sessions synthétiques distinctes sur une IP commune. Le plafond IP est explicitement relevé pour isoler la correction des transactions ; ce test ne mesure pas l’acceptation de la politique par défaut. Il exige 256 compteurs client plus un compteur IP, sans perte de tentative admise.

`build/load-results.json` indique plateforme/PHP, durée totale, latences p50/p95, mémoire maximale par worker et taille d’état. Ces durées sont diagnostiques : aucune promesse de débit ni seuil CI de performance. Le résultat inclut le système de fichiers et les verrous locaux ; ce n’est pas un test de capacité HTTP, DDoS, distribué ou de longue durée. Les tests de concurrence existants vérifient séparément les limites par défaut.

## Défaillances

`composer test:failure` arrête un worker après écriture/synchronisation mais avant renommage de l’état. Il vérifie la conservation du dernier état validé, la libération du verrou et la réussite de la requête suivante. Les temporaires interrompus sont absents ou nettoyables. Il vérifie aussi qu’une saturation refuse l’action sans évincer les clients actifs ou modifier les compteurs conservés.

La suite de stockage couvre aussi exceptions d’écriture, états malformés/falsifiés/trop volumineux, fichiers/clés manquants, changements de schéma/périmètre, recul d’horloge, rollback et migration avec bannissements actifs. Permissions POSIX et liens symboliques sont conditionnels et signalent leurs sauts.

Cela ne simule ni coupure électrique physique, ni panne du cache disque, ni indisponibilité de tout l’hébergement, ni verrous réseau peu fiables. Conserver une sauvegarde cohérente clé/état et vérifier la récupération chez l’hébergeur. Arrêter les workers avant migration/reset.
