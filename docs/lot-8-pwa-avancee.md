# Lot 8 — PWA avancée : note de livraison

Périmètre (§16, lot 7 de la spécification, numéroté 8 ici car le lot 7 est la charte Ramy) : hors ligne, envoi différé, notifications push, rappels.
Critère de fin : **tests validés sur appareils réels** (liste en fin de note, à faire sur le terrain).

Contrat d'API et d'écrans du lot, avec les règles retenues : [lot-8-contrat-api.md](lot-8-contrat-api.md), en particulier son §10 « Décisions proposées » (I-101 à I-114).

Valeurs par défaut retenues :
- notifications push activées (`notifications.push_enabled`, Admin → Paramètres), dès que les clés VAPID sont posées sur le serveur ;
- plage sans push de 21:00 à 06:00 (paramètres existants) : les push y sont abandonnés, les notifications restent dans la cloche ;
- rappels 30 min avant l'heure limite de répartition, 1 h avant celle de commande, 30 min avant celle de validation.

## Contenu livré

| Exigence | Réalisation |
|---|---|
| EF-NOT-01, §7.8 | **Notifications push** (Web Push, clés VAPID) : N-01 à N-05, N-07, N-09 à N-12, N-14 et la prolongation d'une heure limite partent aussi sur les appareils abonnés, application fermée. N-06, N-13 et N-15 restent dans l'application. Toute notification reste dans la cloche ; le push y renvoie. Appui sur la notification : l'application s'ouvre sur l'écran concerné. |
| EF-NOT-02, I-109 | **Anti-saturation** par compte et par type : un push tout de suite, puis au plus un push groupé toutes les 5 minutes (« 3 nouvelles commandes à valider » pour le régional, « n nouvelles notifications » sinon). **Plage sans push** (21:00 – 06:00) : rien ne part, la cloche garde tout. |
| EF-NOT-03 | Textes du push sans montant ni donnée commerciale : ceux de la notification enregistrée. |
| EF-NOT-04 | **Préférences** (Profil → Notifications) : notifications de cet appareil (activer, désactiver, ou la marche à suivre si le navigateur les bloque) ; interrupteur **Rappels** pour les clients et les régionaux. |
| EF-NOT-05 | **iPhone et iPad** : guide d'installation sur l'écran d'accueil (iOS 16.4 ou plus récent), condition des notifications sur ces appareils. |
| §8 | **Invitation** « Recevez vos notifications » sur l'accueil du client et « Ma journée » du régional, une fois par compte et par appareil, avec « Activer » et « Plus tard ». |
| §4.3 | **Rappels** chaque minute, sur l'heure limite effective de la région (prolongation comprise), une fois par heure limite : **N-10** aux régionaux, 30 min avant la limite de répartition si elle n'est pas envoyée ; **N-11** aux clients notifiés sans commande, 1 h avant la limite de commande ; **N-12** aux régionaux, 30 min avant la limite de validation s'il reste des commandes à valider ; **N-13** « Répartition en retard » à l'ADV de la région et à l'Admin, une fois la limite passée. Une prolongation déclenche un nouveau rappel. Rien après l'heure limite (N-13 excepté). |
| N-15 | **Sécurité du compte** : après une réinitialisation du mot de passe par l'Admin ou par la commande `ramymaak:reset-password`, le compte reçoit l'avis dans l'application (jamais le mot de passe). |
| EF-AUTH-06, I-110, I-113 | **Appareils connectés** (Profil) : chaque appareil avec son navigateur, son adresse, sa dernière activité, « Cet appareil », « Reste connecté » et « Notifications activées » ; « Déconnecter » un autre appareil, avec confirmation. « Rester connecté » est désormais **propre à chaque appareil** : un appareil déconnecté depuis la liste ne se reconnecte plus par son cookie, sans toucher aux autres. **Changer son mot de passe** déconnecte ses autres appareils et garde « Rester connecté » sur celui-ci. |
| I-103 | Un appareil partagé ne reçoit que les notifications du dernier compte ouvert ; les abonnements sont supprimés à la déconnexion, à la déconnexion de tous les appareils, à la désactivation du compte et à la réinitialisation du mot de passe. |
| EF-CLT-07, §9.5, I-101 | **Lecture hors ligne** : l'accueil, le catalogue, le panier et les commandes du client ; « Ma journée », les quotas, la répartition, les commandes reçues et « Mes clients » du régional ; le centre de notifications. Les données sont enregistrées sur l'appareil pour le compte connecté (7 jours au plus) et effacées à la déconnexion. Bandeau « Hors ligne — données de 09:42 » ; un écran jamais ouvert sur l'appareil le dit. Le back-office n'est jamais enregistré. **Photos** des produits gardées 30 jours (500 au plus). |
| §9.5, I-102, I-112 | **Envoi différé** : « Envoyer la commande » marche hors ligne. La commande attend sur l'appareil (« Commandes en attente d'envoi » sur l'accueil et dans « Mes commandes », pastille de l'onglet), puis part d'elle-même au retour du réseau, à la réouverture de l'application, toutes les 30 s, et en arrière-plan sur Android (Background Sync, avec une notification « Commande RMK-… envoyée »). Une commande refusée par le serveur (heure limite passée, quantité au-delà du disponible) l'est avec le motif, puis « Reprendre dans le panier » ou « Retirer ». Jamais de doublon : le serveur reconnaît un renvoi. Une seule commande en attente par journée. La déconnexion avec une commande en attente demande confirmation. |
| D-13 | Hors ligne, les autres écritures (modification et annulation d'une commande, « Recommander », bornes, décisions, réglages) sont désactivées, avec la raison. |
| §12 | Journal « auth » : préférences, déconnexion d'un appareil. Purge nocturne (03:10) des abonnements en échec ou inutilisés depuis 60 jours. |

## Vérifications

_À compléter._

## Mettre à jour la production (v0.7.0 → v0.8.0)

Procédure habituelle ([deploiement.md](deploiement.md), « Livraisons suivantes »), entre 20:00 et 06:00 (heure d'Alger), **hors du créneau 22:55 – 23:05** (clôture des journées) :

```bash
cd /opt/ramymaak
git fetch origin
git tag v0.8.0 origin/claude/new-session-lhmuq1
git show --no-patch --oneline v0.8.0     # doit afficher le dernier commit de la branche (lot 8)
git push origin v0.8.0                   # facultatif
scripts/deploy.sh v0.8.0
```

Après ce déploiement, tout marche comme avant, sans push : il manque les clés VAPID, à générer **une seule fois** (les changer désabonne tous les appareils) :

```bash
cd /opt/ramymaak
docker compose run --rm --no-deps app php artisan webpush:vapid --show
```

La commande affiche deux lignes, `VAPID_PUBLIC_KEY=…` et `VAPID_PRIVATE_KEY=…`. Les recopier dans `/opt/ramymaak/.env` (les lignes existent déjà, vides, si le fichier vient de `.env.example` ; sinon les ajouter), avec l'adresse de contact du service :

```bash
VAPID_SUBJECT=mailto:contact@votre-domaine.dz
VAPID_PUBLIC_KEY=B…
VAPID_PRIVATE_KEY=…
```

Puis relancer les services de l'application, qui relisent `.env` au démarrage :

```bash
docker compose up -d --force-recreate --wait app worker scheduler reverb
```

La clé privée ne doit jamais quitter le serveur ; la sauvegarder avec `.env`.

Contrôle après déploiement (après « Recharger » sur le bandeau « Nouvelle version disponible », ou un rechargement forcé) :
- **Profil** affiche « Notifications » (avec « Activer les notifications sur cet appareil ») et « Appareils connectés » ;
- sur un téléphone Android, « Activer », puis depuis le compte ADV publier un quota : le régional reçoit la notification, application fermée ;
- Admin → Paramètres → Notifications : case « Notifications push » cochée.

Ce qui change au déploiement, sans action manuelle :
- trois nouvelles tables (`push_subscriptions`, `push_throttles`, `remember_tokens`) et une colonne `users.reminders_enabled` (rappels activés pour tous) ;
- le service `scheduler` lance les rappels chaque minute (`ramymaak:send-reminders`) et la purge des abonnements chaque nuit à 03:10 (`ramymaak:purge-push-subscriptions`) ;
- les processus de file d'attente envoient les push : le serveur doit pouvoir joindre en HTTPS les services push des navigateurs (`fcm.googleapis.com` pour Chrome et Android, `*.push.apple.com` pour iPhone, `*.push.services.mozilla.com` pour Firefox, `*.notify.windows.com` pour Edge) ;
- **« Rester connecté »** : les appareils déjà connectés avec « Rester connecté » le restent jusqu'à la fin de leur session ; ensuite, une nouvelle connexion est demandée une fois, et le nouveau « Rester connecté » est propre à l'appareil.

Variables facultatives : `PUSH_ALLOWED_HOSTS` (services push acceptés, séparés par des virgules ; la liste par défaut couvre Chrome, Edge, Firefox et Safari) et `PUSH_TRANSPORT=log` (recette : push écrits dans `storage/logs/push.log` au lieu d'être envoyés).

Retour arrière : `scripts/deploy.sh v0.7.0`. Les nouvelles tables sont ignorées ; les appareils « Rester connecté » depuis la v0.8.0 devront se reconnecter.

## Tests sur appareils réels (critère de fin)

À faire sur le terrain après le déploiement et la pose des clés. Chaque ligne se coche quand le résultat attendu est obtenu.

| Appareil | Vérification | Résultat attendu |
|---|---|---|
| Android d'entrée de gamme, Chrome | Installer l'application (Profil → Installer), puis Profil → Notifications → « Activer » | « Activées » ; l'appareil apparaît dans « Appareils connectés » avec « Notifications activées » |
| | Fermer l'application ; l'ADV publie les quotas, le régional envoie la répartition | N-03 « Répartition du … » s'affiche ; l'appui ouvre l'accueil du client |
| | Le régional valide la commande du client | N-07 « Commande RMK-… validée » ; l'appui ouvre la commande |
| | Une heure avant l'heure limite de commande, sans commande | N-11 « Rappel : commande avant … » |
| | Mode avion, envoyer une commande, fermer l'application, retrouver le réseau | Notification « Commande RMK-… envoyée » sans rouvrir l'application ; la commande est chez le régional |
| | Mode avion, ouvrir le catalogue et « Mes commandes » | Données affichées avec « Hors ligne — données de HH:MM » ; photos déjà vues affichées |
| iPhone, iOS 16.4 ou plus récent | Profil → Notifications dans Safari | Guide d'installation |
| | Installer, ouvrir depuis l'icône, « Activer » | « Activées » |
| | Le régional valide une commande | N-07 reçu |
| | Mode avion, envoyer une commande, réseau retrouvé, rouvrir l'application | La commande part à la réouverture (pas d'envoi en arrière-plan sur iPhone) |
| | Mode avion, ouvrir l'accueil et les commandes | Lecture hors ligne avec le bandeau |
| PC, Chrome ou Edge (régional) | « Activer », puis trois clients envoient une commande en moins de 5 minutes | Un push « Nouvelle commande », puis un push groupé « 2 nouvelles commandes à valider » |
| | 30 min avant l'heure limite de validation, avec des commandes à valider | N-12 « Rappel : n commandes à valider » |
| | Wi-Fi coupé, ouvrir « Répartition » | Grille lue hors ligne, avec le bandeau |
| Tous | Entre 21:00 et 06:00 | Aucun push ; les notifications sont dans la cloche |

## Décisions d'implémentation à valider

Les décisions I-101 à I-114 sont détaillées au §10 du [contrat](lot-8-contrat-api.md#10-décisions-proposées). Les principales :

| # | Décision | Raison |
|---|---|---|
| I-101 | Données hors ligne enregistrées par compte dans le navigateur (IndexedDB), sur une liste d'écrans de terrain ; jamais l'API dans le cache du service worker | Effacées à la déconnexion, jamais partagées entre deux comptes du même téléphone |
| I-102 | Toute nouvelle commande client passe par la boîte d'envoi, en ligne comme hors ligne | Un seul chemin, sans doublon (identifiant d'envoi) |
| I-103 | Un abonnement push appartient au dernier compte ouvert sur l'appareil | Appareil partagé |
| I-104 | Abonnements limités aux services push connus | Le serveur n'écrit jamais à une adresse choisie librement |
| I-105 | Plage sans push : push abandonnés, pas reportés | Pas d'avalanche à 06:00 ; la cloche garde tout |
| I-107 | Rappels une fois par heure limite effective ; une prolongation en déclenche un nouveau | §4.3 |
| I-109 | Un push immédiat, puis au plus un push groupé toutes les 5 minutes, par compte et par type | EF-NOT-02 |
| I-111 | N-15 dans l'application seulement | L'e-mail n'est pas encore en place |
| I-113 | « Rester connecté » propre à chaque appareil | Déconnecter un appareil perdu sans déconnecter les autres |
| I-114 | Changer son mot de passe déconnecte ses autres appareils | Leurs cookies ne s'ouvraient déjà plus ; ils ne restent plus listés ni abonnés aux push |

## Reporté, avec la dépendance qui le justifie

| Élément | Lot |
|---|---|
| Notifications par e-mail (N-15 compris) | Choix du service d'envoi |
| Tableaux de bord et indicateurs, tests de charge, sauvegardes chiffrées hors site, supervision | 9 — exploitation |
| Langue arabe | V1.1 |
