# Lot 5 — Commandes client : contrat d'API et d'écrans

Référence commune pour l'implémentation du lot 5 (§16 : catalogue mobile, panier, contrôles, envoi, suivi, N-05 à N-07). Critère de fin : **une commande envoyée depuis un téléphone**. Les formes JSON ci-dessous font foi pour le back-end **et** pour la PWA.

## 1. Conventions

Celles des lots 2 à 4 s'appliquent (contrats des lots 2, 3 et 4, §1, et leurs « Précisions ») :
- droits contrôlés avant la validation (403 avant 422) ;
- objet hors périmètre ou d'un autre client → 404 ;
- erreurs « Problem Details » ;
- dates `AAAA-MM-JJ`, heures `"HH:MM"` dans le fuseau métier, instants ISO 8601 UTC ;
- l'application relit l'API après un événement temps réel ;
- l'heure du serveur fait foi (D-10).

**Notations** (§5.1), pour une journée, un client c de la région r et un produit p :

| Symbole | Sens | Source |
|---|---|---|
| Q(r,p) | quota **publié** | `region_quotas.quantity` |
| V(r,p) | validé de la région (0 jusqu'au lot 6) | `region_quotas.validated_qty` |
| R(r,p) | reste région = Q − V(r,p) | calculé |
| B(c,p) | borne **publiée** (null = pas de borne) | `client_limits.max_qty` |
| S(c,p) | quantités demandées des commandes **Envoyées** | `OrderUsage` |
| V(c,p) | quantités validées des commandes Validées et suivantes | `OrderUsage` (0 jusqu'au lot 6) |
| E(c,p) | engagé = S + V, hors commande en cours de modification | `OrderUsage::forClient` |
| D(c,p) | disponible : borne → `max(0, min(B − E, R))` ; sans borne → `R` (LIBRE) ou `0` (OBLIGATOIRE) | `Availability::compute` |

**Services partagés à utiliser, pas à réécrire** (`App\Domain\Orders`) :
- `ClientOrders` :
  - `submit(User, requestId, CarbonImmutable day, lines, ?comment)` → `{order, created}` ;
  - `modify(User, publicId, version, lines, ?comment)` → `Order` ;
  - `cancel(User, publicId, version)` → `Order`.

  `lines` = `[{product_id, quantity}]`. Tous les contrôles métier sont faits ici (ordre au §3), sous le verrou du client (§5.8), avec idempotence (RG-C07), historique, journal d'audit et événement.
- `OrderingWindow` :
  - `state(Client, day, lock)` et `forRegion(BusinessDay, regionId)` → `OrderingState` (journée, journée régionale, statut `ClientDayStatus`, heure limite applicable `cutoffAt`, `extended`) ;
  - `defaultDay(Client)` : journée proposée (I-42) ;
  - `assertOpen(state)` : `DAY_NOT_OPEN` ou `CUTOFF_PASSED`.
- `OrderBook::availability(RegionDay, clientId, ?exceptOrderId)` : produit → `{quota, region_validated, region_remaining, limit, engaged, available, sold_out, price_ht}`, pour les seuls produits **commandables** (I-44).
- `OrderUsage` (liaison de `ClientUsage`) : `forRegionDay` pour la grille du lot 4 (S réel, V à 0) ; `forClient`.
- `OrderWorkflow` : table des transitions (§6.3) avec leur acteur ; transition interdite → `INVALID_TRANSITION`.
- `OrderExpiry` : appelée par `DayCalendar::close` (§4.4).
- `App\Support\Money` : centimes, TVA au centime (demi supérieur). La PWA applique les mêmes règles (`shared/money.ts`).
- `App\Http\Resources\Orders\OrderPresenter` : `summary`, `lines`, `history`, avec les relations `OrderPresenter::RELATIONS` à charger.

**Statuts** (`OrderStatus`, §6.2) :
- les 9 statuts : `SUBMITTED`, `VALIDATED`, `REJECTED`, `CANCELLED`, `EXPIRED`, `IN_PREPARATION`, `PREPARED`, `SHIPPED`, `DELIVERED` ;
- groupes : `pending()` = [SUBMITTED] ; `validated()` = VALIDATED à DELIVERED ; `counted()` = les deux (RG-C04).

Le lot 5 exerce l'envoi, la modification, l'annulation par le client et l'expiration. Le régional et l'ADV agissent au lot 6.

**Événements de domaine** (`ShouldDispatchAfterCommit`) :
- `OrderSubmitted`, `OrderUpdated`, `OrderCancelled` (`orderId`, `clientId`, `regionId`, `businessDayId`, `version`, `actorId`) ;
- `OrdersExpired` (`businessDayId`, `orderIds`).

**Historique** : `order_status_history` (de, vers, version après la transition, auteur — null = système —, commentaire, date), jamais modifié.

**Journal d'audit** `orders` :
- `submitted` : numéro, journée, client, lignes, total HT ;
- `updated` : lignes avant / après, version ;
- `cancelled` ;
- `expired` (sur la journée) : nombre, 200 numéros au plus.

**Numéro** : `RMK-AAMMJJ-NNNNN`, rang dans la journée (`order_number_sequences`), inchangé à la modification. **Identifiant exposé** : `public_id`, ULID en majuscules. L'id interne n'est jamais exposé.

## 2. Droits

| Action | Permission | Rôle exigé | Remarque |
|---|---|---|---|
| `GET /client/today` | `order.view` + `limit.view` | Client | compte rattaché à un client, sinon 403 |
| Lire ses commandes | `order.view` | Client | celles **du client** (tous ses comptes, Q-11) ; autre → 404 |
| Envoyer, modifier, annuler | `order.create` | Client | |
| `GET /regional/orders`, `/regional/orders/{id}` | `order.view` | Régional | régions du périmètre **à la date de la commande** ; autre → 404 |

L'ADV et l'Admin reçoivent 403 sur ces points d'accès : ils lisent les commandes au lot 6.

`{order}` est un ULID **non lié par la route** : le contrôleur vérifie les droits (403), puis le format (`Order::isPublicId`, sinon 404 sans requête), puis recherche la commande dans le périmètre (404).

## 3. Client

### `GET /client/today[?day=AAAA-MM-JJ]`

Sans `day`, la journée vient de `OrderingWindow::defaultDay`. Un `day` invalide → 422.

```json
{
  "server_time": "2026-10-08T08:30:12+00:00",
  "day": "2026-10-08",
  "delivery_date": "2026-10-11",
  "status": "ORDERING_OPEN",
  "ordering_cutoff": "13:00",
  "ordering_cutoff_at": "2026-10-08T12:00:00+00:00",
  "ordering_cutoff_extended": false,
  "client": { "id": 12, "code": "C0012", "name": "Superette El Baraka", "status": "ACTIVE" },
  "max_orders_per_day": 1,
  "min_amount_ht": null,
  "can_submit": true,
  "submit_blocker": null,
  "orders": [ OrderSummary ],
  "families": [ { "id": 2, "code": "JUS", "name": "Jus", "sort_order": 1 } ],
  "products": [ {
    "id": 42, "sku": "P0042", "name": "Jus orange 1 L", "short_name": "Orange 1 L", "family_id": 2,
    "format": "1 L", "sales_unit_label": "Fardeau 6 × 1 L", "units_per_sales_unit": 6, "vat_rate": 19.0,
    "price": { "price_ht": 900.0, "price_ttc": 1071.0, "unit_price_ht": 150.0, "valid_from": "2026-01-01" },
    "photos": [ { "id": 7, "is_main": true, "urls": { "thumb": "…", "medium": "…", "large": "…" } } ],
    "is_new": false, "is_featured": true,
    "limit": 50, "engaged": 10, "available": 40, "sold_out": false
  } ]
}
```

Champs de l'état de la journée :
- **`status`** (`ClientDayStatus`, I-43), par ordre de priorité :
  1. `DAY_CLOSED` : journée ou région clôturée ;
  2. `ORDERING_CLOSED` : heure limite passée ;
  3. `ORDERING_OPEN` : répartition envoyée ;
  4. `WAITING_QUOTAS` : sinon, y compris pour une journée non ouverte.
- **`ordering_cutoff*`** : heure limite de commande **de la région du client**, prolongation comprise (RG-A05). Ces champs valent null si la journée n'est pas ouverte.
- **`can_submit`** et **`submit_blocker`** : `can_submit` est vrai si **toutes** ces conditions sont réunies :
  - le client est actif ;
  - `status` vaut `ORDERING_OPEN` ;
  - le nombre de commandes comptées est inférieur à `max_orders_per_day`.

  Sinon, `submit_blocker` vaut, dans cet ordre : `CLIENT_BLOCKED`, `DAY_NOT_OPEN` (statut `WAITING_QUOTAS` ou `DAY_CLOSED`), `CUTOFF_PASSED`, `MAX_ORDERS_REACHED`.
- **`orders`** : commandes du client pour **cette journée**, tous statuts, de la plus récente à la plus ancienne.

Catalogue :
- **`products`** : `OrderBook::availability`. Liste vide tant que la répartition n'est pas envoyée (`status` ≠ `ORDERING_OPEN` et ≠ `ORDERING_CLOSED`).
  - Les produits commandables sont triés comme la matrice du lot 3 (famille, puis `sort_order` et `sku`).
  - `price` suit la forme PriceRef du lot 2, calculée avec `Money`.
  - `photos` suit la forme du lot 2, principale en premier.
- **`families`** : familles des produits listés, par `sort_order` puis code.
- **Champs de quantité** (RG-C09) :
  - `limit` et `available` valent null sans borne : rien n'est affiché au client ;
  - `engaged` (ses propres quantités) est toujours présent ;
  - `sold_out` vaut `available === 0`, ou R = 0 sans borne.
- **Jamais renvoyés au client** : Q, R, ΣB, V(r,p), les bornes des autres clients, `ean`, `next_price`, `pending_deactivation_on`.

Le nombre de requêtes ne dépend pas du nombre de produits.

**Écarts avec l'exemple §11.3, à garder** :
- `price` (PriceRef) remplace `price_ht` ;
- `photos[]` remplace `images` ;
- `sales_unit_label` remplace `sales_unit` ;
- les instants sont en UTC, l'heure en `"HH:MM"` à part.

### Commandes

`OrderSummary` (`OrderPresenter::summary` + droits) :
```json
{ "id": "01JA2Z6K8QW3X9M4T7RBN5CDEF", "number": "RMK-261008-00042", "day": "2026-10-08",
  "status": "SUBMITTED", "status_label": "Envoyée", "version": 1, "submitted_at": "2026-10-08T08:40:00+00:00",
  "total_ht": 58500.0, "total_ttc": 69615.0, "lines_count": 2, "is_adjusted": false,
  "can_modify": true, "can_cancel": true }
```

Droits d'action sur une commande :
- **`can_modify`** est vrai si **toutes** ces conditions sont réunies :
  - la commande est `SUBMITTED` ;
  - la fenêtre de sa région est `ORDERING_OPEN` ;
  - le client est actif.
- **`can_cancel`** : mêmes conditions, sauf que le client peut être bloqué (I-53).

`Order` = `OrderSummary` +
```json
{ "submitted_by": { "id": 31, "name": "Gérant C0012" }, "comment": "Livraison avant 10 h",
  "total_vat": 11115.0, "decided_at": null, "decision_reason_code": null, "decision_comment": null,
  "modifiable_until": "2026-10-08T12:00:00+00:00",
  "lines": [ { "product_id": 42, "sku": "P0042", "name": "Jus orange 1 L", "short_name": "Orange 1 L",
               "sales_unit_label": "Fardeau 6 × 1 L", "photo": { "thumb": "…", "medium": "…" },
               "requested_qty": 40, "validated_qty": null, "shipped_qty": null,
               "unit_price_ht": 900.0, "vat_rate": 19.0, "amount_ht": 36000.0, "adjustment_reason_code": null } ],
  "history": [ { "from": null, "to": "SUBMITTED", "version": 1, "at": "…", "actor": "CLIENT",
                 "by_name": "Gérant C0012", "comment": null } ] }
```
- `modifiable_until` : heure limite applicable tant que la commande est `SUBMITTED`, sinon null.
- `history` : vue client = `OrderPresenter::history($order, false)` ; les comptes du régional et de l'ADV ne sont pas nommés.

| Méthode | Chemin | Corps | Réponse |
|---|---|---|---|
| GET | `/client/orders` | `filter[day]`, `filter[status]` (liste séparée par des virgules), `page`, `per_page` (25 par défaut, 100 au plus) | page de `OrderSummary`, toutes journées, `submitted_at` décroissant |
| GET | `/client/orders/{id}` | — | `Order` |
| POST | `/client/orders` | `client_request_id` (uuid, requis) ; `day` (`AAAA-MM-JJ`, requis) ; `comment` (null ou ≤ 500 caractères) ; `lines` (1 à 200 éléments `{product_id: entier, quantity: entier 1 à 1 000 000}`, un produit au plus une fois, sinon 422 sur `lines.N.product_id`) | **201** `Order` (nouvelle) ou **200** `Order` (renvoi du même identifiant, RG-C07) |
| PATCH | `/client/orders/{id}` | `version` (requis) ; `lines` (règles du POST) ; `comment` (clé requise, null permis) | 200 `Order` : remplacement complet, contrôles rejoués, E hors de cette commande |
| POST | `/client/orders/{id}/cancel` | `version` (requis) | 200 `Order` |

**Ordre des contrôles** :
- **POST** :
  1. 401 ;
  2. 403 ;
  3. 422 de format ;
  4. renvoi idempotent → 200 ;
  5. `CLIENT_BLOCKED` (403) ;
  6. `DAY_NOT_OPEN` (409) ;
  7. `CUTOFF_PASSED` (409) ;
  8. `MAX_ORDERS_REACHED` (409, + `max_orders_per_day`) ;
  9. `PRODUCT_NOT_AVAILABLE` (422, + `violations: [{line, product_id}]`) ;
  10. `CLIENT_LIMIT_EXCEEDED` (409, + `violations: [{line, product_id, requested, max_allowed}]`) ;
  11. `ORDER_BELOW_MINIMUM` (422, + `min_amount_ht`, `total_ht`).
- **PATCH** :
  1. 403 ;
  2. 404 ;
  3. 422 de format ;
  4. `INVALID_TRANSITION` ;
  5. `VERSION_CONFLICT` (+ `version` actuelle) ;
  6. `CLIENT_BLOCKED` ;
  7. `DAY_NOT_OPEN` ;
  8. `CUTOFF_PASSED` ;
  9. produits ;
  10. disponible ;
  11. minimum.
- **Annulation** :
  1. 403 ;
  2. 404 ;
  3. 422 de format ;
  4. `INVALID_TRANSITION` ;
  5. `VERSION_CONFLICT` ;
  6. `DAY_NOT_OPEN` ;
  7. `CUTOFF_PASSED`.

`max_allowed` vaut null sans borne : le reste de la région n'est jamais montré (RG-C09). Le `detail` nomme le produit et son maximum (« « Jus orange 1 L » : maximum 50. ») ou, sans borne, demande de réduire la quantité.

## 4. Régional (lecture)

### `GET /regional/orders?day=AAAA-MM-JJ&filter[status]=…&filter[region]=…`

- **`day`** : par défaut aujourd'hui.
- **Régions** : celles du périmètre à cette date ; `filter[region]` hors périmètre → 404.
- **Tri** : `submitted_at` croissant (RG-V01).
- **Pagination** : 50 par page, 100 au plus.
- **`filter[status]`** : liste ; par défaut `SUBMITTED`.

```json
{ "data": [ { "id": "01JA…", "number": "RMK-261008-00042", "day": "2026-10-08", "status": "SUBMITTED",
              "status_label": "Envoyée", "version": 2, "submitted_at": "…", "updated_at": "…",
              "client": { "id": 12, "code": "C0012", "name": "Superette El Baraka", "status": "ACTIVE" },
              "region": { "id": 3, "code": "CENTRE", "name": "Centre" },
              "lines_count": 2, "total_ht": 58500.0, "is_adjusted": false } ],
  "meta": { …pagination…, "counts": { "SUBMITTED": 4, "VALIDATED": 0, "REJECTED": 0, "CLOSED": 1 } } }
```
`counts` porte sur la journée et les régions filtrées. `VALIDATED` regroupe VALIDATED à DELIVERED ; `CLOSED` regroupe CANCELLED et EXPIRED.

### `GET /regional/orders/{id}`

Réponse : `Order` (sans `can_modify`, `can_cancel` ni `modifiable_until`) +
```json
{ "client": { "id": 12, "code": "C0012", "name": "…", "status": "ACTIVE", "phone": "0550…", "phone2": null },
  "region": { "id": 3, "code": "CENTRE", "name": "Centre" },
  "lines": [ { …ligne…, "context": { "limit": 50, "client_validated": 0, "region_quota": 500,
                                      "region_validated": 0, "region_remaining": 500 } } ],
  "can_validate": false }
```
- `history` : `OrderPresenter::history($order, true)`, tous les auteurs nommés.
- `context` : valeurs **publiées** du moment (lot 6 : contrôle de la validation).

## 5. Notifications et temps réel

Notifications, en base et sur `user.{id}`, envoyées par une tâche de fond rejouable (clé d'unicité) :

| Type | Code | Déclencheur | Destinataires | Titre / corps | `url` |
|---|---|---|---|---|---|
| `ORDER_SUBMITTED` | N-05 | `OrderSubmitted` | régionaux de la région ce jour-là (`RegionStaff::regionals`, titulaire et suppléants actifs) | « Nouvelle commande — C0012 Superette El Baraka » / « RMK-261008-00042 à valider. » | `/regional/commandes/{id}` |
| `ORDER_CHANGED` | N-06 | `OrderUpdated`, `OrderCancelled` | idem | « Commande RMK-261008-00042 modifiée » / « Superette El Baraka a modifié sa commande. » ; « … annulée » / « … a annulé sa commande. » | idem |
| `ORDER_DECIDED` | N-07 | lot 6 (classe, textes et destinataires livrés au lot 5) | comptes actifs de rôle client du client | « Votre commande RMK-… est validée. » / « … validée (2 lignes ajustées). » / « … est rejetée. » / « … est annulée. » | `/client/commandes/{id}` |
| `ORDER_EXPIRED` | N-14 | `OrdersExpired` | comptes actifs de rôle client du client | « Commande RMK-… expirée » / « Elle n'a pas été validée avant la clôture de la journée. » | `/client/commandes/{id}` |
| `CUTOFF_EXTENDED` | — | `CutoffExtended`, type `ORDERING` | en plus des régionaux (lot 3) : comptes actifs de rôle client des clients **actifs** de la région, si la répartition est envoyée | « Heure limite de commande prolongée » / « Commandez jusqu'à 13:30. » | `/client` |

Les textes ne portent jamais de montant (EF-NOT-03). Clés d'unicité :
- N-05 et N-14 : commande et destinataire ;
- N-06 et N-07 : commande, version et destinataire.

Le regroupement des push (« 3 nouvelles commandes ») arrive au lot 7, avec le push.

Temps réel (identifiants seulement, aucune quantité) :

| Canal | Événement | Contenu |
|---|---|---|
| `region-day.{regionId}.{day}` | `OrderSubmitted`, `OrderUpdated`, `OrderCancelled` | `{ id, number, status, version, day, client_id }` |
| `region-day.{regionId}.{day}` | `OrdersExpired` | `{ day, count }` (un par région) |
| `client.{clientId}` | `OrderSubmitted`, `OrderUpdated`, `OrderCancelled` | `{ id, number, status, version, day }` |
| `client.{clientId}` | `OrderStatusChanged` | `{ id, number, status, version, day }` : expiration au lot 5, décisions au lot 6 |

La grille de répartition (lot 4) relit S en direct sur les événements de commande de son canal `region-day`.

## 6. Écrans

| Rôle | Chemin | Contenu |
|---|---|---|
| Client | `/client` | **Accueil** (EF-CLT-01) — détail ci-dessous |
| Client | `/client/catalogue` | **Catalogue du jour** (EF-CLT-02) ; `?famille=`, `?q=`, `?produit=42` ouvre la fiche |
| Client | `/client/panier` | **Panier** (EF-CLT-03) et envoi |
| Client | `/client/commandes`, `/client/commandes/:id` | **Mes commandes** (EF-CLT-04), modification, annulation, **Recommander** (EF-CLT-05) |
| Régional | `/regional/commandes`, `/regional/commandes/:id` | **Commandes reçues**, en lecture (EF-REG-04 sans décision) |

**Accueil client** :
- **Bandeau** selon `status` : « En attente des quotas du jour », « Commandes ouvertes jusqu'à 13:00 » (avec « (prolongée) » si prolongée), ou « Commandes clôturées ».
- **Date de livraison**, et la mention « Commande suspendue » pour un client bloqué, sans motif.
- **Commandes du jour**, avec leur statut.
- **Bouton** selon `submit_blocker` : « Commander » ; « Modifier ma commande » quand le maximum est atteint et qu'une commande peut être modifiée ; rien sinon.
- **Minuterie** à `ordering_cutoff_at` : relit `today`. Le serveur reste juge.

**Catalogue** :
- **Navigation** : onglets de familles qui défilent horizontalement, et recherche instantanée sur nom et code.
- **Affichage** : sur téléphone, liste compacte (vignette 72 px) ou galerie à 2 colonnes, choix gardé sur l'appareil. Tablette : 3 colonnes. PC : 4 à 6 colonnes, avec le panier en panneau latéral à partir de 1024 px.
- **Carte produit** : photo, nom, UV, prix HT et TTC, « Max : n » seulement si une borne existe, sélecteur de quantité (`QuantityStepper`) bloqué à `available` quand il n'est pas null. Un produit épuisé est grisé et désactivé.
- **Fiche produit** : carrousel par défilement natif, grande image, UV, prix unitaire indicatif.
- **Barre du panier** fixe : nombre de lignes et total HT.
- **Lecture seule** pour un client bloqué ou une commande impossible.
- **Modification d'une commande** : bandeau « Modification de la commande RMK-… » avec « Abandonner la modification ».

**Panier** :
- **Contenu** : lignes avec photo, quantité et montant ; totaux HT, TVA et TTC « indicatifs » (`shared/money.ts`) ; commentaire de 500 caractères au plus.
- **Envoi** : « Envoyer la commande », puis confirmation, puis POST, ou PATCH en modification.
- **Identifiant d'envoi** : `store.beginSend()` l'enregistre **avant** la requête.
  - Erreur réseau, 5xx ou 429 : l'identifiant est gardé (« Pas de réseau — panier conservé, réessayez »).
  - 2xx : panier vidé, puis détail de la commande.
  - Refus 4xx métier : `abandonSend()`, puis affichage des violations ligne par ligne.
  - `VERSION_CONFLICT` en modification : relecture de la commande et message.
- **Rapprochement** avec `GET /client/today` (journée du panier ou de la commande modifiée), au chargement et à chaque relecture. Un produit qui n'est plus commandable est retiré ; une quantité au-dessus de `available` (s'il n'est pas null) est plafonnée ; un avis le signale. Une relecture n'écrase jamais une saisie en cours.
- **Changement de journée** (`cart.day` ≠ journée proposée, hors modification) : panier vidé avec un avis.

**Mes commandes** :
- **Liste** paginée, de la plus récente à la plus ancienne.
- **Détail** :
  - frise de statut : Envoyée → Validée → En préparation → Préparée → Expédiée, avec les terminaux Rejetée, Annulée, Expirée ;
  - lignes, avec quantités demandées et, dès qu'elles existent, validées et expédiées, et les motifs ;
  - historique et totaux.
- **Modifier** (`can_modify`) : `store.startEditing(order)`, après confirmation si le panier contient autre chose, puis le catalogue.
- **Annuler** (`can_cancel`) : confirmation, puis `POST …/cancel {version}`.
- **Recommander** : panier rebâti depuis une commande passée, sur la journée proposée.
  - Les produits non commandables sont ignorés ; les quantités sont plafonnées à `available` s'il n'est pas null.
  - Un résumé indique les changements, puis ouverture du panier.

**Commandes reçues (régional)** :
- **Filtres** : Aujourd'hui / Demain, région s'il y en a plusieurs.
- **Onglets** : « À valider » (`SUBMITTED`, avec compteur), « Validées », « Rejetées », plus « Annulées et expirées » (CANCELLED, EXPIRED).
- **Liste** par heure d'envoi : numéro, client, heure, nombre de lignes, total HT. Elle se met à jour en temps réel sur chaque région du périmètre.
- **Détail** en lecture seule :
  - client, avec un lien pour l'appeler ;
  - lignes, avec leur contexte : borne, déjà validé, quota, reste de la région ;
  - historique.

**Temps réel PWA** :
- **Client** : `useClientLive()` (`modules/client/api.ts`), soit le canal `client.{id}` et les événements `CLIENT_EVENTS`, avec repli toutes les 30 s.
- **Régional** : `useLiveRefresh` sur `region-day.{r}.{day}` pour `OrderSubmitted`, `OrderUpdated`, `OrderCancelled` et `OrdersExpired`. Ces événements s'ajoutent aussi à ceux de la grille de répartition.

**Hors ligne au lot 5** :
- le code est en cache, la page reste lisible et le panier reste modifiable ;
- l'envoi est désactivé hors ligne ;
- l'envoi différé, le cache des données et des photos arrivent au lot 7.

## 7. Répartition du travail (fichiers possédés)

La fondation fournit les éléments suivants ; un besoin de modification s'y signale au lieu d'être fait :
- **API** :
  - migration ; modèles `Order`, `OrderLine`, `OrderStatusHistory` et leurs fabriques ;
  - `Domain/Orders/*` ; `App\Support\Money` ; `OrderPresenter` ;
  - événements `Order*` / `OrdersExpired` ;
  - `ErrorCode::OrderBelowMinimum` ; `NotificationType` (cas `ORDER_*`) ; `lang/fr/orders.php` (ajouts permis) ;
  - routes, liaison `ClientUsage`, alias `order` ;
  - aide de test `openOrderingDay()` (`tests/Pest.php`).
- **PWA** :
  - `types.ts` ; `shared/storage.ts`, `shared/money.ts` ;
  - `shared/ui/Dialog.tsx`, `QuantityStepper.tsx`, `ProductImage.tsx` ;
  - panier `modules/client/cart/store.ts` ; données `modules/client/api.ts` ;
  - navigation (`AppShell.tsx`) et routes ;
  - libellés `shared/i18n/fr/orders/common.ts` et l'enregistrement `shared/i18n/orders.ts`.

| Lot de travail | Fichiers |
|---|---|
| A — Commandes du client (API) | `Api/V1/Client/*` (`ClientTodayController`, `ClientOrderController`), `Http/Requests/Orders/Client*`, `Http/Resources/Orders/Client*`, `tests/Feature/Orders/Client*` |
| B — Notifications, temps réel, clôture (API) | `app/Notifications/Order*.php` (et une base commune si utile), `app/Notifications/CutoffExtendedClientNotification.php`, `app/Listeners/NotifyOrder*.php`, `app/Listeners/BroadcastOrderActivity.php`, `app/Listeners/NotifyCutoffExtended.php` (branche client), `app/Events/Realtime/Order*.php`, `lang/fr/notifications.php` (ajouts), `tests/Feature/Notifications/Order*`, `tests/Feature/Realtime/Order*` |
| C — Commandes reçues (API) et accès simultanés | `Api/V1/Regional/RegionalOrderController`, `Http/Requests/Orders/Regional*`, `Http/Resources/Orders/Regional*`, `tests/Feature/Orders/Regional*`, `tests/Feature/Concurrency/*` (MySQL seulement, ignorés sous SQLite) |
| D — Accueil, catalogue, panier (PWA) | `modules/client/home/**`, `modules/client/catalogue/**`, `modules/client/cart/**` (sauf `store.ts`), `shared/i18n/fr/orders/client.ts` |
| E — Mes commandes (PWA) | `modules/client/orders/**`, `shared/i18n/fr/orders/clientOrders.ts` |
| F — Commandes reçues (PWA) et grille | `modules/regional/orders/**`, `shared/i18n/fr/orders/regional.ts`, `modules/distribution/api.ts` (événements de la grille seulement) |

Reporté :
- **Lot 6** : décisions (validation, ajustement, rejet), traitement ADV, vues commandes de l'ADV et de l'Admin, « Ma journée » du régional.
- **Lot 7** : envoi différé hors ligne, cache des données et des photos, push (N-05 regroupé, N-07, N-14), rappels N-11, préférences de notification.

## 8. Décisions proposées

| # | Décision |
|---|---|
| I-39 | N-07 : classe, textes et destinataires livrés au lot 5 ; déclenchée par les décisions du lot 6 |
| I-40 | Panier dans le stockage du navigateur (par compte et client), identifiant d'envoi enregistré avant l'envoi ; pas d'envoi différé avant le lot 7 |
| I-41 | Expiration à la clôture (§4.4) et N-14 au lot 5, dans la transaction de clôture |
| I-42 | Journée proposée au client : la première journée ouverte à la commande à partir d'aujourd'hui (la répartition de demain peut être envoyée la veille), sinon aujourd'hui, sinon la prochaine journée ouverte |
| I-43 | État de la journée : clôturée > heure limite passée > répartition envoyée > en attente |
| I-44 | Produit commandable : quota publié > 0, produit actif ce jour-là, prix en vigueur. Pas de borne à 0. Pas de produit sans borne en politique OBLIGATOIRE |
| I-45 | Avec une borne, le disponible montré est D (le reste région peut s'en déduire quand il est inférieur) ; sans borne, aucune quantité n'est montrée, ni dans le catalogue ni dans les refus |
| I-46 | Produit sans prix, borne 0, OBLIGATOIRE sans borne : `PRODUCT_NOT_AVAILABLE` |
| I-47 | Prix du jour de la commande ; à la modification, un produit gardé garde son prix figé, un nouveau prend le prix du jour |
| I-48 | Montants en centimes, TVA par ligne arrondie au centime (demi supérieur), TTC = HT + TVA |
| I-49 | Montant minimum HT par commande, à l'envoi et à la modification : `ORDER_BELOW_MINIMUM` (422) |
| I-50 | RG-C04 : comptent les commandes Envoyées et Validées à Livrées ; le renvoi idempotent est reconnu avant tout contrôle |
| I-51 | Modification = remplacement complet ; au moins une ligne (sinon annuler) ; `submitted_at` et le numéro sont gardés ; rien de changé → même version, pas de N-06 |
| I-52 | Annulation par le client : version requise, sans motif, avant l'heure limite |
| I-53 | Client bloqué : envoi et modification refusés (`CLIENT_BLOCKED`), annulation permise ; sa commande envoyée expire à la clôture (le lot 6 refusera de la valider) |
| I-54 | Renvoi idempotent : par client (tous ses comptes), sans comparer le contenu, réponse 200 avec l'état actuel |
| I-55 | Numéro `RMK-AAMMJJ-NNNNN` par journée, alloué dans la transaction d'envoi |
| I-56 | ULID en majuscules ; recherche insensible à la casse |
| I-57 | N-05 : une notification par commande dans l'application ; regroupement des push au lot 7 |
| I-58 | Régional : liste et détail des commandes en lecture au lot 5 |
| I-59 | Prolongation de l'heure limite de commande : les clients actifs de la région sont notifiés si la répartition est envoyée |
| I-60 | Limites de saisie : 200 lignes, 1 000 000 par ligne, commentaire de 500 caractères |
