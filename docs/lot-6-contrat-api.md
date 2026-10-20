# Lot 6 — Validation et ADV : contrat d'API et d'écrans

Référence commune pour l'implémentation du lot 6 (§16 : validation unitaire, ajustement, rejet, garanties d'accès simultané, traitement ADV, documents, exports). Critère de fin : **parcours complet d'une journée en recette** (ADV → régional → client → régional → ADV). Les formes JSON ci-dessous font foi pour le back-end **et** pour la PWA.

## 1. Conventions

Celles des lots 2 à 5 s'appliquent (contrats précédents, §1, et décisions I-1 à I-66) :
- droits contrôlés avant la validation (403 avant 422) ;
- objet hors périmètre → 404 ;
- erreurs « Problem Details » ;
- dates `AAAA-MM-JJ`, heures `"HH:MM"` dans le fuseau métier, instants ISO 8601 UTC ;
- commandes désignées par leur ULID (`public_id`), vérifié dans sa forme avant toute requête ;
- l'application relit l'API après un événement temps réel ; l'heure du serveur fait foi.

**Notations** (§5.1) : Q(r,p) quota publié, V(r,p) validé de la région (`region_quotas.validated_qty`), R = Q − V(r,p), B(c,p) borne publiée, V(c,p) validé du client (commandes Validées et suivantes). Une quantité **validable** pour une ligne vaut `max(0, min(R, B − V(c,p)))`, B absent = pas de plafond client (RG-V03 « si une borne existe », I-91).

**Services partagés** (socle du lot 6, `App\Domain\…`), à utiliser et non à réécrire :
- `Orders\OrderDecisions` : `validate(User, Order, version, lines)` et `reject(User, Order, version, reasonCode, ?comment)` (régional) ;
- `Orders\AdvOrders` : `transition(User, OrderStatus, items)` (unitaire et en masse, résultat partiel), `updateShipping(…)`, `cancel(…)` (ADV) ;
- `Quotas\QuotaLedger` : **seul** service qui écrit `region_quotas.validated_qty` (`reserve`, `release`, `reconcile`) ;
- `Orders\OrderTotals`, `OrderHistory`, `OrderVersion`, `OrderLocks` : montants, historique, version conditionnelle, verrous (aussi utilisés par `ClientOrders`) ;
- `Orders\ValidationWindow` et `DecisionRights` : heure limite de validation de la région (prolongation comprise), `can_validate`, `can_reject`, `blocked_reason`, `max_validatable` ;
- `ReasonCodes` : motifs actifs par type, libellé d'un code (motifs désactivés compris), règle de validation « motif actif de ce type » ;
- `Access\Perimeter::constrainOrders` : périmètre ADV (RG-S05) jugé à la journée de chaque commande.

