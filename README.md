# LemonSuperPDP (fork) — ⚠️ Projet archivé / non maintenu

> **Ce dépôt n'est plus maintenu.**
> Aucune nouvelle version, correction de bug ni évolution de raccordement ne sera publiée ici.

---

## 📌 Pourquoi cet arrêt ?

Ce dépôt était un **fork communautaire** du module LemonSuperPDP, assurant le raccordement de Dolibarr à une
plateforme de dématérialisation partenaire (SuperPDP) dans le cadre de la réforme française de la facturation
électronique.

La société **HelloLemon**, éditrice du module d'origine, a décidé de faire évoluer sa diffusion vers un
**modèle payant**. Ce choix lui appartient pleinement et se comprend : le suivi d'un raccordement à une PA
(spécifications externes DGFiP, évolutions d'API, cycle de vie des statuts, homologations) demande un effort
continu qui doit pouvoir être financé.

Ce changement rend en revanche ce fork sans objet :

- il ne peut plus suivre un amont qui n'est plus diffusé librement ;
- un connecteur mono-fournisseur maintenu isolément a peu d'intérêt face à un écosystème qui se structure ;
- je préfère mutualiser l'effort plutôt que d'entretenir une divergence de plus.

---

## 🍋 ➜ 🧾 Où aller maintenant ?

**J'ai choisi de réinvestir mon temps dans le module communautaire `einvoicing`, hébergé dans le dépôt
officiel [`Dolibarr/dolibarr-community-modules`](https://github.com/Dolibarr/dolibarr-community-modules).**

C'est aujourd'hui le meilleur point de convergence pour la facturation électronique sous Dolibarr :

- **officiel et véritablement communautaire** — le dépôt est hébergé sous l'organisation **Dolibarr**
  elle-même, avec une gouvernance ouverte et des contributions multiples : aucune dépendance à la
  stratégie commerciale d'un éditeur unique ;
- **communauté active** — issues traitées, pull requests revues, discussions techniques vivantes ;
- **installation native** — ce dépôt alimente le fichier `index.yaml` que Dolibarr télécharge pour proposer
  les modules communautaires **directement installables depuis la page de configuration des modules** ;
- **approche multi-PA** — l'objectif est justement de ne pas enfermer les utilisateurs dans un seul
  fournisseur : abstraction du client de transmission, puis implémentations par plateforme
  (dont une interface conforme au profil d'interopérabilité **XP Z12-013**) ;
- **ouverture internationale** — le même dépôt héberge aussi `peppol` (réseau européen) et `ksef`
  (Pologne), signe d'une approche qui dépasse le seul cadre franco-français ;
- **licence libre** — GPL-3.0 : utilisable, auditable, adaptable et redistribuable.

👉 **Module Einvoicing :** <https://github.com/Dolibarr/dolibarr-community-modules/tree/main/einvoicing>

Mes contributions se poursuivent désormais **exclusivement** de ce côté.

---

## 🔁 Migration

| Vous utilisiez ce fork pour… | Aller vers… |
|---|---|
| Transmettre / recevoir des factures via une PDP | Module `einvoicing` (connecteurs multi-fournisseurs) |
| Suivi des statuts du cycle de vie | Module `einvoicing` |
| Modèles ODT / PDF associés | Fonctions natives Dolibarr + `einvoicing` |
| Raccordement supporté contractuellement | Offre payante **HelloLemon** |

**Aucun script de migration automatique n'est fourni.** Le module `einvoicing` s'installe directement
depuis **Configuration → Modules → Déployer/activer un module externe**, sans téléchargement manuel.

> ⚠️ **Points d'attention avant de basculer :**
> - sauvegardez base de données et répertoire `documents/` ;
> - conservez vos **identifiants** de raccordement, ils devront être ressaisis ;
> - vérifiez qu'aucun flux n'est **en cours de transmission** au moment de la bascule ;
> - assurez l'**archivage** des pièces déjà émises (obligation légale indépendante du module utilisé).

---

## 📦 Et le code existant ?

Le dépôt reste **en lecture seule, à titre d'archive** :

- le code est toujours consultable et téléchargeable ;
- la licence d'origine (**GPL v3+**) continue de s'appliquer : libre à vous de forker et d'en reprendre
  la maintenance ;
- **les issues et pull requests ne seront plus traitées** ;
- la dernière version publiée reste fonctionnelle en l'état, **mais sans garantie de conformité** avec les
  évolutions réglementaires et les évolutions d'API côté plateforme. Ne comptez pas dessus pour couvrir
  une obligation légale.

---

## 🙏 Remerciements

Merci à **HelloLemon** pour le travail initial, qui a permis d'expérimenter très tôt le raccordement SuperPDP
depuis Dolibarr. Merci aussi à celles et ceux qui ont testé ce fork, remonté des anomalies et proposé
des correctifs.

Rendez-vous sur le module **Einvoicing** — les contributions y sont les bienvenues. 🚀
