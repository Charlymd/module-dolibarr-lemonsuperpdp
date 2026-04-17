# LemonSuperPDP

Module Dolibarr pour la transmission des factures électroniques via la **Plateforme Agréée SUPER PDP** (https://www.superpdp.tech).

Complément du module [LemonFacturX](https://github.com/hello-lemon/module-dolibarr-lemonfacturx) : là où LemonFacturX s'occupe du **format** (génération PDF/A-3 + XML EN16931 embarqué), LemonSuperPDP s'occupe du **transport** (envoi via l'API de la PA officielle DGFiP, synchronisation des statuts de cycle de vie).

## Statut

Version 0.1.0 — en cours de développement. À ce stade :

- Descripteur du module et installation Dolibarr
- Client HTTP OAuth 2.1 (client_credentials)
- Page de configuration avec test de connexion
- Traductions FR/EN

À venir (roadmap) :

- Bouton "Envoyer via SUPER PDP" sur la fiche facture
- Envoi en masse depuis la liste des factures
- Affichage des statuts (envoyée, acceptée, refusée, encaissée)
- Cron de synchronisation des `invoice_events`
- Envoi d'événements de cycle de vie depuis Dolibarr (statut encaissée à l'enregistrement d'un paiement)

## Prérequis

- **Dolibarr** 18.0+
- **Module LemonFacturX** activé (dépendance stricte)
- **PHP** 7.4+
- Un compte SUPER PDP avec une application OAuth créée (https://www.superpdp.tech)

## Installation

1. Copier le dossier dans le répertoire custom de Dolibarr :

```bash
cp -r lemonsuperpdp/ /var/www/html/custom/
chown -R www-data:www-data /var/www/html/custom/lemonsuperpdp
```

2. Activer le module dans **Accueil > Configuration > Modules**
3. Ouvrir la configuration du module et renseigner :
   - `client_id` et `client_secret` de l'application SUPER PDP
   - Format d'envoi (Factur-X par défaut)
4. Cliquer sur **Tester la connexion** pour valider l'authentification OAuth

## Architecture

```
lemonsuperpdp/
├── core/modules/modLemonSuperPDP.class.php  # Descripteur (n° 500240)
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

Distribué sous licence [GPLv3](https://www.gnu.org/licenses/gpl-3.0.html) — Copyright (C) 2026 SASU LEMON.