**Ordre des verrous** (I-67, prolonge I-61), le même pour tout écrivain :
1. client (FOR UPDATE), pour les écritures qui changent S, V ou le nombre de commandes du client (envoi, modification et annulation par le client, validation, rejet, annulation ADV) ;
2. journée(s) en partagé, par identifiant croissant (tout écrivain de commande, ADV compris, même après la clôture) ;
3. journée régionale en partagé (validation seulement : la publication d'une borne attend) ;
4. commande(s) FOR UPDATE, par identifiant croissant ;
5. bornes du client FOR UPDATE, par produit croissant (validation) ;
6. compteurs `region_quotas` (validation et annulation ADV, dans `QuotaLedger`), par produit croissant ;
7. numéro du jour (envoi seulement, en dernier).

Jamais de verrou de journée après un verrou de commande. Paramètres et motifs sont lus **avant** la transaction (instantané REPEATABLE READ). Chaque écriture est rejouée jusqu'à 3 fois sur interblocage.

Une insertion vérifie ses clés étrangères en posant un verrou partagé sur la ligne parente : une relecture de l'ordre des verrous doit les compter. Ainsi `LimitBook::saveDrafts` verrouille en partagé les clients des cellules, par identifiant croissant, **avant** la journée régionale (une borne insérée pointe vers son client), sinon il s'interbloquerait avec une validation (client, puis journée régionale).

**Validation d'une commande** (§5.8), une transaction : verrous ci-dessus → version conditionnelle (statut Envoyée et version transmise) → pour chaque produit, dans l'ordre croissant, contrôle de la borne puis mise à jour conditionnelle du compteur région (`validated_qty + q <= quantity`) → lignes, totaux, historique, journal → événements **après** le commit. Une ligne validée à 0 ne touche pas le compteur.

**Événements de domaine** (`ShouldDispatchAfterCommit`) :
- `OrderValidated` (orderId, clientId, regionId, businessDayId, version, actorId, adjustedLines) ;
- `OrderRejected` (mêmes champs, sans adjustedLines) ;
- `OrderCancelledByAdv` (mêmes champs) — distinct de `OrderCancelled` (annulation par le client, N-06) ;
- `OrdersProcessed` (éléments {orderId, clientId, regionId, businessDayId, version}, statut atteint, actorId, lot) ;
- `OrderShippingUpdated` (orderId, businessDayId, version, actorId).

**Historique** : chaque transition enregistre son acteur (I-65) : REGIONAL pour la validation et le rejet, ADV pour les statuts de traitement et l'annulation exceptionnelle.

**Journal d'audit** `orders` :

| Événement | Propriétés |
|---|---|
| `validated` | numéro, version, lignes `[{sku, requested, validated, reason, comment}]`, lignes ajustées, total HT |
| `rejected` | numéro, version, motif, commentaire |
| `cancelled_by_adv` | numéro, version, motif, commentaire, quantités restituées `[{sku, qty}]` |
| `status_changed` | numéro, de, vers, version, lot |
| `shipping_updated` | numéro, version, lignes avant / après, bon de livraison avant / après |

Journal `reasons` : création et modification des motifs.

**Montants** (RG-P04) : dès la validation, totaux recalculés sur les quantités validées (prix et TVA figés, règles `Money`). Rejet : quantités validées nulles, totaux inchangés. Annulation ADV : quantités validées et totaux gardés. Rien n'est recalculé sur les quantités expédiées.

## 2. Droits

| Point d'accès | Permission et rôle | Périmètre |
|---|---|---|
| `POST /regional/orders/{id}/validate`, `/reject` | `order.validate`, Régional | région de la commande, à sa journée (titulaire ou suppléant) |
| `GET /regional/dashboard` | `dashboard.view`, Régional | ses régions ce jour-là |
| `GET /reason-codes` | Régional, ADV, Admin (client → 403) | — |
| `GET`, `POST /admin/reason-codes` ; `PATCH /admin/reason-codes/{id}` | `settings.manage`, Admin | — |
| `GET /adv/orders`, `/adv/orders/{id}` | `order.view`, ADV ou Admin (lecture) | RG-S05 à la journée de la commande ; Admin : tout |
| `POST /adv/orders/bulk-status` ; `PATCH /adv/orders/{id}/shipping` ; `GET /adv/orders/{id}/pdf` ; `GET /adv/days/{day}/picking-list` | `order.process`, ADV | RG-S05 |
| `POST /adv/orders/{id}/cancel` | `order.cancel-validated`, ADV | RG-S05 |
| `GET /adv/days/{day}/orders/export` ; `GET /adv/days/{day}/monitoring` | `dashboard.view`, ADV ou Admin | RG-S05 |
| `POST /adv/days/{day}/regions/{r}/extend-cutoff` avec `kind: VALIDATION` | `day.extend-cutoff` (existant) | existant ; les régions de la journée (`GET /adv/days/{day}`, lot 3) portent désormais `cutoffs.validation` et `extended.validation` (I-98) |

Les points d'accès `/regional/orders*` restent refusés (403) à l'ADV et à l'Admin.

## 3. Régional : décision et « Ma journée »

### `POST /regional/orders/{id}/validate`

```json
{ "version": 3,
  "lines": [ { "product_id": 42, "validated_qty": 40 },
             { "product_id": 57, "validated_qty": 15, "reason_code": "QUOTA_INSUFFISANT", "comment": "Reste région limité" } ] }
```

- **Forme** (FormRequest, 422) : `version` entier ≥ 1 ; `lines` de 1 à 200 éléments, `product_id` distincts ; `validated_qty` entier de 0 à 1 000 000 ; `reason_code` motif ADJUSTMENT actif ; `comment` ≤ 200 caractères.
- **Sens** (contrôlé par le service après les verrous, 422 sur `lines` ou `lines.N.*`) : exactement les produits de la commande ; `validated_qty` ≤ demandé ; motif obligatoire sur une ligne réduite et refusé sur une ligne entière ; commentaire obligatoire si le motif l'exige (`requires_comment`), refusé sur une ligne entière ; toutes les lignes à 0 → « rejetez la commande » (RG-V05).
- **Réponse 200** : le détail régional (ci-dessous). Un renvoi de la même décision (commande déjà Validée à `version + 1` par le même auteur) rend l'état actuel, sans effet (I-92).
- **Ordre des contrôles** :
  1. 401 ;
  2. 403 `FORBIDDEN` ;
  3. 404 `NOT_FOUND` ;
  4. 422 de forme ;
  5. 409 `INVALID_TRANSITION` ;
  6. 409 `VERSION_CONFLICT` (+ `version` actuelle) ;
  7. 409 `DAY_NOT_OPEN` (journée ou région clôturée) ;
  8. 409 `CUTOFF_PASSED` (+ `cutoff`), seulement si l'heure limite de validation est bloquante (RG-V08) ;
  9. 403 `CLIENT_BLOCKED` ;
  10. 422 de sens ;
  11. 409 `REGION_QUOTA_EXCEEDED` ou `CLIENT_LIMIT_EXCEEDED`.

Refus de quota ou de borne (toutes les lignes en cause) :

```json
{ "type": "…/errors/region-quota-exceeded", "title": "Quota région dépassé", "status": 409,
  "code": "REGION_QUOTA_EXCEEDED", "detail": "La validation dépasserait le quota de la région pour 1 produit.",
  "violations": [ { "line": 1, "product_id": 57, "sku": "P0057", "requested": 25,
                    "quota": 300, "validated": 290, "limit": 30, "client_validated": 10,
                    "max_allowed": 10, "cause": "REGION_QUOTA" } ] }
```

`cause` vaut `REGION_QUOTA` ou `CLIENT_LIMIT` ; `limit` est null sans borne ; `requested` est la quantité à valider. Le code est `REGION_QUOTA_EXCEEDED` dès qu'une ligne dépasse le quota de la région, sinon `CLIENT_LIMIT_EXCEEDED`.

### `POST /regional/orders/{id}/reject`

`{"version": 3, "reason_code": "QUOTA_EPUISE", "comment": "…"}` : motif REJECTION actif ; `comment` ≤ 500, obligatoire si le motif l'exige. Réponse 200 : le détail régional. Erreurs : 403, 404, 422, 409 `INVALID_TRANSITION`, 409 `VERSION_CONFLICT`, 409 `DAY_NOT_OPEN`. Le rejet n'est jamais bloqué par l'heure limite de validation ni par le statut du client (I-71).

### Détail régional (`GET /regional/orders/{id}`, forme du lot 5 complétée)

```json
{ "decided_at": "…", "decided_by": { "id": 9, "name": "…" },
  "decision_reason": { "code": "QUOTA_EPUISE", "label": "Quota épuisé" },
  "validation": { "cutoff": "15:00", "cutoff_at": "2026-10-08T14:00:00+00:00", "extended": false, "blocking": false, "passed": false },
  "can_validate": true, "can_reject": true, "blocked_reason": null,
  "lines": [ { "…": "ligne du lot 5",
               "adjustment_reason": { "code": "…", "label": "…" }, "adjustment_comment": null,
               "context": { "limit": 50, "client_validated": 10, "region_quota": 300, "region_validated": 290,
                            "region_remaining": 10, "max_validatable": 10 } } ] }
```

- `can_validate` : commande Envoyée, droit `order.validate`, journée et région non clôturées, client actif, et heure limite non bloquante ou non atteinte.
- `can_reject` : commande Envoyée, droit `order.validate`, journée non clôturée.
- `blocked_reason` : `CLIENT_BLOCKED`, `CUTOFF_PASSED`, `DAY_NOT_OPEN` ou null.
- `max_validatable` : `max(0, min(demandé, R, B − V(c,p)))` avec la même formule que le service (« Ajuster au maximum possible »).
- Les champs `*_reason_code` du lot 5 restent présents. La liste (`RegionalOrderSummary`) gagne `decided_at` et `decided_by {id, name}`.

### « Ma journée » : `GET /regional/dashboard?day=AAAA-MM-JJ` (aujourd'hui par défaut)

```json
{ "day": "2026-10-08", "opened": true, "status": "OPEN",
  "cutoffs": { "distribution": "10:00", "ordering": "13:00", "validation": "15:00", "closing": "23:00" },
  "validation_blocking": false,
  "regions": [ { "region": { "id": 3, "code": "CENTRE", "name": "Centre" },
     "status": "DISTRIBUTION_PUBLISHED", "status_label": "…",
     "quota_published_at": "…", "distribution_published_at": "…",
     "cutoffs": { "distribution": "10:00", "ordering": "13:30", "validation": "15:00" },
     "extended": { "distribution": false, "ordering": true, "validation": false },
     "orders": { "SUBMITTED": 4, "VALIDATED": 12, "REJECTED": 1, "CLOSED": 0 },
     "oldest_submitted_at": "…",
     "clients": { "notified": 40, "ordered": 17, "without_order": 23 } } ],
  "clients_without_order": [ { "id": 12, "code": "C0012", "name": "…", "status": "ACTIVE",
                               "region_id": 3, "phone": "…", "phone2": null } ] }
```

- Réponse sans enveloppe `data`, comme les autres ressources uniques de l'API (I-96).
- Journée non ouverte : `opened: false`, listes vides.
- **notifiés** : destinataires de N-03 une fois la répartition envoyée (`LimitBook::openingRecipients`), sinon 0 ; **ont commandé** : clients distincts ayant une commande comptée (Envoyée ou Validée et suivantes) ; **sans commande** : notifiés actifs moins ceux qui ont commandé (une commande seulement rejetée, annulée ou expirée ne compte pas).
- `?region=` hors périmètre → 404. Nombre de requêtes indépendant du nombre de commandes.

## 4. Motifs (EF-ADM-05)

Types : `REJECTION`, `ADJUSTMENT`, `SHIPPING_GAP`, `CANCELLATION`. Les motifs par défaut sont insérés par la migration (I-82) ; `AUTRE` exige un commentaire, sauf pour un écart d'expédition, qui n'en garde pas (I-95 : un motif SHIPPING_GAP ne peut pas exiger de commentaire).

- `GET /reason-codes?type=ADJUSTMENT[,REJECTION]` → `{"data": [{"type", "code", "label", "requires_comment", "sort_order"}]}` : motifs actifs, non paginés, par type, puis `sort_order`, puis libellé.
- `GET /admin/reason-codes?filter[type]=` → mêmes éléments avec `id`, `is_active`, `created_at`, `updated_at`, motifs désactivés compris.
- `POST /admin/reason-codes {type, code, label, requires_comment?, sort_order?}` → 201. Code `^[A-Z][A-Z0-9_]{1,29}$`, unique par type ; libellé unique par type.
- `PATCH /admin/reason-codes/{id} {label?, is_active?, requires_comment?, sort_order?}` → 200. `code` et `type` sont immuables (422). Pas de suppression : on désactive.
- Partout où un code de motif est montré, l'API ajoute son libellé `{code, label}` ; un motif désactivé garde son libellé.

## 5. ADV : traitement des commandes

### File : `GET /adv/orders`

Paramètres : `filter[status]=VALIDATED,IN_PREPARATION`, `filter[day]=AAAA-MM-JJ`, `filter[region]=3`, `filter[depot]=1|none`, `filter[client]=12`, `filter[product]=42`, `filter[search]=…` (numéro, code ou nom du client), `sort=decided_at` (par défaut, croissant : premier validé, premier préparé), `-decided_at`, `number`, `-number`, `submitted_at`, `page`, `per_page` (50 par défaut, 100 au plus).

- Statuts admis : Validée et suivantes, et Annulée après validation. Par défaut : VALIDATED, IN_PREPARATION, PREPARED, toutes journées.
- `region` ou `depot` hors périmètre → 404.

```json
{ "data": [ { "id": "01J…", "number": "RMK-261008-00042", "day": "2026-10-08", "status": "VALIDATED", "status_label": "Validée",
   "version": 2, "submitted_at": "…", "decided_at": "…", "decided_by": { "id": 9, "name": "…" },
   "prep_started_at": null, "prepared_at": null, "shipped_at": null, "delivery_note_ref": null,
   "client": { "id": 12, "code": "C0012", "name": "…", "status": "ACTIVE" },
   "region": { "id": 3, "code": "CENTRE", "name": "Centre" }, "depot": { "id": 1, "code": "BLIDA", "name": "…" },
   "lines_count": 6, "total_ht": 58500.0, "total_ttc": 69615.0, "is_adjusted": true, "has_shipping_gap": false,
   "next_statuses": ["IN_PREPARATION"], "can_cancel": true } ],
  "meta": { "current_page": 1, "last_page": 3, "per_page": 50, "total": 120,
            "counts": { "VALIDATED": 40, "IN_PREPARATION": 30, "PREPARED": 10, "SHIPPED": 38, "CANCELLED": 2 } },
  "links": {} }
```

`counts` : mêmes filtres, sauf le statut. `next_statuses` et `can_cancel` suivent les droits de l'appelant (vides pour l'Admin).

