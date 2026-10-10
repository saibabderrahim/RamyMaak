# Lot 2 — Référentiels : note de livraison

Périmètre (§16) : régions, affectations, suppléance, clients, produits, photos, prix, imports.
Critère de fin : **référentiels chargés à partir des fichiers réels** (procédure en fin de note).

Contrat d'API et d'écrans du lot, avec les règles retenues : [lot-2-contrat-api.md](lot-2-contrat-api.md).

## Contenu livré

| Exigence | Réalisation |
|---|---|
| §3.3, §3.4, H-02, RG-S01 à RG-S05 | Régions et dépôts ; affectations titulaire, suppléant (période obligatoire) et ADV ; un seul titulaire à la fois, remplacement sur confirmation explicite ; fin d'affectation immédiate, historique conservé. Le périmètre est recalculé côté serveur à chaque requête : listes filtrées en base, 404 pour un objet hors périmètre |
| EF-ADM-03 | Écrans Régions (liste, fiche, affectations, historique) et Dépôts |
| EF-CLI-01 à EF-CLI-04, RG-S04, §3.5 | Clients et catégories : recherche, filtres, fiche ; changement de région effectif le lendemain (appliqué chaque nuit à 00:05, heure d'Alger) ; blocage avec motif obligatoire ; client inactif : comptes désactivés et sessions révoquées |
| EF-ADM-01, EF-ADM-02 | Rattachement d'un compte à son client par recherche (code ou raison sociale) ; création groupée des comptes clients et **fiches d'accès PDF** (identifiant = code client en minuscules, mot de passe temporaire, aide à l'installation) |
| EF-AUTH-05 | Le régional réinitialise le mot de passe des comptes clients de son périmètre depuis « Mes clients » |
| EF-PRD-01 à EF-PRD-05, RG-P01 à RG-P05 | Produits et familles (arborescence) ; prix HT par UV avec date d'effet, prix programmé, TTC et prix unitaire indicatif ; historique des prix |
| §9.8, EF-PRD-02 | Photos : 5 au plus, type réel contrôlé, image ré-encodée (métadonnées supprimées), variantes WebP 200, 600 et 1 200 px servies directement par nginx avec un cache d'un an |
| §8, annexes B.3 à B.5 | Imports Excel des clients, produits et prix : gabarits pré-remplis, analyse en arrière-plan avec progression, erreurs au format « Ligne 14, colonne code_region : … », fichier annoté téléchargeable, aperçu avant / après, application en **tout ou rien** après confirmation |
| EF-CLI-03 | « Mes clients » du régional sur téléphone : recherche, statut, appel en un geste, fiche, carte |
| EF-CLT-06 | Carte « Mon entreprise » dans le profil du client |
| EF-ADM-06, §12 | Journaux « Référentiels » (avant / après de chaque champ, dont prix, statuts, affectations et révocations) et « Imports Excel » (application regroupée par lot) dans le journal d'audit |

## Vérifications

- **API** : 563 tests Pest, exécutés sur SQLite et sur MySQL 8 ; la CI les exécute sur MySQL 8.4.
- **PWA** : 117 tests Vitest ; lint, types et build sans avertissement. Le code et les libellés du back-office restent hors du pré-cache et du paquet initial du client (95 Ko compressés).
- **Parcours réels dans Chromium** (Playwright), sur l'API, la base MySQL et la file d'attente réelles, 9 scénarios et 211 contrôles :
  - Admin : affectations, fiches d'accès PDF, changement de région, blocage, création d'un client, photos, prix, import Excel complet (valide, invalide avec fichier annoté, prix) ;
  - ADV : consultation seule ;
  - régional sur téléphone : « Mes clients » ;
  - client : « Mon entreprise ».

  Deux anomalies relevées ont été corrigées : le dépôt et la catégorie actuels n'étaient pas présélectionnés dans les formulaires de modification.
- **Revue contradictoire** en quatre axes (sécurité et périmètre, conformité à la spécification, robustesse de l'API, écrans), chaque constat vérifié par un second relecteur. Tous les constats confirmés sont corrigés et couverts par un test :
  - un ADV restreint ne retrouve jamais toutes les régions à la fin d'une affectation ;
  - un prix déjà appliqué ne se réécrit plus ;
  - le fichier annoté ne garde aucune formule active ;
  - le régional réinitialise les mots de passe clients ;
  - les photos s'ouvrent dans un nouvel onglet malgré le service worker.
- **Mise à jour depuis la v0.1.0** rejouée sur MySQL (migrations, rôles, retour arrière). Elle a révélé un blocage, corrigé : un compte client créé en v0.1.0 avec un identifiant de client faisait échouer la migration.
- **Images et pile complète** : construites et démarrées par la CI.

## Mettre à jour la production (v0.1.0 → v0.2.0)

Procédure habituelle ([deploiement.md](deploiement.md), « Livraisons suivantes »), entre 20:00 et 06:00 (heure d'Alger) :

```bash
# poste de développement
git tag v0.2.0 && git push origin v0.2.0
# serveur
cd /opt/ramymaak && scripts/deploy.sh v0.2.0
```

Ce qui change au déploiement, sans action manuelle :
- nouvelles tables (régions, clients, produits, prix, photos, imports) ;
- le conteneur web lit les photos dans le volume de stockage, en lecture seule ;
- les deux processus de file d'attente analysent les imports ;
- la tâche planifiée applique chaque nuit les changements de région.

Point d'attention : les comptes clients créés en v0.1.0 perdent leur rattachement provisoire (l'identifiant de client saisi alors ne désignait aucun client). Après l'import des clients, rattachez chacun à son client : **Comptes → le compte → Client**.

## Charger les référentiels réels (critère de fin)

L'ordre compte : chaque fichier s'appuie sur les codes créés à l'étape précédente. Les codes sont **définitifs** : ce sont les clés des fichiers Excel.

1. **Dépôts et régions** (Admin → Régions, onglet Dépôts) : saisir les codes définitifs.
2. **Catégories de clients** (Admin → Clients, onglet Catégories) : leur code ou leur libellé est attendu dans la colonne `categorie`.
3. **Familles de produits** (Admin → Produits, onglet Familles) : leur code est attendu dans la colonne `code_famille`.
4. **Clients** (Admin → Imports → Clients) :
   1. Télécharger le gabarit, y coller les données (annexe B.3) et déposer le fichier.
   2. En cas d'erreurs, corriger à l'aide du fichier annoté puis déposer de nouveau.
   3. Contrôler l'aperçu, puis **Appliquer**.
5. **Produits** puis **Prix** : même démarche (annexes B.4 et B.5). Les dates d'effet sont aujourd'hui ou plus tard ; le gabarit des prix réimporté tel quel ne change rien.
6. **Photos** : depuis chaque fiche produit.
7. **Régionaux** :
   1. Créer leurs comptes (Comptes).
   2. Les affecter, titulaire puis suppléants, depuis la fiche de chaque région.
8. **Comptes clients** (Clients) :
   1. Sélectionner les clients, puis « Créer les comptes et imprimer les fiches d'accès ».
   2. Remettre chaque fiche en main propre.

Vérification finale : un régional ne voit que les clients de ses régions dans « Mes clients » ; un client voit sa fiche dans **Profil** ; les prix HT et TTC du catalogue sont ceux du fichier.

## Décisions d'implémentation à valider

| # | Décision | Raison |
|---|---|---|
| I-11 | Les codes (région, dépôt, catégorie, client, produit, famille) ne se modifient plus après création | Clés des fichiers Excel et des échanges avec l'ERP |
| I-12 | Un ADV sans aucune affectation ADV voit toutes les régions. Dès sa première affectation, il est limité à ses affectations actives, éventuellement aucune | RG-S05 : la fin d'une affectation ne doit jamais élargir le périmètre |
| I-13 | Un prix s'enregistre à une date d'effet d'aujourd'hui ou plus tard ; un prix programmé à la même date est remplacé | RG-P03 : l'historique des prix appliqués ne se réécrit pas |
| I-14 | Imports : `.xlsx` seulement (pas de macros), 5 Mo et 10 000 lignes au plus ; l'application se fait pendant la requête (environ 5 s pour 2 000 clients) | Volumes du §13.1 ; un traitement en file d'attente reste possible si les fichiers grossissent |
| I-15 | Fichiers d'import et fichiers annotés conservés sans limite de durée | Historique des imports (§8.1) ; durée de conservation à décider |
| I-16 | Photos ré-encodées en WebP de 2 400 px au plus ; image de plus de 25 mégapixels refusée | Sécurité (§12) et poids des vignettes (§9.8) |
| I-17 | Fiches d'accès : 200 clients au plus par lot ; les clients déjà équipés d'un compte ou inactifs sont ignorés | Durée de génération du PDF (environ 20 s pour 200 fiches) |
| I-18 | Un statut `BLOQUE` importé sans motif reçoit le motif « Import Excel du JJ/MM/AAAA » | Le motif est obligatoire pour bloquer (EF-CLI-04) |
| I-19 | Le client bloqué voit que la commande est suspendue, sans le motif saisi par l'Admin | Le motif peut être une note interne (commerciale ou financière) |
| I-20 | Lien « Voir sur la carte » vers Google Maps | Ouvre l'application de cartes sur Android et iPhone |

## Reporté, avec la dépendance qui le justifie

| Élément | Lot |
|---|---|
| « Mes clients » consultable hors ligne | 7 — PWA avancée |
| Purge des fichiers d'import (durée de conservation, I-15) | 8 — mise en service |
| Écran de gestion des rôles et permissions (observation 2 du lot 1) | À planifier |

## Observations sur la spécification (pour le lot 0)

1. **Périmètre ADV (RG-S05)** : rien ne permet de rendre « toutes les régions » à un ADV qui a eu des affectations (I-12). Un indicateur explicite sur le compte (« toutes les régions » ou « liste ») serait plus clair que la seule présence d'affectations.
2. **Désactivations** : une région ne peut être désactivée tant qu'elle compte des clients actifs ou bloqués. En revanche, ses affectations restent en place, et un dépôt encore utilisé peut être désactivé. Règles à préciser.
3. **Motif de blocage** : visible ou non du client (I-19) ?
4. **Conservation des fichiers d'import** : durée à fixer avec la politique de conservation du §12.
