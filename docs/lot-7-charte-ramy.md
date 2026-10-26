# Lot 7 — Charte Ramy : note de livraison

Périmètre : nouvelles interfaces aux couleurs de Ramy, proposées en maquettes interactives puis validées écran par écran (8 écrans sur 8, direction « A, Rouge Ramy »). Les règles métier, l'API et les données ne changent pas.

Système retenu : [DESIGN.md](../Design.md) (neutres, couleurs d'état, espacements, rayons) teinté vers le rouge du logo Ramy, `#E30613`, mesuré sur le fichier fourni ; police Rubik, auto-hébergée pour le mode hors ligne (alphabet latin ; l'arabe viendra avec la V1.1).

## Contenu livré

| Écran | Réalisation |
|---|---|
| Toute l'application | Couleurs, police, boutons, champs, avis et pastilles refaits à la source (`src/index.css`, `src/shared/ui`) : tous les écrans, Admin et référentiels compris, prennent la charte sans changer leur mise en page. Action principale rouge ; action destructrice encadrée en rouge foncé, pour ne pas se confondre avec elle ; anneau de focus noir, distinct du rouge des erreurs. Têtes de tableau en casse de phrase. |
| Coquille, client et régional | Barre rouge au logo blanc, onglets du bas avec pictogramme et libellé, pastille du panier et des commandes. Sur téléphone, « Me déconnecter » devient un pictogramme (le libellé reste annoncé). |
| Coquille, ADV et Admin | Barre latérale groupée par rubrique (Opérations, Référentiels, Échanges, Administration) sur grand écran ; menu repliable sur tablette et téléphone. |
| Icônes, manifeste | Icônes de l'application et favicon au logo Ramy sur fond rouge ; couleur du thème `#E30613`. |
| Connexion | Logo Ramy et photo de la bouteille Ramy Extra Hibiscus Menthe à côté du formulaire (en bandeau sur téléphone). Mêmes champs, mêmes messages. |
| Accueil client | **Fil de la journée** : heure limite (avec « prolongée »), validation, livraison, et l'heure courante sur l'horloge du serveur ; temps restant avant l'heure limite (« 2 h 51 pour envoyer votre commande »). Bloc **« Mis en avant »** : les produits du jour marqués « mis en avant » par l'ADV, avec photo et quantité liée au panier ; il lit le drapeau déjà renvoyé par l'API du jour. |
| Catalogue | Liste du téléphone en un seul bloc à filets ; quantité à droite ; borne et maximum au-dessus. Produit sans photo : son format en grand (« 1 L ») au lieu d'un pictogramme. Fiche produit : photo entière, caractéristiques alignées. |
| Panier | Lignes en un seul bloc ; sur téléphone, total TTC et bouton d'envoi toujours visibles au-dessus des onglets. |
| Ma journée (régional) | Fil de la journée dans chaque carte de région (répartition, commande, validation, clôture, prolongations et validation bloquante) ; chiffres en colonnes (validées, rejetées, annulées ou expirées ; clients notifiés, ayant commandé, sans commande) ; alerte **« Répartition en retard »** quand les quotas sont publiés, la répartition non envoyée et son heure limite passée ; téléphones groupés par paires (« 0770 34 56 78 »), bouton « Appeler » secondaire. Les phrases d'origine restent lues par les lecteurs d'écran. |
| Décision (régional) | Pour chaque ligne : demandé, borne, déjà validé, reste région et maximum validable côte à côte, puis la quantité et le reste après validation ; motif et commentaire dans un encadré dès qu'une ligne est réduite ; barre d'actions « Rejeter » / « Valider ». |
| Suivi de la journée (ADV) | Fil des heures limites ; chiffres clés en **chaîne** (à valider → à préparer → en préparation → préparées → expédiées), fins de parcours à part ; jauges des clients ayant commandé et des quotas validés ; une jauge dans chaque case de la matrice produit × région. |
| File des commandes (ADV) | Barre de traitement en masse collée en bas de l'écran ; sur écran étroit, chaque commande en bloc (case, numéro et total, client et validation, région). |