### Détail : `GET /adv/orders/{id}`

Le résumé, plus :
- `client {…, address, wilaya, commune, phone, phone2, nif, rc}` ;
- `comment`, `total_vat`, `decision_reason {code, label}`, `decision_comment` ;
- `lines [{…ligne du lot 5, adjustment_reason, adjustment_comment, shipping_gap_reason {code, label} | null}]` ;
- `history` (auteurs nommés) ;
- `can {next_statuses, edit_shipping_lines, edit_delivery_note, cancel, print}`.

### Statuts en masse : `POST /adv/orders/bulk-status`

`{"status": "IN_PREPARATION", "orders": [{"id": "01J…", "version": 2}]}` : `status` parmi IN_PREPARATION, PREPARED, SHIPPED (une seule étape à la fois) ; 1 à 100 commandes distinctes.

```json
{ "applied": [ { "id": "…", "number": "…", "status": "IN_PREPARATION", "version": 3 } ],
  "refused": [ { "id": "…", "number": "…", "code": "VERSION_CONFLICT", "detail": "…", "version": 4 } ] }
```

- Refus par élément : `NOT_FOUND` (inconnue ou hors périmètre), `INVALID_TRANSITION`, `VERSION_CONFLICT`. Un refus n'annule pas les autres (I-78).
- PREPARED et SHIPPED : `shipped_qty` = quantité validée pour les lignes non encore saisies.
- Horodatages : `prep_started_at`, `prepared_at`, `shipped_at`.

