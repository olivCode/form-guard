[English](integration.md) · [Français](integration.fr.md)

# Intégration

Commencer par le [guide d’installation](installation.fr.md) : avec Composer ou par copie manuelle en PHP pur, avec la même API dans les deux cas.

## Responsabilités de l’application

Définir un `scope` stable et un ensemble de formulaires conservé côté serveur. Démarrer une session sécurisée avant toute sortie : chemin de cookie adapté, HttpOnly, SameSite et HTTPS. Ne pas mettre les réponses contenant des jetons en cache. Éviter la compression lorsqu’une page associe des secrets et des contenus utilisateur réfléchis. Conserver le verrou de session de la validation jusqu’à la consommation du jeton.

Pour chaque POST, appeler `beginRequest()` exactement une fois avant de rejeter un jeton, une requête trop grosse ou un champ métier. Limiter aussi la taille des requêtes à la frontière du serveur : PHP peut avoir déjà analysé le corps avant l’application. Ne pas compter les GET ou les assets.

L’application valide les adresses e-mail, longueurs, encodages, choix et règles métier. Elle échappe l’affichage, fixe le destinataire et décide de l’action. Form Guard n’a aucune dépendance à un outil d’envoi de mails.

## Balisage

Utiliser `method="post"` et `data-antispam` sur le formulaire, puis afficher :

1. Les champs cachés `form_id` et `csrf_token` renvoyés par `issueForm()`.
2. Le résultat de `renderSignals($form)`.
3. Le honeypot vide `$form['fields']['honeypot']`, caché, exclu du focus et des technologies d’assistance.
4. Les champs visibles sous les noms `$form['fields'][...]`, avec leurs labels et attributs autocomplete adaptés.

Les noms `as_js`, `as_trap` et `as_interactions` sont réservés. Échapper les valeurs HTML dynamiques avec `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.

Copier `assets/form-guard.js` vers un emplacement public ; ne pas exposer `vendor/` pour servir ce fichier. Charger le script avec `defer` avant le gestionnaire AJAX. Si le script est indisponible, le mode natif généré côté serveur reste utilisable.

## Erreurs

`FormGuard\Rejection` étend `DomainException`. Son code propose le statut HTTP : 403 pour les jetons/formulaires invalides ou expirés, 422 pour les signaux/délais refusés, 429 pour une limite ou un bannissement. Transmettre `retryAfter` dans `Retry-After` pour un 429.

L’exception fournit un enum `reason`, des `parameters` sûrs et un message traduit. Tester le code de refus plutôt que le texte ; voir [i18n](i18n.fr.md). Les erreurs de stockage sont techniques : afficher une erreur générique et ne pas traiter/envoyer la demande. Ne pas exposer les détails de l’exception au visiteur.

Après un POST HTML, l’application peut faire une redirection 303 avec message en session. En JSON, fournir un résultat explicite et le statut approprié. La soumission ne prouve pas la livraison ; une panne réseau ne permet pas à Form Guard de garantir une livraison externe exactement une fois.

## Renouvellement AJAX

Après consommation, générer un nouveau formulaire et renvoyer `publicForm()` en JSON. Mettre à jour `form_id`, `csrf_token`, les noms aléatoires et `as_trap` depuis `js_trap`, puis appeler :

```js
window.FormGuard?.refresh(formElement);
```

Cela remet à zéro les signaux et le compteur d’activité du helper. L’application gère les champs métier, les messages accessibles et le focus. Le nouveau formulaire garde son délai minimal côté serveur.

## API actuelle

- `new Guard(array|Configuration $options = [], ?StoreInterface $store = null, TranslatorInterface|callable|null $translator = null, array $rules = [], array $plugins = [])`
- `issueForm(array &$forms, array $fieldNames, ?int $now = null): array`
- `publicForm(array $form): array`
- `renderSignals(array $form): string`
- `beginRequest(string $ip, string $userAgent, string $sessionId, ?int $now = null): void`
- `validateForm(array $post, array $forms, ?int $now = null): array`
- `consumeForm(array &$forms, string $id): void`
- `runtimeDir(): string` (backend fichiers uniquement)
- `prune(?int $now = null): int`

Les horodatages facultatifs servent aux tests déterministes. En production, les omettre et utiliser l’horloge serveur. Les engagements de compatibilité 1.x figurent dans le [contrat de compatibilité](compatibility.fr.md). Voir les [profils](profiles.fr.md), [règles](rules.fr.md), [plugins](plugins.fr.md) et [scénarios recommandés](scenarios.fr.md).
