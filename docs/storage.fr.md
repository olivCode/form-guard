[English](storage.md) · [Français](storage.fr.md)

# Stockage, horloges et entretien

## Contrat

Le second argument facultatif de `Guard` accepte un `StoreInterface`. `FileStore` est le backend par défaut. `InMemoryStore` sert aux tests et aux essais dans un seul processus : créer une nouvelle instance mémoire à chaque requête ne protège pas correctement contre les tentatives répétées.

`transaction(callable $operation): mixed` exécute exactement une fois le callback avec un `State` de travail sous accès exclusif. Un retour normal valide la transaction ; une exception l’annule. Les décisions de refus sont renvoyées, puis traduites et levées **après validation**, afin de conserver les compteurs. Les callbacks doivent être courts, sans réentrance ni action externe.

Un backend personnalisé doit conserver une clé stable, valider l’état, garantir ces transactions et partager son stockage entre workers. Aucun backend distribué n’est fourni ; les verrous du système de fichiers local doivent être fiables.

## Fichiers privés

Le runtime par défaut est un répertoire temporaire dédié dérivé du `scope`. Définir un périmètre stable et un `runtime_dir` privé persistant si les compteurs doivent survivre aux déplacements et purges système. Le chemin doit être absolu, local et hors de la racine HTTP.

Le stockage exige des fichiers réguliers et refuse les liens symboliques et fichiers sensibles ayant plusieurs liens physiques. Il contrôle propriétaire et droits privés POSIX quand disponibles. Sous Windows, configurer une ACL privée pour le compte PHP : les modes PHP ne garantissent pas les ACL. Les répertoires parents et le compte hôte font partie de la confiance ; un processus de mêmes privilèges peut toujours modifier les données.

Un verrou stable couvre lectures et écritures. Une écriture utilise un fichier temporaire voisin aléatoire, l’écriture complète, flush, fsync, fermeture puis renommage. L’ancien état reste intact si le remplacement échoue. Un processus interrompu peut laisser un temporaire ignoré en lecture. Cela traite les interruptions de processus, sans garantie universelle contre une panne matérielle ou électrique.

Un JSON/schéma invalide, un fichier trop gros, un membre manquant du couple clé/état, une clé ou un périmètre différents provoquent une `StorageException` et un refus de poursuivre l’action. L’enveloppe est signée HMAC-SHA256 : une modification valide selon le schéma est également détectée. La clé n’est jamais dans le JSON. Le reset remplace deux fichiers ; une interruption intermédiaire laisse une incohérence détectable.

Si les deux fichiers ou tout le runtime disparaissent, cela reste indiscernable d’une installation neuve. Restaurer une sauvegarde cohérente plus ancienne restaure aussi ses anciens compteurs. La signature ne protège pas d’un attaquant qui possède la clé ou le code et ne fournit pas un journal monotone externe. Les backends personnalisés devraient utiliser `State::toArray()` et `fromArray()` pour la sérialisation/signature.

Limites par défaut : 10 000 compteurs et 8 Mio de JSON. La saturation refuse la demande au lieu d’effacer des bannissements actifs. Les entrées expirées sont purgées avant le contrôle de capacité.

## Horloge, sessions et IP

- Horloge maîtrisée par le serveur ; les arguments de temps servent uniquement aux tests.
- Recul jusqu’à `clock_tolerance` secondes (5 par défaut) : temps figé au dernier instant du limiteur. Recul supérieur : `clock_moved_back`. Corriger la synchronisation, sans effacer les compteurs.
- Un saut en avant fait naturellement expirer les données ; la bibliothèque ne distingue pas un saut d’horloge d’une longue période écoulée.
- Les formulaires datés du futur sont refusés. Ils sont liés au périmètre et à la session conservée côté serveur ; changer de langue ne les invalide pas.
- Une rotation de session change l’empreinte client, sans enlever la limite IP. Les données de session préservées peuvent maintenir les formulaires non consommés ; une session perdue exige un nouveau formulaire. Un formulaire consommé ne peut pas être rejoué via l’historique.
- Fournir une IP vérifiée et une session non vide. Les écritures IPv6 équivalentes sont normalisées. La bibliothèque ne lit pas les en-têtes proxy : configurer les proxys de confiance dans l’application. Ne pas passer une chaîne de relais séparés par des virgules ou un `X-Forwarded-For` arbitraire.

## Réseaux partagés

Tous les nouveaux POST comptent, y compris les jetons invalides et erreurs métier. Après cinq tentatives admises sur dix minutes, le client reçoit un bannissement progressif. Ses demandes déjà bloquées ne consomment plus le quota de ses collègues et ne prolongent pas son bannissement.

La limite IP partagée de 30 tentatives/10 minutes attend la libération du plus ancien créneau au lieu de bloquer tout le monde une heure. Un refus global n’aggrave pas le bannissement individuel. `ip_ban: true` réactive volontairement les bannissements IP progressifs. Les bannissements actifs migrés restent respectés jusqu’à expiration.

Adapter cette politique aux erreurs honnêtes et connexions partagées. Ce n’est ni une protection de connexion générique ni un dispositif DDoS. Changer navigateur/session contourne un compteur client, mais pas celui de l’IP. Des robots patients et distribués peuvent passer.

## Entretien planifié

Commande Composer : `vendor/bin/form-guard`. Depuis les sources :

```sh
php bin/form-guard status --runtime-dir=/private/form-guard --scope=contact
php bin/form-guard prune --runtime-dir=/private/form-guard --scope=contact
php bin/form-guard migrate --runtime-dir=/private/form-guard --scope=contact
php bin/form-guard reset --runtime-dir=/private/form-guard --scope=contact --confirm-reset
```

Le répertoire explicite et le même périmètre que l’application sont nécessaires. `status` donne format et nombres, jamais les clés ou identifiants. `prune` retire les compteurs expirés et temporaires reconnus vieux d’au moins une heure, en conservant les autres fichiers et écritures récentes. Le planificateur système permet l’entretien sans visiteur ; les bannissements et historiques sont conservés jusqu’à leur fin de rétention.

La purge à chaque demande n’exige pas de planificateur. Une purge planifiée donne un intervalle de nettoyage borné, sans prouver une politique légale de conservation. Journaux, sauvegardes et copies ont leur propre rétention.

Arrêter les workers et sauvegarder clé/état avant migration ou reset. Restaurer un couple cohérent conserve les bannissements. Le reset confirmé efface volontairement l’historique et change la clé. Ne jamais publier les fichiers runtime ou les copier entre sites sans lien.