### Expédition : `PATCH /adv/orders/{id}/shipping`

`{"version": 4, "delivery_note_ref": "BL-0042", "lines": [{"product_id": 57, "shipped_qty": 12, "reason_code": "RUPTURE"}]}`

- `lines` facultatif, partiel ; `delivery_note_ref` facultatif, nullable, ≤ 50.
- Quantités modifiables en IN_PREPARATION et PREPARED ; en SHIPPED, seul le bon de livraison change.
- `0 ≤ shipped_qty ≤ validated_qty` ; motif SHIPPING_GAP obligatoire si et seulement si `shipped_qty < validated_qty`, sans commentaire (I-95). Le quota n'est jamais touché (RG-A03, Q-16).
- 200 → détail ADV. Erreurs : 403, 404, 422, 409 `INVALID_TRANSITION`, 409 `VERSION_CONFLICT`. Version incrémentée, pas de ligne d'historique (I-79).

### Annulation exceptionnelle : `POST /adv/orders/{id}/cancel`

`{"version": 4, "reason_code": "RUPTURE", "comment": "…"}` → 200 détail ADV. Possible depuis Validée ou En préparation seulement, même après la clôture de la journée ; V(r,p) et V(c,p) sont restitués. Erreurs : 403, 404, 422, 409 `INVALID_TRANSITION` (Préparée ou au-delà), 409 `VERSION_CONFLICT`.

## 6. Documents, exports et suivi

Téléchargements synchrones, en flux, jamais écrits sur le disque, `Cache-Control: no-store` (I-83). Dates JJ/MM/AAAA, heure d'Alger, montants en DA avec la mention « Montants indicatifs : la facture ERP fait foi » (RG-P05). Toute cellule qui commencerait une formule est neutralisée.

- **Bon de commande** : `GET /adv/orders/{id}/pdf` → `application/pdf`, `bon-de-commande-RMK-….pdf`. Identité du client (NIF, RC, adresse), numéro, journée, date de livraison ; lignes (SKU, produit, UV, demandé, validé, motif, expédié et écart quand ils existent, prix unitaire HT, TVA, montant HT sur la quantité validée) ; totaux. Une commande annulée porte la mention « ANNULÉE ».
- **Bon de préparation** : `GET /adv/days/{day}/picking-list?format=pdf|xlsx&group=region|depot&status=…&region=…`.
  - Par défaut : regroupement par région, commandes Validées, En préparation et Préparées de la journée ; quantités validées, lignes à 0 exclues.
  - Par groupe (« Sans dépôt » pour les régions sans dépôt), par produit (famille, puis ordre) : SKU, produit, UV, quantité en UV, nombre de commandes, palettes (`pleines`, `reste en UV`, équivalent décimal ; « — » sans colisage). Totaux par groupe et total général.
  - En-tête : journée, livraison, statuts inclus, heure de génération. Journée sans commande : document « Aucune commande ».
