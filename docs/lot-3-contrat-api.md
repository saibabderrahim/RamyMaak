# Lot 3 — Quotas ADV : contrat d'API et d'écrans

Référence commune pour l'implémentation du lot 3 (§16 : journées, matrice, import Excel, publication, N-01 et N-02). Critère de fin : **une journée publiée de bout en bout côté ADV**. Les formes JSON ci-dessous font foi pour le back-end **et** pour la PWA.

## 1. Conventions

Celles du lot 2 s'appliquent (docs/lot-2-contrat-api.md §1) :
- droits contrôlés **avant** la validation (403 avant 422) ;
- objet hors périmètre → 404 ;
- erreurs « Problem Details » ;
- un fichier `lang/fr/<domaine>.php` par domaine.

S'y ajoutent les règles suivantes.

- **Journée** : désignée dans les URL par sa date `AAAA-MM-JJ` (paramètre de route `{businessDay}`, lié par `App\Providers\AppServiceProvider` ; une journée non ouverte ou une date mal formée répond 404).
- **Heures** :
  - en base, des instants UTC ;
  - dans l'API, deux formes : `"HH:MM"` dans le fuseau métier Africa/Algiers (`App\Support\BusinessClock::toLocal`), et l'instant ISO 8601 (`*_at`) ;
  - en saisie, toujours `"HH:MM"` dans le fuseau métier.
- **Services partagés à utiliser, pas à réécrire** :
  - `App\Domain\Days\DayCalendar` : ouverture, heures limites, prolongation, clôture, `regionDayFor`, `isWorkingDay`.
  - `App\Domain\Quotas\QuotaBook` : `saveDrafts`, `copyFrom`, `publish`.
  - `App\Domain\Days\RegionStaff` : régionaux d'une région un jour donné.
  - `App\Domain\Access\Perimeter` (périmètre ADV, RG-S05).

  Ces services lèvent eux-mêmes `DAY_NOT_OPEN` (409), `QUOTA_BELOW_VALIDATED` (409, avec `violations`), `INVALID_TRANSITION` (409) et des `ValidationException` (422).
- **Événements de domaine**, diffusés après validation de la transaction (`ShouldDispatchAfterCommit`) :
  - `App\Events\QuotasPublished` (`regionDayId`, `version`, `first`, `changedProducts`, `actorId`) ;
  - `App\Events\QuotaDraftsSaved` (`businessDayId`, `regionIds`, `actorId`), émis par `QuotaBook::saveDrafts` (saisie, copie, import) quand au moins une cellule change ;
  - `App\Events\CutoffExtended` (`regionDayId`, `kind`, `actorId`) ;
  - `App\Events\DayClosed` (`businessDayId`).

  Les notifications et la diffusion temps réel s'y abonnent (écouteurs dans `app/Listeners`).
- **Audit** :
  - journal `days` : `opened`, `schedule.updated`, `cutoff.extended`, `closed` ;
  - journal `quotas` : `published`, puis `updated` pour les publications suivantes ; propriétés `day`, `region`, `version` et `changes` (`[{product_id, sku, old, new}]`). Écrits par les services.
- **Tâches planifiées** (déclarées dans `routes/console.php`) :
  - `ramymaak:close-days` chaque minute (§4.4) ;
  - `ramymaak:apply-product-deactivations` à 00:01, heure d'Alger (RG-Q07).

## 2. Droits

| Action | Permission | Rôles par défaut |
|---|---|---|
| Lire le calendrier, une journée, la matrice | `quota.view` | ADV, Admin (lecture) ; le régional passe par `/regional/quotas` |
| Ouvrir une journée, modifier ses heures limites et sa date de livraison | `day.manage` | ADV |
| Prolonger une heure limite pour une région (RG-A05) | `day.extend-cutoff` | ADV, Admin |
| Saisir des brouillons, copier depuis une journée, importer | `quota.edit` | ADV |
| Publier | `quota.publish` | ADV |
| Lire les quotas **publiés** de ses régions | `quota.view` + périmètre | Régional |

Périmètre :
- un ADV restreint à une liste de régions (RG-S05) ne voit et ne modifie que les colonnes de ses régions ;
- les cellules ou régions hors périmètre envoyées en écriture → 422 sur le champ concerné (« Région hors de votre périmètre. ») ;
- le client n'a accès à rien de ce lot.

