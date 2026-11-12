[English](accessibility-testing.md) · [Français](accessibility-testing.fr.md)

# Recette humaine : accessibilité et intégration extérieure

Les tests automatiques évitent les régressions. Ils ne prouvent pas une conformité WCAG/RGAA et ne remplacent ni un utilisateur de lecteur d’écran ni un développeur indépendant. Les validations ci-dessous restent en attente jusqu’à consignation de résultats réels.

## Technologies d’assistance

Couples proposés : NVDA + Firefox sous Windows ; VoiceOver + Safari sous macOS/iOS. Consigner versions, système, langue, commit, testeur, date et état de JavaScript. Utiliser des adresses synthétiques `example.test`, jamais un formulaire de contact de production.

1. Démarrer [l’exemple](../README.fr.md#exemple-exécutable), passer de EN à FR et parcourir titres/régions. Vérifier langue, titre et région principale.
2. Naviguer par Tab et Maj+Tab. Le champ e-mail doit avoir son label visible, ses instructions et l’état obligatoire. Honeypot et signaux doivent être absents du parcours de focus et de l’arbre d’accessibilité.
3. Soumettre une adresse invalide, puis une valide après trois secondes. Vérifier l’identification de l’erreur, le titre de résultat, le message lisible et l’accès au nouveau formulaire.
4. Désactiver JS, recharger et refaire l’envoi au clavier. Aucun mouvement de souris ne doit être nécessaire.
5. À 200 % et 400 % de zoom, vérifier repli de mise en page et visibilité du focus. Refaire avec texte système agrandi et couleurs forcées si disponibles.
6. Utiliser le vrai remplissage automatique du navigateur et un gestionnaire de mots de passe : suggestion e-mail, honeypot non rempli, soumission réussie. Le test programmatique de préremplissage ne valide pas cette étape.
7. Dans une application AJAX, vérifier annonce unique du message, focus cohérent et formulaire renouvelé utilisable. Le module ne gère pas lui-même la région live ou le focus de l’application.
8. Tester expiration, jeton consommé et session perdue ; vérifier la récupération compréhensible. Après des erreurs honnêtes répétées, vérifier le message et délai de limitation.

Pour chaque étape : `réussi`, `échec` ou `non testé`, comportement attendu/observé et reproduction synthétique. Un succès axe ne vaut pas recette avec lecteur d’écran. Aucun audit manuel avec technologie d’assistance n’est revendiqué.

## Développeur extérieur

Un développeur qui n’a pas réalisé la bibliothèque doit recevoir README et guides, sans présentation orale préalable. Dans un projet PHP vierge, lui demander de :

1. Installer le package publié ou un checkout local et exécuter l’exemple.
2. Intégrer un formulaire natif labellisé, puis AJAX, avec verrouillage de session et consommation corrects.
3. Choisir un périmètre/runtime privé stables et les messages français.
4. Diagnostiquer un jeton invalide, un 429 et un stockage indisponible à l’aide des codes documentés.
5. Planifier l’entretien, expliquer le mode sans JS et les validations restant à l’application.
6. Relire migration/récupération et reformuler les limites de protection.

Collecter temps d’installation, passages ambigus, informations manquantes, défauts, commit et plateforme. Corriger les blocages et relancer les tests. Un consommateur Composer automatisé ne remplace pas cette recette extérieure.

## Fiche de résultat

```text
Commit / date / personne :
Système / navigateur / technologie d’assistance / versions :
Langue / état JS :
Étape :
Résultat (réussi / échec / non testé) :
Attendu / observé :
Reproduction synthétique / suite :
```
