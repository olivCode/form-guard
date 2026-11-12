[English](http-security.md) · [Français](http-security.fr.md)

# Frontière HTTP et Turnstile facultatif

## Configuration recommandée

Conserver les jetons liés à la session, le délai et les limites de tentatives pour un formulaire de contact. Ajouter `FormGuard\Http\RequestGuard` avant la validation du formulaire : origines autorisées explicites, POST uniquement, corps limité à 16 Kio, nombre/profondeur/longueur des champs bornés et aucun fichier téléversé. `examples/index.php` contient le branchement complet. Son origine de confiance vaut `http://127.0.0.1:8000` ; définir `FORM_GUARD_EXAMPLE_ORIGIN` pour servir cet exemple local sur une autre origine. Ne jamais déduire les origines de confiance du Host ou d’un en-tête proxy fourni par le visiteur.

`RequestMetadata` reçoit du serveur/framework la méthode, le type de contenu, la taille connue du corps et les éventuels Origin, Referer et Sec-Fetch-Site. Une taille inconnue est refusée. L’origine est comparée exactement avec son protocole et son port, sans comparaison par suffixe. L’absence d’en-têtes est admise par défaut pour la compatibilité, mais impose toujours ensuite les vérifications de session/CSRF ; choisir `allowMissingOrigin: false` si tous les clients pris en charge fournissent une origine ou un référent. Une requête explicitement cross-site est refusée par défaut. Ces en-têtes ne prouvent pas une présence humaine.

La politique traite des données déjà analysées. Configurer aussi la limite du serveur web, `post_max_size`, `max_input_vars`, `max_input_nesting_level`, les téléversements et les timeouts PHP : une bibliothèque ne peut empêcher le travail déjà effectué par le serveur/parser. Un challenge exige seulement d’autoriser son jeton dans ces bornes, pas de débrider les requêtes. La politique HTTP accepte par défaut les formulaires URL-encodés et multipart sans fichiers. JSON exige une activation explicite et reste limité au modèle de champs chaînes/tableaux de cette politique. Ce n’est pas un validateur d’API généraliste.

## Challenge externe facultatif

`FormGuard\Plugin\TurnstilePlugin` est optionnel. Les profils par défaut ne contactent pas Cloudflare et fonctionnent sans JavaScript. Envisager Turnstile face à des abus persistants qui passent les protections locales : il introduit un tiers, JavaScript, des questions de confidentialité/accessibilité et une dépendance de disponibilité. Ne pas contourner silencieusement un échec du challenge. Prévoir si nécessaire un autre moyen de contact accessible.

Construire le plugin avec un secret serveur et un hostname/action attendus fixes, puis le passer dans l’argument `plugins` de `Guard`. Ajouter `challenge` aux champs logiques de `issueForm()`. L’attribut `data-response-field-name` du widget doit utiliser `$form['fields']['challenge']` et son `data-action` doit correspondre à l’action configurée. La clé de site publique est distincte ; ne jamais exposer le secret dans le HTML. Échapper chaque attribut dynamique. Réinitialiser le widget après une erreur ou un renouvellement, car les jetons expirent.

Le transport fourni contacte uniquement `https://challenges.cloudflare.com/turnstile/v0/siteverify`, vérifie TLS, refuse les redirections, borne la réponse à 8 Kio et utilise une échéance réseau de trois secondes avec un timeout initial de deux secondes. Il nécessite OpenSSL, une chaîne de confiance CA fonctionnelle et `allow_url_fopen`. Un transport personnalisé `TurnstileTransportInterface` doit conserver la vérification TLS, les bornes d’entrée/sortie et une destination fixe. Définir `rule_budget_ms` à 5000 pour ce plugin réseau ; les 100 ms par défaut visent les plugins locaux. Le budget du plugin est contrôlé après retour et ne peut interrompre arbitrairement du code PHP/réseau.

Le succès doit être le booléen `true`, avec hostname et action conformes. Un jeton absent/trop long ou une vérification négative produit `rule.turnstile.challenge_failed` ; une erreur réseau/format arrête le traitement comme panne technique. Ne pas journaliser jetons, secrets, contenus visiteurs ou réponses du fournisseur. Les tests locaux injectent des réponses et n’utilisent aucune clé de production.

La détection de réutilisation de Turnstile est à cohérence éventuelle : elle ne constitue pas un verrou atomique de l’application. Conserver la consommation des jetons Form Guard et le verrou de session. Voir [la validation serveur Cloudflare](https://developers.cloudflare.com/turnstile/get-started/server-side-validation/) et [les réglages du widget](https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/widget-configurations/).

## Bannissements à partir de la 1.0

La progression par défaut vaut 60, 120, 240, 480, 960, 1920, puis au maximum 3600 secondes. Les incidents s’effacent après 86400 secondes sans nouvel incident. Les essais bloqués ne prolongent pas un bannissement actif. Les IP partagées sont limitées sur leur fenêtre ; les bannissements IP longs restent optionnels. Les bannissements déjà stockés sont conservés lors de la mise à jour, sans réduction silencieuse. Adapter les seuils au trafic réel ; des robots patients ou distribués peuvent toujours passer.

Les messages HTTP acceptent la même syntaxe de locale que le moteur : les variantes régionales françaises se replient sur le français, les autres langues non fournies sur l’anglais. Le timeout et l’échéance du transport bornent les échanges, mais ne peuvent interrompre de force une résolution DNS système bloquante ; utiliser un transport à résolveur asynchrone et timeout total si une borne stricte de durée réseau est exigée.
