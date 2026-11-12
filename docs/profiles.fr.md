[English](profiles.md) · [Français](profiles.fr.md)

# Profils et configuration regroupée

```php
$configuration = FormGuard\Configuration::fromArray([
    'profile' => 'standard',
    'scope' => 'contact',
    'forms' => ['min_seconds' => 3],
    'limits' => ['client' => 7],
    'browser' => ['enabled' => true],
    'storage' => ['directory' => '/private/my-app/form-guard'],
    'language' => ['locale' => 'fr', 'fallback' => 'en'],
    'rules' => ['budget_ms' => 100],
]);
$guard = new FormGuard\Guard($configuration);
```

| Profil | Tentatives/client/10 min | Tentatives/IP/10 min | Délai minimal |
| --- | --- | --- | --- |
| `tolerant` — Tolérant | 10 | 60 | 3 s |
| `standard` — Standard | 5 | 30 | 3 s |
| `reinforced` — Renforcé | 3 | 20 | 5 s |

Standard conserve les anciens réglages. Tous les profils gardent jetons de session/périmètre, CSRF, honeypot, intégrité des signaux et consommation avant l’action. Tous acceptent le mode natif sans JS. La progression des bannissements clients et la fenêtre IP partagée restent identiques ; aucun profil n’active silencieusement de longs bannissements IP globaux. Ce sont des politiques de départ pour des formulaires de contact, sans garantie contre tout spam.

Priorité : défauts → profil → personnalisations explicites. `describe()` indique profil, personnalisation de la politique et origine des valeurs ; il masque périmètre, chemins et textes. `options()` contient les valeurs complètes destinées à l’exécution et ne doit pas être journalisé.

## Groupes

- `forms` : `timing`, `min_seconds`, `ttl`, `max_pending`.
- `limits` : `enabled`, `window`, `client`, `ip`, `ban_initial`, `ban_max`, `strike_decay`, `ip_ban`.
- `browser` : `enabled`, `minimum_score`, `minimum_interactions`, `max_interactions_per_second`.
- `storage` : `directory`, `max_entries`, `max_bytes`, `clock_tolerance`.
- `language` : `locale`, `fallback`, `messages`.
- `rules` : `budget_ms` pour chaque [règle supplémentaire](rules.fr.md).

Noms inconnus, types/durées invalides et mélange des clés à plat/regroupées sont refusés. Le profil accepte aussi l’enum `ProtectionLevel`. Les anciennes options à plat restent utilisables via le même résolveur ; ne pas mélanger les deux syntaxes dans un tableau. `scope` reste une clé commune au premier niveau.

Les protections optionnelles de délai, limitation et comportement disposent de commutateurs séparés. Les désactiver personnalise explicitement la politique. Désactiver le comportement conserve l’intégrité des signaux/pièges et n’impose pas JS. Désactiver le délai conserve expiration et anti-rejeu. Désactiver la limitation arrête les compteurs ; le diagnostic indique cette perte de protection. Un groupe désactivé reste validé pour éviter une erreur latente lors de sa réactivation.

## Commandes

```sh
php bin/form-guard init --profile=standard --locale=fr --scope=contact --output=contact.config.php
php bin/form-guard init --interactive --locale=fr
php bin/form-guard config:check --config=contact.config.php --locale=fr
php bin/form-guard config:show --config=contact.config.php
php bin/form-guard prune --config=contact.config.php
```

L’initialisation crée un nouveau PHP commenté sans écraser un chemin existant. Définir un `storage.directory` privé persistant avant l’entretien. Guard et l’entretien doivent utiliser **le même fichier de confiance**, pour respecter fenêtres et rétention personnalisées. Une portée ou un répertoire explicitement contradictoires sont refusés.

Ces fichiers exécutent du PHP de confiance : les garder sous contrôle de l’exploitant et hors racine HTTP. Ne jamais charger un upload ou un chemin choisi par un visiteur. Le diagnostic n’initialise pas le stockage et n’affiche ni chemins, ni messages, ni sorties echo du fichier. Les commandes de configuration/initialisation sont EN/FR ; les codes historiques de maintenance restent stables.

```php
$config = require '/private/my-app/contact.config.php';
$guard = new FormGuard\Guard($config);
```

Conserver le contrat d’intégration session/POST existant. La suite pilote installe le vrai ZIP et vérifie les trois politiques avec saisies synthétiques honnêtes, rafales, collègues d’une IP partagée, rejeu et métadonnées AJAX. Ce n’est pas une observation de vrais clients. Les sites existants ne sont pas modifiés.

Le diagnostic signale aussi les politiques actives mais neutralisées ou redondantes : délai nul (`timing_has_no_delay`), score toujours accepté (`behavior_score_always_passes`), plafond IP inférieur au plafond client (`shared_ip_ceiling_below_client`), limites locales désactivées (`local_limits_disabled`) et âge minimal égal à l’expiration (`minimum_age_equals_expiry`). Ces avertissements expliquent une combinaison autorisée ; les incompatibilités de dépendances sont des erreurs, pas des avertissements.
