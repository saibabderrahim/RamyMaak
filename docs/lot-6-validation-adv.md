# Lot 6 — Validation et ADV : note de livraison

Périmètre (§16) : validation unitaire, ajustement, rejet, garanties d'accès simultané, traitement ADV, documents, exports.
Critère de fin : **parcours complet d'une journée en recette** (ADV → régional → client → régional → ADV), procédure en fin de note.

Contrat d'API et d'écrans du lot, avec les règles retenues : [lot-6-contrat-api.md](lot-6-contrat-api.md), en particulier son §10 « Décisions proposées » (I-67 à I-100) et son §11 « Précisions ».

Valeurs par défaut de la spécification retenues :
- heure limite de validation à 15:00, **non bloquante** (`cutoffs.validation_blocking` désactivé, Admin → Paramètres) : passé 15:00, le régional peut encore valider, et le suivi de l'ADV signale le retard ;
- motifs par défaut (rejet, ajustement, écart d'expédition, annulation) insérés au déploiement, modifiables dans Admin → Motifs.

## Contenu livré

| Exigence | Réalisation |
|---|---|
| EF-REG-05, RG-V01 | **Décision du régional**, sur téléphone d'abord, depuis **Commandes → détail**. Par ligne : demandé, borne, déjà validé pour ce client, reste région, maximum validable, et ce qui restera après validation. Sélecteur de quantité (au plus le demandé). « Valider », ou « Valider avec ajustement (n) » dès qu'une ligne est réduite ; « Rejeter » ouvre le choix du motif et un commentaire. Barre d'actions fixe ; après la décision, « Commande suivante » ouvre la plus ancienne à valider. |
| RG-V02, RG-V03 | **Plafonds contrôlés à la validation** : le reste du quota de la région et la borne du client. Un dépassement liste chaque ligne en cause, sa cause (« Quota de la région insuffisant » ou « Borne du client ») et la quantité maximale validable, avec « Appliquer n » ; **« Ajuster au maximum possible »** applique le maximum à toutes les lignes, les motifs restant à choisir. Rien n'est validé tant qu'une ligne dépasse. |
| RG-V04 | **Ajustement** : motif obligatoire sur chaque ligne réduite, commentaire de 200 caractères visible du client (obligatoire pour le motif « Autre »). Le client voit « Validée avec ajustement », la quantité demandée barrée, la quantité validée, le motif et le commentaire. |
| RG-V05 | Toutes les lignes à 0 : « Valider » est désactivé et le rejet proposé. |
| RG-V06 | **Rejet** avec motif et commentaire (500 caractères). Le client voit le libellé du motif et le commentaire, et peut renvoyer une commande avant l'heure limite de commande (I-94). |
| RG-V07 | Une commande validée n'est plus modifiable par le client ni par le régional ; seul l'ADV la fait avancer. |
| RG-V08, I-71, I-98, I-99 | **Heure limite de validation** de la région affichée sur la décision et sur « Ma journée ». Bloquante seulement si le paramètre l'exige, et alors pour la validation seule : le rejet reste possible. Prolongeable par région depuis la fiche de la journée de l'ADV ; le régional en est prévenu et la commande ouverte est relue. Chaque région garde l'ordre répartition < commande < validation : pour prolonger la commande au-delà de la validation, prolongez d'abord la validation. |
| RG-P04 | Totaux recalculés sur les quantités validées (prix et TVA figés à l'envoi). Rejet : totaux inchangés. |
| §5.8 | **Garanties d'accès simultané** : deux régionaux (titulaire et suppléant) qui valident en même temps des commandes de la même région ne dépassent jamais le quota ni la borne ; une modification du client pendant la décision est détectée (« La commande a été modifiée par le client », le brouillon repart de la commande à jour) ; un double clic ou un renvoi après une coupure n'applique la décision qu'une fois (I-92). Un seul ordre de verrous pour toutes les écritures (I-67) : aucun interblocage sous charge. Le compteur validé de la région n'est écrit que par un seul service. |
| §5.8 | **Réconciliation nocturne** à 02:10 (heure d'Alger) sur les 7 dernières journées : chaque compteur validé de la région est comparé à la somme des quantités validées ; un écart est écrit dans le journal d'erreurs, sans correction automatique. |
| EF-REG-01 | **Ma journée** remplace l'accueil du régional : aujourd'hui ou demain ; une carte par région (statut, heures limites, commandes à valider, validées, rejetées, annulées ou expirées, clients notifiés et ayant commandé) ; **« Clients sans commande »** par région, avec l'appel en un geste et le lien vers la fiche. Mise à jour en temps réel. |
| EF-REG-04 | Commandes reçues : la décision met à jour les onglets et les compteurs en temps réel, sur tous les appareils des régionaux de la région. |
| EF-ADV-04, RG-A01, RG-A02 | **File des commandes validées** de l'ADV : onglets « À préparer », « En préparation », « Préparées », « Expédiées », « Annulées » avec compteurs ; la première validée est la première listée ; filtres journée, région, dépôt, client ou numéro, produit. **Statuts en masse** (100 commandes au plus) avec le compte rendu « n appliquées / m refusées » et le motif de chaque refus. Chaque étape est horodatée et tracée avec son auteur. Le menu « Commandes » de l'ADV porte le nombre de commandes à préparer, en temps réel (N-08). |
| EF-ADV-06, RG-A03 | **Expédition** : quantités expédiées par ligne (égales au validé par défaut), motif d'écart obligatoire sous le validé, bon de livraison. L'expédié n'est jamais au-dessus du validé et ne touche jamais au quota. Le client voit l'expédié, le motif d'écart et le bon de livraison une fois la commande expédiée. |
| §6.3, I-76 | **Annulation exceptionnelle** par l'ADV d'une commande Validée ou En préparation, avec motif : les quantités validées sont restituées au quota de la région et à la borne du client, même après la clôture de la journée. Le client est prévenu (N-07 « annulée ») ; le régional ne reçoit pas d'avis d'annulation par le client. |
| RG-A04, EF-ADV-05 | **Documents** : bon de commande PDF par commande (identité du client, NIF, RC, lignes, motifs, expédié, totaux, « ANNULÉE » le cas échéant) ; **bon de préparation** de la journée en PDF ou Excel, par région ou par dépôt, par produit, avec le nombre de commandes et l'équivalent en palettes (pleines + reste). |
| EF-ADV-07, I-100 | **Exports de la journée** : Excel, CSV pour Excel français (`;`, virgule décimale) et format ERP provisoire `erp-v0` (en attendant la réponse à Q-14), une ligne par ligne de commande. « Décidée le / par » donne la validation, ou le rejet d'une commande rejetée ; Excel et CSV portent la mention des montants indicatifs. Les cellules qui commenceraient une formule sont neutralisées. |
| EF-ADV-03 | **Suivi de la journée** (ADV, Admin en lecture) : chiffres clés par statut, clients ayant commandé, quotas validés ; tableau des régions avec heures limites, commandes, dernière validation et alertes (« Répartition en retard », « Validation en retard ») ; matrice produit × région validé / quota, avec les quantités en attente et les produits épuisés en évidence. Boutons « Bon de préparation » et « Export ». Mise à jour en temps réel. |
| EF-ADM-05 | **Motifs** (Admin → Motifs) : un onglet par type, création, libellé, ordre, commentaire obligatoire ou non, activation. Le code n'est plus modifiable après création ; un motif désactivé garde son libellé sur les commandes passées. |
| I-87 | L'Admin voit la file, le détail des commandes et le suivi de la journée en lecture, et peut exporter ; il ne change aucun statut. |
| §7.8 | **N-07** au client (« Commande RMK-… validée », « … validée (n lignes ajustées) », « … rejetée », « … annulée ») et **N-09** (« Commande RMK-… expédiée »), sur tous les comptes actifs du client, sans montant. |
| §9.6 | **Temps réel** : décisions, statuts, expédition et annulations mettent à jour sans recharger la grille de répartition, les quotas du jour, « Ma journée », les commandes reçues, la file et le suivi de l'ADV, et les écrans du client. Un changement de statut en masse n'envoie qu'un événement par journée (I-97). |
| §12 | Journal « orders » : validation (lignes demandées et validées, motifs), rejet, annulation ADV (quantités restituées), changements de statut, expédition (avant / après) ; journal « reasons » pour les motifs. |

## Vérifications

- **API** : 2 031 tests Pest, exécutés sur SQLite et sur MySQL 8, tous verts, dont les tests de concurrence propres à MySQL : deux régionaux qui valident en même temps sur le même produit, validation contre modification du client, validation contre publication d'une borne, statuts en masse contre annulation, clôture pendant les décisions. La CI les exécute sur MySQL 8.4.
- **PWA** : 804 tests Vitest ; lint, types et build sans avertissement. Les écrans du client et du régional ne chargent aucun code du back-office.
- **Parcours complet d'une journée dans Chromium** (Playwright), sur l'API, MySQL, la file d'attente et Reverb : deux clients sur téléphone (Pixel 7), le régional et l'ADV sur PC, 64 contrôles.
  - Deux commandes arrivent chez le régional, « À valider », la plus ancienne en premier. Il rejette B avec le motif « Quota épuisé » et un commentaire ; le client voit le motif, reçoit N-07 « rejetée » et renvoie une commande C.
  - Le régional valide A telle quelle (version 2, totaux sur les quantités validées) ; la grille de répartition affiche V = 50 aussitôt ; le client reçoit N-07 « validée ».
  - Sur C, au-dessus du reste région : « Quota de la région insuffisant : 10 au plus », rien n'est validé. « Ajuster au maximum possible » ramène la ligne à 10 ; sans motif, la validation est bloquée ; avec « Quota insuffisant » et un commentaire, « Commande … validée avec 1 ligne ajustée ». Le quota de la région est épuisé (V = Q = 60). Le client voit « Validée avec ajustement », 40 → 10, le motif et le commentaire, et reçoit N-07 « 1 ligne ajustée » ; aucun quota ni reste région dans sa réponse (RG-C09).
  - ADV : la file « À préparer » liste A puis C ; passage en préparation des deux en masse, « 2 appliquées ». Le client voit « En préparation ».
  - Sur A : 90 expédiés sur 100 avec le motif « Rupture de stock », bon de livraison BL-2610-0042, puis « Préparée » et « Expédiée ». Le client voit l'expédié, le motif et le bon, et reçoit N-09.
  - Documents : bon de commande PDF, bon de préparation PDF et Excel, export CSV (BOM, `;`, une ligne par ligne de commande) et ERP (`erp-v0`).
  - Suivi : V = Q = 60 et produit épuisé pour la région ; 1 expédiée, 1 en préparation, 1 rejetée, 2 clients ayant commandé.
  - Annulation de C par l'ADV : les 10 sont restitués, le produit n'est plus épuisé ; le client reçoit N-07 « annulée », le régional aucun avis d'annulation par le client.
  - Ma journée du régional : compteurs à jour, clients sans commande avec l'appel ; tient sur un téléphone de 360 px.
  - Clôture : les statuts expédié, annulé et rejeté sont gardés ; la réconciliation ne trouve aucun écart ; une commande expédiée ne peut plus être annulée (409).
  - Refus d'accès : le client n'a ni la validation, ni la file ADV, ni les motifs (403) ; le régional n'a ni la file ADV ni l'export (403) ; l'ADV ne peut pas valider (403).
- **Relecture des écrans sur les captures du parcours, au regard de Design.md** : 5 défauts corrigés.
  - Dans tout le back-office, les en-têtes des colonnes de quantités et de montants restaient alignés à gauche.
  - Le statut d'un client actif était vide sur la fiche commande de l'ADV.
  - Les filtres de la file de l'ADV étaient tronqués.
  - Le message « Commande … envoyée. » réapparaissait au rechargement, même une fois la commande validée.
  - Le numéro de commande se coupait dans le titre sur téléphone.
- **Revue contradictoire finale** en six axes (sécurité et périmètre, concurrence, conformité, robustesse de l'API, écrans, conformité à Design.md), chaque constat vérifié par un second relecteur : 21 constats confirmés (17 problèmes distincts), tous corrigés et couverts par un test, sauf deux défauts de mise en page vérifiés sur les captures.
  - Sur un appareil partagé, le brouillon de décision d'un régional était proposé au compte suivant, qui l'aurait enregistré en son nom.
  - Un export Excel interrompu (onglet fermé) laissait une copie des données de la journée sur le disque du serveur.
  - La clôture pouvait verrouiller les commandes envoyées de la journée suivante et s'interbloquer avec une décision ; elle passe désormais par un index propre à sa journée et se rejoue sur interblocage.
  - La réconciliation nocturne parcourait tout l'historique des commandes au lieu des 7 jours examinés.
  - Une prolongation de l'heure de commande pouvait dépasser l'heure de validation de la région : les dernières commandes n'auraient plus été validables (I-99).
  - L'export appelait « Validée le / par » la date et l'auteur d'un rejet ; le CSV n'avait pas la mention des montants indicatifs ; un paramètre de format malformé donnait une erreur serveur au lieu d'un refus.
  - Après un rejet refusé, la boîte de rejet se rouvrait à la validation suivante ; après un refus « heure limite passée », la validation restait bloquée même une fois l'heure prolongée.
  - « Commande suivante » manquait quand la commande était ouverte depuis une notification, et pouvait proposer une commande déjà décidée par le suppléant.
  - La file et le compteur « Commandes » de l'ADV ne suivaient pas les validations des commandes de demain ; le suivi de la journée se relisait une fois par région à chaque événement.
  - Sur PC, le tableau des lignes de la fiche commande de l'ADV était tronqué : prix, montants et parfois quantités validées hors de l'écran.
  - La barre d'actions fixe masquait le bas de l'historique ; un échec de chargement des motifs ne proposait pas de réessayer ; une erreur de quantité expédiée n'était pas reliée à son champ.
- **Dépendances** : deux avis de sécurité publiés depuis la v0.5.0 (`zod`, élevé ; `fast-uri`, modéré) ; versions corrigées, l'audit de la CI est vert.

## Mettre à jour la production (v0.5.0 → v0.6.0)

La v0.5.0 (lot 5) doit être déployée et validée avant ; à défaut, la v0.6.0 l'inclut et peut être déployée directement depuis la v0.4.0.

Procédure habituelle ([deploiement.md](deploiement.md), « Livraisons suivantes »), entre 20:00 et 06:00 (heure d'Alger), **hors du créneau 22:55 – 23:05** (clôture des journées) :

```bash
cd /opt/ramymaak
git fetch origin
git tag v0.6.0 origin/claude/new-session-lhmuq1
git show --no-patch --oneline v0.6.0     # doit afficher le dernier commit du lot 6
git push origin v0.6.0                   # facultatif
scripts/deploy.sh v0.6.0
```

Contrôle après déploiement (après « Recharger » sur le bandeau « Nouvelle version disponible », ou un rechargement forcé de la page) :
- le régional arrive sur **Ma journée** et voit « Valider » et « Rejeter » sur une commande envoyée ;
- l'ADV a **Commandes** dans son menu, et **Suivi** sur la fiche d'une journée ;
- l'Admin a **Motifs** dans son menu, avec les motifs par défaut.

Ce qui change au déploiement, sans action manuelle :
- une nouvelle table `reason_codes`, remplie des motifs par défaut ; deux colonnes ajoutées (commentaire d'ajustement par ligne, prolongation de l'heure limite de validation par région), une contrainte « expédié ≤ validé » sur les lignes et deux index sur les commandes (file de l'ADV, clôture) ;
- le service `scheduler` lance chaque nuit à 02:10 la réconciliation des quotas (`ramymaak:reconcile-quotas`) ; un écart apparaît dans `docker compose logs scheduler` ;
- les processus de file d'attente envoient N-07 et N-09 ; le service `reverb` diffuse les nouveaux événements, dont le canal de l'ADV.

Un changement de pratique pour l'ADV : pour prolonger l'heure de commande d'une région au-delà de son heure de validation, prolonger d'abord la validation (le message le rappelle).

Aucun nouveau service. Une variable facultative : `DOCUMENTS_TEMP_DIR`, dossier des fichiers temporaires des exports Excel (par défaut, le dossier temporaire du système). Retour arrière : `scripts/deploy.sh v0.5.0`. La nouvelle table et les nouvelles colonnes sont alors ignorées ; les décisions déjà prises restent en base.

## Parcours complet d'une journée (critère de fin)

Prérequis : une journée ouverte, les quotas publiés par l'ADV (lot 3), la répartition de la région envoyée par le régional (lot 4), deux clients actifs de la région avec un compte client.

1. **ADV** : la journée est ouverte et les quotas publiés ; le régional a envoyé sa répartition.
2. **Clients** : sur leur téléphone, chacun envoie une commande (lot 5). Le régional reçoit N-05.
3. **Régional** : **Commandes → À valider**, ouvrir la plus ancienne. Vérifier par ligne la borne, le déjà validé, le reste région et le maximum validable.
   - « **Valider** » : la commande passe « Validée » ; le client reçoit N-07 « validée ».
   - Sur une commande au-dessus du reste région : « Valider » est refusé ligne par ligne, avec le maximum. « **Ajuster au maximum possible** », choisir le motif de chaque ligne réduite (commentaire facultatif), puis « Valider avec ajustement (n) ». Le client voit « Validée avec ajustement » et le motif.
   - « **Rejeter** » avec un motif : le client voit le motif et peut renvoyer une commande avant l'heure limite de commande.
4. **ADV** : **Commandes → À préparer**, tout sélectionner, « Passer en préparation ». Ouvrir une commande : saisir un écart d'expédition (quantité et motif) et le bon de livraison, « Enregistrer l'expédition », puis « Marquer préparée » et « Marquer expédiée ». Le client voit « Expédiée », l'écart et le bon de livraison, et reçoit N-09.
5. **ADV** : **Journées → la journée → Suivi** : chiffres de la journée, alertes, matrice validé / quota. Télécharger le bon de préparation (PDF ou Excel) et l'export (Excel, CSV ou ERP) ; sur une commande, « Bon de commande (PDF) ».
6. **Régional** : **Ma journée** montre les compteurs à jour et les clients sans commande, avec l'appel en un geste.

## Décisions d'implémentation à valider

Les décisions I-67 à I-100 sont détaillées au §10 du [contrat](lot-6-contrat-api.md#10-décisions-proposées). Les principales :

| # | Décision | Raison |
|---|---|---|
| I-67 | Ordre des verrous commun à toutes les écritures sur les commandes, les bornes et les quotas | §5.8 : aucun interblocage, jamais de dépassement |
| I-69 | Un dépassement liste toutes les lignes en cause, avec la cause et le maximum validable | RG-V02 : « quantité maximale validable » par produit |
| I-70 | Client bloqué : validation refusée, rejet permis | §5.4 |
| I-71 | Heure limite de validation non bloquante par défaut ; bloquante, elle n'empêche que la validation | RG-V08 |
| I-72 | Motif obligatoire sur chaque ligne réduite ; toutes les lignes à 0 → rejeter | RG-V04, RG-V05 |
| I-76 | L'ADV peut annuler une commande Validée ou En préparation, même après la clôture ; les quantités sont restituées | §6.3 |
| I-77 | Quantité expédiée égale au validé par défaut, modifiable en préparation, figée une fois expédiée (seul le bon de livraison change) | RG-A03 |
| I-78 | Statuts en masse : une étape à la fois, 100 commandes au plus, résultat partiel | RG-A02 |
| I-80 | N-08 sans notification enregistrée : compteur et liste en temps réel pour l'ADV | Éviter des centaines d'avis par jour |
| I-83 | Documents et exports produits à la demande, en flux | Volumes du §13 ; file d'attente si les tests de charge du lot 8 l'exigent |
| I-84 | Bon de préparation par région (ou par dépôt), par produit, sans détail par client | RG-A04 |
| I-85 | Format ERP provisoire `erp-v0` | Q-14 en attente |
| I-88 | « Clients sans commande » : clients notifiés de la répartition, actifs, sans commande envoyée ou validée | EF-REG-01 |
| I-92 | Une décision renvoyée (double clic, coupure) n'est appliquée qu'une fois | §5.8 |
| I-93 | Réconciliation nocturne : rapport seul, aucune correction automatique | Un écart doit être compris avant d'être corrigé |
| I-99 | Chaque région garde l'ordre répartition < commande < validation ; prolonger la commande au-delà de la validation exige de prolonger d'abord la validation | Une commande envoyée pendant la prolongation doit rester validable (RG-V08) |

## Reporté, avec la dépendance qui le justifie

| Élément | Lot |
|---|---|
| Notifications push (N-07, N-09), rappels N-10 à N-13, cache hors ligne et envoi différé | 7 — PWA avancée |
| Tableaux de bord et indicateurs au-delà du suivi de la journée, tests de charge (documents et exports en file d'attente si nécessaire), supervision de l'alerte de réconciliation | 8 |
| Statut « Livrée » | V2 |
| Format définitif de l'export ERP | Réponse à Q-14 |
