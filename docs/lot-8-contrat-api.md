# Lot 8 — PWA avancée : contrat d'API et d'écrans

Lot 7 de la spécification (§16 : « Hors ligne, envoi différé, notifications push, rappels | Tests validés sur appareils réels »), numéroté 8 dans ce dépôt : le lot 7 y est la charte Ramy ([lot-7-charte-ramy.md](lot-7-charte-ramy.md)). Référence commune pour le back-end **et** la PWA ; les formes JSON ci-dessous font foi.

Reprend les éléments reportés par les lots précédents : profil et données consultables hors ligne (lot 1), « Mes clients » hors ligne (lot 2), push et anti-saturation (lot 3), N-10 et N-13 (lot 4), envoi différé, cache des données et des photos, N-05 regroupé, rappel N-11, préférences (lot 5), push N-07 et N-09, rappels N-10 à N-13 (lot 6).

## 1. Conventions

Celles des lots 2 à 6 s'appliquent (décisions I-1 à I-100) :
- droits avant validation (403 avant 422) ;
- erreurs « Problem Details » ;
- instants ISO 8601 UTC, heures `"HH:MM"` dans le fuseau métier (Africa/Algiers).

Le serveur reste la seule source de vérité : le cache de l'appareil ne sert qu'à **lire** hors ligne, et toute écriture repasse par l'API, qui rejoue ses contrôles (RG-C08).

Ce qui ne change pas :
- **Écritures en ligne seulement** (D-13) : bornes, décisions du régional, actions ADV et Admin, modification et annulation d'une commande client.
- **Seule exception** : l'**envoi** d'une nouvelle commande client, qui passe par la boîte d'envoi de l'appareil (§6).
- **Back-office** (ADV, Admin) : jamais mis en cache, ni dans le service worker, ni dans le cache de requêtes persistant (§9.5).

## 2. Notifications push (Web Push, VAPID)

### 2.1 Configuration

Clés VAPID dans `.env` :
- `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` (base64url) ;
- `VAPID_SUBJECT` (`mailto:` ou URL du site).

Elles sont générées une fois, avec `php artisan webpush:vapid --show`. Sans clés, le push est désactivé : la PWA n'offre pas l'abonnement, et le centre de notifications fonctionne comme avant.

Paramètre général `notifications.push_enabled` (Admin → Paramètres, vrai par défaut) : interrupteur global des envois push. Les paramètres existants `notifications.quiet_start` et `notifications.quiet_end` (21:00 et 06:00) bornent la plage sans push (EF-NOT-02).

`GET /auth/me` gagne :

```json
{ "push": { "public_key": "BEl…" },
  "preferences": { "reminders": true } }
```

`push` vaut `null` si les clés sont absentes ou si `push_enabled` est faux.

### 2.2 Abonnements : `POST /push-subscriptions`, `DELETE /push-subscriptions`

```json
{ "endpoint": "https://fcm.googleapis.com/fcm/send/…",
  "keys": { "p256dh": "BN…", "auth": "k…" },
  "content_encoding": "aes128gcm" }
```

- Tous les rôles. **Forme** (422) :
  - `endpoint` : URL `https`, 500 caractères au plus, dont l'hôte appartient à la liste des services push autorisés (`config('ramymaak.push.allowed_hosts')` : `fcm.googleapis.com`, `*.push.services.mozilla.com`, `*.notify.windows.com`, `*.push.apple.com`, `android.googleapis.com`). C'est une garde contre les requêtes forgées vers le réseau interne : le serveur écrit à cette adresse (I-104).
  - `keys.p256dh` : base64url de 65 octets décodés.
  - `keys.auth` : base64url de 16 octets décodés.
  - `content_encoding` : `aes128gcm` (défaut) ou `aesgcm`.
