# Politique de sécurité — LemonSuperPDP

Ce document décrit le modèle de menace du module LemonSuperPDP, les protections en place, les limitations assumées, et le processus de signalement responsable d'une faille.

## Signaler une vulnérabilité

Merci de **ne pas** ouvrir d'issue publique pour une faille de sécurité. Écrivez à :

**hello@hellolemon.fr**

Précisez :

- Version du module concernée (ou commit SHA)
- Description de la vulnérabilité et impact estimé
- Étapes de reproduction minimales
- Éventuelle preuve de concept

Nous nous engageons à :

- Accuser réception sous 72 heures
- Vous tenir informé de l'avancement de l'analyse
- Mentionner votre contribution (si vous le souhaitez) une fois le correctif publié
- Appliquer un délai de divulgation coordonnée de 90 jours maximum avant publication publique du détail

Merci d'éviter toute action qui pourrait dégrader un service en production, accéder à des données tierces, ou exploiter une faille au-delà du strict nécessaire pour la démontrer.

## Modèle de menace

LemonSuperPDP est un module Dolibarr qui orchestre la transmission de factures électroniques vers la Plateforme Agréée SUPER PDP. Il s'exécute **à l'intérieur** d'une instance Dolibarr authentifiée. Le modèle de menace est donc celui d'une application métier en intranet :

### Rôles

| Rôle | Accès | Confiance |
|---|---|---|
| Administrateur Dolibarr | Configuration complète du module, lecture/écriture des constantes (dont `CLIENT_SECRET`) | **Confiance forte**. Un admin compromis implique de toute façon une compromission totale de Dolibarr. |
| Utilisateur `lemonsuperpdp.transmission.ecrire` | Envoi d'une facture, émission manuelle de statuts | Confiance interne. Pas d'accès aux secrets. |
| Utilisateur `lemonsuperpdp.transmission.lire` | Lecture de l'historique des transmissions et événements | Confiance interne. |
| Utilisateur anonyme (hors Dolibarr) | Aucun accès | Non concerné : le module n'expose aucun endpoint public. |

### Surface exposée

- **Hooks Dolibarr** : exécutés dans le contexte fiche facture (utilisateur authentifié)
- **Page de configuration admin** : `admin/setup.php`, réservée aux admins via `accessforbidden()`
- **Cron** : exécuté via planificateur Dolibarr (`LemonSuperPDPCron::syncEvents`) ou CLI (`scripts/cron_sync_events.php`, guard `php_sapi_name() === 'cli'`)
- **Trigger** : `interface_99_modLemonSuperPDP_lemonsuperpdp` sur `BILL_PAYED`, exécuté en interne par Dolibarr
- **Aucun endpoint web exposé publiquement**

### Ce qui est **hors** modèle de menace

