# Lot 4 — Répartition : contrat d'API et d'écrans

Référence commune pour l'implémentation du lot 4 (§16 : grille produit × client sur PC et téléphone, « Reprendre J-1 », publication, N-03 et N-04). Critère de fin : **une répartition publiée sur une région pilote**. Les formes JSON ci-dessous font foi pour le back-end **et** pour la PWA.

## 1. Conventions

Celles des lots 2 et 3 s'appliquent (docs/lot-2-contrat-api.md §1, docs/lot-3-contrat-api.md §1) :
- droits contrôlés **avant** la validation (403 avant 422) ;
- objet hors périmètre → 404 ;
- erreurs « Problem Details » ;
- journée désignée par `AAAA-MM-JJ` (`{businessDay}`, 404 pour une journée non ouverte) ;
- heures `"HH:MM"` dans le fuseau métier ;
- l'application relit l'API après un événement temps réel.

**Notations** (§5.1), pour une journée, une région r, un produit p et un client c :

| Symbole | Sens | Source |
|---|---|---|
| Q(r,p) | quota **publié** de la région | `region_quotas.quantity` |
| B(c,p) | borne **publiée** (absente = pas de borne) | `client_limits.max_qty` |
| S(c,p), V(c,p) | quantités en attente, validées du client | `App\Domain\Limits\ClientUsage` (0 jusqu'au lot 5) |
| V(r,p) | validé de la région | `region_quotas.validated_qty` |
| R(r,p) | reste région = Q − V(r,p) | calculé |
| ΣB(r,p) | somme des bornes **affichées** (brouillon compris) des clients de la grille | calculé |
| D(c,p) | disponible client : borne → `max(0, min(B − S − V, R))` ; sans borne → `R` (LIBRE) ou `0` (OBLIGATOIRE) | calculé |

**Valeur affichée** d'une cellule : la valeur du brouillon s'il existe (`draft.value`, `null` = « pas de borne »), sinon la borne publiée `limit`.

**Services partagés à utiliser, pas à réécrire** :
- `App\Domain\Limits\LimitBook` :
  - `clientsQuery(RegionDay)` : clients de la région **ce jour-là** (RG-S04), actifs ou bloqués, par code ;
  - `availableQuotas(RegionDay)` : produit → Q, produits actifs ce jour-là avec un quota publié > 0 (RG-B02) ;
  - `saveDrafts`, `copyPrevious`, `publish`, `previewPublication`, `overallocated`, `openingRecipients`.
- `App\Domain\Limits\ClientUsage` (liaison : `NoClientUsage` jusqu'au lot 5) : `forRegionDay(RegionDay)` → `[client_id][product_id] = ['pending' => S, 'validated' => V]`.
- `App\Domain\Days\DayCalendar::regionDayFor`, `App\Domain\Access\Perimeter`, `App\Domain\Days\RegionStaff` (lot 3).

`LimitBook` lève lui-même :
- `NOT_FOUND` (404) pour une région hors du périmètre de l'utilisateur ce jour-là ;
- `DAY_NOT_OPEN` (409) : journée ou région clôturée, quotas de la région non publiés (« Les quotas de la région CENTRE du 08/10/2026 ne sont pas encore publiés. ») ;
- `INVALID_TRANSITION` (409) : « Aucune modification à envoyer. » ;
- `VALIDATION_FAILED` (422) et `QUOTA_BELOW_VALIDATED` (409), avec `violations` ;
- des `ValidationException` (422) sur `cells.N.client_id`, `cells.N.product_id`, `cells.N.limit` et `source`.

**Événements de domaine** (`ShouldDispatchAfterCommit`) :
- `App\Events\LimitDraftsSaved` (`regionDayId`, `actorId`) : saisie ou « Reprendre J-1 » qui change au moins une cellule ;
- `App\Events\DistributionPublished` (`regionDayId`, `version`, `first`, `notifiedClientIds`, `actorId`) : chaque envoi.

**Audit** : journal `limits`, événements `published` (premier envoi) puis `updated`, propriétés `day`, `region`, `version`, `changes_count`, `changes` (`[{client_id, client, product_id, sku, old, new}]`, 200 au plus), `notified_clients`. Écrit par `LimitBook`.

**Statuts de la journée régionale** (§6.1) :
- `QUOTA_PUBLISHED` → `DISTRIBUTION_DRAFT` au premier brouillon enregistré ;
- `DISTRIBUTION_PUBLISHED` au premier envoi ;
- chaque envoi incrémente `distribution_version`.

`RegionDay` (lot 3 §3) gagne `distribution_version` (0 avant le premier envoi) et `distribution_published_at` (premier envoi, ISO 8601 ou null). L'entrée de région de `GET /regional/quotas` gagne `distribution_version`.

## 2. Droits

| Action | Permission | Rôle exigé en plus | Rôles par défaut |
|---|---|---|---|
| Lire la grille de ses régions | `limit.view` | Régional | Régional |
| Saisir des bornes, « Reprendre J-1 » | `limit.edit` | Régional | Régional |
| Valider et envoyer aux clients | `limit.publish` | Régional | Régional |
| Lire la grille d'une région (lecture seule) | `limit.view` | ADV ou Admin | ADV, Admin |

Périmètre :
- **régional** : régions de ses affectations titulaire ou suppléant actives **à la date de la journée** (`Perimeter::canAccessRegion(user, region, day)`) ; autre région → 404 ;
- **ADV restreint** (RG-S05) : ses régions, jugées à la date de la journée ; autre région → 404 ;
- le **client** a `limit.view` pour ses propres bornes (lot 5) : les points d'accès de ce lot lui répondent 403.

## 3. Grille de répartition

### `GET /regional/days/{day}/grid?region={id}` et `GET /adv/days/{day}/regions/{region}/grid`

- `region` est requis pour le régional (entier ; absent → 422 sur `region`).
- La journée régionale est obtenue par `DayCalendar::regionDayFor` : une région ajoutée depuis l'ouverture apparaît avec le statut `QUOTA_DRAFT`.

```json
{
  "day": { "day": "2026-10-08", "status": "OPEN", "delivery_date": "2026-10-11",
           "cutoffs": { "distribution": "10:00", "ordering": "13:00", "validation": "15:00", "closing": "23:00" } },
  "region": { "id": 3, "code": "CENTRE", "name": "Centre",
              "status": "DISTRIBUTION_PUBLISHED", "status_label": "Répartition publiée",
              "quota_version": 2, "distribution_version": 1,
              "distribution_published_at": "2026-10-08T08:40:00+00:00",
              "cutoffs": { "distribution": "10:00", "ordering": "13:00" },
              "extended": { "distribution": false, "ordering": false } },
  "policy": "FREE",
  "can_edit": true,
  "can_publish": true,
  "products": [
    { "id": 42, "sku": "P0042", "name": "Jus orange 1 L", "short_name": "Orange 1 L",
      "family": { "id": 2, "code": "JUS", "name": "Jus" }, "sales_unit_label": "Fardeau 6 × 1 L",
      "quota": 500, "sum_limits": 620, "pending": 120, "validated": 300, "remaining": 200 }
  ],
  "clients": [
    { "id": 12, "code": "C0012", "name": "Client A", "status": "ACTIVE",
      "category": { "id": 1, "code": "GROS", "name": "Grossiste" } }
  ],
  "cells": {
    "12": { "42": { "limit": 50, "draft": { "value": 60 }, "pending": 10, "validated": 30 } }
  },
  "publication": { "first": false, "drafts": 3, "notified_clients": 2,
                   "overallocated": [ { "product_id": 42, "sku": "P0042", "quota": 500, "sum_limits": 620 } ] }
}
```

- **`day`** : les heures sont celles de la journée.
- **`region`** :
  - les heures limites applicables à la région, prolongation comprise (comme `RegionDay`) ;
  - pas de `pending_drafts` ici : ce compteur de `RegionDay` désigne les brouillons de quotas de l'ADV.
- **`policy`** : paramètre `limits.no_limit_policy` (`FREE` ou `REQUIRED`, RG-B04).
- **`can_edit`** : vrai si **toutes** ces conditions sont réunies :
  - l'utilisateur a `limit.edit` et le rôle régional ;
  - la journée est ouverte et la région n'est pas clôturée ;
  - les quotas de la région sont publiés.

  Toujours faux pour l'ADV et l'Admin.
- **`can_publish`** : mêmes conditions avec `limit.publish`. L'écran désactive l'envoi quand `publication.first` est faux et `publication.drafts` vaut 0.
- **`products`** (colonnes) :
  - `LimitBook::availableQuotas`, triés comme la matrice du lot 3 (famille, puis `sort_order` et `sku`) ;
  - `quota` = Q, `sum_limits` = ΣB des valeurs affichées, `pending` = Σ S(c,p), `validated` = V(r,p), `remaining` = Q − V(r,p).
- **`clients`** (lignes) : `LimitBook::clientsQuery` (actifs et bloqués), par code.
- **`cells`** : `cells[clientId][productId]`, pour les clients et produits listés. Seules les cellules ayant une borne, un brouillon, ou des quantités en attente ou validées sont renvoyées.
  - `limit` : borne publiée B (`null` = pas de borne) ;
  - `draft` : `null` = rien à envoyer ; `{ "value": 60 }` ou `{ "value": null }` (suppression de la borne en attente d'envoi) ;
  - `pending` et `validated` : S(c,p) et V(c,p).
- **`publication`** : `LimitBook::previewPublication`. C'est le récapitulatif affiché avant l'envoi : premier envoi ou non, brouillons à envoyer, clients qui seront notifiés, surallocations sur les valeurs affichées. Il est **toujours présent**, y compris en lecture seule.
- **Quotas de la région non publiés** : réponse 200 avec `products: []`, `cells: {}`, `can_edit: false`, `can_publish: false`, statut `QUOTA_DRAFT`. L'écran affiche « En attente des quotas de l'ADV ».

### Écriture (régional)

| Méthode | Chemin | Corps | Réponse |
|---|---|---|---|
| PUT | `/regional/days/{day}/limits` | `region_id` (requis) ; `cells` : 1 à 2 000 éléments `{ client_id, product_id, limit }`, `limit` entier de 0 à 1 000 000 ou `null` (pas de borne) | `{ "changed": 2, "status": "DISTRIBUTION_DRAFT", "cells": [ { "client_id": 12, "product_id": 42, "limit": 50, "draft": { "value": 60 }, "pending": 10, "validated": 30 } ] }` : état des cellules envoyées (`LimitBook::saveDrafts`) |
| POST | `/regional/days/{day}/limits/copy-previous` | `region_id` (requis), `mode` : `REPLACE` (écraser) ou `FILL` (compléter les cases vides) | `{ "changed": 120, "source_day": "2026-10-07" }` (`LimitBook::copyPrevious`) ; aucune répartition envoyée auparavant → 422 sur `source` |
| POST | `/regional/days/{day}/distribution/publish` | `region_id` (requis) | `{ "version": 2, "first": false, "changes": 3, "notified_clients": 2, "overallocated": [ … ] }` (`LimitBook::publish`) |

Règles (appliquées par `LimitBook`) :
- **RG-B01, RG-B03** : 0 interdit le produit au client ; une borne dépasse au plus Q (422 « La borne ne peut pas dépasser le quota de la région (500). »).
- **RG-B02** : client de la région ce jour-là et produit disponible, sinon 422 sur `cells.N.client_id` ou `cells.N.product_id`.
- **RG-B07** :
  - après le premier envoi, une borne ne descend pas sous V(c,p) (422 « Borne inférieure aux 30 déjà validés pour ce client. ») ;
  - une borne sous E(c,p) = S + V est permise ; l'écran avertit que la commande en attente devra être réduite à la validation.
- **Annuler un brouillon** : renvoyer la borne publiée de la cellule (`limit`). Une valeur égale à la borne publiée efface le brouillon.
- **RG-B05** : la surallocation (ΣB > Q) est permise et signalée, sans blocage.
- **RG-B08**, « Reprendre J-1 » :
  - source : la dernière journée de la région, antérieure, dont la répartition a été envoyée (`distribution_version` > 0) ;
  - seuls les produits disponibles ce jour et les clients de la région ce jour sont repris, avec des bornes plafonnées à Q ;
  - le résultat est un brouillon.
- **RG-B06, RG-B07, envoi tout ou rien** :
  - une borne au-dessus d'un quota diminué depuis → 422 `VALIDATION_FAILED` ;
  - une borne sous le validé → 409 `QUOTA_BELOW_VALIDATED` ;
  - dans les deux cas, `violations: [{ client_id, client, product_id, sku, requested, quota | validated }]` ;
  - un envoi suivant sans brouillon → 409 `INVALID_TRANSITION` ;
  - le premier envoi peut ne contenir aucune borne (politique LIBRE : tous les clients commandent dans la limite du reste région).
- Les brouillons devenus sans objet (client sorti de la région, produit sans quota) sont effacés à l'envoi.
- L'heure limite de répartition **n'est pas bloquante** (§4.3) : l'envoi reste possible jusqu'à la clôture.

## 4. Notifications et temps réel (§7.8, §9.6)

| Type | Code | Déclencheur | Destinataires | Titre / corps | `url` |
|---|---|---|---|---|---|
| `DISTRIBUTION_PUBLISHED` | N-03 | `DistributionPublished` avec `first` | comptes **actifs** de rôle client rattachés aux clients de `notifiedClientIds` | « Quotas du 08/10 disponibles » / « Vos quotas du 08/10 sont disponibles. Commandez avant 13:00. » (heure limite de commande de la région, prolongation comprise) | `/client` |
| `LIMITS_UPDATED` | N-04 | `DistributionPublished` sans `first` | idem (clients impactés) | « Quotas du 08/10 mis à jour » / « Vos quotas du jour ont été mis à jour. » | `/client` |

Ces notifications suivent les règles du lot 3 :
- envoyées par une tâche de fond (EF-NOT-06), en base et sur `user.{id}` ;
- **rejouables** : clé d'unicité par journée régionale, version et destinataire.

`notifiedClientIds` est calculé par `LimitBook` :
- **premier envoi** : clients actifs de la région ; en politique OBLIGATOIRE, ceux qui ont une borne > 0 ;
- **envois suivants** : clients actifs dont une borne a changé.

Canaux temps réel :

| Canal privé | Accès | Nouveaux événements (nom diffusé) |
|---|---|---|
| `region-day.{regionId}.{day}` | inchangé (lot 3) | `DistributionPublished` `{ region_id, day, version, first }` ; `LimitDraftsUpdated` `{ region_id, day }` (sur `LimitDraftsSaved` : un autre régional de la région a modifié la grille) |
| `adv.{day}` | inchangé | `DistributionPublished` (même contenu) |
| `client.{clientId}` | compte client rattaché à ce client (`routes/channels.php`) | `LimitsUpdated` `{ day, version }`, pour chaque client notifié ; les écrans client arrivent au lot 5 |

Une panne de la diffusion est journalisée sans faire échouer l'enregistrement ni l'envoi (comme au lot 3).

## 5. Écrans

| Rôle | Chemin | Contenu |
|---|---|---|
| Régional | `/regional/repartition?day=AAAA-MM-JJ&region=ID` | **Grille de répartition** : aujourd'hui ou demain, choix de la région du périmètre (liste de `GET /regional/quotas?day=`), grille PC ou vue pivot sur téléphone |
| Régional | `/regional/quotas` | Pour chaque région aux quotas publiés : statut de la répartition et bouton « Répartir » vers la grille |
| Régional | onglet « Répartition » | Dans la navigation (barre d'onglets du téléphone) et sur l'accueil |
| ADV, Admin | `/adv/journees/:day/repartition/:regionId`, `/admin/journees/:day/repartition/:regionId` | Grille en **lecture seule** (modes Bornes, Commandes, Reste) |
| ADV, Admin | `/…/journees`, `/…/journees/:day` | Statut de répartition de chaque région (« Répartition en cours », « Répartition envoyée (v2) »), lien vers la grille |

### Grille PC et tablette (EF-REG-02), à partir de 768 px

- **Lignes** : clients (code et nom figés à gauche, pastille « Bloqué »), avec recherche (code, nom) et filtre par catégorie.
- **Colonnes** : produits groupés par famille, en-têtes figés.
- **Synthèse** : cinq lignes épinglées en haut : **Quota (Q)**, **Σ bornes (ΣB)**, **En attente (S)**, **Validé (V)**, **Reste (R)**. ΣB est recalculé à chaque saisie.
- **Cellule** :
  - mode **Bornes** : la borne modifiable (vide = pas de borne), avec le rappel S et V du client ;
  - mode **Commandes** : S et V ;
  - mode **Reste** : D(c,p).
- **Couleurs** :
  - orange si ΣB > Q (surallocation, info-bulle « Σ bornes 620 > quota 500 ») ;
  - rouge si R = 0 (épuisé) ;
  - fond distinct pour une cellule modifiée non envoyée (info-bulle « envoyé : 50 » ou « envoyé : pas de borne ») ;
  - avertissement si la borne est sous E(c,p).
- **Clavier**, comme la matrice du lot 3 :
  - flèches, Tab, Entrée ;
  - une saisie remplace la valeur ;
  - Suppr ou Retour arrière vide la cellule, ce qui retire la borne ;
  - Échap annule la cellule en cours ;
  - collage d'un bloc copié depuis Excel.
- **Enregistrement automatique** en brouillon, par lots (`PUT …/limits`), avec l'indicateur « Enregistré à 09:12 ». Aucune perte si l'application se ferme (RG-B09) : la file d'attente est vidée avant de quitter la page ou de masquer l'onglet.
- **Actions de masse** :
  - appliquer une valeur à une colonne (produit) pour les clients affichés, ou à une sélection ;
  - effacer les bornes affichées d'une colonne ;
  - **Reprendre J-1** : dialogue Écraser / Compléter les cases vides, puis rechargement ;
  - **Annuler les modifications** : remet les brouillons à la borne publiée.
- **« Valider et envoyer aux clients »** : vide la file d'attente, relit la grille, puis affiche un récapitulatif :
  - premier envoi ou modifications ;
  - nombre de brouillons ;
  - nombre de clients notifiés ;
  - produits en surallocation.

  Après confirmation, le compte rendu indique la version et le nombre de clients notifiés. En cas de refus (`violations`), la liste client / produit est affichée.
- **Temps réel** :
  - `DistributionPublished`, `LimitDraftsUpdated` (un autre régional de la région) et `QuotaPublished` (quotas modifiés par l'ADV) relisent la grille ;
  - une relecture ne remplace jamais une saisie en cours ou en attente d'enregistrement ;
  - sans temps réel, rafraîchissement toutes les 30 s.
- **Lecture seule** : sans `can_edit`, une fois la journée clôturée, et **hors ligne** (bandeau « Hors ligne — données de 09:42 »).

### Téléphone (EF-REG-03), en dessous de 768 px

- **Par produit** (vue par défaut) : choix du produit (liste par famille, avec Q et ΣB). La liste des clients suit, avec le champ borne (clavier numérique), S et V. Une barre de synthèse fixe montre Q, ΣB, V et R, en orange en cas de surallocation.
- **Par client** : choix du client, puis liste de ses produits avec le champ borne et le disponible.
- Mêmes enregistrement automatique, « Reprendre J-1 », envoi avec récapitulatif, lecture seule.

### Back-office (lecture)

Même grille, sans saisie ni actions. Elle est accessible depuis la fiche de la journée (lien « Répartition » sur chaque région aux quotas publiés).

## 6. Répartition du travail (fichiers possédés)

Chaque lot de travail ne crée ou ne modifie **que** ses fichiers.

La fondation fournit les éléments suivants ; un besoin de modification s'y signale au lieu d'être fait :
- **API** :
  - migration `client_limits`, modèle `ClientLimit` et sa fabrique, `RegionDay::limits` et `isDistributionPublished` ;
  - `Domain/Limits/*` (`LimitBook`, `ClientUsage`, `NoClientUsage`, `DistributionResult`) ;
  - événements `LimitDraftsSaved` et `DistributionPublished` ;
  - `routes/api.php`, `routes/channels.php` ;
  - champs de répartition de `RegionDayResource` et `RegionalQuotasResource` ;
  - `lang/fr/limits.php` (les lots de travail y **ajoutent** des clés, sans modifier les existantes).
- **PWA** :
  - `types.ts` ;
  - navigation (`AppShell.tsx`, onglet « Répartition »), accueil du régional (`modules/home/**`) et routes des modules `regional`, `adv`, `admin` ;
  - `shared/i18n/fr.ts` (branchement du fichier `fr/distribution.ts`).

| Lot de travail | Fichiers |
|---|---|
| A — Bornes et grille (API) | `Api/V1/Regional/DistributionGridController`, `Api/V1/Regional/LimitController`, `Http/Requests/Limits/*`, `Http/Resources/Distribution*` (grille et cellules), `tests/Feature/Limits/*` |
| B — Notifications et temps réel (API) | `app/Notifications/DistributionPublishedNotification.php`, `app/Notifications/LimitsUpdatedNotification.php` (et une classe de base commune aux notifications client si utile), `NotificationType` (deux cas ajoutés), `app/Listeners/NotifyDistributionPublished.php`, `app/Listeners/BroadcastDistributionActivity.php`, `app/Events/Realtime/DistributionPublished.php`, `LimitDraftsUpdated.php`, `LimitsUpdated.php`, `lang/fr/notifications.php` (clés ajoutées), `tests/Feature/Notifications/Distribution*`, `tests/Feature/Realtime/Distribution*` |
| C — Grille de répartition (PWA) | `frontend/src/modules/distribution/**` (données, grille PC, vue téléphone, dialogues, page régionale `RegionalDistributionPage`), `frontend/src/modules/backoffice/distribution/**` (page de lecture `BackOfficeDistributionPage`, qui réutilise la grille de `modules/distribution` ; placée sous `backoffice/` pour rester dans les fichiers « backoffice-* », hors du pré-cache), `frontend/src/shared/i18n/fr/distribution.ts` |
| D — Statuts et accès (PWA) | `frontend/src/modules/backoffice/days/**`, `frontend/src/modules/backoffice/i18n/days.ts`, `frontend/src/modules/regional/quotas/**`, `frontend/src/shared/i18n/fr/notifications.ts` (libellés de « Quotas du jour » et des notifications), `frontend/src/shared/notifications/**` |

Reporté : import Excel des bornes (EF-REG-06, priorité S, §8.2).

## 7. Précisions (implémentation)

Décisions prises pendant l'implémentation, qui complètent le contrat.

**Droits et erreurs**
- Ordre des contrôles :
  1. 403 si le rôle ou la permission manque ;
  2. 404 si la région désignée est inconnue, inactive ou hors du périmètre ce jour-là ;
  3. 422 pour les autres champs.

  Un régional d'une autre région qui envoie des cellules invalides reçoit donc 404.
- `region_id` des écritures est un entier JSON strict (`"3"` → 422) ; le paramètre `?region=` de la lecture accepte un entier écrit en texte.
- Une écriture sur une journée clôturée répond 409 `DAY_NOT_OPEN` sans créer de journée régionale. La lecture passe par `DayCalendar::regionDayFor`.
- Grille back-office : une région inconnue dans le chemin répond 404 avant le contrôle des droits (liaison de route), comme la prolongation du lot 3.

**Grille**
- `cells` ne contient pas une ligne qui n'a ni borne publiée ni brouillon (brouillon annulé vers « pas de borne », borne retirée par un envoi précédent), sauf si le client a des quantités en attente ou validées.
- `products[].pending` additionne S(c,p) pour tous les clients que renvoie `ClientUsage` : c'est un total de la région. `sum_limits` ne compte que les clients listés.
- Quotas non publiés : les clients restent listés ; seuls `products` et `cells` sont vides.
- Le nombre de requêtes de la grille ne dépend pas du volume (26 requêtes pour 30 clients × 10 produits comme pour une petite grille).

**Notifications et temps réel**
- N-03 et N-04 ont leur propre classe de base, `ClientDayNotification` (adresse `/client`, sans nom de région), distincte de `RegionDayNotification` (régionaux).
- L'heure de N-03 est l'heure limite de commande de la région au moment de l'envoi, prolongation comprise.
- Destinataires : les comptes actifs de rôle client rattachés aux clients notifiés, lus par paquets de 100. Un client bloqué n'est jamais notifié.
- Un compte resté rattaché au client mais passé à un autre rôle n'est ni notifié ni autorisé sur `client.{id}`.
- `LimitDraftsUpdated` parvient aussi à l'écran de l'auteur de la saisie : la PWA ne laisse jamais une relecture écraser une saisie en cours.
- Comme au lot 3, une notification enregistrée dont la diffusion a échoué reste dans le centre de notifications, mais n'est pas rediffusée.