## Écarts par rapport aux maquettes validées

- **« Valider » n'est pas grisé** quand une ligne dépasse son maximum validable. Le serveur reste seul juge (le maximum affiché peut être dépassé par une relecture en direct) et les tests du lot 6 fixent cette règle. La raison est écrite sous la barre d'actions : « Une ligne dépasse son maximum validable : appliquez le maximum proposé, sinon la validation sera refusée. » À trancher si le blocage est souhaité.
- **Alerte « Répartition en retard » du régional** : calculée sur l'heure de l'appareil, pour la journée en cours seulement (l'API de « Ma journée » ne renvoie pas d'alerte). Indicative ; le suivi ADV garde l'alerte calculée par le serveur.
- **Fil de l'accueil client** : la validation n'a pas d'heure (l'API du jour ne la renvoie pas au client), d'où « Ensuite ».
- Pastilles d'état gardées arrondies (les tests du calendrier des journées en dépendent).

## Fichiers de marque provisoires

Le logo blanc (`public/brand/ramy-logo-blanc.png`) et les icônes (`public/icons/*`, `public/favicon.svg`) sont dérivés du logo fourni. À remplacer par les fichiers officiels de Ramy dès qu'ils sont disponibles, sous les mêmes noms. La photo de la connexion est `public/brand/bouteille-hibiscus-menthe.webp`.

## Défauts corrigés au passage

- Écran de décision du régional vide dans les navigateurs récents : `window.scrollTo` y renvoie une promesse, qu'un effet React ne doit pas renvoyer (« destroy is not a function » au départ de l'écran).
- Page plus large que l'écran sur téléphone dans le back-office : les textes réservés aux lecteurs d'écran des tableaux sortaient de leur zone de défilement.

## Vérifications

- **PWA** : 799 tests Vitest, inchangés et tous verts ; lint (oxlint), types et build sans avertissement ; le back-office reste hors du pré-cache.
- **Parcours réel dans Chromium** (Playwright) sur l'API Laravel (SQLite) avec les données de démonstration : journée ouverte par l'ADV, quotas publiés, répartition envoyée par le régional, commandes de C0012 et C0047, une validée. Captures inspectées sur téléphone (390 px) et PC (1 440 px) : connexion, accueil, catalogue, fiche produit, panier en modification, « Ma journée », décision, suivi de la journée, file des commandes, menu du back-office, comptes de l'Admin. Aucune erreur dans la console, aucun défilement horizontal.

## Mettre à jour la production (v0.6.0 → v0.7.0)

La charte est fusionnée avec les correctifs de la revue du lot 6 dans la branche `claude/new-session-lhmuq1` : la v0.7.0 porte les deux ([lot-6-validation-adv.md](lot-6-validation-adv.md)). La branche `claude/epic-hamilton-2tjasz` est gardée telle quelle.

Procédure habituelle ([deploiement.md](deploiement.md), « Livraisons suivantes »), entre 20:00 et 06:00 (heure d'Alger), hors du créneau 22:55 – 23:05 :

```bash
cd /opt/ramymaak
git fetch origin
git tag v0.7.0 origin/claude/new-session-lhmuq1
git show --no-patch --oneline v0.7.0     # doit afficher le dernier commit de la branche (lot 6 et charte)
git push origin v0.7.0                   # facultatif
scripts/deploy.sh v0.7.0
```

Contrôle après déploiement : barre rouge et logo Ramy sur tous les écrans (après « Recharger » sur le bandeau « Nouvelle version disponible », ou un rechargement forcé). Sur un téléphone où l'application est installée, la nouvelle icône peut demander de la réinstaller.

La charte seule n'ajoute ni migration, ni variable d'environnement, ni service ; les changements du lot 6 sont décrits dans sa note. Retour arrière : `scripts/deploy.sh v0.6.0`.
