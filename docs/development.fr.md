[English](development.md) · [Français](development.fr.md)

# Développement

Le moteur PHP et son adaptateur Laravel sont maintenus dans des dépôts distincts. Le moteur ne dépend pas de Laravel. Les contributions publiques sont vérifiées par la CI du dépôt.

```sh
composer install
composer check
composer test
composer test:concurrency
composer test:failure
composer test:load
composer test:distribution
npm ci --ignore-scripts
npx playwright install chromium firefox webkit
npm run test:e2e
```

PHP 8.3+ et Node 22+ sont nécessaires pour ces contrôles ; Node reste réservé au développement. Utiliser des données synthétiques. Ne jamais committer des messages clients, identifiants ou répertoires d’exécution. Préserver les fichiers de verrouillage des outils et mettre à jour les documents EN/FR ensemble.