- **POST**, enregistrement idempotent par `endpoint` :
  - 201 à la création, 200 à la mise à jour ;
  - réponse `{ "endpoint": "…", "created_at": "…" }`.
  - L'abonnement est rattaché à l'utilisateur et à la **session** courante, avec le navigateur (`user_agent`, 255 caractères) et `last_used_at`.
  - Un `endpoint` déjà rattaché à un autre compte (appareil partagé) **passe au compte courant** : l'ancien propriétaire ne reçoit plus rien sur cet appareil (I-103).
  - La PWA renvoie son abonnement à chaque ouverture, ce qui met à jour la session et `last_used_at`.
- **DELETE** `{ "endpoint": "…" }` : 204, y compris pour un endpoint inconnu ou appartenant à un autre compte, qui reste alors intact.
- Limitation : 20 requêtes par minute et par utilisateur.

**Fin de session et abonnements** (I-103). Les abonnements de l'appareil sont supprimés :
- à la déconnexion (`POST /auth/logout`) : ceux de la session courante ;
- à la déconnexion de tous les appareils, à la désactivation du compte et à une réinitialisation du mot de passe par l'Admin (`SessionRevoker::revokeAll`) : tous ;
- à la déconnexion d'un appareil depuis le profil (§5) : ceux de la session révoquée.

### 2.3 Quelles notifications partent en push (§7.8)

