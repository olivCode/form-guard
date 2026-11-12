[English](CONTRIBUTING.md) · [Français](CONTRIBUTING.fr.md)

# Contribuer

Les contributions sont les bienvenues : ouvrir une issue pour discuter d’un changement ou une pull request ciblée. Signaler les vulnérabilités par le canal confidentiel décrit dans SECURITY.

- Garder des changements ciblés et tester les comportements observables.
- Exécuter `composer check`, `composer test`, `composer test:concurrency`, `composer test:browser`, `composer test:failure`, `composer test:load`, `npm run test:e2e` et `composer test:distribution`.
- Ne pas ajouter de runtimes, d’identifiants, de messages réels ou d’informations clients.
- Préserver la soumission au clavier et sans JavaScript ; les signaux navigateur restent facultatifs.
- Discuter des évolutions de l’API et des réglages par défaut avant de les considérer comme stables.
- Suivre la [procédure de sécurité](SECURITY.fr.md) pour les signalements sensibles.
- Maintenir les documents anglais et français ensemble, avec leur navigation de langue et leurs liens internes.

Les tests n’envoient aucun e-mail réel et ne dépendent d’aucun service externe à l’exécution. Les outils et navigateurs de développement sont téléchargés pendant l’installation. Projet sous licence MIT.