- **Export de la journée** : `GET /adv/days/{day}/orders/export?format=xlsx|csv|erp&filter[status]=…&filter[region]=…`, une ligne par ligne de commande.
  - `xlsx` et `csv` : tous les statuts par défaut ; colonnes Journée, Livraison, Numéro, Statut, Ajustée, Code client, Client, Région, Dépôt, SKU, Produit, UV, Demandé, Validé, Expédié, Motif ajustement, Motif écart, Prix HT, TVA %, Montant HT validé, Envoyée le, Validée le, Validée par, Expédiée le, BL. CSV : `;`, BOM UTF-8, virgule décimale (Excel français). XLSX écrit en flux (OpenSpout), cellules numériques.
  - `erp` : format **provisoire** `erp-v0` (Q-14) : Validée à Expédiée, jamais Annulée ; colonnes `day;delivery_date;order_number;client_code;region_code;depot_code;sku;validated_qty;shipped_qty;unit_price_ht;vat_rate;amount_ht;status`, dates ISO, point décimal, UTF-8 sans BOM.
  - Au-delà de 100 000 lignes → 422 sur `filter`.
- **Suivi de la journée** (EF-ADV-03) : `GET /adv/days/{day}/monitoring`

```json
{ "day": "…", "status": "OPEN", "cutoffs": { "distribution": "10:00", "ordering": "13:00", "validation": "15:00", "closing": "23:00" }, "generated_at": "…",
  "totals": { "orders": { "SUBMITTED": 0, "VALIDATED": 0, "IN_PREPARATION": 0, "PREPARED": 0, "SHIPPED": 0, "REJECTED": 0, "CANCELLED": 0, "EXPIRED": 0 },
              "clients": { "notified": 0, "ordered": 0 } },
  "regions": [ { "region": { "id": 3, "code": "…", "name": "…", "depot": null }, "status": "…", "status_label": "…",
     "quota_published_at": "…", "distribution_published_at": null,
     "cutoffs": { "distribution": "…", "ordering": "…", "validation": "…" },
     "clients": { "notified": 0, "ordered": 0 }, "orders": { "…": 0 }, "last_validated_at": null,
     "alerts": ["DISTRIBUTION_LATE", "VALIDATION_LATE"] } ],
  "products": [ { "product_id": 42, "sku": "…", "short_name": "…", "family": { "id": 1, "name": "…" },
     "quota": 0, "validated": 0, "pending": 0, "remaining": 0, "sold_out_regions": [3] } ],
  "cells": { "3": { "42": { "quota": 300, "validated": 290, "pending": 25 } } } }
```

  - `DISTRIBUTION_LATE` : quotas publiés, répartition non envoyée, heure limite de répartition dépassée. `VALIDATION_LATE` : commandes Envoyées et heure limite de validation dépassée. Épuisé : Q > 0 et R = 0. Alertes visuelles seulement (N-13 au lot 7).
