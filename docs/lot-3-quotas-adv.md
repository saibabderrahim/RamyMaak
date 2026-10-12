# Lot 3 — Quotas ADV : note de livraison

Périmètre (§16) : journées, matrice, import Excel, publication, N-01 et N-02.
Critère de fin : **journée publiée de bout en bout côté ADV** (procédure en fin de note).

Contrat d'API et d'écrans du lot, avec les règles retenues : [lot-3-contrat-api.md](lot-3-contrat-api.md), en particulier son §9 « Précisions (implémentation) ».

Valeurs par défaut de la spécification retenues : quotas en UV (Q-01), pas de report des quotas non consommés (Q-06), heures limites 10:00 / 13:00 / 15:00 / 23:00 et livraison le jour ouvré suivant (Q-07), une seule équipe ADV (Q-10).

## Contenu livré

| Exigence | Réalisation |
|---|---|
| EF-ADV-01, §4.3 | **Calendrier des journées** : journées de J−7 à J+14, période précédente ou suivante, statut de chaque région en pastilles, brouillons en attente, accès direct à J et J+1. Ouverture d'une journée jusqu'à J+30 (confirmation pour un jour non ouvré) ; heures limites copiées des paramètres, date de livraison au jour ouvré suivant |
| §4.3, RG-A05 | **Fiche de la journée** : modification des heures limites (ordre strict, clôture à venir) et de la date de livraison ; prolongation de l'heure limite de répartition ou de commande pour une région, tracée, avec notification des régionaux |
| EF-ADV-02 | **Matrice des quotas** produit × région, produits groupés par famille : saisie au clavier comme dans un tableur (flèches, Tab, Entrée, F2, Suppr = 0, Échap), collage d'un bloc copié depuis Excel, enregistrement automatique en brouillon, totaux par ligne et par colonne, brouillons et valeurs sous le validé mis en évidence, « Copier depuis la journée… » (Remplacer ou Compléter), import, gabarit, publication par région, par sélection ou pour toutes les régions |
| RG-Q01 à RG-Q09, §5.8 | Brouillons invisibles des régionaux jusqu'à la publication ; publication **tout ou rien**, jamais sous la quantité déjà validée, même en accès simultané (mise à jour conditionnelle et contrainte en base) ; version incrémentée à chaque publication ; un import ne publie jamais |
| RG-Q07 | Un produit doté d'un quota publié aujourd'hui n'est désactivé que le lendemain, depuis la fiche produit comme par l'import Excel des produits ; ce jour-là il n'est plus proposé dans les quotas |
| §8.1, annexe B.1 | **Import Excel des quotas** : gabarit de la journée pré-rempli, modes Remplacer et Compléter, contrôles ligne par ligne (« Ligne 18, colonne OUEST : 12,5 n'est pas un entier positif », « … inférieur aux 300 déjà validés »), fichier annoté, aperçu valeur actuelle → nouvelle valeur, application en brouillon en une seule transaction |
| §7.6 | **« Quotas du jour »** du régional sur téléphone : aujourd'hui ou demain, régions de son périmètre, « En attente des quotas » ou quotas publiés par famille, heures limites applicables (prolongation signalée) |
| §7.8 N-01, N-02, EF-NOT-01, EF-NOT-03, EF-NOT-06 | N-01 « Quotas du 08/10 reçus — région Centre. À répartir avant 10:00. », N-02 « L'ADV a modifié 3 quotas. », notification de prolongation ; envoyées en tâche de fond au titulaire et aux suppléants actifs ce jour-là, sans doublon si la tâche est rejouée. **Centre de notifications** (lu / non lu, « Tout marquer comme lu », ouverture de l'écran concerné) et **cloche** dans l'en-tête de tous les rôles |
| §9.6 | **Temps réel** (Reverb) : quotas du régional, matrice, calendrier et cloche se mettent à jour sans recharger ; reprise des événements manqués après une coupure ; rafraîchissement toutes les 30 s si le temps réel est indisponible |
| §4.4, §9.7 | Clôture automatique de chaque journée à son heure de clôture (tâche chaque minute, rejouable) ; désactivations programmées appliquées à 00:01 |
| §12 | Journal « days » (ouverture, heures limites, prolongations, clôture) et « quotas » (chaque publication, avec avant / après par produit) dans le journal d'audit |

