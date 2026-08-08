# LemonFacturX (fork) — ⚠️ Projet archivé / non maintenu

> **Ce dépôt n'est plus maintenu.**
> Aucune nouvelle version, correction de bug ni mise en conformité réglementaire ne sera publiée ici.

---

## 📌 Pourquoi cet arrêt ?

Ce dépôt était un **fork communautaire** du module LemonFacturX, maintenu bénévolement dans une logique
d'ouverture et de partage autour de la réforme française de la facturation électronique.

La société **HelloLemon**, éditrice du module d'origine, a décidé de faire évoluer sa diffusion vers un
**modèle payant**. Ce choix lui appartient pleinement et est parfaitement légitime : maintenir un module
au rythme des évolutions réglementaires (spécifications externes DGFiP, formats Factur-X / EN 16931,
raccordements PDP) représente un travail considérable qui mérite d'être financé.

En revanche, ce changement de modèle rend la poursuite de ce fork sans objet :

- il n'est plus aligné avec l'amont, qui n'est plus diffusé librement ;
- maintenir seul une divergence croissante sur un périmètre aussi mouvant que l'e-invoicing
  n'est ni tenable, ni utile à la communauté ;
- dupliquer l'effort au lieu de le mutualiser irait à l'encontre de ce que je recherche dans l'open source.

---

## 🍋 ➜ 🧾 Où aller maintenant ?

**J'ai choisi de réinvestir mon temps dans le module communautaire `einvoicing`, hébergé dans le dépôt
officiel [`Dolibarr/dolibarr-community-modules`](https://github.com/Dolibarr/dolibarr-community-modules).**

C'est aujourd'hui, à mon sens, le meilleur point de convergence pour la facturation électronique
sous Dolibarr :

- **officiel et véritablement communautaire** — le dépôt est hébergé sous l'organisation **Dolibarr**
  elle-même, avec une gouvernance ouverte et des contributions multiples : aucune dépendance à un
  éditeur unique ni à sa stratégie commerciale ;
- **communauté active** — issues traitées, pull requests revues, discussions techniques vivantes ;
- **installation native** — ce dépôt alimente le fichier `index.yaml` que Dolibarr télécharge pour proposer
  les modules communautaires **directement installables depuis la page de configuration des modules**.
  Plus de ZIP à récupérer à la main ;
- **couverture fonctionnelle en progression rapide** — Factur-X / EN 16931, cycle de vie des statuts,
  et intégration de plusieurs fournisseurs / PDP ;
- **licence libre** — GPL-3.0 : vous pouvez l'utiliser, l'auditer, l'adapter et le redistribuer.

👉 **Module Einvoicing :** <https://github.com/Dolibarr/dolibarr-community-modules/tree/main/einvoicing>

> ℹ️ Le même dépôt héberge également un module **`facturx`** dédié à la génération du format, ainsi que
> `peppol` et `ksef` pour les contextes européens. Selon votre besoin (génération seule ou chaîne complète),
> l'un ou l'autre peut être plus adapté.

Mes contributions se poursuivent désormais **exclusivement** de ce côté (notamment sur l'intégration
multi-PDP et l'abstraction des clients de transmission).

---

## 🔁 Migration

| Vous utilisiez ce fork pour… | Aller vers… |
|---|---|
| Générer des factures au format Factur-X (EN 16931) | Modules `facturx` / `einvoicing` |
| Profils CII / niveaux MINIMUM → EXTENDED | Module `einvoicing` |
| Transmettre les factures à une plateforme (PDP) | Module `einvoicing` |
| Support commercial et fonctions avancées | Offre payante **HelloLemon** |

**Aucun script de migration automatique n'est fourni.** Les modules communautaires s'installent directement
depuis **Configuration → Modules → Déployer/activer un module externe**, sans téléchargement manuel.
Les données de facturation restant portées par le cœur de Dolibarr, la bascule consiste essentiellement à
désactiver ce module puis à configurer le module communautaire.

> 💡 **Avant toute manipulation : sauvegardez votre base de données et votre répertoire `documents/`.**

---

## 📦 Et le code existant ?

Le dépôt reste **en lecture seule, à titre d'archive** :

- le code est toujours consultable et téléchargeable ;
- la licence d'origine (**GPL v3+**) continue de s'appliquer : vous êtes libre de forker et de reprendre
  la maintenance de votre côté ;
- **les issues et pull requests ne seront plus traitées** ;
- la dernière version publiée reste fonctionnelle en l'état, **mais sans garantie de conformité** avec les
  évolutions réglementaires à venir. Ne l'utilisez pas en production pour des obligations légales.

---

## 🙏 Remerciements

Merci à **HelloLemon** pour le travail initial, qui a permis à beaucoup d'entre nous de défricher le sujet
Factur-X sous Dolibarr très tôt. Merci également à toutes les personnes qui ont testé ce fork, remonté des
bugs et proposé des correctifs.

Rendez-vous sur le module **Einvoicing** — les contributions y sont les bienvenues. 🚀