- **Réconciliation nocturne** (§5.8) : commande `ramymaak:reconcile-quotas {--from=} {--to=}` (7 dernières journées par défaut), planifiée chaque nuit à 02:10 (heure d'Alger). Compare chaque compteur V(r,p) à la somme des quantités validées ; écart → `Log::error` et code de sortie 1, jamais de correction automatique.

## 7. Notifications et temps réel

| Type | Code | Déclencheur | Destinataires | Titre / corps | `url` |
|---|---|---|---|---|---|
| `ORDER_DECIDED` | N-07 | `OrderValidated`, `OrderRejected`, `OrderCancelledByAdv` | comptes actifs de rôle client du client | textes du lot 5 (validée, validée avec n lignes ajustées, rejetée, annulée) | `/client/commandes/{id}` |
| — | N-08 | `OrderValidated` | ADV et Admin | pas de notification enregistrée : liste et compteur en temps réel (I-80) | — |
| `ORDER_SHIPPED` | N-09 | `OrdersProcessed` vers SHIPPED | comptes actifs de rôle client du client | « Commande RMK-… expédiée » / « Votre commande RMK-… a été expédiée. » | `/client/commandes/{id}` |
| `CUTOFF_EXTENDED` | — | `CutoffExtended`, type `VALIDATION` | régionaux de la région | « Heure limite de validation prolongée » / « Validez jusqu'à 15:30. » | `/regional/commandes` |

Clés d'unicité : N-07 par commande, version et destinataire ; N-09 par commande et destinataire. Aucun montant dans les textes. N-12, N-13 et le push arrivent au lot 7.

Temps réel (identifiants seulement) :

| Canal | Événement | Contenu |
|---|---|---|
| `region-day.{r}.{day}` | `OrderValidated`, `OrderRejected`, `OrderCancelledByAdv` | `{ id, number, status, version, day, client_id }` (un par commande) |
| `region-day.{r}.{day}` | `OrdersProcessed` | `{ day, status, count }` : un seul par journée régionale et par changement de statut en masse (I-97) |
| `client.{c}` | `OrderStatusChanged` | `{ id, number, status, version, day }` à chaque décision, statut de traitement ou annulation ADV |
| `adv.{day}` | `OrderValidated`, `OrderShippingUpdated`, `OrderCancelledByAdv` | `{ id, status, version, day }` (sans région ni client, I-81) |
| `adv.{day}` | `OrdersProcessed` | `{ day, status, count }`, un seul par journée et par changement en masse |

Les écrans qui montrent V se relisent sur ces événements : grille de répartition (régional, ADV, Admin), quotas du jour du régional, matrice de l'ADV, suivi de la journée, « Ma journée », commandes reçues, file ADV.

## 8. Écrans

| Rôle | Chemin | Contenu |
|---|---|---|
| Régional | `/regional` | **Ma journée** (EF-REG-01) : remplace l'accueil ; onglet « Ma journée » |
| Régional | `/regional/commandes/:id` | **Détail et décision** (EF-REG-05) |
| ADV | `/adv/commandes`, `/adv/commandes/:id` | **File des commandes validées** (EF-ADV-04), détail, expédition, annulation, bon de commande |
| ADV | `/adv/journees/:day/suivi` | **Suivi de la journée** (EF-ADV-03), bon de préparation, export |
| Admin | `/admin/commandes`, `/admin/commandes/:id`, `/admin/journees/:day/suivi` | mêmes écrans en lecture |
| Admin | `/admin/motifs` | **Motifs** (EF-ADM-05) |
| Client | `/client/commandes/:id` | libellés des motifs, « Validée avec ajustement », quantités expédiées et bon de livraison une fois expédiée |

**Décision (régional)** :
- **Brouillon local** par commande et version : une relecture en direct ne l'écrase jamais. Si la version change (« modifiée par le client »), un bandeau le dit et le brouillon repart de la commande à jour après confirmation. Si seul le contexte change, les maximums se mettent à jour et les lignes au-dessus sont signalées.
- **Par ligne** : demandé, borne, déjà validé pour ce client, reste région, reste après validation (`region_remaining − q`, et `limit − client_validated − q` avec une borne) ; sélecteur de quantité (au plus le demandé) ; motif d'ajustement et commentaire quand la ligne est réduite (le commentaire est visible du client).
- **« Ajuster au maximum possible »** : applique `max_validatable` à chaque ligne ; les motifs restent à choisir.
- **Actions** : « Valider » (« Valider avec ajustement (n) » si des lignes sont réduites) ; « Rejeter » ouvre le choix du motif et le commentaire. Toutes les lignes à 0 : « Valider » est désactivé et le rejet proposé.
- **Erreurs** : `VERSION_CONFLICT` → relecture et bandeau ; dépassement → explication par ligne avec `max_allowed` et « Appliquer n » ; `INVALID_TRANSITION` → « déjà traitée », relecture ; `CUTOFF_PASSED` et `CLIENT_BLOCKED` → bandeau, seul le rejet reste.
- **Après une décision** : « Commande suivante » (la plus ancienne à valider) ou retour à la liste. Barre d'actions fixe au-dessus de la barre d'onglets. Hors ligne : actions désactivées.

**Ma journée (régional)** : choix aujourd'hui / demain ; une carte par région (statut, heures limites dont la validation, « bloquante » ou « prolongée », « n à valider » vers la liste, raccourci vers la grille) ; « Clients sans commande » par région avec l'appel en un geste et le lien vers la fiche du client. Mise à jour en temps réel.

**File ADV** : onglets « À préparer », « En préparation », « Préparées », « Expédiées », « Annulées » avec compteurs ; filtres journée, région, dépôt, client, produit ; sélection multiple (100 au plus) et changement de statut en masse avec le compte rendu « n appliquées / m refusées » et leurs motifs ; temps réel sur `adv.{jour}` pour chaque journée affichée. Le menu « Commandes » de l'ADV porte le nombre de commandes à préparer (N-08).

**Détail ADV** : client, lignes, historique ; saisie des quantités expédiées et du motif d'écart (En préparation, Préparée), du bon de livraison (jusqu'à Expédiée) ; étape suivante ; « Annuler la commande » (motif et commentaire) ; « Bon de commande (PDF) ».

**Suivi de la journée (ADV, Admin)** : chiffres clés, tableau des régions avec alertes, matrice produit × région V/Q (épuisés en évidence) ; boutons « Bon de préparation » (regroupement et format) et « Export » (format). Mise à jour en temps réel.

**Motifs (Admin)** : un onglet par type, liste, création et modification (code non modifiable après création), activation, ordre.

**Téléchargements** : par requête authentifiée puis enregistrement du fichier ; une erreur 4xx s'affiche comme un message, jamais comme un fichier.

**Hors ligne** : décisions, statuts, expédition, annulation et téléchargements désactivés.

## 9. Répartition du travail (fichiers possédés)

Le socle (commits du lot 6) fournit migrations, modèles, `Domain/Orders/{OrderDecisions, AdvOrders, ValidationWindow, DecisionRights, OrderTotals, OrderHistory, OrderVersion, OrderLocks}`, `Domain/Quotas/QuotaLedger`, `Domain/Reasons/*`, `Rules/ActiveReasonCode`, événements, routes (contrôleurs provisoires à remplacer, mêmes noms de classes et de méthodes), `OrderPresenter` (motifs avec libellé), types et bundles PWA, pages provisoires et navigation.

