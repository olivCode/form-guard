[English](scenarios.md) · [Français](scenarios.fr.md)

# Choisir une politique et associer les protections

Démarrer avec Standard, protections optionnelles actives, `ip_ban: false` et le même fichier de confiance pour HTTP et entretien. Tester les erreurs honnêtes avant de régler les seuils ; si l’application produit des statistiques, observer uniquement des refus agrégés, jamais messages, jetons, sessions ou IP dans ce diagnostic. Le profil règle tentatives/délais, sans promettre de reconnaître tout robot.

| Scénario | Association recommandée | Pourquoi | Contrepartie à vérifier |
| --- | --- | --- | --- |
| Contact d’entreprise courant | Standard + délai + limitation + comportement JS ; socle toujours actif | Budget adapté à un petit formulaire et délai de trois secondes | Les corrections répétées comptent ; rendre le 429 compréhensible |
| Demande réutilisée fréquemment | Tolérant, mêmes composants | Plus de tentatives sans supprimer les contrôles | Davantage d’abus admis ; valider les champs et le volume métier |
| Formulaire subissant des rafales | Renforcé + mêmes composants ; règle de contenu pertinente si justifiée | Plafonds réduits et délai de cinq secondes | Plus de refus légitimes/IP partagées possibles ; tester le public concerné |
| Salariés partageant une IP publique | Standard ou Tolérant, fenêtre IP glissante, `ip_ban: false` | Conserve la limite client sans long bannissement collectif | Le plafond global s’applique toujours ; l’adapter au nombre d’utilisateurs |
| Clavier, lecteur d’écran, navigateur sans JS | Standard et composants habituels | Le mode natif est accepté, sans mouvement/JS requis | Ne pas désactiver le comportement au seul motif que JS peut être absent |
| Remplissage automatique rapide d’un formulaire authentifié connu | Tester Standard d’abord ; désactiver uniquement le délai si l’envoi immédiat est nécessaire | Retire l’heuristique temporelle en conservant CSRF/honeypot/limites | Le robot rapide perd aussi cet obstacle ; tester les valeurs préremplies |
| Limiteur fiable déjà présent à la frontière de l’application | Socle/délai/comportement ; désactiver les limites locales seulement après vérification de l’équivalent | Évite quotas et double comptage contradictoires | Form Guard n’enregistre alors plus ses tentatives ; panne externe = action refusée |
| Contrainte métier de contenu connue | Profil + règle ciblée | Applique une contrainte explicable après vérification d’intégrité | Liens/mots-clés peuvent être légitimes ; ne pas généraliser l’exemple de règle |
| Formulaire long à remplir | Standard, ajuster `forms.ttl` sans changer le profil au hasard | Expiration et délai minimal répondent à deux besoins différents | TTL plus long = jetons valides plus longtemps ; borner les formulaires en attente |

## Exemple complet : demandes métier sur IP partagée

```php
$configuration = FormGuard\Configuration::fromArray([
    'profile' => 'tolerant',
    'scope' => 'business-request',
    'limits' => ['ip' => 90, 'ip_ban' => false],
    'storage' => ['directory' => '/private/app/request-guard'],
    'language' => ['locale' => 'fr'],
]);
$guard = new FormGuard\Guard($configuration);
```

Cela autorise dix tentatives par client et quatre-vingt-dix pour l’IP sur dix minutes, avec trois secondes de délai et les autres contrôles. Quatre-vingt-dix est un **choix métier illustratif**, pas un plafond universel. Appeler `beginRequest()` une fois au début du POST, valider formulaire/champs puis consommer avant l’action, sous verrou de session. Planifier `prune --config=...` avec ce même fichier.

## Sens précis des commutateurs

- Désactiver le comportement arrête uniquement le score ; les marqueurs et pièges JS/natifs restent vérifiés. Le mode sans JS n’exige jamais de désactiver ce composant.
- Désactiver le délai ne désactive ni expiration, ni jetons, ni anti-rejeu.
- Désactiver les limites arrête leurs compteurs, sans enlever le socle. Le diagnostic ne doit pas présenter cette combinaison comme un limiteur local complet.
- Une règle qui passe n’annule aucun refus du socle ou d’une règle antérieure. Panne ou réponse tardive = refus technique.
- Les valeurs d’un groupe désactivé restent validées pour garantir sa réactivation. Durées contradictoires et noms inconnus sont refusés, sans correction silencieuse.

## Prérequis d’intégration

Fournir une IP vérifiée : proxys de confiance configurés dans l’application, jamais une chaîne brute `X-Forwarded-For`. Conserver périmètre stable, stockage privé persistant et session sécurisée. Le fichier de configuration appartient à l’exploitant. En AJAX, remplacer tous les jetons/noms/pièges avant de réinitialiser le helper et respecter l’âge du nouveau formulaire. Un changement de politique ne doit pas effacer silencieusement état ou bannissements.

Le modèle session/jetons vise les formulaires navigateur. Pour un webhook/API machine avec signatures, utiliser une authentification et des limites dédiées plutôt que désactiver tous les contrôles du formulaire. Un profil n’en fait pas un protocole d’authentification.

## Vérification après personnalisation

Tester natif et JS, clavier/préremplissage, erreurs honnêtes, collègue sur même IP, jeton/honeypot invalides, consommation/rejeu, message 429, stockage indisponible et panne de règle ajoutée. Vérifier les valeurs/origines dans le diagnostic. Les suites fournies donnent des exemples synthétiques reproductibles, sans mesurer un taux réel de faux positifs.
