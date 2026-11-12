[English](installation.md) · [Français](installation.fr.md)

# Installation : Composer ou PHP pur

Les deux méthodes utilisent le même moteur PHP, la même configuration et les mêmes protections. Composer facilite l’autoload et la gestion des dépendances ; il n’est pas nécessaire à l’exécution d’une installation manuelle. PHP 8.3 ou supérieur dans la série PHP 8 est requis. Aucune des deux méthodes ne nécessite Node.js ou npm en production.

## Avec Composer

Installer le package depuis Packagist :

```sh
composer require olivcode/form-guard:^1.0
```

Si le dossier de l’application contient `vendor/`, `private/` et `public/`, placer ce bootstrap dans `private/form-guard.php` :

```php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

return new FormGuard\Guard([
    'scope' => 'contact',
    'locale' => 'fr',
    'runtime_dir' => dirname(__DIR__) . '/var/form-guard',
]);
```

Pour utiliser les signaux navigateur facultatifs, copier `vendor/olivcode/form-guard/assets/form-guard.js` vers `public/assets/form-guard.js`. Servir cette copie, sans exposer le dossier `vendor/`.

## Sans Composer : copier les fichiers PHP

Télécharger une archive de la révision ou de la release choisie. Extraire l’archive localement, puis copier au minimum ces éléments dans `lib/form-guard/` au sein de l’application :

- `src/`, avec tous ses sous-dossiers ;
- `bin/`, notamment `bootstrap.php` et `form-guard` ;
- `assets/`, si les signaux navigateur sont utilisés ;
- `LICENSE`, en conservant la notice MIT avec les copies redistribuées.

Aucun dossier `vendor/`, exécutable Composer, autoloader généré par Composer ou dépendance de développement n’est nécessaire. Garder la documentation avec le projet pour la configuration et l’entretien. Utiliser les fichiers complets d’une même révision : ne pas mélanger les classes de différentes versions.

Exemple d’arborescence, avec la racine HTTP du serveur configurée sur **`public/`** :

```text
my-app/
├── lib/form-guard/
│   ├── src/
│   ├── bin/
│   ├── assets/
│   └── LICENSE
├── private/form-guard.php
├── var/form-guard/          # Runtime privé persistant, créé par la bibliothèque
└── public/
    ├── index.php
    └── assets/form-guard.js # Copie publique facultative
```

Placer ce bootstrap dans `private/form-guard.php`. Le fichier fourni `bin/bootstrap.php` enregistre l’autoloader autonome de la bibliothèque :

```php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/form-guard/bin/bootstrap.php';

return new FormGuard\Guard([
    'scope' => 'contact',
    'locale' => 'fr',
    'runtime_dir' => dirname(__DIR__) . '/var/form-guard',
]);
```

Si JavaScript est utilisé, copier `lib/form-guard/assets/form-guard.js` vers `public/assets/form-guard.js`. Aucune compilation n’est nécessaire. Le charger dans le HTML :

```html
<script src="/assets/form-guard.js" defer></script>
```

Adapter l’URL si l’application est servie dans un sous-dossier. Le script est facultatif ; le mode natif sans JavaScript reste utilisable avec la configuration par défaut.

## Brancher le formulaire, quelle que soit l’installation

Dans `public/index.php`, charger l’instance configurée avec `$guard = require dirname(__DIR__) . '/private/form-guard.php';`. L’application doit également démarrer une session sécurisée avant toute sortie, générer et afficher les jetons, compter les tentatives POST, valider la soumission et consommer son jeton immédiatement avant l’action en conservant le verrou de session.

Charger la bibliothèque ne protège **pas** automatiquement un formulaire. Suivre le [guide d’intégration](integration.fr.md) et l’[exemple PHP complet](../examples/index.php) pour les branches GET/POST, les noms des champs, l’échappement HTML et le traitement des refus. L’exemple n’envoie aucun e-mail ; l’envoi et la validation des champs métier restent à la charge de l’application.

Garder `lib/`, `private/`, `vendor/` et `var/` hors de la racine publique. Le processus PHP doit pouvoir écrire dans le répertoire dédié au runtime, avec des permissions privées (répertoires 0700 et fichiers 0600 sous POSIX, ACL privées équivalentes sous Windows). Ne jamais utiliser 0777. Si l’hébergeur impose une racine HTTP fixe, placer ces répertoires à côté et adapter les chemins absolus. Des noms de fichiers difficiles à deviner ne protègent pas les clés et l’état.

## Entretien et mises à jour

Avec Composer, lancer la commande d’entretien depuis le dossier de l’application :

```sh
php vendor/bin/form-guard status --runtime-dir=/chemin/absolu/prive/my-app/var/form-guard --scope=contact
```

Avec l’installation manuelle :

```sh
php lib/form-guard/bin/form-guard status --runtime-dir=/chemin/absolu/prive/my-app/var/form-guard --scope=contact
```

Remplacer le chemin d’exemple par le **même** répertoire de runtime que celui de l’application. Voir [stockage et entretien](storage.fr.md) pour la purge planifiée et les configurations personnalisées.

Avant une mise à jour, lire le changelog et les instructions de migration, tester sur une copie de l’application, puis déployer une révision cohérente de la bibliothèque et actualiser la copie publique du JavaScript si elle est utilisée. Avec Composer, mettre le package à jour puis déployer depuis le lockfile de l’application ; en installation manuelle, remplacer ensemble les fichiers de la bibliothèque. Conserver le runtime persistant, sa clé et son état, la configuration de l’application et les sessions. Ne pas supprimer le runtime pour une simple mise à jour de code : cela réinitialiserait l’historique des protections. Une migration du stockage ou une remise à zéro documentée exige sa propre procédure.

`composer test:distribution` vérifie les deux installations dans des consommateurs isolés, dont une copie manuelle sans dossier `vendor/` ni autoloader Composer.