| Volet | Contenu | Fichiers |
|---|---|---|
| A | Décision du régional, « Ma journée », motifs (API) | contrôleurs `Regional/RegionalOrderDecisionController`, `Regional/RegionalDashboardController`, `ReasonCodeController`, `Admin/ReasonCodeController` ; requêtes et ressources associées ; `RegionalOrderResource`, `RegionalOrderSummaryResource` ; lecture « Ma journée » ; leurs tests |
| B | File ADV, statuts, expédition, annulation, suivi (API) | `Adv/AdvOrderController` (dont `pdf()` qui délègue au volet C), `Adv/DayMonitoringController` ; requêtes et ressources ADV ; lecture du suivi ; `RegionDayResource` (heure limite de validation) ; leurs tests |
| C | Documents et exports | `Adv/DayDocumentController`, `Domain/Documents/*` (bon de commande, bon de préparation, export), vues PDF ; leurs tests |
| D | Notifications, temps réel, réconciliation | écouteurs N-07 et N-09, notification N-09, relais `BroadcastOrderActivity`, événements temps réel, commande `ramymaak:reconcile-quotas` et sa planification ; leurs tests |
| E | Concurrence MySQL | `tests/Feature/Concurrency/*` (outillage commun extrait) |
| F | PWA régional | `modules/regional/orders/**`, `modules/regional/home/**`, `modules/regional/quotas/**`, bundles `orders.decision` et `orders.myDay` |
| G | PWA ADV (back-office) | `modules/backoffice/orders/**`, `modules/backoffice/monitoring/**`, `modules/backoffice/days/**`, listes d'événements des écrans back-office, compteur du menu « Commandes », bundles back-office des commandes, du suivi et des documents |
| H | Motifs (Admin) et affichage client | `modules/backoffice/reasons/**`, `modules/client/orders/**`, `ClientOrderResource`, bundles des motifs et de « Mes commandes » |


## 10. Décisions proposées

