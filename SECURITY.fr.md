[English](SECURITY.md) · [Français](SECURITY.fr.md)

# Politique de sécurité

La dernière révision corrective 1.0.x reçoit les correctifs de sécurité. Aucun délai contractuel de réponse ni audit indépendant n’est promis.

## Signaler une vulnérabilité

Utiliser le [signalement confidentiel GitHub](https://github.com/olivCode/form-guard/security/advisories/new). Ne pas publier de détails d’exploitation dans une issue ou une PR publique. Si la vulnérabilité affecte aussi l’autre package Form Guard, le préciser dans un seul signalement.

Fournir la version, l’environnement et une reproduction synthétique minimale. Ne transmettre ni identifiants SMTP, ni clés, ni jetons/cookies de session, ni messages réels ou identifiants de visiteurs.

## Périmètre

Les signaux navigateur peuvent être falsifiés ; le mode sans JavaScript reste pris en charge. Les limites peuvent affecter les utilisateurs légitimes d’une IP partagée. La protection dépend des sessions sécurisées, des validations de l’application, d’une IP vérifiée côté serveur et d’un stockage privé fiable. Le backend fichiers n’est pas un service distribué.

L’application PHP choisit l’affichage des erreurs, leur journalisation et le traitement d’un refus ou d’une action incertaine. Ne jamais poursuivre une action après une erreur de protection. Ne pas faire confiance à un en-tête proxy sans configuration des proxys de confiance à la frontière de l’application ou du serveur.

En production, définir `display_errors=Off` et `zend.exception_ignore_args=On`. Ne pas journaliser les corps, jetons, secrets ou contextes complets des plugins. Les paramètres d’entrée sensibles portent l’attribut PHP `SensitiveParameter` ; le code applicatif et les plugins personnalisés doivent suivre la même politique. Un attribut ne masque pas les variables globales ou dumps arbitraires d’objets.
