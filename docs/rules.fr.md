[English](rules.md) · [Français](rules.fr.md)

# Règles supplémentaires

Implémenter `FormGuard\Rule\RuleInterface` : `id(): string` et `evaluate(RuleContext $context): ?RuleFailure`. Transmettre les instances ordonnées au quatrième argument facultatif/nommé `rules` de Guard. Identifiants uniques en minuscules/chiffres/underscore, commençant par une lettre, maximum 32 caractères et 32 règles.

```php
$rule = new class implements FormGuard\Rule\RuleInterface {
    public function id(): string { return 'links'; }
    public function evaluate(FormGuard\Rule\RuleContext $context): ?FormGuard\Rule\RuleFailure {
        $message = $context->fields['message'] ?? '';
        if (is_string($message) && substr_count($message, 'https://') > 2) {
            return new FormGuard\Rule\RuleFailure('too_many', ['limit' => 2]);
        }
        return null;
    }
};
$guard = new FormGuard\Guard(['locale' => 'fr'], rules: [$rule]);
```

Cette règle illustre le contrat, sans constituer un détecteur complet de liens/spam. Le contexte contient les valeurs métier non fiables sous leurs noms logiques, l’âge du formulaire et le mode JS. Il n’expose ni jeton, ni honeypot, ni IP ou session. L’application reste responsable de valider les champs et d’échapper l’affichage.

Le socle est vérifié d’abord ; un refus arrête les règles suivantes. Les règles suivent l’ordre fourni, hors des transactions de stockage. Null ne valide que la règle courante et n’annule aucun refus antérieur. Ne pas envoyer de mail, consommer de jeton ou réaliser l’action métier dans une règle. Compter chaque POST une seule fois avec `beginRequest()` avant validation.

Un refus produit HTTP 422, `RejectionReason::CustomRule`, `ruleId` et `ruleCode`. Les codes suivent la même syntaxe d’identifiant. Huit paramètres entiers nommés au maximum, bornés à ±10 000 000, servent à la présentation ; ne jamais y placer d’identifiant visiteur ou de contenu. Traduire `rule.<id>.<code>` dans les catalogues/adaptateurs ; sinon un message EN/FR générique est utilisé.

Exception et dépassement du budget produisent une `RuleExecutionException` (`rule_failed`/`rule_timed_out`), jamais une réussite. Traiter cette panne technique à la frontière de l’application sans réaliser l’action. Budget par défaut : 100 ms par règle, configurable par `rules.budget_ms` (1–10 000). Le temps est vérifié **après le retour synchrone** : PHP ne préempte pas une règle bloquante. Borner ses entrées/sorties ; aucun service distant/CAPTCHA n’est fourni ou activé implicitement.

La bibliothèque fournit les [plugins](plugins.fr.md) avec dépendances/incompatibilités et phases explicites, en conservant les règles via un adaptateur. Le code de configuration/extension reste du PHP de confiance, sans sandbox.
