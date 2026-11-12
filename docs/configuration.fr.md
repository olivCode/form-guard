[English](configuration.md) · [Français](configuration.fr.md)

# Configuration

Passer les options explicitement à `new FormGuard\Guard([...])`. La bibliothèque ne lit pas les variables d’environnement, ne démarre pas les sessions, n’émet pas d’en-têtes HTTP et n’envoie aucun message.

| Option | Défaut | Rôle |
| --- | --- | --- |
| `scope` | Chemin d’installation du package | Périmètre stable à définir par application |
| `runtime_dir` | Vide | Chemin privé explicite ; sinon répertoire temporaire dérivé du périmètre |
| `window` | 600 | Fenêtre glissante en secondes |
| `client_limit` | 5 | Tentatives par IP + navigateur + session |
| `ip_limit` | 30 | Tentatives par IP, tous clients confondus |
| `base_ban` | 60 | Premier bannissement, en secondes |
| `max_ban` | 3600 | Durée maximale d’un bannissement |
| `strike_decay` | 86400 | Période calme avant remise à zéro de la progression |
| `min_seconds` | 3 | Âge minimal du formulaire, mesuré côté serveur |
| `form_ttl` | 7200 | Âge maximal du formulaire |
| `max_forms` | 20 | Formulaires en attente par session |
| `max_entries` | 10000 | Capacité des compteurs avant refus technique |
| `behavior_enabled` | true | Contrôle du score si le navigateur annonce le mode JavaScript |
| `minimum_behavior_score` | 4 | Score minimal accepté, sur 5 |
| `min_interactions` | 2 | Nombre d’interactions donnant un point supplémentaire |
| `max_interactions_per_second` | 20 | Seuil de ratio pour un point supplémentaire |
| `locale` | en | Langue explicite des refus |
| `fallback_locale` | en | Langue de repli |
| `messages` | [] | Langue => code de refus => texte personnalisé |
| `clock_tolerance` | 5 | Petit recul d’horloge toléré, en secondes |
| `ip_ban` | false | Bannissement IP progressif explicite, à la place de la fenêtre glissante |
| `max_state_bytes` | 8388608 | Taille JSON maximale lue/enregistrée |

Les options inconnues, types incorrects, périmètres vides, entiers hors limites et durées incohérentes sont refusés. Désactiver le score comportemental ne désactive pas CSRF, délai, honeypot, piège JS, limites ou protection contre le rejeu.

Changer le périmètre change le répertoire privé par défaut. Changer de répertoire crée d’autres compteurs, sauf migration volontaire. Un répertoire associé à une autre clé ou un autre périmètre est refusé. Voir [stockage](storage.fr.md). Ne pas partager clés et états entre des sites sans lien. Les workers d’un même endpoint doivent utiliser le même stockage et des verrous fiables.

L’état contient des identifiants pseudonymes HMAC, des horodatages et des compteurs ; jamais le contenu des formulaires ni les événements bruts du navigateur. La clé privée ne doit pas être publiée. Les entrées expirées sont supprimées au passage d’une requête ou avec la commande de purge planifiée.

Un bannissement actif n’est pas prolongé par les demandes déjà bloquées. Une autre limite, notamment celle de l’IP partagée, peut être atteinte indépendamment. Prévoir les réseaux partagés et les erreurs honnêtes de saisie.

## Configuration simplifiée disponible

Les profils et groupes sont disponibles : voir [profils](profiles.fr.md). Nouvelles options à plat : `timing_enabled` (true), `rate_limit_enabled` (true), `rule_budget_ms` (100). Désactiver le score conserve l’intégrité des signaux. Voir [règles](rules.fr.md) pour les extensions.