| # | Décision |
|---|---|
| I-67 | Ordre des verrous commun à tout écrivain (client → journée(s) en partagé → journée régionale en partagé pour la validation → commande(s) → bornes → compteurs → numéro) ; jamais de verrou de journée après une commande |
| I-68 | Contrôles de la validation : `INVALID_TRANSITION`, puis `VERSION_CONFLICT`, puis journée, heure limite, client, sens des lignes, plafonds ; mise à jour conditionnelle de la version gardée en filet |
| I-69 | Un dépassement liste toutes les lignes en cause, avec leur cause (quota région ou borne) et le maximum validable |
| I-70 | Client bloqué ou inactif : validation refusée (`CLIENT_BLOCKED`), rejet permis |
| I-71 | RG-V08 : l'heure limite de validation, si elle est bloquante, ne bloque que la validation ; prolongation par région (`kind: VALIDATION`) |
| I-72 | Toutes les lignes sont transmises ; motif sur les lignes réduites seulement ; toutes à 0 → rejeter ; `is_adjusted` dès qu'une ligne est réduite |
| I-73 | Commentaire d'ajustement par ligne (200 caractères), visible du client |
| I-74 | Rejet : quantités validées nulles, totaux inchangés ; annulation ADV : quantités et totaux validés gardés |
| I-75 | Annulation ADV : motif et commentaire dans `decision_reason_code` / `decision_comment`, la validation reste dans `decided_*` ; auteur et date dans l'historique |
| I-76 | Annulation ADV possible après la clôture (V restitué) ; jamais depuis Préparée ou au-delà ; elle libère une commande du jour pour le client |
| I-77 | Quantité préparée = quantité expédiée : égale au validé par défaut à Préparée et à Expédiée, modifiable en préparation, figée à l'expédition (seul le bon de livraison change) ; 0 permis avec un motif |
| I-78 | Statuts en masse : une étape à la fois, version par commande, résultat partiel, 100 commandes au plus ; Livrée (V2) refusée |
| I-79 | Expédition : version incrémentée, sans ligne d'historique ; le client voit l'expédié une fois la commande expédiée |
| I-80 | N-08 sans notification enregistrée : compteur et liste en temps réel pour l'ADV et l'Admin |
| I-81 | `adv.{jour}` porte les identifiants seuls (ni région ni client) ; le suivi relit les données filtrées par périmètre |
| I-82 | Motifs par défaut insérés par la migration ; code et type immuables ; pas de suppression ; un motif désactivé garde son libellé |
| I-83 | Documents et exports synchrones et en flux (OpenSpout pour l'XLSX) ; file d'attente seulement si les tests de charge du lot 8 l'exigent |
| I-84 | Bon de préparation : journée de la commande ; Validées, En préparation, Préparées ; par région (par défaut) ou par dépôt actuel de la région, avec « Sans dépôt » ; palettes en pleines + reste + décimal ; sans détail par client |
| I-85 | CSV pour Excel français (`;`, BOM, virgule décimale) ; format ERP provisoire `erp-v0` (point décimal, dates ISO) en attendant Q-14 |
| I-86 | Bon de commande PDF : ADV seulement, une commande à la fois |
| I-87 | Admin : file, détail, suivi et export en lecture ; ni bon de préparation ni bon de commande |
| I-88 | « Clients sans commande » : destinataires de N-03 moins les clients ayant une commande comptée, clients actifs seulement |
| I-89 | Suivi : clients notifiés recalculés comme N-03 ; alertes visuelles (retard de répartition, retard de validation, épuisé) ; N-13 au lot 7 |
| I-90 | File ADV par défaut : travail en cours toutes journées (Validées, En préparation, Préparées), par heure de validation ; périmètre jugé à la journée de chaque commande |
| I-91 | Borne 0 → validable 0 ; pas de borne → pas de plafond client, même en politique OBLIGATOIRE ; produit désactivé après l'envoi → toujours validable |
| I-92 | Renvoi d'une décision déjà appliquée (même auteur, version suivante) : 200 avec l'état actuel, sans effet |
| I-93 | Réconciliation : 7 dernières journées, chaque nuit, rapport seul (journal d'erreur et code de sortie 1) |
| I-94 | Après un rejet ou une annulation ADV, le client peut renvoyer une commande avant l'heure limite de commande (RG-C01, RG-C04) |
| I-95 | Écart d'expédition : motif sans commentaire ; un motif SHIPPING_GAP ne peut pas exiger de commentaire |
| I-96 | « Ma journée » et suivi de la journée : réponses sans enveloppe `data` |
| I-97 | Changement de statut en masse : un seul événement `OrdersProcessed` par journée régionale et par journée, pour ne pas relire chaque écran cent fois |
| I-98 | Heure limite de validation prolongeable par région ; la fiche de la journée de l'ADV l'affiche avec les autres |

## 11. Précisions (implémentation de l'API)

Ce que les volets de l'API ont retenu ; la PWA s'y conforme.

**Régional**
- « Ma journée » et le suivi de la journée sont **sans enveloppe `data`** (I-96) : `api.get<RegionalDashboard>` et `api.get<DayMonitoring>`.
- Journée non ouverte : `opened: false`, `status` et `cutoffs` à null, listes vides ; `validation_blocking` porte la valeur réelle du paramètre.
- `clients_without_order[].region_id` est la région du jour (la région effective), pas forcément celle de la fiche du client si un changement est programmé. `ordered` peut dépasser `notified` si la politique « borne obligatoire » a changé depuis l'envoi.
- `GET /reason-codes` et la liste Admin : groupes par code de type dans l'ordre alphabétique (ADJUSTMENT, CANCELLATION, REJECTION, SHIPPING_GAP), puis `sort_order`, puis libellé, quel que soit l'ordre de `?type=`.
- Le rejet vérifie dès la forme que `reason_code` est un motif REJECTION actif ; le commentaire exigé par le motif (AUTRE) est contrôlé par le service, après les 409.
- Rejouer une décision dont le motif a été désactivé entre-temps répond 422 (la forme est contrôlée avant le rejeu) et non 200 : cas limite accepté.

**ADV**
- Détail, expédition, annulation et bon de commande répondent **404** pour une commande hors du dossier de l'ADV (envoyée, rejetée, expirée, annulée par le client), pour l'ADV comme pour l'Admin.
- `filter[status]` n'admet que Validée et suivantes, plus Annulée (422 sinon) ; `meta.counts` a exactement 5 clés (VALIDATED, IN_PREPARATION, PREPARED, SHIPPED, CANCELLED).
- Statuts en masse : un identifiant mal formé ou en double répond 422 sur `orders.N.id` ; un identifiant inconnu ou hors périmètre est refusé `NOT_FOUND` dans le résultat.
- Filtres région et dépôt hors périmètre → 404. Avec `filter[day]`, le périmètre est celui de ce jour ; sans journée, un ADV restreint voit les régions de ses affectations non révoquées, quelle que soit la date, et chaque commande reste jugée à sa journée.
- Suivi : alertes seulement sur une journée ouverte et une région non clôturée, seuil atteint (`>=`) ; `VALIDATION_LATE` ignore le caractère bloquant ; `cells` contient une clé par région visible (objet vide sans cellule) et une cellule n'existe que si quota > 0 ou en attente > 0 ; `last_validated_at` ignore les rejets.
- Bon de préparation et export : clés de requête `format`, `group`, `status`, `region` (bon) et `format`, `filter[status]`, `filter[region]` (export) ; le nom du fichier est dans `Content-Disposition` ; une erreur 4xx arrive en JSON (Problem Details), jamais comme un fichier. Une journée inconnue répond 404 avant les contrôles de droits.
- La colonne « Dépôt » est celle d'aujourd'hui de la région, pas figée sur la commande.
- Vitesse de l'export Excel : environ 3 600 lignes par seconde (une journée de 45 000 lignes ≈ 12 s) ; la file d'attente reste le repli si les tests de charge du lot 8 le demandent.

**Temps réel**
- `OrderRejected` n'est pas relayé sur `adv.{day}`.
- Sur `adv.{day}`, `OrderValidated`, `OrderCancelledByAdv` et `OrderShippingUpdated` portent `{ id, status, version, day }` sans numéro ; `OrdersProcessed` porte `{ day, status, count }`, un seul par journée. Le statut de `OrderShippingUpdated` est lu au moment du relais.
- `OrderStatusChanged` sur `client.{c}` porte le numéro de la commande.

**Réconciliation** : période par défaut = les 7 dernières journées ouvertes jusqu'à aujourd'hui (heure d'Alger) ; les dépassements I-1 et I-2 sont des avertissements (`Log::warning`) sans effet sur le code de sortie ; options invalides → code 2 ; planification chaque nuit à 02:10 avec `withoutOverlapping(30)`.

**Motifs** : `SHIPPING_GAP` + `AUTRE` n'exige pas de commentaire (I-95).