## Vérifications

- **API** : 911 tests Pest, exécutés sur SQLite et sur MySQL 8 ; la CI les exécute sur MySQL 8.4.
- **PWA** : 241 tests Vitest ; lint, types et build sans avertissement. Le code du back-office reste hors du pré-cache et du paquet initial du client (92 Ko compressés).
- **Parcours réel dans Chromium** (Playwright) sur l'API, MySQL, la file d'attente et Reverb, en trois sessions simultanées, 38 contrôles :
  - l'ADV (PC) ouvre la journée, saisit au clavier, colle un bloc Excel, publie la région Centre ;
  - le régional (téléphone) voit les quotas apparaître **en temps réel** (moins d'une seconde), reçoit N-01 avec le bon texte, l'ouvre depuis la cloche ;
  - l'ADV prolonge l'heure limite de répartition : le régional est notifié et voit « Répartition avant 11:15 (prolongée) » ;
  - l'ADV modifie une valeur et republie : version 2, N-02 « L'ADV a modifié 1 quota. » ;
  - import Excel : valeur invalide signalée ligne et colonne, puis fichier correct appliqué en brouillon, sans publication ;
  - Admin : calendrier et matrice en lecture, prolongation possible.
- **Revue contradictoire** en quatre axes (sécurité et périmètre, conformité, robustesse de l'API, écrans), chaque constat vérifié par un second relecteur : 18 constats confirmés (15 distincts), tous corrigés et couverts par un test qui échoue sans la correction, notamment :
  - un produit à désactivation programmée pouvait encore recevoir un quota pour le jour de sa désactivation ;
  - une prolongation pouvait finir plus tôt que l'heure de la journée si celle-ci était repoussée ;
  - des brouillons sur un produit désactivé restaient « en attente » sans pouvoir être publiés ni annulés ;
  - un ancien régional passé à un autre rôle restait destinataire des notifications ;
  - l'autorisation des canaux temps réel ne vérifiait ni l'expiration de session ni le mot de passe à changer ;
  - un arrêt brutal du planificateur pouvait bloquer la clôture des journées pendant 24 h ;
  - dans la matrice, une saisie en cours pouvait glisser vers un autre produit si la liste changeait, et un rafraîchissement en échec effaçait la grille ;
  - les événements temps réel manqués pendant une courte coupure n'étaient pas rattrapés.
- **Mise à jour depuis la v0.2.0** rejouée sur MySQL : migrations sur la base existante, retour arrière puis réapplication.

## Mettre à jour la production (v0.2.0 → v0.3.0)

Procédure habituelle ([deploiement.md](deploiement.md), « Livraisons suivantes »), entre 20:00 et 06:00 (heure d'Alger), **hors du créneau 22:55 – 23:05** (clôture des journées) :

```bash
# poste de développement : étiqueter le dernier commit publié du lot 3
git fetch origin
git tag v0.3.0 origin/claude/new-session-lhmuq1
git show --no-patch --oneline v0.3.0     # doit afficher un commit du lot 3, pas 168a898 (v0.2.0)
git push origin v0.3.0
# serveur
cd /opt/ramymaak && scripts/deploy.sh v0.3.0
```

Contrôle après déploiement : **ADV → Journées** apparaît dans le menu (après « Recharger » sur le bandeau « Nouvelle version disponible », ou un rechargement forcé de la page).

Ce qui change au déploiement, sans action manuelle :
- nouvelles tables (journées, journées régionales, quotas, notifications) ;
- le planificateur clôture les journées à leur heure et applique les désactivations programmées ;
- les processus de file d'attente envoient les notifications ;
- le service `reverb` (présent depuis le lot 1) diffuse le temps réel.

À vérifier après le déploiement :
1. `docker compose ps` : `reverb`, `worker` et `scheduler` sont `healthy`.
2. Dans le fichier `.env` du serveur, `BROADCAST_CONNECTION=reverb`, `REVERB_APP_KEY` et `REVERB_APP_SECRET` sont renseignés (générés à l'installation, §3 de deploiement.md).
3. Le frontal nginx de l'hôte relaie bien `/app` vers Reverb (bloc `location /app` de `docker/nginx-host/ramymaak.conf`). Sans cela, l'application fonctionne mais se rafraîchit seulement toutes les 30 s.

## Publier une journée de bout en bout (critère de fin)

Prérequis : référentiels chargés (lot 2), régionaux affectés à leurs régions.

1. **ADV → Journées** : « Ouvrir la journée » de demain (la veille au soir, §4.3). La matrice s'ouvre.
2. **Saisir les quotas**, au choix :
   - au clavier dans la matrice, ou en collant un bloc copié depuis Excel ;
   - « Copier depuis la journée… » pour repartir des quotas publiés d'une autre journée ;
   - « Importer » : télécharger le gabarit, le remplir, le déposer, contrôler l'aperçu, appliquer.

   Les valeurs restent des brouillons (fond coloré) : les régionaux ne voient rien.
3. **« Publier… »** : toutes les régions ou une sélection, puis confirmer.
4. Chaque régional de la région reçoit N-01 et voit ses quotas dans **Quotas** (bascule « Demain »).
5. Une modification après publication se republie de la même façon : version suivante, N-02 aux régionaux.

Vérification finale : sur le téléphone d'un régional, la cloche signale la notification, et les quantités de « Quotas du jour » sont celles de la matrice publiée.

## Décisions d'implémentation à valider

| # | Décision | Raison |
|---|---|---|
| I-21 | Le périmètre d'un ADV restreint est jugé à la date de la journée, pas à la date du jour | Une journée se prépare la veille (§4.3) |
| I-22 | Une journée s'ouvre au plus 30 jours à l'avance ; un jour non ouvré peut être ouvert après confirmation | Jours fériés et journées exceptionnelles |
| I-23 | Dans la matrice, Suppr et une cellule vide collée valent 0 ; en import Remplacer, un produit absent du fichier vaut 0 | Même règle partout : une valeur absente n'est pas « inchangée » |
| I-24 | Un produit dont la désactivation est programmée est inactif pour les quotas dès sa date d'effet ; ses brouillons sont effacés, ses quotas déjà publiés restent en lecture seule | RG-Q07 et RG-Q02 |
| I-25 | L'heure limite d'une région est la plus tardive entre la prolongation et l'heure de la journée ; une prolongation dépassée par l'heure de la journée est effacée | RG-A05 : prolonger ne raccourcit jamais |
| I-26 | Destinataires de N-01, N-02 et des prolongations : comptes actifs ayant le rôle régional et une affectation titulaire ou suppléant active ce jour-là | §7.8, §3.4 |
| I-27 | Prolongation de l'heure limite de commande : seuls les régionaux sont notifiés pour l'instant | Les clients n'ont rien à commander avant le lot 5 |
| I-28 | Le temps réel ne transporte que des identifiants ; l'application relit l'API. Sans temps réel, rafraîchissement toutes les 30 s | §9.6 |
| I-29 | Deux codes région que seuls « - » et « _ » distinguent sont refusés | Les en-têtes Excel ne les distinguent pas |

## Reporté, avec la dépendance qui le justifie

| Élément | Lot |
|---|---|
| Notifications push, rappels N-10 à N-12, regroupement anti-saturation (EF-NOT-02) | 7 — PWA avancée |
| E-mail pour N-01 (option) | À décider (paramètre « Notifications par e-mail ») |
| Suivi de la journée en temps réel (EF-ADV-03) | 6 — Validation et ADV |
| Programmation de la publication à une heure donnée (EF-ADV-08, priorité C) | À planifier |
| Notification des clients lors d'une prolongation de l'heure limite de commande | 5 — Commandes client |

## Observations sur la spécification (pour le lot 0)

1. **ADV restreint** (RG-S05 avec plusieurs équipes) : un ADV limité à certaines régions peut ouvrir une journée et modifier ses heures limites, qui valent pour toutes les régions. À trancher si Q-10 retient plusieurs équipes ADV.
2. **Quota publié sur un produit désactivé** : il reste visible et réparti le jour même (RG-Q07). Faut-il permettre à l'ADV de le ramener à 0 ?
3. **Validation (15:00)** : l'heure limite de validation n'apparaît pas encore sur l'écran du régional ; elle sera utile au lot 6.
