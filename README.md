# LemonSuperPDP

Module Dolibarr pour la transmission des factures électroniques via la **Plateforme Agréée SUPER PDP** (https://www.superpdp.tech).

Complément du module [LemonFacturX](https://github.com/hello-lemon/module-dolibarr-lemonfacturx) : là où LemonFacturX s'occupe du **format** (génération PDF/A-3 + XML EN16931 embarqué), LemonSuperPDP s'occupe du **transport** (envoi via l'API de la PA officielle DGFiP, synchronisation des statuts de cycle de vie).

Développé et maintenu par [Lemon](https://hellolemon.fr), agence web et communication à Clermont-Ferrand, spécialisée dans Dolibarr, WordPress et la facturation électronique.

## Statut

Version 0.2.0 — phase pilote. À ce stade :

- Descripteur du module et installation Dolibarr
- Client HTTP OAuth 2.1 (client_credentials)
- Page de configuration avec test de connexion et diagnostic
- Bouton "Envoyer via SUPER PDP" sur la fiche facture
- Envoi en masse depuis la liste des factures
- Affichage des statuts de transmission (envoyée, acceptée, refusée, encaissée)
- Cron de synchronisation des `invoice_events` (polling 15 min)
- Trigger BILL_PAYED → envoi automatique du statut `fr:212`
- Mode sandbox temporaire pour phase pilote (swap SIREN via idprof6)
- Bandeau "nouvelle version disponible" + bloc À propos sur la page admin
- Traductions FR/EN complètes

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

4. Cliquer sur **Créer**. Le `client_id` et le `client_secret` s'affichent. **⚠️ Le `client_secret` n'est affiché qu'une seule fois** — copiez-le immédiatement.

### 3. Configurer le module dans Dolibarr

Ouvrir **Accueil > Configuration > Modules > LemonSuperPDP > Configurer** et renseigner :

| Champ | Valeur |
|---|---|
| **URL de l'API** | `https://api.superpdp.tech` (production et bac à sable utilisent le même endpoint) |
| **Identifiant OAuth (client_id)** | Le `client_id` de l'application créée à l'étape 2 |
| **Secret OAuth (client_secret)** | Le `client_secret` de l'application créée à l'étape 2 |
| **Format d'envoi** | `Factur-X` (recommandé — utilise le PDF/A-3 généré par LemonFacturX) |

Cliquer sur **Tester la connexion** pour valider l'authentification OAuth. Si le test réussit, le module est prêt à transmettre des factures.

### 4. (Optionnel) Activer le cron de synchronisation

Pour la remontée automatique des statuts (acceptée, refusée, encaissée), activer le cron `scripts/cron_sync_events.php` toutes les 15 minutes via le planificateur Dolibarr ou cron système.

## Architecture

```
lemonsuperpdp/
├── core/modules/modLemonSuperPDP.class.php  # Descripteur (n° 210009)
├── core/lib/lemonsuperpdp.lib.php           # Lib utilitaire (update check GitHub)
├── class/superpdp_client.class.php          # Client HTTP OAuth 2.1
├── admin/setup.php                          # Configuration + test connexion
├── sql/
│   ├── llx_lemonsuperpdp_transmission.sql
│   └── llx_lemonsuperpdp_transmission.key.sql
└── langs/
    ├── fr_FR/lemonsuperpdp.lang
    └── en_US/lemonsuperpdp.lang
```

## API SUPER PDP

Le module consomme l'API documentée ici : https://www.superpdp.tech/documentation

- Authentification : OAuth 2.1 `client_credentials` sur `/oauth2/token`
- Endpoints utilisés : `/v1.beta/companies/me`, `/v1.beta/invoices`, `/v1.beta/invoice_events`
- Synchronisation des événements : polling avec `starting_after_id` (la doc ne prévoit pas de webhook)

## Sécurité

- `client_secret` stocké en constante Dolibarr (table `llx_const`), jamais affiché en clair dans la page de configuration (remplacé par `********` si déjà défini)
- Token OAuth caché en constante avec `expires_at`, rafraîchi automatiquement
- Toutes les actions POST sont protégées par un token CSRF

## Constantes du module

| Constante | Type | Défaut | Description |
|---|---|---|---|
| `LEMONSUPERPDP_ENABLED` | int | 1 | Activer/désactiver |
| `LEMONSUPERPDP_ENDPOINT` | string | `https://api.superpdp.tech` | URL de base de l'API |
| `LEMONSUPERPDP_CLIENT_ID` | string | (vide) | OAuth client_id |
| `LEMONSUPERPDP_CLIENT_SECRET` | string | (vide) | OAuth client_secret |
| `LEMONSUPERPDP_FORMAT` | string | `facturx` | Format d'envoi (facturx, ubl, cii) |
| `LEMONSUPERPDP_ACCESS_TOKEN` | string | (vide) | Cache du token OAuth (JSON) |
| `LEMONSUPERPDP_LAST_EVENT_ID` | string | 0 | Dernier invoice_event synchronisé |

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
