# Lot 5 — Commandes client : note de livraison

Périmètre (§16) : catalogue mobile, panier, contrôles, envoi, suivi, N-05 à N-07.
Critère de fin : **une commande envoyée depuis un téléphone** (procédure en fin de note).

Contrat d'API et d'écrans du lot, avec les règles retenues : [lot-5-contrat-api.md](lot-5-contrat-api.md), en particulier son §8 « Décisions proposées » (I-39 à I-63).

Valeurs par défaut de la spécification retenues :
- une commande par client et par journée (`orders.max_per_day` = 1, Admin → Paramètres) ;
- pas de montant minimum de commande (`orders.min_amount` vide).

## Contenu livré

| Exigence | Réalisation |
|---|---|
| EF-CLT-01 | **Accueil du client**, sur téléphone d'abord. Il affiche : l'état de la journée (« En attente des quotas du jour », « Commandes ouvertes jusqu'à 13:00 », avec « (prolongée) » si l'heure limite a été prolongée, « Commandes clôturées ») ; la date de livraison ; ses commandes du jour et leur statut ; le bouton « Commander », ou « Modifier ma commande » une fois le maximum atteint. Un client bloqué voit « Commande suspendue », sans motif. Une minuterie relit l'état à l'heure limite. |
| I-42 | **Journée proposée** : la première journée ouverte à la commande à partir d'aujourd'hui. Une répartition de demain envoyée la veille ouvre donc la commande de demain dès son envoi. |
| EF-CLT-02, §7.3 | **Catalogue du jour** : onglets de familles, recherche instantanée (nom, nom court, code). Sur téléphone, liste compacte ou galerie à 2 colonnes, au choix (gardé sur l'appareil) ; 3 colonnes sur tablette ; sur PC, le panier en panneau latéral. Carte produit : photo, unité de vente, prix HT et TTC, « Max : n » seulement si une borne existe, sélecteur de quantité bloqué au disponible. Produit épuisé grisé. Fiche produit avec carrousel de photos (`?produit=`). Barre du panier fixe, avec le nombre de produits et le total HT. |
| RG-C08, RG-C09 | Le client ne voit que ses produits commandables (borne > 0, quota publié, prix en vigueur) et son disponible. Ne sont jamais montrés : le quota de la région, son reste, les bornes des autres clients, ni de quantité pour un produit sans borne. |
| EF-CLT-03 | **Panier** : lignes avec photo, quantité et montant ; totaux HT, TVA et TTC « indicatifs », calculés comme le serveur ; commentaire (500 caractères). « Envoyer la commande » passe par une confirmation. Le panier reste sur l'appareil (par compte), survit à un rechargement et se met à jour entre onglets. Il est rapproché du catalogue du jour : un produit qui n'est plus disponible est retiré, une quantité au-dessus du disponible est plafonnée, et un avis le dit. |
| RG-C01 à RG-C07 | **Contrôles à l'envoi**, tout ou rien, dans l'ordre : client actif ; journée ouverte et heure limite de sa région (prolongation comprise) ; nombre de commandes du jour ; produits disponibles ; quantités dans le disponible (borne moins ce qui est déjà engagé, dans la limite du reste de la région) ; montant minimum s'il est fixé. Chaque refus est rattaché à sa ligne dans le panier. |
| RG-C07 | **Envoi sans doublon** : l'identifiant d'envoi est enregistré sur l'appareil avant la requête. Après une coupure (réponse perdue, erreur serveur), « Pas de réseau — panier conservé, réessayez » : le renvoi reprend le même identifiant et retrouve la commande déjà créée. Hors ligne, l'envoi est désactivé. |
| RG-P03, RG-P04 | Prix HT et TVA figés à l'envoi. TVA arrondie au centime par ligne ; TTC = HT + TVA. |
| I-55 | Numéro lisible par journée : `RMK-AAMMJJ-00001`, puis 00002… |
| EF-CLT-04 | **Mes commandes** : liste paginée, de la plus récente à la plus ancienne. Le détail montre une frise de statut (Envoyée → Validée → En préparation → Préparée → Expédiée, et les issues Rejetée, Annulée, Expirée), les lignes (quantités demandées, puis validées et expédiées quand elles existent), les totaux, le commentaire et l'historique. |
| RG-C05 | **Modification** jusqu'à l'heure limite. La commande est rechargée dans le panier ; le catalogue affiche le disponible **hors de cette commande**. L'enregistrement donne une nouvelle version, et les contrôles sont rejoués. Les produits gardés gardent leur prix figé ; un produit ajouté prend le prix du jour. Une modification faite entre-temps depuis un autre appareil est signalée, et la commande est rechargée. |
| §6.3 | **Annulation** par le client jusqu'à l'heure limite, après confirmation. Elle reste permise à un client bloqué. |
| EF-CLT-05 | **Recommander** : panier rebâti depuis une commande passée, sur la journée proposée. Les produits non disponibles sont ignorés et les quantités plafonnées, avec un résumé des changements. |
| §4.4 | **Clôture de la journée** : les commandes encore envoyées passent « Expirée », ce qui libère leurs quantités, et le client reçoit N-14. |
| EF-REG-04 (lecture) | **Commandes reçues** du régional : aujourd'hui ou demain, et la région quand il en suit plusieurs. Onglets avec compteurs : « À valider », « Validées », « Rejetées », « Annulées et expirées ». Liste par heure d'envoi : client (badge « Bloqué »), heure, lignes, total HT. Le détail montre le client (lien pour l'appeler) et les lignes avec leur contexte : borne, déjà validé pour ce client, quota et reste de la région. La validation arrive au lot 6. |
| §7.8 | **N-05** « Nouvelle commande — C0012 … » / « RMK-… à valider. » et **N-06** (« Commande RMK-… modifiée » / « … annulée ») aux régionaux de la région, titulaire et suppléants actifs. **N-14** « Commande RMK-… expirée » aux comptes du client. **N-07** (commande validée, ajustée, rejetée ou annulée) est prête pour les décisions du lot 6. Une prolongation de l'heure limite de commande prévient aussi les clients actifs de la région. Aucun montant dans les textes. |
| §9.6 | **Temps réel** : la liste et le détail du régional, la grille de répartition (S, pour le régional, l'ADV et l'Admin) et les écrans du client se mettent à jour sans recharger. |
| §5.8 | **Envois simultanés** (plusieurs appareils d'un client, plusieurs clients, clôture pendant un envoi) : sérialisés sans doublon ni dépassement de borne. Vérifié sous MySQL par des processus parallèles. |
| §12 | Journal « orders » : envoi (lignes, total HT), modification (avant / après), annulation, expiration (nombre et numéros). |

## Vérifications

- **API** : 1 253 tests Pest, exécutés sur SQLite et sur MySQL 8, dont 16 tests de concurrence propres à MySQL (processus parallèles : envois simultanés d'un client et de plusieurs clients, modification contre annulation, clôture pendant les écritures) ; la CI les exécute sur MySQL 8.4.
- **PWA** : 521 tests Vitest ; lint, types et build sans avertissement. Les écrans client et régional ne chargent aucun code du back-office, qui reste hors du pré-cache.
- **Parcours réel dans Chromium** (Playwright) sur l'API, MySQL, la file d'attente et Reverb : deux clients sur téléphone (Pixel 7) et le régional sur PC, 56 contrôles.
  - Le régional ouvre « Commandes reçues » de demain : rien à valider.
  - Accueil du client C0012 : journée de demain proposée, « Commandes ouvertes jusqu'à 13:00 », date de livraison. Le catalogue n'a pas le produit interdit (borne 0) ; « Max : 120 » s'affiche sur le produit borné, et rien sur le produit sans borne. Une saisie de 999 est ramenée à 120.
  - Panier : total HT 115 920,00 DA, montants indiqués comme indicatifs. Hors ligne, l'envoi est désactivé et le panier est gardé.
  - Envoi avec une réponse perdue (coupure simulée) : « Pas de réseau — panier conservé, réessayez », identifiant gardé sur l'appareil. Le renvoi aboutit sur **une seule** commande, `RMK-261020-00001`, et « Commande … envoyée. » s'affiche : **commande envoyée depuis un téléphone**.
  - Chez le régional, sans recharger : la cloche affiche N-05 en moins d'une seconde, avec le bon texte et le bon lien ; « À valider (1) » ; dans la grille de répartition, S = 100 pour C0012 × Orange.
  - Détail côté régional : « Borne : 120 », « Pas de borne », téléphone cliquable, aucune action.
  - Le maximum d'une commande par jour atteint, l'accueil propose « Modifier ma commande ». En modification, le catalogue affiche 120 disponibles (hors de la commande elle-même) ; la commande passe à 120 en version 2, et le régional reçoit N-06 « modifiée ».
  - C0047 envoie `RMK-261020-00002` puis l'annule : le régional reçoit N-05 puis N-06 « annulée », et les compteurs passent à 1 à valider et 1 annulée.
  - Clôture de la journée : la commande de C0012 passe « Expirée » sous les yeux du client, qui reçoit N-14 ; S revient à 0 dans la grille.
  - Refus d'accès : un client n'a ni les commandes du régional (403) ni celles d'un autre client (404) ; un régional n'a pas l'accueil client (403).

## Mettre à jour la production (v0.4.0 → v0.5.0)

Procédure habituelle ([deploiement.md](deploiement.md), « Livraisons suivantes »), entre 20:00 et 06:00 (heure d'Alger), **hors du créneau 22:55 – 23:05** (clôture des journées) :

```bash
cd /opt/ramymaak
git fetch origin
git tag v0.5.0 origin/claude/new-session-lhmuq1
git show --no-patch --oneline v0.5.0     # doit afficher le dernier commit du lot 5
git push origin v0.5.0                   # facultatif
scripts/deploy.sh v0.5.0
```

Contrôle après déploiement : les onglets **Commander**, **Panier** et **Commandes** apparaissent pour les clients, **Commandes** pour le régional (après « Recharger » sur le bandeau « Nouvelle version disponible », ou un rechargement forcé de la page).

Ce qui change au déploiement, sans action manuelle :
- quatre nouvelles tables : `orders`, `order_lines`, `order_status_history`, `order_number_sequences` ;
- les processus de file d'attente envoient N-05, N-06 et N-14 ; le service `reverb` diffuse les nouveaux événements ;
- la clôture automatique des journées fait expirer les commandes encore envoyées.

Aucune nouvelle variable d'environnement, aucun nouveau service. Retour arrière : `scripts/deploy.sh v0.4.0`. Les nouvelles tables sont alors ignorées ; les commandes déjà passées restent en base et réapparaissent au redéploiement de la v0.5.0.

## Envoyer une commande depuis un téléphone (critère de fin)

Prérequis :
- quotas de la journée publiés par l'ADV (lot 3) ;
- répartition de la région **envoyée** par le régional (lot 4) ;
- un client actif de la région, avec un compte client (Admin → Clients → client → comptes).

1. Sur le téléphone du client, ouvrir RamyMaak et se connecter. L'accueil affiche « Commandes ouvertes jusqu'à 13:00 » pour la journée de commande.
2. **Commander** : choisir les quantités dans le catalogue (« Max : n » quand une borne est fixée), puis « Voir le panier ».
3. **Envoyer la commande**, puis « Confirmer l'envoi » : « Commande RMK-… envoyée. » s'affiche, avec le détail de la commande.
4. Le régional de la région reçoit N-05 « Nouvelle commande — … » et voit la commande dans **Commandes → À valider**.

Jusqu'à l'heure limite, le client peut modifier ou annuler sa commande depuis **Commandes**. Une commande non validée à la clôture de la journée passe « Expirée » (la validation par le régional arrive au lot 6).

## Décisions d'implémentation à valider

Les décisions I-39 à I-63 sont détaillées au §8 du [contrat](lot-5-contrat-api.md#8-décisions-proposées). Les principales :

| # | Décision | Raison |
|---|---|---|
| I-42 | Journée proposée au client : la première ouverte à la commande à partir d'aujourd'hui | La répartition de demain peut être envoyée la veille |
| I-45 | Avec une borne, le client voit son disponible ; sans borne, aucune quantité, ni dans le catalogue ni dans les refus | RG-C09 : le reste de la région n'est jamais montré |
| I-47 | En modification, un produit gardé garde son prix figé, un produit ajouté prend le prix du jour | RG-P03 |
| I-51 | Modifier = remplacer toutes les lignes ; au moins une ligne (sinon annuler) ; numéro et heure d'envoi gardés | §6.3 |
| I-53 | Client bloqué : envoi et modification refusés, annulation permise ; sa commande envoyée expire à la clôture | §5.4 |
| I-54 | Renvoi du même identifiant : la commande existante est rendue, pour tous les comptes du client | RG-C07 |
| I-59 | Prolongation de l'heure limite de commande : les clients actifs de la région sont prévenus si la répartition est envoyée | RG-A05 |
| I-61 | Ordre des verrous commun à toutes les écritures d'une commande, clôture comprise | §5.8 : aucun interblocage sous charge |
| I-62 | En modification, le disponible affiché exclut la commande modifiée | §5.1 |

## Reporté, avec la dépendance qui le justifie

| Élément | Lot |
|---|---|
| Validation, ajustement et rejet par le régional ; N-07 déclenchée par ces décisions ; traitement ADV ; vues commandes de l'ADV et de l'Admin ; « Ma journée » du régional | 6 — Validation et ADV |
| Libellés des motifs d'ajustement et de rejet (listes paramétrables, EF-ADM-05) | 6 |
| Envoi différé hors ligne, cache des données et des photos, push (N-05 regroupé, N-07, N-14), rappel N-11, préférences de notification | 7 — PWA avancée |
