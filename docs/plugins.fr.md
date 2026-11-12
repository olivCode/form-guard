[English](plugins.md) · [Français](plugins.fr.md)

# Plugins de protection

Les plugins isolent les politiques optionnelles pour simplifier leur maintenance, sans remplacer l’intégrité du formulaire/de la session. Guard valide la composition à sa construction, avant traitement. Les options à plat/regroupées et `rules:` restent compatibles.

## Composants fournis et ordre

| Identifiant | Phase | Commutateur | Rôle |
| --- | --- | --- | --- |
| `request_limits` | Requête | `limits.enabled` | Compte chaque POST une fois et valide ses compteurs avant de renvoyer le refus |
| `timing` | Formulaire | `forms.timing` | Vérifie l’âge minimal conservé côté serveur |
| `browser_behavior` | Formulaire | `browser.enabled` | Score facultatif en mode JS déclaré ; le mode natif passe |

`beginRequest()` exécute la phase requête. Le limiteur fourni passe d’abord ; le code personnalisé s’exécute après libération de son verrou. `validateForm()` vérifie session/périmètre, expiration, CSRF, honeypot et intégrité des signaux avant les plugins formulaire. Consommer avant l’action métier, sous verrou de session. Activer un plugin ne remplace pas ces appels de l’application.

Ordre : phase requête avant formulaire, dépendances dans chaque phase, puis ordre d’insertion des pairs disponibles. Sans dépendance modifiant l’ordre, les composants fournis précèdent les ajouts. Refus/exception arrête la suite. Null ne valide que le composant courant et n’annule jamais un refus antérieur.

## Créer un plugin formulaire

```php
use FormGuard\Plugin\{FormContext, FormPluginInterface, Phase, Refusal};
use FormGuard\RejectionReason;

final class ContentLinks implements FormPluginInterface
{
    public function id(): string { return 'content_links'; }
    public function phase(): Phase { return Phase::Form; }
    public function requires(): array { return ['request_limits']; }
    public function conflicts(): array { return []; }
    public function evaluate(FormContext $context): ?Refusal
    {
        $message = $context->fields['message'] ?? '';
        if (is_string($message) && substr_count($message, 'https://') > 2) {
            return new Refusal(RejectionReason::CustomRule, ['limit' => 2], code: 'too_many');
        }
        return null;
    }
}
$guard = new FormGuard\Guard(['profile' => 'standard'], plugins: [new ContentLinks()]);
```

À utiliser **uniquement si une limite métier de deux liens est pertinente** : ce n’est pas un détecteur général d’URL/spam. La dépendance aux limites locales conserve un budget de tentatives avant d’examiner le contenu. La désactiver invalide la composition au lieu de l’affaiblir silencieusement. Traduire `rule.content_links.too_many` comme les règles ; sinon le message est générique. L’application valide toujours ses champs métier.

Pour un contrôle de requête personnalisé : `RequestPluginInterface`, `Phase::Request`, `RequestContext`. IP vérifiée, navigateur et session y sont sensibles et ne doivent pas être journalisés. Une protection requête ne dépend que d’autres protections requête. Un plugin formulaire peut dépendre d’un composant requête ou formulaire activé. Les refus doivent correspondre à la phase ; un résultat incohérent devient une panne technique.

## Associations cohérentes

| Association | Recommandation / raison |
| --- | --- |
| Standard et les trois composants fournis | Point de départ public ; aucun ajout nécessaire pour accepter sans JS/clavier/préremplissage |
| Contrainte de contenu dépendant de `request_limits` | Pertinent si l’examen doit conserver un budget de tentatives ; dépendance explicite |
| Deux fournisseurs exclusifs d’une même politique métier | Déclarer l’incompatibilité dans au moins un manifeste ; refuser leur activation simultanée |
| Vérification devant suivre un autre plugin | Déclarer `requires`, sans deviner l’ordre de configuration. Le contexte étant immuable, la normalisation se fait hors pipeline ou se formule comme validation, jamais comme mutation cachée |
| Plugin requête dépendant de la validation formulaire | Refusé : le traitement de requête précède les données fiables du formulaire |
| Plugin ajouté nommé `timing`, `browser_behavior` ou `request_limits` | Refusé même si le composant fourni est désactivé ; utiliser ses groupes de réglages |
| Dépendance cyclique, sur soi-même, absente ou désactivée | Refus avant toute requête, sans activation automatique |

Le guide [scénarios](scenarios.fr.md) détaille choix, compromis de faux positifs et vérifications. `Configuration::describe()` indique les commutateurs fournis ; `$guard->describePlugins()` donne l’ordre réellement validé, les identifiants, phases, dépendances et incompatibilités, sans donnée visiteur ou réglage privé. Le diagnostic ne peut pas connaître des plugins non enregistrés par l’application.

## Isolation, pannes et compatibilité

Les manifestes sont figés une fois. Identifiants et relations : lettres minuscules/chiffres/underscore, première lettre alphabétique, maximum 32 caractères, sans doublon. Listes, contrats de phase et cycles sont vérifiés. Maximum : 32 plugins ajoutés, 32 règles historiques et 64 composants au total, fournis inclus.

Le contexte formulaire est en lecture seule et détaché récursivement des références d’entrée, limité à 16 niveaux et 10 000 valeurs, avec uniquement des scalaires/tableaux métier. Il exclut jetons, honeypot, IP et session. Les appels récursifs aux méthodes de Guard qui changent l’état sont refusés. Plugins/configuration restent du PHP de confiance ; ce n’est pas une sandbox contre un code malveillant accédant directement aux fichiers ou variables globales.

Exception, métadonnées invalides, résultat incompatible avec sa phase ou budget dépassé produisent `PluginExecutionException`, compatible avec le gestionnaire `RuleExecutionException`. Les erreurs du stockage fourni restent des `StorageException`. L’affichage visiteur reste générique. Les paramètres sont des informations de présentation bornées, sans contenu visiteur. Un refus de limitation exige délai/périmètre cohérents ; un refus personnalisé exige code et paramètres numériques.

Le budget personnalisé reprend `rules.budget_ms`, mesuré après retour synchrone : il ne préempte pas une entrée/sortie bloquante. Borner les services dans le code ajouté. Le stockage fourni n’est pas soumis à ce minuteur de code utilisateur. Aucun service distant/CAPTCHA n’est découvert ou activé implicitement.

Les règles passent par `RuleAdapter`, qui conserve `rule_failed`/`rule_timed_out`, traductions et champs de refus. Leurs identifiants partagent désormais les noms réservés des plugins. L’identité de requête est vérifiée même avec limitation désactivée. Les seuils d’interaction supérieurs à 99 999 sont refusés. L’intégrité des signaux précède désormais le délai : pour plusieurs défauts simultanés, le premier motif peut changer.
