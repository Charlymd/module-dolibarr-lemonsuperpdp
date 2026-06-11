# LemonSuperPDP

Module Dolibarr pour l'**émission et la réception** des factures électroniques via la **Plateforme Agréée SUPER PDP** (https://www.superpdp.tech).

Complément du module [LemonFacturX](https://github.com/hello-lemon/module-dolibarr-lemonfacturx) : là où LemonFacturX s'occupe du **format** (génération PDF/A-3 + XML EN16931 embarqué), LemonSuperPDP s'occupe du **transport** dans les deux sens — envoi des factures clients via l'API de la PA, synchronisation des statuts de cycle de vie, et import des factures fournisseurs reçues sur la plateforme en factures fournisseurs Dolibarr brouillon.

Développé et maintenu par [Lemon](https://hellolemon.fr), agence web et communication à Clermont-Ferrand, spécialisée dans Dolibarr, WordPress et la facturation électronique.

## Statut

Version 0.4.0 — phase pilote SUPER PDP. Fonctionnalités :

- **Réception des factures fournisseurs** (nouveau) : polling de l'API (`direction=in`), rattachement automatique du tiers par SIREN/SIRET, création de la facture fournisseur Dolibarr **en brouillon** (jamais auto-validée) avec lignes, remises/frais de pied de document et fichier original (PDF Factur-X ou XML) attaché ; écran « Factur-X reçues » avec quarantaine pour les tiers introuvables ou ambigus et les devises étrangères

- Authentification OAuth 2.1 `client_credentials` avec rafraîchissement automatique du token
- Page de configuration avec test de connexion et diagnostic complet
- Vérification de la cohérence du SIREN Dolibarr ↔ SIREN de l'application OAuth (les factures émises avec un SIREN incohérent sont rejetées par la PA)
- Bouton "Envoyer via SUPER PDP" sur la fiche facture, grisé automatiquement quand le client est un particulier (les factures B2C ne sont pas concernées par la réforme)
- Envoi en masse depuis la liste des factures
- Suivi des statuts de cycle de vie (déposée, acceptée, refusée, encaissée) dans une table d'événements
- Bouton "Rafraîchir" pour synchroniser à la demande, et cron `cron_sync_events.php` pour la synchronisation périodique
- Trigger `BILL_PAYED` → envoi automatique du statut `fr:212` (encaissée)
- Mode sandbox temporaire (swap SIREN via `idprof6`) pour la phase pilote
- Bandeau "nouvelle version disponible" + bloc À propos sur la page admin
- Traductions FR / EN complètes

## Prérequis

- **Dolibarr** 18.0+
- **Module LemonFacturX** activé (dépendance stricte)
- **PHP** 7.4+
- Un compte SUPER PDP avec une application OAuth créée (https://www.superpdp.tech)

## Installation

### 1. Déployer le module dans Dolibarr

```bash
cp -r lemonsuperpdp/ /var/www/html/custom/
chown -R www-data:www-data /var/www/html/custom/lemonsuperpdp
```

Activer le module dans **Accueil > Configuration > Modules**.

### 2. Créer une application OAuth sur SUPER PDP

Avant de configurer le module, créez une application OAuth sur la plateforme SUPER PDP en production :

1. Connectez-vous sur https://www.superpdp.tech/app
2. **Applications > Nouvelle application**
3. Remplissez le formulaire :

| Champ | Valeur |
|---|---|
| **Entreprise** | Sélectionner votre entreprise |
| **URLs de redirection** | *Laisser vide* — non utilisé en flow `client_credentials` |
| **Type d'application** | **Confidentielle** — le module est PHP server-side, le `client_secret` est stocké côté serveur Dolibarr (jamais exposé au navigateur) |
| **IBAN** | IBAN de votre entreprise (mandat SEPA, prélèvement des frais d'API selon la grille tarifaire SUPER PDP) |
| **Souscription** | Cocher pour accepter la grille tarifaire et signer le mandat SEPA |

4. Cliquer sur **Créer**. Le `client_id` et le `client_secret` s'affichent. **Le `client_secret` n'est affiché qu'une seule fois** — copiez-le immédiatement.

### 3. Configurer le module dans Dolibarr

Ouvrir **Accueil > Configuration > Modules > LemonSuperPDP > Configurer** et renseigner :

| Champ | Valeur |
|---|---|
| **URL de l'API** | `https://api.superpdp.tech` (production et bac à sable utilisent le même endpoint) |
| **Identifiant OAuth (client_id)** | Le `client_id` de l'application créée à l'étape 2 |
| **Secret OAuth (client_secret)** | Le `client_secret` de l'application créée à l'étape 2 |
| **Format d'envoi** | `Factur-X` (recommandé — utilise le PDF/A-3 généré par LemonFacturX) |

Cliquer sur **Tester la connexion**. Le module appelle `GET /v1.beta/companies/me`, vérifie que le SIREN renvoyé par SUPER PDP correspond bien à celui de votre société Dolibarr (champ `idprof1` dans Accueil > Setup > Société), et mémorise ce SIREN pour les vérifications ultérieures.

Le diagnostic en bas de la page de configuration récapitule l'état de la configuration. Tant que le test de connexion n'a pas été effectué, la cohérence SIREN apparaît en erreur.

### 4. (Optionnel) Activer le cron de synchronisation des statuts

Pour la remontée automatique des accusés de réception et changements de statut (acceptée, refusée, encaissée), deux options :

**Cron interne Dolibarr** (recommandé) — **Accueil > Configuration > Cron jobs** :

| Champ | Valeur |
|---|---|
| Type | Exécution d'une méthode d'une classe PHP |
| Nom | Synchronisation événements SUPER PDP |
| Classe | `LemonSuperPDPCron` |
| Fichier | `/lemonsuperpdp/class/lemonsuperpdp_cron.class.php` |
| Méthode | `syncEvents` |
| Fréquence | 15 minutes |

**Cron système** :

```cron
*/15 * * * * www-data php /var/www/html/custom/lemonsuperpdp/scripts/cron_sync_events.php
```

## Mode d'emploi

### Envoyer une facture

1. Ouvrir la fiche d'une facture **validée**
2. Cliquer sur **Envoyer via SUPER PDP** dans la barre d'actions
3. Le module génère le payload (PDF Factur-X de LemonFacturX par défaut), appelle `POST /v1.beta/invoices`, enregistre la transmission et affiche le résultat

Le bouton est **grisé** dans les cas suivants, avec un message au survol :

| État | Message |
|---|---|
| Facture en brouillon | "La facture doit être validée avant d'être transmise" |
| Client = particulier (`typent_id=8`) | "Le client est un particulier. La facturation électronique B2B ne concerne pas les factures aux particuliers." |
| Facture déjà transmise avec succès | Le bouton n'apparaît pas, le bloc latéral affiche l'état de la transmission |

### Envoyer en masse

Depuis la liste des factures (**Comptabilité > Factures clients > Liste**), sélectionner les factures, choisir **Envoyer via SUPER PDP** dans le menu d'actions de masse. Le module envoie chaque facture individuellement et résume le résultat (`X réussie(s), Y échec(s), Z ignorée(s)`).

Les ignorées sont les brouillons, les déjà transmises, et les factures B2C.

### Suivre une transmission

Sur la fiche facture, le bloc latéral **Transmission SUPER PDP** affiche le statut courant et la date d'envoi. L'onglet **Historique des événements** liste tous les `invoice_events` reçus (statuts AFNOR `fr:200` à `fr:212`, sens entrant/sortant).

Le bouton **Rafraîchir** force une synchronisation à la demande pour cette facture (utile sans attendre le cron).

### Envoyer manuellement un statut

Le menu déroulant **Envoyer un statut** propose les codes AFNOR `fr:204` à `fr:212` (réception, refus, mise à disposition, encaissée, etc.). Pour `fr:207` et `fr:212`, le module ventile automatiquement les montants TVA depuis les lignes de la facture.

Le statut `fr:212` (encaissée) est également envoyé automatiquement par le trigger `BILL_PAYED` quand vous validez un paiement dans Dolibarr.

### Recevoir les factures fournisseurs

1. Activer **Réception des factures fournisseurs** dans la configuration du module (désactivée par défaut)
2. La tâche planifiée **Sync factures reçues SUPER PDP** (créée à l'activation du module, toutes les 15 minutes) interroge `GET /v1.beta/invoices?direction=in` et traite chaque nouvelle facture :
   - tiers résolu par SIREN/SIRET (`idprof1`/`idprof2`) → **facture fournisseur brouillon** créée avec ses lignes, et le fichier original (PDF Factur-X ou XML) attaché à la facture
   - tiers introuvable ou plusieurs correspondances → **quarantaine**
   - devise différente de celle de l'instance → **quarantaine** (saisie manuelle)
3. L'écran **Facturation > Factures fournisseurs > Factur-X reçues (SUPER PDP)** liste tout : bouton **Synchroniser maintenant**, choix du tiers et import pour les quarantaines, écarter/réintégrer une facture
4. La facture importée reste un **brouillon** : vérification humaine puis validation dans Dolibarr, comme une saisie manuelle

> **Mise à jour depuis une version < 0.4.0** : désactiver puis réactiver le module pour créer la table `llx_lemonsuperpdp_reception`, les nouvelles permissions, l'entrée de menu et la tâche planifiée. La désactivation ne supprime aucune donnée.

## Diagnostic et dépannage

### Page de diagnostic

La page de configuration affiche en bas un bloc **Diagnostic** qui vérifie quatre points :

1. Module LemonFacturX activé
2. Identifiants OAuth renseignés
3. SIRET de votre société renseigné (`idprof2` de mysoc)
4. Cohérence du SIREN Dolibarr avec le SIREN de l'application OAuth (alimentée par le dernier "Tester la connexion" réussi)

Tous les points doivent être verts pour que la configuration soit considérée comme prête.

### Erreurs courantes

**`pre-check: receiver address does not exist in peppol directory`** (HTTP 400) — Le destinataire n'est pas inscrit dans l'annuaire Peppol. Causes possibles :
- Le client n'est pas raccordé à une PA/PDP. En période de transition (avant l'obligation générale), c'est le cas le plus courant. Le destinataire doit s'inscrire auprès d'une PA pour pouvoir recevoir.
- Le SIRET du client est absent ou erroné côté Dolibarr.
- L'application OAuth est en bac à sable et le destinataire en production (ou inversement). Vérifier le champ `env` dans la réponse de `/v1.beta/companies/me`.

**`SIREN de votre société (X) différent du SIREN de l'application OAuth SUPER PDP (Y)`** — La société Dolibarr et l'application OAuth ne pointent pas la même entité juridique. Corriger soit `idprof1` dans Accueil > Setup > Société, soit créer une nouvelle application OAuth pour la bonne entreprise sur SUPER PDP.

**`Échec de la connexion SUPER PDP — invalid_client`** — `client_id` ou `client_secret` incorrect. Recréer une application OAuth si le secret a été perdu (il n'est affiché qu'une fois à la création).

**`Échec de la connexion SUPER PDP — pdf not found`** — La facture n'a pas de PDF Factur-X. Vérifier que LemonFacturX est activé et qu'un PDF a été généré pour la facture (bouton **Générer le PDF** sur la fiche facture).

### Mode sandbox du module (phase pilote uniquement)

L'option **Mode sandbox (phase pilote)** dans la configuration est destinée à la **phase pilote SUPER PDP**. Quand activée, le module remplace le SIREN émetteur de la facture par la valeur du champ `idprof6` de votre société avant l'envoi (utile quand votre application OAuth est sur une entreprise bac à sable mais que votre Dolibarr est configuré avec votre SIREN réel).

À désactiver dès que votre SIREN réel est validé côté SUPER PDP. Cette option a vocation à disparaître après la fin de la phase pilote.

## Architecture

```
lemonsuperpdp/
├── core/modules/modLemonSuperPDP.class.php  # Descripteur (n° 210009)
├── core/lib/lemonsuperpdp.lib.php           # Lib utilitaire (update check GitHub)
├── core/triggers/                           # Trigger BILL_PAYED → fr:212
├── class/superpdp_client.class.php          # Client HTTP OAuth 2.1
├── class/transmission.class.php             # Objet métier transmission
├── class/event.class.php                    # Objet métier event
├── class/actions_lemonsuperpdp.class.php    # Hooks UI (bouton, bloc latéral, bulk)
├── class/lemonsuperpdp_cron.class.php       # Cron de synchronisation
├── admin/setup.php                          # Configuration + test connexion + diagnostic
├── ajax/                                    # Handlers AJAX (rafraîchir, statut manuel)
├── scripts/cron_sync_events.php             # Cron CLI standalone
├── sql/                                     # CREATE TABLE + index
└── langs/fr_FR/, langs/en_US/               # Traductions
```

## API SUPER PDP

Le module consomme l'API documentée ici : https://www.superpdp.tech/documentation

- Authentification : OAuth 2.1 `client_credentials` sur `/oauth2/token`
- Endpoints utilisés : `/v1.beta/companies/me`, `/v1.beta/invoices` (envoi, et liste `direction=in` avec expand `en_invoice.*` pour la réception), `/v1.beta/invoices/{id}/download`, `/v1.beta/invoice_events`, `/v1.beta/invoices/{id}` (expand `invoice_events`)
- Synchronisation des événements : polling avec `starting_after_id` (la doc ne prévoit pas de webhook)
- La réponse `/v1.beta/companies/me` expose le SIREN dans le champ `number` quand `number_scheme == "fr_siren"`, et l'environnement de l'application dans le champ `env` (`production` ou `sandbox`)

## Sécurité

- `client_secret` stocké en constante Dolibarr (table `llx_const`), jamais affiché en clair dans la page de configuration (remplacé par `********` si déjà défini)
- Token OAuth caché en constante avec `expires_at`, rafraîchi automatiquement avant expiration
- Endpoint OAuth contraint au schéma `https://` (mitigation SSRF)
- Toutes les actions POST sont protégées par un token CSRF Dolibarr
- Voir [SECURITY.md](SECURITY.md) pour le threat model et la politique de divulgation

## Constantes du module

| Constante | Type | Défaut | Description |
|---|---|---|---|
| `LEMONSUPERPDP_ENABLED` | int | 1 | Activer/désactiver |
| `LEMONSUPERPDP_ENDPOINT` | string | `https://api.superpdp.tech` | URL de base de l'API |
| `LEMONSUPERPDP_CLIENT_ID` | string | (vide) | OAuth client_id |
| `LEMONSUPERPDP_CLIENT_SECRET` | string | (vide) | OAuth client_secret |
| `LEMONSUPERPDP_FORMAT` | string | `facturx` | Format d'envoi (`facturx`, `ubl`, `cii`) |
| `LEMONSUPERPDP_ACCESS_TOKEN` | string | (vide) | Cache du token OAuth (JSON `{access_token, expires_at}`) |
| `LEMONSUPERPDP_LAST_EVENT_ID` | string | `0` | Dernier `invoice_event` synchronisé (pagination cron) |
| `LEMONSUPERPDP_IN_ENABLED` | int | 0 | Activer la réception des factures fournisseurs (`direction=in`) |
| `LEMONSUPERPDP_LAST_IN_ID` | string | `0` | Dernière facture reçue synchronisée (curseur de polling) |
| `LEMONSUPERPDP_OAUTH_SIREN` | string | (vide) | SIREN de l'application OAuth, mémorisé au dernier "Tester la connexion" réussi pour la cohérence du diagnostic |
| `LEMONSUPERPDP_OAUTH_SIREN_AT` | string | `0` | Timestamp du dernier rafraîchissement de `LEMONSUPERPDP_OAUTH_SIREN` |
| `LEMONSUPERPDP_SANDBOX_MODE` | int | 0 | Mode sandbox phase pilote : remplace le SIREN émetteur par `idprof6` avant envoi (à désactiver en prod) |

## Licence

Distribué sous licence [GPLv3](https://www.gnu.org/licenses/gpl-3.0.html) — Copyright (C) 2026 [SASU Lemon](https://hellolemon.fr).

## À propos de Lemon

[Lemon](https://hellolemon.fr) est une agence web et communication basée à Clermont-Ferrand, fondée en 2012. Nous accompagnons TPE, PME et indépendants bien au-delà du simple site web :

- **Déploiement et hébergement Dolibarr** : installation, migration, paramétrage métier, formation de vos équipes
- **Modules Dolibarr sur mesure** : CRM, pointeuse NFC, facturation électronique, intégrations API, automatisations — on développe le module qui manque à votre ERP
- **Facturation électronique** : mise en conformité Factur-X EN16931, raccordement aux Plateformes Agréées (PA/PDP), accompagnement réforme 2026-2027
- **IA au service des pros** : extraction automatique de factures fournisseurs, rapprochement bancaire, génération de contenus, assistants métier — on met l'IA au travail pour vous faire gagner du temps
- **Sites web** : WordPress, Astro, Symfony — performance, SEO, éco-conception
- **Communication & print** : identité visuelle, impression, fabrication (laser, 3D)

Un projet Dolibarr, une idée d'automatisation, un besoin IA ? [Parlons-en](https://hellolemon.fr) — Clermont-Ferrand (63).