- Un administrateur Dolibarr malveillant. Un admin peut déjà tout faire dans Dolibarr, y compris lire la base et les constantes. Aucun mécanisme ne protège contre un admin hostile (et ne le peut pas dans l'architecture Dolibarr).
- Une compromission de la Plateforme Agréée SUPER PDP elle-même. Les messages retournés par l'API sont décodés en JSON et stockés tels quels en base ; ils ne sont jamais désérialisés (pas de `unserialize()`) ni exécutés. En cas de compromission de la PA, le risque principal est d'importer des événements falsifiés dans l'historique local.

## Protections en place

### Injection SQL

Toutes les requêtes SQL du module utilisent soit :

- des casts explicites `(int)` pour les identifiants et entiers
- `$this->db->escape()` pour les chaînes

Aucune concaténation de variable utilisateur non échappée dans du SQL.

### Cross-Site Scripting (XSS)

Toute donnée affichée dans l'interface passe par `dol_escape_htmltag()` (contexte HTML) ou `dol_escape_js()` (contexte JavaScript inline). Les données venant de l'API SUPER PDP sont également échappées avant affichage.

### Cross-Site Request Forgery (CSRF)

Toute action modifiant l'état passe par une vérification `GETPOST('token', 'alpha') != newToken()` (token CSRF Dolibarr standard). Actions concernées :

- `dosendsuperpdp` (envoi d'une facture)
- `dosendsuperpdp_bulk` (envoi en masse)
- `refreshsuperpdpevents` (rafraîchir les événements)
- `sendstatussuperpdp` (émission manuelle de statut)
- `resettransmissionsuperpdp` (sandbox uniquement)
- Tous les POST de `admin/setup.php`

### Path traversal

Le chemin du PDF source est construit à partir de `$invoice->ref` passé par `dol_sanitizeFileName()`. Un `ref` contenant `..` ou autres séquences dangereuses est neutralisé côté Dolibarr en amont.

### SSRF (Server-Side Request Forgery)

L'endpoint SUPER PDP est configurable (constante `LEMONSUPERPDP_ENDPOINT`), mais :

- La modification de cette constante est **réservée aux administrateurs** et protégée par CSRF
- Depuis la version 0.1.1, le schéma `https://` est **obligatoire** côté formulaire de configuration : toute URL non-HTTPS est rejetée au `save`
- `curl` n'active pas `CURLOPT_FOLLOWLOCATION` par défaut en PHP : pas de suivi de redirection 3xx
- Les appels sortants sont limités aux verbes `GET` et `POST` nécessaires à l'API

Un admin hostile peut en théorie toujours pointer l'endpoint vers un domaine externe qu'il contrôle (le HTTPS ne l'empêche pas). C'est cohérent avec le modèle de menace (admin de confiance).

### Secrets

- `LEMONSUPERPDP_CLIENT_SECRET` et `LEMONSUPERPDP_ACCESS_TOKEN` sont stockés en constantes Dolibarr (table `llx_const`).
- Le `CLIENT_SECRET` **n'est jamais réaffiché** dans l'interface : la page de configuration affiche `********` si déjà défini. La valeur n'est écrasée que si l'utilisateur saisit explicitement une nouvelle valeur (non vide et différente de `********`).
- Toute modification de la configuration **invalide** le cache du token OAuth (`LEMONSUPERPDP_ACCESS_TOKEN` vidé).
- Les réponses d'erreur HTTP de l'API sont tronquées à 500 caractères dans les logs pour limiter le risque qu'un payload d'erreur contienne accidentellement un token partiel.

### Limitation assumée

Le stockage des secrets en base `llx_const` est la convention Dolibarr. Un administrateur (ou toute personne ayant un accès en lecture à la table `llx_const`, ce qui implique déjà un niveau de compromission élevé) peut voir le `CLIENT_SECRET`. Aucun mécanisme de secret manager n'étant disponible nativement dans Dolibarr, cette limitation est documentée mais non corrigée à ce stade. Recommandation côté exploitant : restreindre strictement les droits admin et surveiller les accès à la base.

### XML et Factur-X

Le mode sandbox extrait le XML du PDF via `Atgp\FacturX\Reader::extractXML($pdf, false)` **sans validation XSD** et manipule le XML résultant comme une simple chaîne de caractères (remplacements `str_replace`). Aucun parsing XML (`loadXML`, `simplexml_load_*`) n'est effectué dans le chemin critique : **pas de surface XXE** dans le module.

### Logs

- `dol_syslog` est utilisé en niveau `LOG_DEBUG` pour les méthodes et URL, `LOG_ERR` pour les erreurs
- Les tokens OAuth ne sont jamais inscrits en clair dans les logs
- En cas d'erreur d'authentification HTTP, le corps de réponse brut n'est pas loggé (il pourrait contenir un token partiel en cas de bug côté API)

## Dépendances

- **LemonFacturX** (module Lemon pendant) : sa bibliothèque vendored `atgp/factur-x` est utilisée côté sandbox pour extraire le XML d'un PDF Factur-X. Une compromission de LemonFacturX implique une compromission transitive de LemonSuperPDP.
- **Dolibarr 18.0+** : la sécurité du module s'appuie sur les primitives Dolibarr (`GETPOST`, `newToken`, `dol_escape_htmltag`, `accessforbidden`, permissions). Un Dolibarr non à jour affecte directement la sécurité de tous ses modules, dont celui-ci.

## Historique des avis

_Aucune vulnérabilité corrigée n'a été publiée à ce jour._

---

Pour toute question sur la sécurité de ce module : hello@hellolemon.fr