| Type | Code | Push | Groupe d'anti-saturation |
|---|---|:-:|---|
| `QUOTAS_PUBLISHED` | N-01 | oui | type |
| `QUOTAS_UPDATED` | N-02 | oui | type |
| `DISTRIBUTION_PUBLISHED` | N-03 | oui | type |
| `LIMITS_UPDATED` | N-04 | oui | type |
| `ORDER_SUBMITTED` | N-05 | oui, **regroupé** | type (« 3 nouvelles commandes à valider ») |
| `ORDER_CHANGED` | N-06 | non | — |
| `ORDER_DECIDED` | N-07 | oui | type |
| `ORDER_SHIPPED` | N-09 | oui | type |
| `DISTRIBUTION_REMINDER` | N-10 | oui | type |
| `ORDERING_REMINDER` | N-11 | oui | type |
| `VALIDATION_REMINDER` | N-12 | oui | type |
| `DISTRIBUTION_LATE` | N-13 | non (ADV, dans l'application) | — |
| `ORDER_EXPIRED` | N-14 | oui | type |
| `ACCOUNT_SECURITY` | N-15 | non | — |
| `CUTOFF_EXTENDED` | — | oui (I-106) | type |

**Toute** notification reste enregistrée dans le centre de notifications et diffusée sur `user.{id}`, comme aux lots 3 à 6 (EF-NOT-01). Le push n'est qu'un signal qui y renvoie.

### 2.4 Envoi, regroupement, plage sans push (EF-NOT-02, EF-NOT-06)

1. Le canal `push`, ajouté à `AppNotification::via()` pour les types concernés, ne fait rien si l'une de ces conditions est vraie :
   - `push_enabled` est faux ;
   - les clés VAPID sont absentes ;
   - l'utilisateur n'a aucun abonnement ;
   - l'heure locale est dans la plage sans push. Dans ce cas, le push est **abandonné**, pas reporté : le centre de notifications le garde (I-105).
2. **Anti-saturation**, état par utilisateur et par groupe dans `push_throttles`, verrouillé pendant la décision :
   - si aucun push de ce groupe n'est parti depuis 5 minutes, le push part tout de suite (tâche `DeliverPush`) ;
   - sinon il est compté (`pending + 1`) et une tâche `FlushPush` est programmée pour la fin de la fenêtre, une seule par utilisateur et par groupe ;
   - à son tour, cette tâche envoie un push **groupé** (§2.5) s'il reste des notifications comptées, hors de la plage sans push. Le délai est en configuration (`ramymaak.push.group_window_minutes`, 5).
3. `DeliverPush` (file d'attente, 3 essais) chiffre la charge pour chaque abonnement de l'utilisateur :
   - réponse 404 ou 410 → abonnement supprimé ;
   - autre échec → `failures + 1` ;
   - succès → `failures = 0` et `last_used_at`.
   - En-têtes : TTL de 6 h, `Urgency: high` pour les rappels, `normal` sinon, `Topic` = groupe (le service push remplace un message en attente du même groupe).
4. **Rejouable sans double effet** (§9.7) : la notification n'est envoyée qu'une fois (`sendOnce`), et son push avec elle ; une tâche relancée ne recompte pas.

Purge nocturne (`ramymaak:purge-push-subscriptions`, 03:10) :
- abonnements à 3 échecs consécutifs ou plus ;
- abonnements inutilisés depuis 60 jours ;
- états d'anti-saturation de plus de 7 jours.

### 2.5 Charge utile (EF-NOT-03 : jamais de montant ni de donnée commerciale)

```json
{ "id": "6f1c…", "type": "ORDER_DECIDED", "code": "N-07",
  "title": "Commande RMK-261008-00042 validée", "body": "Votre commande RMK-261008-00042 est validée.",
  "url": "/client/commandes/01J…", "tag": "ORDER_DECIDED", "count": 1 }
```

Push groupé (`id` null, `count` ≥ 2) :

| Groupe | Titre | Texte | `url` |
|---|---|---|---|
| `ORDER_SUBMITTED` | « {n} nouvelles commandes à valider » | « Ouvrez les commandes reçues. » | `/regional/commandes` |
| autres | « {n} nouvelles notifications » | titre de la dernière | `/notifications` |

## 3. Rappels et alerte (N-10 à N-13) — planificateur, chaque minute

Commande `ramymaak:send-reminders`, planifiée `everyMinute()` et `withoutOverlapping(5)`. Elle porte sur les journées régionales non clôturées des journées ouvertes. L'heure limite est l'heure **effective** de la région, prolongation comprise (`RegionDay::effectiveCutoff`).

| Code | Condition sur la journée régionale | Fenêtre d'envoi | Destinataires | Titre / texte | `url` |
|---|---|---|---|---|---|
| N-10 | quotas publiés, répartition non envoyée | [limite de répartition − 30 min ; limite[ | régionaux de la région à cette date (`RegionStaff::regionals`) | « Rappel : répartition du 08/10 — Centre » / « Envoyez la répartition avant 10:00. » | `/regional/repartition?day=…&region=…` |
| N-11 | répartition envoyée | [limite de commande − 1 h ; limite[ | clients notifiés **sans commande comptée** (même calcul que « Clients sans commande », I-88), comptes actifs de rôle client | « Rappel : commande avant 13:00 » / « Vous n'avez pas encore commandé pour le 08/10. » | `/client` |
| N-12 | commandes **Envoyées** de la région ce jour-là | [limite de validation − 30 min ; limite[ | régionaux de la région | « Rappel : 3 commandes à valider » / « Centre : validez avant 15:00. » | `/regional/commandes?day=…` |
| N-13 | quotas publiés, répartition non envoyée | [limite de répartition ; clôture[ | ADV dont le périmètre comprend la région à cette date, et Admin | « Répartition en retard — Centre » / « La répartition du 08/10 n'est pas envoyée (limite 10:00). » | `/adv/journees/{day}/suivi` |

- **Une fois par heure limite** : la clé d'unicité est formée du code, de la journée régionale et de l'heure limite effective. Une prolongation déclenche donc un nouveau rappel avant la nouvelle heure (I-107).
- **Hors fenêtre, rien** : un planificateur arrêté puis relancé après l'heure limite n'envoie pas de rappel en retard (N-13 excepté, jusqu'à la clôture).
- **N-10 à N-12** : un compte qui a coupé les rappels (`preferences.reminders = false`) ne reçoit rien, ni dans l'application ni en push (EF-NOT-04). **N-13** n'est pas coupable.
- **Délais** en configuration (`ramymaak.reminders` : `distribution` 30, `ordering` 60, `validation` 30 minutes), pas dans les paramètres de l'Admin : ils viennent de la spécification (§4.3, I-108).
- La PWA ajoute les écrans de N-13 (ADV) aux événements déjà suivis : le suivi de la journée l'affichait déjà comme alerte visuelle (lot 6).

## 4. Préférences : `PATCH /auth/me/preferences`

`{ "reminders": false }` → 200 avec l'utilisateur courant (forme de `GET /auth/me`).
- Tous les rôles authentifiés.
- `reminders` : booléen requis. Colonne `users.reminders_enabled`, vraie par défaut.
- Journal `auth`, événement `preferences.updated`.

## 5. Appareils connectés : `GET /auth/sessions`, `DELETE /auth/sessions/{id}`

```json
{ "data": [ { "id": "s_3fa9c1d2e4b5", "device": "Chrome sur Android", "ip": "41.200.10.7",
              "last_active_at": "2026-10-08T09:12:00+00:00", "current": true, "push": true } ] }
```

- **Sessions** : les sessions du compte (table `sessions`), de la plus récente à la plus ancienne.
- **`id`** : identifiant opaque, `s_` suivi des 12 premiers caractères hexadécimaux d'un HMAC de l'identifiant de session avec la clé de l'application. L'identifiant de session lui-même n'est **jamais** exposé (§12).
- **`device`** : libellé lisible tiré du navigateur, de la forme « {navigateur} sur {système} ».
  - Navigateurs : Chrome, Edge, Firefox, Safari, Samsung Internet, Opera.
  - Systèmes : Android, iPhone, iPad, Windows, macOS, Linux.
  - Repli : « Navigateur inconnu ».
- **`push`** : un abonnement push est rattaché à cette session.
- **DELETE** :
  - 204 ;
  - supprime la session et ses abonnements push ;
  - journal `auth`, événement `session.revoked`.
  - **Refus** : 404 si l'identifiant est inconnu ou appartient à un autre compte ; 422 si c'est la session courante (utiliser « Me déconnecter »).

## 6. Hors ligne (EF-CLT-07, §9.5)

### 6.1 Données lues hors ligne : cache de requêtes persistant

Le cache de TanStack Query est enregistré dans IndexedDB (Dexie, base `ramymaak`, table `kv`). C'est le choix retenu à la place d'un cache d'API dans le service worker (I-101) : il est propre à chaque compte, effacé à la déconnexion, et ne dépend pas des en-têtes `no-store` de l'API.

- **Clé** : `query-cache:{userId}`. Au plus 7 jours ; la version du schéma (`PERSIST_VERSION`) invalide les anciens enregistrements.
- **Requêtes enregistrées**, réussies seulement, sur une liste blanche de préfixes de clés :
  - `['auth','me']` ;
  - client : `['client', …]` (état du jour, catalogue, commandes) ;
  - régional : `['regional','dashboard'|'quotas'|'orders'|'order', …]`, `['distribution','grid', …]`, `['distribution','regions', …]`, `['field','clients', …]` ;
  - tous : `['notifications', …]` (centre de notifications).
  - **Jamais** `['adv', …]`, `['admin', …]`, `['backoffice', …]` ni rien du back-office.
- **Démarrage hors ligne** : l'utilisateur et les données enregistrés s'affichent. Au retour du réseau, `GET /auth/me` est relu ; un 401 renvoie à la connexion.
- **Bandeau** sur chaque écran lu hors ligne : « Hors ligne — données de 09:42 » (heure de la dernière relecture réussie de la requête principale de l'écran, `dataUpdatedAt`). C'est déjà le cas de la grille du régional (lot 4) ; le lot l'étend à l'accueil, au catalogue, au panier, aux commandes, à « Ma journée », aux quotas, aux commandes reçues et à « Mes clients ».
- **Pas encore lu sur cet appareil** : message « Pas de données enregistrées sur cet appareil pour cet écran : reconnectez-vous au réseau. »
- **Déconnexion** : le cache persistant du compte, la boîte d'envoi (§6.3) et les brouillons de bornes sont effacés de l'appareil.

### 6.2 Photos : cache du service worker

`/media/*` en « cache d'abord », 30 jours, 500 images au plus (workbox-strategies, workbox-expiration). Les URL des photos sont versionnées (lot 2) : une photo remplacée change d'URL. Hors ligne, une photo jamais vue affiche le visuel par défaut.

### 6.3 Envoi différé des commandes client (boîte d'envoi, §9.5)

Table Dexie `outbox` :

```ts
{ id: string /* client_request_id */, user_id: number, client_id: number, day: string,
  body: SubmitOrderInput, created_at: string, status: 'pending' | 'sending' | 'sent' | 'refused',
  attempts: number, error: { code: string, detail: string } | null,
  order: { id: string, number: string } | null }
```

1. « Envoyer la commande » (après confirmation) dépose la commande dans la boîte d'envoi avec son `client_request_id`, vide le panier et tente l'envoi aussitôt. C'est le cas en ligne comme hors ligne : l'envoi passe toujours par la boîte.
2. **Nouvelles tentatives** :
   - au retour du réseau (`online`), à l'ouverture de l'application, au retour au premier plan, puis toutes les 30 s tant qu'une commande attend et que l'application est ouverte ;
   - en arrière-plan avec Background Sync (Chrome, Android), étiquette `ramymaak-outbox`. Le service worker envoie alors lui-même la commande, avec le jeton CSRF lu par l'API Cookie Store, et prévient l'utilisateur par une notification locale « Commande RMK-… envoyée » ou « Commande refusée ».
3. **Résultat** :
   - 201 ou 200 (renvoi idempotent) → `sent`, numéro de commande, lien vers la commande ;
   - erreur de réseau, 5xx, 408, 419 ou 429 → reste `pending` ;
   - 401 → `pending`, avec « Reconnectez-vous pour envoyer » ;
   - autre 4xx → `refused` avec le motif du serveur (`detail`, par exemple « L'heure limite de commande (13:00) est passée »), et deux actions : « Reprendre dans le panier » (le panier est reconstruit depuis `body`, un nouvel identifiant d'envoi sera créé) et « Retirer ».
4. **Affichage** :
   - accueil du client et « Mes commandes » : bloc « Commandes en attente d'envoi » (« En attente d'envoi », « Envoi en cours… », « Envoyée — RMK-… », « Refusée : motif ») ;
   - pastille de l'onglet « Commandes » ;
   - une commande `sent` reste affichée jusqu'à ce que la liste des commandes l'ait relue, puis elle est retirée de la boîte.
5. **Une commande en attente par journée** : tant qu'une commande de la journée est `pending`, le panier de cette journée ne peut pas en envoyer une autre (« Une commande est déjà en attente d'envoi pour cette journée »). Le serveur reste juge du nombre de commandes par jour (RG-C04).
6. **Modification, annulation, « Recommander »** : en ligne seulement, comme au lot 5 (D-13).
7. **Déconnexion avec une commande en attente** : confirmation « 1 commande en attente d'envoi sera supprimée de cet appareil. Se déconnecter quand même ? ».

## 7. Service worker

| Événement | Rôle |
|---|---|
| `install` / `activate` | Pré-cache (lot 1) ; `clientsClaim` à l'activation d'une nouvelle version acceptée |
| `fetch` | Navigation vers `index.html` (lot 1) ; `/media/*` en cache d'abord (§6.2) ; **rien d'autre** : jamais l'API |
| `push` | Affiche la notification (`title`, `body`, `tag`, icône 192, badge, `data.url`) ; charge illisible → « RamyMaak — nouvelle notification » |
| `notificationclick` | Ferme la notification, met au premier plan une fenêtre de l'application et la mène à `data.url` (chemin interne seulement), sinon ouvre une fenêtre |
| `pushsubscriptionchange` | Réabonne avec la même clé publique, et laisse la PWA renvoyer l'abonnement à la prochaine ouverture |
| `sync` (`ramymaak-outbox`) | Envoie la boîte d'envoi (§6.3) |
| `message` `SKIP_WAITING` | Lot 1 |

Découpage du code : `src/sw.ts` assemble `src/sw/cache.ts` (photos), `src/sw/push.ts` (push, clic, changement d'abonnement) et `src/sw/outbox.ts` (Background Sync). La boîte d'envoi est partagée avec l'application (`src/shared/offline/outbox.ts`, sans React).

## 8. Écrans

| Rôle | Écran | Contenu |
|---|---|---|
| Tous (client, régional, ADV, Admin) | Profil → **Notifications** | État sur cet appareil, avec l'action qui convient : <br>– non pris en charge par le navigateur ; <br>– iPhone ou iPad sans installation : guide d'installation (EF-NOT-05, iOS 16.4 ou plus récent) ; <br>– permission refusée : comment la rétablir dans les réglages du navigateur ; <br>– désactivées : « Activer les notifications sur cet appareil » ; <br>– activées : « Désactiver sur cet appareil ». <br>Interrupteur **Rappels** (N-10 à N-12), pour les clients et les régionaux. |
| Tous | Profil → **Appareils connectés** | Liste (§5) avec « Cet appareil », dernière activité et une icône quand le push est actif ; « Déconnecter » pour chaque autre appareil (avec confirmation) ; « Me déconnecter de tous mes appareils » (lot 1). |
| Client, régional | Accueil | Invitation « Recevez vos notifications » avec « Activer » et « Plus tard » : une fois par compte et par appareil, quand le push est disponible et que la permission n'a pas encore été demandée. |
| Client | Accueil, Mes commandes, panier | Boîte d'envoi (§6.3) ; « Envoyer la commande » possible hors ligne. |
| Client, régional | Écrans lus hors ligne | Bandeau « Hors ligne — données de HH:MM » ; actions d'écriture désactivées hors ligne, sauf l'envoi d'une commande. |
| ADV | Suivi de la journée, cloche | N-13 dans le centre de notifications. |
| Admin | Paramètres → Notifications | Case « Notifications push » (`notifications.push_enabled`) avec la plage sans push (existante). |

**Textes du push** : ceux de la notification enregistrée (lots 3 à 6), et ceux du §3 pour les rappels.

## 9. Répartition du travail (fichiers possédés)

Le socle (commits du lot 8) fournit :
- les migrations ;
- les modèles `PushSubscription` et `PushThrottle` ;
- les nouveaux cas de `NotificationType` ;
- les routes et les contrôleurs provisoires ;
- la configuration (clés VAPID par la configuration `webpush` du paquet, `ramymaak.push`, `ramymaak.reminders`) ;
- côté PWA : les types, les bundles de traduction `push` (volet C) et `offline` (volet D), la base Dexie commune (`src/shared/offline/db.ts`), le découpage du service worker en modules vides, et les dépendances (Dexie, workbox-strategies, workbox-expiration, le persisteur de TanStack Query, fake-indexeddb pour les tests).

Côté serveur, le socle fournit aussi `NotificationType::pushable()`, `isReminder()` et `pushGroup()`, `App\Domain\Push\WebPushConfig`, la limite de débit `push-subscriptions`, le paramètre `notifications.push_enabled` (défaut et règle) et les champs `push` et `preferences` de `/auth/me`.

| Volet | Contenu | Fichiers |
|---|---|---|
| A | Push côté serveur | `app/Domain/Push/*` (transport Web Push et faux transport des tests, anti-saturation, plage sans push), canal `push` et `AppNotification::via`, tâches `DeliverPush` et `FlushPush`, `Api/V1/PushSubscriptionController` et ses requêtes, `push` de `CurrentUserResource`, nettoyage à la fin de session (`AuthController::logout`, `SessionRevoker`), commande de purge et sa planification, paramètre `notifications.push_enabled` (API), tests |
| B | Rappels, N-13, N-15, préférences, appareils | `app/Domain/Reminders/*`, commande `ramymaak:send-reminders` et sa planification, notifications N-10 à N-13 et N-15 (réinitialisation par l'Admin et `ramymaak:reset-password`), `PATCH /auth/me/preferences`, `GET` et `DELETE /auth/sessions` (contrôleur `Api/V1/SessionController`, libellé de l'appareil), `preferences` de `CurrentUserResource`, tests |
| C | PWA : push et profil | `src/sw/push.ts`, `src/shared/push/*` (abonnement, renvoi à l'ouverture, désabonnement à la déconnexion), `modules/profile/*` (notifications, rappels, appareils), invitation sur l'accueil (`shared/push/PushInvite.tsx`, montée dans les accueils client et régional), `admin/SettingsPage.tsx` (case push), N-13 dans les écrans ADV, bundles `push` et `profile` |
| D | PWA : hors ligne et boîte d'envoi | `src/sw/cache.ts`, `src/sw/outbox.ts`, `src/shared/offline/*` (base Dexie, persisteur, boîte d'envoi, bandeau « données de »), `main.tsx` et `queryClient.ts` (persistance), `modules/client/cart/*` (envoi par la boîte), accueil et « Mes commandes » du client (bloc de la boîte d'envoi), bandeaux des écrans régionaux, purge à la déconnexion (`shared/storage.ts`, `shared/auth/session.ts`), bundle `offline` |

## 10. Décisions proposées

| # | Décision |
|---|---|
| I-101 | Données hors ligne : cache de TanStack Query persisté dans IndexedDB, par compte, sur une liste blanche de requêtes de terrain ; le service worker ne met jamais l'API en cache (§9.5 l'envisageait « réseau d'abord » dans le service worker) |
| I-102 | Envoi différé : toute nouvelle commande client passe par la boîte d'envoi de l'appareil, en ligne comme hors ligne ; modification et annulation restent en ligne seulement |
| I-103 | Un abonnement push appartient au dernier compte qui l'a enregistré et à sa session ; il est supprimé à la déconnexion de cet appareil, à la déconnexion de tous les appareils, à la désactivation et à la réinitialisation du mot de passe |
| I-104 | Adresses d'abonnement limitées aux services push connus (liste en configuration) : le serveur n'écrit jamais à une adresse choisie librement |
| I-105 | Plage sans push : les push sont abandonnés, pas reportés ; le centre de notifications les garde |
| I-106 | La prolongation d'une heure limite (RG-A05) part aussi en push : elle change ce que l'utilisateur peut faire dans l'heure |
| I-107 | Rappels une fois par heure limite effective ; une prolongation en déclenche un nouveau ; jamais après l'heure limite (N-13 excepté, jusqu'à la clôture) |
| I-108 | Délais des rappels en configuration (30 min, 1 h, 30 min), pas dans les paramètres de l'Admin |
| I-109 | Anti-saturation par utilisateur et par type : un push immédiat, puis au plus un push groupé par fenêtre de 5 minutes |
| I-110 | « Appareils connectés » : sessions du compte, identifiant opaque (HMAC), libellé tiré du navigateur ; la session courante ne se révoque pas depuis la liste |
| I-111 | N-15 (sécurité du compte) dans l'application seulement, à la réinitialisation du mot de passe par l'Admin ou par la commande `ramymaak:reset-password` ; l'e-mail reste reporté |
| I-112 | Une seule commande en attente d'envoi par journée sur un appareil ; le serveur reste juge (RG-C04) |

## 11. Tests sur appareils réels (critère de fin)

| Appareil | Vérifications |
|---|---|
| Android d'entrée de gamme, Chrome | Installation ; activation des notifications ; push N-03, N-07, N-11 application fermée, appui → bon écran ; commande envoyée en mode avion puis réseau retrouvé, application fermée (Background Sync) ; catalogue et commandes lus hors ligne |
| iPhone, iOS 16.4 ou plus récent, application installée | Guide d'installation ; activation ; push N-07 ; commande en attente envoyée à la réouverture ; lecture hors ligne |
| PC, Chrome ou Edge | Régional : push N-05 regroupé (« 3 nouvelles commandes à valider ») ; rappel N-12 ; grille lue hors ligne |