## 3. Journées

### Ressources

`RegionDay`
```json
{ "region": { "id": 3, "code": "CENTRE", "name": "Centre" },
  "status": "QUOTA_PUBLISHED", "status_label": "Quotas publiés",
  "quota_version": 2,
  "quota_published_at": "2026-10-08T06:55:00+00:00", "quota_last_published_at": "2026-10-08T08:10:00+00:00",
  "pending_drafts": 3,
  "cutoffs": { "distribution": "11:15", "ordering": "13:00" },
  "extended": { "distribution": true, "ordering": false } }
```
- `status` : `QUOTA_DRAFT`, `QUOTA_PUBLISHED`, `DISTRIBUTION_DRAFT`, `DISTRIBUTION_PUBLISHED`, `CLOSED` (§6.1).
- `pending_drafts` : nombre de cellules en brouillon non publié.
- `cutoffs` : heures limites applicables à la région, prolongation comprise.

`Day`
```json
{ "day": "2026-10-08", "weekday": 4, "is_working_day": true,
  "status": "OPEN", "status_label": "Ouverte",
  "delivery_date": "2026-10-11",
  "cutoffs": { "distribution": "10:00", "ordering": "13:00", "validation": "15:00", "closing": "23:00" },
  "cutoffs_at": { "distribution": "2026-10-08T09:00:00+00:00", "ordering": "…", "validation": "…", "closing": "2026-10-08T22:00:00+00:00" },
  "closed_at": null,
  "regions": [RegionDay],
  "created_by": { "id": 4, "username": "a.adv", "name": "ADV" } | null,
  "created_at": "…" }
```
- `weekday` : 0 = dimanche … 6 = samedi.
- `is_working_day` : selon le paramètre `calendar.working_days`.
- `regions` : régions **actives** du périmètre (celles ajoutées depuis l'ouverture apparaissent avec le statut `QUOTA_DRAFT` grâce à `DayCalendar::syncRegions`), triées par code.

### Points d'accès

| Méthode | Chemin | Corps / filtres | Réponse |
|---|---|---|---|
| GET | `/adv/days` | `from`, `to` (`AAAA-MM-JJ`, défaut : aujourd'hui − 7 à aujourd'hui + 14, écart ≤ 62 jours) | `{ "data": [Day] }`, journées **ouvertes** de l'intervalle, par date ; l'écran affiche les autres comme non ouvertes |
| POST | `/adv/days` | `day` (requis, `AAAA-MM-JJ`, de aujourd'hui à aujourd'hui + 30) | 201 `Day` (`DayCalendar::open`) ; déjà ouverte → 409 `INVALID_TRANSITION` ; jour passé → 422. Un jour non ouvré peut être ouvert : l'écran demande confirmation |
| GET | `/adv/days/{day}` | — | `Day` |
| PATCH | `/adv/days/{day}` | `cutoffs` : `{ distribution?, ordering?, validation?, closing? }` (`HH:MM`), `delivery_date` (≥ la journée) | `Day` (`DayCalendar::updateSchedule`) ; ordre strict répartition < commande < validation < clôture, clôture à venir ; journée clôturée → 409 `DAY_NOT_OPEN` |
| POST | `/adv/days/{day}/regions/{region}/extend-cutoff` | `kind` (`DISTRIBUTION` ou `ORDERING`), `until` (`HH:MM`) | `RegionDay` (`DayCalendar::extendCutoff`) ; plus tôt que l'heure actuelle ou après la clôture → 422 ; région hors périmètre → 404 |

Clôture (§4.4) :
- `ramymaak:close-days` appelle `DayCalendar::closeDue()` ;
- journée et journées régionales passent à `CLOSED`, et les quotas ne sont plus modifiables ;
- la commande est rejouable sans double effet.

## 4. Quotas (EF-ADV-02, §5.2)

### Matrice — `GET /adv/days/{day}/quotas`

```json
{ "day": Day,
  "regions": [ { "id": 3, "code": "CENTRE", "name": "Centre", "status": "QUOTA_PUBLISHED", "quota_version": 2, "pending_drafts": 3 } ],
  "products": [ { "id": 42, "sku": "P0042", "name": "Jus orange 1 L", "short_name": "Orange 1 L",
                  "family": { "id": 2, "code": "JUS", "name": "Jus" }, "sales_unit_label": "Fardeau 6 × 1 L",
                  "is_active": true } ],
  "cells": { "42": { "3": { "quantity": 500, "draft": 450, "validated": 0 } } } }
```
- **Colonnes** : régions actives du périmètre, par code.
- **Lignes** :
  - les produits actifs, triés par famille (`sort_order` puis code de la famille), puis par `sort_order` et `sku` du produit ;
  - plus les produits devenus inactifs qui gardent un quota publié > 0 ce jour-là. Ceux-ci sont en lecture seule (`is_active: false`).
- **Cellules** :
  - seules celles qui ont une ligne en base sont renvoyées ;
  - `quantity` est le quota publié Q(r,p), `draft` le brouillon (`null` : aucun changement en attente), `validated` le compteur V(r,p).
- **Valeur affichée** = `draft ?? quantity`. Les totaux (par produit et par région) se calculent à l'écran.

### Points d'accès

| Méthode | Chemin | Corps | Réponse |
|---|---|---|---|
| PUT | `/adv/days/{day}/quotas` | `cells` : 1 à 2 000 éléments `{ region_id, product_id, quantity }`, `quantity` entier de 0 à 1 000 000 ou `null` (annule le brouillon de la cellule) | `{ "changed": 2, "cells": [ { "region_id": 3, "product_id": 42, "quantity": 500, "draft": 450, "validated": 0 } ] }`, l'état des cellules envoyées (`QuotaBook::saveDrafts`) ; produit inactif, région inactive ou hors périmètre → 422 sur `cells.N.*` |
| POST | `/adv/days/{day}/quotas/copy` | `source_day` (requis, journée ouverte ou clôturée, différente), `mode` (`REPLACE` ou `FILL`), `region_ids` (facultatif) | `{ "changed": 120 }` (`QuotaBook::copyFrom`) ; quotas **publiés** de la journée source, en brouillon |
| POST | `/adv/days/{day}/quotas/publish` | `region_ids` (liste) **ou** `all: true` | `{ "published": [ { "region_id": 3, "code": "CENTRE", "version": 2, "first": false, "changes": 3 } ], "skipped": [ { "region_id": 4, "code": "EST", "reason": "NO_CHANGES" } ] }` (`QuotaBook::publish`) |

Règles de la publication :
- **Tout ou rien.** Une diminution sous le validé (RG-Q05) répond 409 `QUOTA_BELOW_VALIDATED`, avec `violations: [{ region_id, region, product_id, sku, validated, requested }]`.
- **Première publication.** Elle exige au moins un quota > 0. Pour une région demandée explicitement, sinon → 422 sur `region_ids`. Avec `all`, la région est simplement écartée (`reason: "EMPTY"`).
- **Raisons de mise à l'écart** : `NO_CHANGES` (déjà publiée, aucun brouillon), `EMPTY`, `CLOSED`.
- **Version et notifications.** Chaque publication incrémente `quota_version` (RG-Q08) et déclenche `QuotasPublished` : N-01 à la première publication, N-02 ensuite.
- **RG-Q02.** Un brouillon sur un produit désactivé depuis n'est pas publié ; il est effacé.

### Lecture par le régional — `GET /regional/quotas?day=AAAA-MM-JJ`

`day` vaut aujourd'hui par défaut. Le point d'accès est réservé au rôle régional, et seules les régions de son périmètre à cette date sont renvoyées.

```json
{ "day": "2026-10-08", "opened": true, "status": "OPEN",
  "cutoffs": { "distribution": "10:00", "ordering": "13:00", "validation": "15:00", "closing": "23:00" },
  "regions": [ { "region": { "id": 3, "code": "CENTRE", "name": "Centre" },
                 "status": "QUOTA_PUBLISHED", "quota_version": 2, "quota_last_published_at": "…",
                 "cutoffs": { "distribution": "11:15", "ordering": "13:00" },
                 "quotas": [ { "product": { "id": 42, "sku": "P0042", "name": "Jus orange 1 L",
                                            "family": { "id": 2, "code": "JUS", "name": "Jus" }, "sales_unit_label": "Fardeau 6 × 1 L" },
                               "quantity": 500, "validated": 0 } ] } ] }
```
- **Contenu** : seuls les quotas **publiés** > 0 apparaissent ; aucun brouillon n'est jamais exposé.
- **Région non encore publiée** : `quotas: []` et `status: "QUOTA_DRAFT"` ; l'écran affiche « En attente des quotas ».
- **Journée non ouverte** : `opened: false`, `status: null`, `cutoffs: null`, `regions: []`.

### Désactivation d'un produit (RG-Q07)

- **Cas visé** : un produit avec un quota **publié** > 0 sur la journée **ouverte d'aujourd'hui**.
- **Effet de `PATCH /products/{id}` avec `is_active: false`** :
  - le produit reste actif ;
  - `deactivate_on` est fixé à demain ;
  - `ProductResource` expose `pending_deactivation_on: "AAAA-MM-JJ" | null`.
- **Application** : `ramymaak:apply-product-deactivations` désactive les produits arrivés à échéance (journal `referentials`).
- **Réactivation** : `is_active: true` annule une désactivation programmée.

## 5. Import des quotas (§8.1, annexe B.1)

- `POST /imports` (multipart) prend `type=QUOTAS`, `business_day` (`AAAA-MM-JJ`, journée ouverte et non clôturée) et `mode` (`REPLACE` ou `COMPLETE`). Le droit requis est `quota.edit`.
- Gabarit : `GET /imports/templates/quotas?day=AAAA-MM-JJ`. Il contient une ligne par produit actif et une colonne par région active du périmètre, pré-remplies avec la valeur courante (`draft ?? quantity`). La colonne `designation (information)` est ignorée à l'import.
- **En-tête** : `code_produit`, puis une colonne par **code région**. Un code région inconnu, inactif ou hors périmètre est une erreur de fichier (« Colonne EST2 : code région inconnu »).
- **Ligne** : la clé est `code_produit`. Un produit inconnu ou inactif est une erreur (« Ligne 14, colonne code_produit : code produit P0999 inconnu ou inactif »), de même qu'un doublon (« … déjà présent ligne 9 »).
- **Valeurs** :
  - entier ≥ 0 et ≤ 1 000 000 ; sinon : « Ligne 18, colonne OUEST : 12,5 n'est pas un entier positif » ;
  - une valeur inférieure au compteur validé est refusée : « Ligne 9, colonne CENTRE : 250 inférieur aux 300 déjà validés ».
- **Modes** :
  - **Remplacer** : pour chaque région présente dans le fichier, chaque produit actif prend la valeur du fichier ; une cellule vide ou un produit absent vaut 0.
  - **Compléter** : seules les cellules renseignées changent.
- **Aperçu** : une ligne par produit modifié ; `changes` a pour clés les **codes région** (`{ "CENTRE": { "old": 500, "new": 450 } }`), et `old` vaut la valeur courante (`draft ?? quantity`).
- **Application** : `QuotaBook::saveDrafts` en une transaction. Elle crée des **brouillons**, sans jamais publier (RG-Q09).
- **`ImportResource`** gagne `business_day` (`AAAA-MM-JJ` ou null) et `mode` (`REPLACE`, `COMPLETE` ou null).

## 6. Notifications et temps réel (§7.8, §9.6)

### Centre de notifications (EF-NOT-01)

`Notification`
```json
{ "id": "9b2d…", "type": "QUOTAS_PUBLISHED", "code": "N-01",
  "title": "Quotas du 08/10 reçus — région Centre",
  "body": "À répartir avant 10:00.",
  "url": "/regional/quotas?day=2026-10-08",
  "read_at": null, "created_at": "…" }
```

| Méthode | Chemin | Réponse |
|---|---|---|
| GET | `/notifications` | liste paginée de `Notification` (plus récentes d'abord), `filter[unread]=1` ; `meta.unread_count` |
| POST | `/notifications/{id}/read` | `Notification` (ses propres notifications seulement, sinon 404) |
| POST | `/notifications/read-all` | `{ "updated": 4 }` |

Notifications du lot, envoyées par une tâche de fond (EF-NOT-06) aux régionaux de la région ce jour-là (`RegionStaff::regionals`), titulaire et suppléants :

| Type | Code | Déclencheur | Titre / corps |
|---|---|---|---|
| `QUOTAS_PUBLISHED` | N-01 | `QuotasPublished` avec `first` | « Quotas du 08/10 reçus — région Centre » / « À répartir avant 10:00. » (heure limite de répartition de la région) |
| `QUOTAS_UPDATED` | N-02 | `QuotasPublished` sans `first` | « Quotas du 08/10 modifiés — région Centre » / « L'ADV a modifié 3 quotas. » |
| `CUTOFF_EXTENDED` | — (RG-A05) | `CutoffExtended` | « Heure limite prolongée — région Centre » / « Répartition jusqu'à 11:15. » |

- Canaux : base de données (centre de notifications) et diffusion (`broadcast`) sur `user.{id}`. Le push arrive au lot 7, l'e-mail est reporté.
- **Rejouable** : une tâche relancée ne renvoie pas une notification déjà envoyée, grâce à une clé d'unicité par événement, destinataire et version.

### Canaux temps réel

Déclarés dans `routes/channels.php`, ils passent par `POST /broadcasting/auth` (session Sanctum, jeton XSRF).

| Canal privé | Accès | Événements (nom diffusé) |
|---|---|---|
| `user.{id}` | l'utilisateur | notifications (`.Illuminate\Notifications\Events\BroadcastNotificationCreated`) |
| `region-day.{regionId}.{day}` | `quota.view` + région du périmètre ce jour-là (régionaux, ADV, Admin) | `QuotaPublished` `{ region_id, day, version, first }` |
| `adv.{day}` | ADV, Admin | `QuotaPublished` (même contenu), `DraftsUpdated` `{ region_ids, day }` (sur `QuotaDraftsSaved`), `DayClosed` `{ day }` |

- Un événement porte un identifiant et un résumé : l'application relit les données par l'API.
- `GET /auth/me` gagne `realtime: { key } | null`. La connexion se fait au même domaine, sur le chemin `/app` (Reverb, protocole Pusher) ; en développement, Vite relaie `/app` vers Reverb.
- Si `realtime` est null ou que la connexion échoue, l'application se rafraîchit toutes les 30 secondes (§9.6).

## 7. Écrans

| Rôle | Chemin | Contenu |
|---|---|---|
| ADV | `/adv/journees` | Calendrier (EF-ADV-01) : journées d'aujourd'hui − 7 à + 14, statut par région (pastilles), accès direct à J et J+1, « Ouvrir la journée » (confirmation si jour non ouvré) |
| ADV | `/adv/journees/:day` | Journée : heures limites et date de livraison (modification), régions (statut, version, dernière publication, heures limites applicables, « Prolonger »), lien vers la matrice |
| ADV | `/adv/journees/:day/quotas` | Matrice produit × région (EF-ADV-02), décrite ci-dessous |
| ADV | `/adv/imports`, `/adv/imports/:id` | Imports : le type Quotas, avec choix de la journée et du mode, et gabarit de la journée |
| Admin | `/admin/journees`, `/admin/journees/:day`, `/admin/journees/:day/quotas` | Mêmes écrans en lecture ; « Prolonger » (`day.extend-cutoff`) |
| Régional | `/regional/quotas` | **Quotas du jour** : régions du périmètre, statut (« En attente des quotas » / publiés), heures limites, quotas publiés par famille ; bascule aujourd'hui / demain ; mise à jour en temps réel |
| Tous | `/notifications` | Centre de notifications : liste, lu / non lu, « Tout marquer comme lu », appui → `url` |
| Tous | en-tête | Cloche avec le nombre de notifications non lues (temps réel, sinon toutes les 30 s) |

**Matrice des quotas** (EF-ADV-02) :
- **Disposition** : produits en lignes, groupés par famille avec un en-tête de groupe ; régions en colonnes. Le code et le nom du produit restent figés à gauche, les en-têtes figés en haut.
- **Saisie au clavier comme dans un tableur** :
  - flèches, Tab et Entrée pour se déplacer ; une saisie remplace la valeur ;
  - Suppr ou Retour arrière vide la cellule, ce qui vaut 0 ;
  - Échap annule la cellule en cours ;
  - collage d'un bloc copié depuis Excel (valeurs séparées par tabulations et retours à la ligne).
- **Enregistrement** : automatique en brouillon, par lots (`PUT …/quotas`, délai court), avec l'indicateur « Enregistré à 09:12 » ou « Enregistrement… ».
- **Totaux** : par ligne et par colonne, sur la valeur affichée.
- **Repères visuels** :
  - une cellule en brouillon non publié a un fond distinct, et l'info-bulle indique « publié : 500 » ;
  - une valeur inférieure au validé apparaît en rouge et bloque la publication côté serveur.
- **Actions** :
  - « Publier… » ouvre un dialogue : toutes les régions ou une sélection ; pour chaque région, son nombre de brouillons et le fait que ce soit une première publication ou des modifications ; confirmation ;
  - « Copier depuis la journée… » : date source, mode Remplacer / Compléter, régions ;
  - « Importer » mène à `/adv/imports` avec le type Quotas et la journée présélectionnés ;
  - « Télécharger le gabarit ».
- **Lecture seule** : sans `quota.edit`, ou une fois la journée clôturée.

## 8. Répartition du travail (fichiers possédés)

Chaque lot de travail ne crée ou ne modifie **que** ses fichiers.

La fondation fournit les éléments communs ; un besoin de modification s'y signale au lieu d'être fait :
- côté API : migrations, modèles, énumérations, services `DayCalendar`, `QuotaBook` et `RegionStaff`, événements de domaine, `routes/api.php`, `routes/channels.php`, `routes/console.php`, `bootstrap/app.php`, `User::receivesBroadcastNotificationsOn`, `CurrentUserResource.realtime` ;
- côté PWA : `types.ts`, navigation, routes de la PWA, dépendances npm.

| Lot de travail | Fichiers |
|---|---|
| A — Journées (API) | `Api/V1/Adv/DayController`, `Http/Requests/Days/*`, `Http/Resources/DayResource`, `RegionDayResource`, `Policies/BusinessDayPolicy`, `Console/Commands/CloseDays` (`ramymaak:close-days`), `lang/fr/days.php`, `tests/Feature/Days/*` |
| B — Quotas (API) | `Api/V1/Adv/QuotaController`, `Api/V1/RegionalQuotaController`, `Http/Requests/Quotas/*`, `Http/Resources/QuotaMatrixResource` (et ressources associées), `lang/fr/quotas.php`, `tests/Feature/Quotas/*` ; RG-Q07 : `Api/V1/ProductController` (désactivation programmée), `Http/Requests/Products/UpdateProductRequest`, `Http/Resources/ProductResource`, `Console/Commands/ApplyProductDeactivations`, `tests/Feature/Products/ProductDeactivationTest.php` |
| C — Import des quotas (API) | `Domain/Imports/*` (sauf `ImportStatus`), `Api/V1/ImportController`, `Http/Resources/ImportResource`, `Policies/ImportPolicy`, `Jobs/AnalyzeImport`, `lang/fr/imports.php`, `tests/Feature/Imports/*` |
| D — Notifications et temps réel (API) | `app/Notifications/*`, `app/Listeners/*`, `app/Events/Realtime/*`, `Api/V1/NotificationController`, `Http/Resources/NotificationResource`, `lang/fr/notifications.php`, `tests/Feature/Notifications/*`, `tests/Feature/Realtime/*` |
| E1 — Écrans journées et imports | `frontend/src/modules/backoffice/days/**`, `frontend/src/modules/backoffice/imports/**`, `frontend/src/modules/backoffice/i18n/days.ts`, `frontend/src/modules/backoffice/i18n/imports.ts` |
| E2 — Matrice des quotas | `frontend/src/modules/backoffice/quotas/**`, `frontend/src/modules/backoffice/i18n/quotas.ts` |
| F — Notifications, temps réel, quotas du régional | `frontend/src/shared/realtime/**`, `frontend/src/shared/notifications/**`, `frontend/src/modules/notifications/**`, `frontend/src/modules/regional/quotas/**`, `frontend/src/shared/layout/AppShell.tsx` (cloche), `frontend/src/shared/i18n/fr/notifications.ts`, `frontend/src/shared/i18n/fr/field.ts` |
