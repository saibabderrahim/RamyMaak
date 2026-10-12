# Lot 4 — Répartition : note de livraison

Périmètre (§16) : grille produit × client (PC et téléphone), « Reprendre J-1 », publication, N-03 et N-04.
Critère de fin : **répartition publiée sur une région pilote** (procédure en fin de note).

Contrat d'API et d'écrans du lot, avec les règles retenues : [lot-4-contrat-api.md](lot-4-contrat-api.md), en particulier son §7 « Précisions (implémentation) ».

Valeur par défaut de la spécification retenue : un produit sans borne reste commandable dans la limite du reste de la région (politique LIBRE, Q-03, paramètre `limits.no_limit_policy`).

## Contenu livré

| Exigence | Réalisation |
|---|---|
| EF-REG-02 | **Grille produit × client sur PC et tablette** (`Répartition` dans le menu du régional) : clients en lignes (code et nom figés, pastille « Bloqué »), recherche et filtre par catégorie ; produits en colonnes groupés par famille ; cinq lignes de synthèse épinglées Quota (Q), Σ bornes (ΣB), En attente (S), Validé (V), Reste (R) ; modes Bornes, Commandes et Reste (disponible du client) ; aujourd'hui ou demain, choix de la région du périmètre |
| EF-REG-02, RG-B05 | Repères visuels : surallocation (ΣB > Q) en orange sans blocage, reste épuisé en rouge, borne modifiée non envoyée sur fond bleu (« envoyé : 50 »), borne sous la commande en attente signalée, valeur refusée en rouge et jamais envoyée |
| EF-REG-02 | Saisie au clavier comme dans un tableur (flèches, Tab, Entrée, F2, Suppr = pas de borne, Échap), collage d'un bloc copié depuis Excel, sélection Maj+flèches ; actions de masse : appliquer une valeur à une colonne ou à une sélection, effacer une colonne, « Annuler les modifications » |
| EF-REG-03 | **Vue téléphone** : « Par produit » (choix du produit, liste des clients avec la borne, barre de synthèse fixe Q · ΣB · V · R) et « Par client » (ses produits avec la borne et le disponible) |
| RG-B09 | Enregistrement automatique en brouillon (« Enregistré à 09:12 ») ; rien n'est perdu si l'application se ferme ou passe en arrière-plan ; hors ligne, la grille reste lisible en lecture seule |
| RG-B01 à RG-B03 | Borne entière de 0 au quota de la région ; 0 = produit interdit au client ce jour ; case vide = pas de borne ; seulement les clients de la région ce jour-là (changement de région à sa date d'effet) et les produits ayant un quota publié |
| RG-B08 | **Reprendre J-1** : bornes de la dernière répartition envoyée de la région, pour les produits disponibles, plafonnées au quota du jour ; modes Écraser ou Compléter les cases vides ; résultat en brouillon |
| RG-B06, RG-B07 | **Valider et envoyer aux clients** : récapitulatif (premier envoi ou modifications, bornes modifiées, clients notifiés, produits en surallocation) puis envoi **tout ou rien**, jamais au-dessus du quota ni sous le validé ; chaque envoi incrémente la version ; l'heure limite de répartition n'est pas bloquante (§4.3) |
| §7.8 N-03, N-04 | N-03 « Vos quotas du 08/10 sont disponibles. Commandez avant 13:00. » aux comptes des clients actifs de la région (en politique OBLIGATOIRE : ceux qui ont une borne > 0) ; N-04 « Vos quotas du jour ont été mis à jour. » aux seuls clients dont une borne change ; envoyées en tâche de fond, sans doublon si la tâche est rejouée |
| §7.5, §6.1 | **ADV et Admin** : statut de répartition de chaque région dans le calendrier et sur la fiche de la journée (« Répartition en cours », « Répartition envoyée (v2) »), lien « Répartition » vers la grille en lecture seule |
| §7.6 | « Quotas du jour » du régional : état de la répartition et bouton « Répartir » ou « Voir la répartition » |
| §9.6 | **Temps réel** : la grille apparaît dès la publication des quotas par l'ADV, se relit quand un autre régional de la région la modifie ou l'envoie ; l'ADV voit le statut changer sans recharger ; la cloche du client signale N-03 / N-04 aussitôt |
| §12 | Journal « limits » : chaque envoi, avec la version, les bornes avant / après (200 au plus) et le nombre de clients notifiés |

## Vérifications

- **API** : 1 023 tests Pest, exécutés sur SQLite et sur MySQL 8 ; la CI les exécute sur MySQL 8.4.
- **PWA** : 332 tests Vitest ; lint, types et build sans avertissement. La grille est chargée à la demande (29 Ko, 10 Ko compressés) ; la lecture back-office reste hors du pré-cache.
- **Parcours réel dans Chromium** (Playwright) sur l'API, MySQL, la file d'attente et Reverb, en six sessions simultanées (ADV, régional sur PC et sur téléphone, deux clients, Admin), 44 contrôles :
  - le régional ouvre « Répartition » de demain : « En attente des quotas de l'ADV » ;
  - l'ADV publie les quotas de la région Centre : la grille apparaît chez le régional **en moins d'une seconde**, sans recharger ;
  - saisie au clavier, surallocation signalée en orange (Σ bornes 650 > quota 600), collage d'un bloc Excel ; l'ADV voit « Répartition en cours » en temps réel ;
  - « Reprendre J-1 » (compléter) reprend la borne de la veille dans la case vide et garde les saisies ;
  - envoi : récapitulatif « 3 clients seront notifiés » (client bloqué exclu) et surallocation listée ; version 1 ;
  - les deux clients reçoivent N-03 avec le bon texte, la cloche se met à jour en moins d'une seconde, l'ouverture mène à l'accueil client ;
  - l'ADV voit « Répartition envoyée (v1) » sans recharger et ouvre la grille en lecture seule ;
  - sur téléphone, le régional baisse une borne (vue « Par produit », barre de synthèse) et renvoie : « 1 client sera notifié », version 2 ; seul ce client reçoit N-04 ; la grille ouverte sur PC se met à jour ;
  - Admin : grille en lecture, mode Reste (disponible du client) ;
  - journal « limits » et refus d'accès (client 403 sur la grille, ADV 403 en écriture).

## Mettre à jour la production (v0.3.0 → v0.4.0)

Procédure habituelle ([deploiement.md](deploiement.md), « Livraisons suivantes »), entre 20:00 et 06:00 (heure d'Alger), **hors du créneau 22:55 – 23:05** (clôture des journées) :

```bash
cd /opt/ramymaak
git fetch origin
git tag v0.4.0 origin/claude/new-session-lhmuq1
git show --no-patch --oneline v0.4.0     # doit afficher le dernier commit du lot 4
git push origin v0.4.0                   # facultatif
scripts/deploy.sh v0.4.0
```

Contrôle après déploiement : l'onglet **Répartition** apparaît dans le menu du régional (après « Recharger » sur le bandeau « Nouvelle version disponible », ou un rechargement forcé de la page).

Ce qui change au déploiement, sans action manuelle :
- une nouvelle table (`client_limits`, bornes des clients) ;
- les processus de file d'attente envoient N-03 et N-04 ; le service `reverb` diffuse les nouveaux événements.

Aucune nouvelle variable d'environnement, aucun nouveau service. Retour arrière : `scripts/deploy.sh v0.3.0` (la nouvelle table est simplement ignorée).

## Publier une répartition sur une région pilote (critère de fin)

Prérequis :
- quotas de la région publiés par l'ADV (lot 3) ;
- un régional affecté à la région ;
- des clients actifs de la région ayant un compte client (Admin → Clients → client → comptes).

1. **Régional → Répartition** (sur PC de préférence) : choisir Aujourd'hui ou Demain ; la grille de la région s'affiche.
2. **Saisir les bornes**, au choix :
   - au clavier ou en collant un bloc copié depuis Excel ;
   - « Reprendre J-1 » pour repartir de la dernière répartition envoyée ;
   - laisser une case vide : le client commande dans la limite du reste de la région.

   Les bornes restent des brouillons (fond bleu) : les clients ne voient rien.
3. **« Valider et envoyer aux clients »** : contrôler le récapitulatif (clients notifiés, surallocations), puis « Envoyer aux clients ».
4. Chaque client actif de la région reçoit N-03 dans l'application.
5. Une modification après l'envoi se renvoie de la même façon : version suivante, N-04 aux seuls clients concernés.

Vérification finale : sur le téléphone d'un client de la région, la cloche signale « Quotas du … disponibles » ; côté ADV, la fiche de la journée affiche « Répartition envoyée (v1) » pour la région.

## Décisions d'implémentation à valider

| # | Décision | Raison |
|---|---|---|
| I-30 | Les clients bloqués apparaissent dans la grille (on peut leur fixer des bornes) mais ne sont pas notifiés | Un client peut être débloqué dans la journée |
| I-31 | « Reprendre J-1 » part de la dernière journée **envoyée** de la région, pas forcément la veille | RG-B08 : « dernière journée publiée » |
| I-32 | Un premier envoi sans aucune borne est permis | Politique LIBRE : chaque client commande dans la limite du reste région |
| I-33 | Destinataires de N-03 / N-04 : comptes actifs de rôle client rattachés au client | §7.8 |
| I-34 | L'heure de N-03 est l'heure limite de commande de la région, prolongation comprise | RG-A05 |
| I-35 | La grille ne compte dans ΣB que les clients de la région ce jour-là (actifs ou bloqués) | Un client inactif ou parti dans une autre région ne consomme pas le quota |

## Reporté, avec la dépendance qui le justifie

| Élément | Lot |
|---|---|
| Valeurs S et V réelles dans la grille (commandes en attente et validées) | 5 — Commandes client (déjà lues par la grille, à 0 jusque-là) |
| Écran client du catalogue avec les bornes (« Max : n ») | 5 — Commandes client |
| Import Excel des bornes (EF-REG-06, priorité S) | À planifier |
| « Ma journée » du régional (EF-REG-01) | 6 — Validation et ADV |
| Rappel N-10 et alerte ADV N-13 à l'heure limite de répartition | 7 — PWA avancée |
