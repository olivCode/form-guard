[English](compatibility.md) · [Français](compatibility.fr.md)

# API 1.0, cycle de vie et compatibilité

Le périmètre 1.0 comprend le moteur PHP et son adaptateur Laravel 13 distinct. Les portages TypeScript/Node et C#/ASP.NET sont prévus après la 1.0 avec des vecteurs de comportement communs ; ils ne sont ni implémentés ni nécessaires à cette version.

## Contrats publics

Les contrats publics documentés sont `Guard`, `Configuration`, `ProtectionLevel`, `Rejection`, `RejectionReason` ; les interfaces de stockage `StoreInterface`, `InMemoryStore`, `FileStore`, `State` et les exceptions/interfaces d’écriture documentées ; les interfaces/catalogues de traduction ; les interfaces/contextes/résultats des règles ; les interfaces, phases, contextes, refus et exceptions documentées des plugins ; le `TurnstilePlugin` et son interface de transport facultatifs ; les classes HTTP `RequestGuard`, `RequestMetadata`, `RequestRejected` ; et les commandes CLI documentées. Les signatures figurent dans [l’intégration](integration.fr.md), [les plugins](plugins.fr.md), [le stockage](storage.fr.md), [i18n](i18n.fr.md) et [la sécurité HTTP](http-security.fr.md). Les auxiliaires d’implémentation comme `Options`, `RateLimiter`, le registre et les utilitaires internes de fichiers ne sont pas des points d’extension.

La branche 1.x conserve les signatures documentées, les valeurs des raisons de refus, la signification des options et le format d’état. Une version mineure peut ajouter des fonctionnalités optionnelles, des cas d’enum et des champs de diagnostic : tester les valeurs nommées et tolérer les champs de diagnostic inconnus. Une rupture nécessite une version majeure et une migration documentée. Un correctif de sécurité peut durcir le refus d’entrées malformées ; consulter ses notes. La prise en charge de versions PHP peut être étendue dans une mineure ; elle n’est pas retirée pendant 1.x sans nécessité de sécurité documentée.

## Intégration et état persistant

Définir un `scope` stable et un répertoire de runtime privé persistant. Conserver ensemble la clé, l’état et les formulaires en session pendant les mises à jour. Les formulaires portent une date `expires` immuable : changer `form_ttl` concerne les nouveaux formulaires et ne réactive pas ceux déjà expirés.

`RequestGuard` est une couche d’intégration explicite ; installer le package ne protège pas automatiquement la frontière HTTP. Turnstile reste facultatif et demande une configuration fournisseur, un widget et des délais réseau adaptés.

## Mise à jour et suppression

Tester sur une copie de préproduction, déployer une révision cohérente du code et du JavaScript public, et conserver le runtime en dehors de la bibliothèque remplacée. Ne pas réinitialiser/supprimer l’état pour une mise à jour ordinaire. Sauvegarder ensemble clé et état, puis restaurer avec les workers arrêtés ; le backend fichiers ne peut détecter la restauration d’une ancienne sauvegarde cohérente.

Avant une désinstallation, retirer ou remplacer volontairement l’intégration des routes et formulaires protégés. Supprimer seulement la dépendance ne doit pas convertir les pannes en soumissions acceptées. Composer laisse intacts runtime et données de session appartenant à l’application. Ne supprimer ces données qu’après choix de la rétention/récupération et arrêt des workers concernés ; aucun hook de désinstallation ne les efface automatiquement.
