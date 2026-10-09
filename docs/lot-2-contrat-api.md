# Lot 2 — Référentiels : contrat d'API et d'écrans

Référence commune pour l'implémentation du lot 2 (§16 : régions, affectations, suppléance, clients, produits, photos, prix, imports). Les formes JSON ci-dessous font foi pour le back-end **et** pour la PWA.

## 1. Conventions (rappel du lot 1)

- Base `/api/v1`, session Sanctum ; toutes les routes du lot 2 exigent une session et un mot de passe à jour.
- Erreurs « Problem Details » (`App\Http\Problems\ApiException` + `ErrorCode`) : `VALIDATION_FAILED` (422, `errors` par champ), `FORBIDDEN` (403), `NOT_FOUND` (404), `INVALID_TRANSITION` (409), `IMPORT_HAS_ERRORS` (409).
- **Droits contrôlés avant la validation** : `authorize()` des FormRequest ou `Gate::authorize()` en tête de contrôleur, pour qu'un utilisateur sans droit reçoive 403 et non 422.
- **Périmètre (RG-S02)** : toute liste de clients passe par `App\Domain\Access\Perimeter::clients($user)` ; tout accès unitaire par `canAccessClient()` / `canAccessRegion()`. Un objet hors périmètre répond **404** (on ne révèle pas son existence).
- Listes : `?page=&per_page=` (25 par défaut, 100 max, `App\Support\Pagination`), filtres `?filter[x]=`, tri `?sort=-champ` (spatie/laravel-query-builder) ; format Laravel `{ data, links, meta }`. Filtre ou tri inconnu → 422.
- Ressources renvoyées **sans enveloppe** `data` (sauf listes paginées).
- Dates calendaires `AAAA-MM-JJ` (cast `App\Casts\BusinessDate`), horodatages ISO 8601. Montants en nombre JSON (`900.5`), arrondis à 2 décimales.
- Libellés et messages en français : un fichier `lang/fr/<domaine>.php` par domaine (attributs de validation, messages).
- Audit : les modèles des référentiels journalisent seuls leurs changements (journal `referentials`) ; les actions sans modèle (révocation, création de comptes, application d'un import) ajoutent une entrée `activity()` explicite.
- Services partagés **à utiliser, pas à réécrire** : `Perimeter`, `ClientRegistry` (création, modification, région à J+1, statut), `PriceBook` (prix, `normalizeAmount`), `BusinessClock`, `SessionRevoker`, `TemporaryPassword`.

## 2. Droits

| Ressource | Lire | Écrire |
|---|---|---|
| Régions, affectations | `region.view` + périmètre (Admin, ADV : toutes ou leurs régions ; régional : les siennes) | `region.manage` (Admin) |
| Dépôts | `region.manage` | `region.manage` |
| Clients | `client.view` + périmètre ; un compte client lit **sa** fiche | `client.manage` (Admin) ; changer le statut exige en plus `client.block` |
| Catégories de clients | tout utilisateur connecté du personnel (`client.view`) | `client.manage` |
| Comptes clients en masse | — | `user.manage` |
| Produits, familles | `product.view` (un client ne voit que les produits actifs) | `product.manage` (Admin ; ADV en option) |
| Prix (historique) | `product.view` sauf rôle client | `product.manage` |
| Imports | auteur, ou Admin (`user.manage`) | droit du type : CLIENTS → `client.manage`, PRODUCTS et PRICES → `product.manage` |

## 3. Régions, dépôts, affectations

### Ressources

`Depot`
```json
{ "id": 1, "code": "BLIDA", "name": "Dépôt Blida", "address": "Zone industrielle, Blida", "is_active": true, "regions_count": 2 }
```

`AssignmentUser` : `{ "id": 12, "username": "k.saidi", "name": "Karim Saïdi" }`

`Assignment`
```json
{ "id": 7, "region_id": 3, "kind": "TITULAR", "kind_label": "Titulaire",
  "user": AssignmentUser, "starts_on": "2026-10-01", "ends_on": null,
  "is_active": true, "revoked_at": null, "revoked_by": AssignmentUser|null,
  "created_at": "2026-10-01T08:00:00+00:00" }
```
`kind` : `TITULAR` | `DEPUTY` | `ADV`. `is_active` = active aujourd'hui (jour métier).

`Region`
```json
{ "id": 3, "code": "CENTRE", "name": "Centre", "is_active": true,
  "depot": { "id": 1, "code": "BLIDA", "name": "Dépôt Blida" } | null,
  "titular": Assignment | null,
  "deputies": [Assignment],
  "clients_count": 142 }
```
`titular` : titulaire actif aujourd'hui. `deputies` : suppléants non révoqués, en cours ou à venir. `clients_count` : clients dont la région **effective aujourd'hui** est celle-ci, hors inactifs.

### Points d'accès

| Méthode | Chemin | Corps / filtres | Réponse |
|---|---|---|---|
| GET | `/regions` et `/admin/regions` | `filter[search]` (code, nom), `filter[is_active]` ; tri `code` (défaut), `name` | liste paginée de `Region`, restreinte au périmètre (le régional ne voit que ses régions ; l'Admin voit aussi les inactives) |
| GET | `/regions/{id}` | — | `Region` ; 404 hors périmètre |
| POST | `/admin/regions` | `code` (requis, unique, `^[A-Z0-9_-]{2,20}$`, mis en majuscules), `name` (requis), `depot_id` (facultatif, dépôt actif), `is_active` | 201 `Region` |
| PATCH | `/admin/regions/{id}` | `name`, `depot_id`, `is_active` ; **le code ne change pas** (clé des fichiers Excel) | `Region` ; désactiver une région qui a encore des clients non inactifs → 422 sur `is_active` |
| GET | `/admin/depots` | — | liste (non paginée) `{ "data": [Depot] }` |
| POST | `/admin/depots` | `code` (unique, majuscules), `name`, `address`, `is_active` | 201 `Depot` |
| PATCH | `/admin/depots/{id}` | `name`, `address`, `is_active` (code immuable) | `Depot` |
| GET | `/admin/regions/{id}/assignments` | `filter[active]=1` (facultatif) | `{ "data": [Assignment] }`, historique complet, plus récentes d'abord |
| POST | `/admin/regions/{id}/assignments` | `user_id`, `kind`, `starts_on` (défaut aujourd'hui), `ends_on`, `replace` (bool) | 201 `Assignment` |
| DELETE | `/admin/assignments/{id}` | — | 200 `Assignment` révoquée (`revoked_at` maintenant) ; déjà révoquée → 409 `INVALID_TRANSITION` |

Règles de création d'une affectation :
- l'utilisateur est actif et a le rôle attendu (`TITULAR`/`DEPUTY` → régional ; `ADV` → ADV) ;
- `DEPUTY` : `ends_on` obligatoire, `starts_on ≤ ends_on` ; plusieurs suppléants possibles ;
- `TITULAR` : `ends_on` facultatif ; **un seul titulaire à la fois** (H-02) : si un titulaire non révoqué chevauche la période → 422 sur `user_id`, sauf `replace: true` qui révoque l'ancien dans la même transaction ;
- doublon (même utilisateur, même nature, périodes qui se chevauchent, non révoqué) → 422 ;
- `starts_on` ne peut pas être dans le passé (une affectation se crée aujourd'hui ou plus tard).

Précisions (implémentation) :
- PATCH région, dépôt, client ou catégorie : renvoyer le code actuel (quelle que soit la casse) est accepté ; un autre code → 422 sur `code`.
- Une région inactive est invisible sans `region.manage` (absente des listes, 404 sur la fiche et l'historique).
- `filter[active]=0` sur l'historique renvoie les affectations non actives aujourd'hui ; ordre : `starts_on` décroissant.
- `replace: true` révoque immédiatement tous les titulaires non révoqués qui chevauchent (en cours et à venir).
- Une révocation est journalisée avec l'action `revoked` (« Affectation révoquée »).

### Profil (`/auth/me`)

`perimeter` devient : `{ "all_regions": true|false, "region_ids": [3, 4] }` (`region_ids` vide si `all_regions`).

## 4. Clients

### Ressources

`ClientCategory` : `{ "id": 1, "code": "GROSSISTE", "name": "Grossiste", "clients_count": 12 }`

`RegionRef` : `{ "id": 3, "code": "CENTRE", "name": "Centre" }`

`Client`
```json
{ "id": 12, "code": "C0012", "name": "Superette El Baraka", "trade_name": "El Baraka",
  "category": { "id": 3, "code": "DETAILLANT", "name": "Détaillant" } | null,
  "region": RegionRef,
  "pending_region_change": { "region": RegionRef, "effective_on": "2026-10-10" } | null,
  "address": "…", "wilaya": "Alger", "commune": "Bab Ezzouar",
  "lat": 36.7212345, "lng": 3.1812345,
  "phone": "0550123456", "phone2": null, "email": null,
  "nif": null, "nis": null, "rc": null, "ai": null,
  "status": "ACTIVE", "status_label": "Actif", "status_reason": null, "status_changed_at": null,
  "accounts": [ { "id": 5, "username": "c0012", "is_active": true, "last_login_at": null } ],
  "created_at": "…", "updated_at": "…" }
```
- `region` : région **effective aujourd'hui** (RG-S04) ; `pending_region_change` : changement programmé après aujourd'hui.
- `accounts` : présent seulement si l'utilisateur a `user.manage` (sinon la clé est absente).

### Points d'accès

| Méthode | Chemin | Corps / filtres | Réponse |
|---|---|---|---|
| GET | `/clients` | `filter[search]` (code, raison sociale, enseigne, téléphone), `filter[region_id]`, `filter[category_id]`, `filter[status]` ; tri `code` (défaut), `name`, `-updated_at` | liste paginée de `Client` dans le périmètre |
| GET | `/clients/{id}` | — | `Client` ; 404 hors périmètre |
| POST | `/clients` | `code` (requis, unique, `^[A-Za-z0-9_-]{1,30}$`, mis en majuscules), `name` (requis), `trade_name`, `category_id`, `region_id` (requis, région active), `address`, `wilaya`, `commune`, `lat` (−90..90), `lng` (−180..180), `phone`, `phone2`, `email`, `nif`, `nis`, `rc`, `ai` | 201 `Client` (via `ClientRegistry::create`) |
| PATCH | `/clients/{id}` | mêmes champs sauf `code` ; `region_id` → changement à J+1 ; `status` (`ACTIVE`/`BLOCKED`/`INACTIVE`) + `status_reason` (requis pour `BLOCKED`) — exige `client.block` | `Client` (via `ClientRegistry::update`) |
| GET | `/client-categories` | — | `{ "data": [ClientCategory] }` |
| POST | `/admin/client-categories` | `code` (unique, majuscules), `name` | 201 `ClientCategory` |
| PATCH | `/admin/client-categories/{id}` | `name` | `ClientCategory` |
| POST | `/admin/client-accounts` | `client_ids` (1 à 200 identifiants) | **PDF** `fiches-acces-AAAAMMJJ.pdf` : une fiche par compte créé (raison sociale, code, identifiant = code client en minuscules, mot de passe temporaire, adresse de l'application, aide à l'installation). Les clients qui ont déjà un compte ou qui sont inactifs sont ignorés ; en-tête `X-Accounts-Created` (nombre). Aucun compte créé → 422 sur `client_ids`. |

Comptes utilisateurs (lot 1, à compléter) :
- `POST/PATCH /admin/users` : `client_id` **obligatoire** et existant pour le rôle client, interdit pour les autres ;
- `UserResource` gagne `client: { id, code, name } | null` ;
- `POST /admin/users/{id}/reset-password` : autorisé au régional pour un compte client dont le client est dans son périmètre (EF-AUTH-05).

Précisions (implémentation) :
- `GET /clients` → 403 pour le rôle client : un compte client lit sa propre fiche via `GET /clients/{id}`.
- PATCH `/clients/{id}` : `client.block` n'est exigé que si le statut ou son motif change réellement ; `status_reason` seul modifie le motif du statut actuel. `region_id` égal à la région actuelle **annule** un changement programmé : un formulaire n'envoie `region_id` que si l'utilisateur l'a modifié.
- `region_id` : région active dans le périmètre de l'utilisateur (la région actuelle ou programmée reste acceptée si elle a été désactivée) ; `lat` et `lng` vont ensemble.
- Le libellé d'une catégorie est unique comme son code (l'import identifie la catégorie par l'un ou l'autre).
- `POST /admin/client-accounts` : identifiants inconnus → 422 ; doublons fusionnés ; clients hors périmètre ou dont l'identifiant est déjà pris ignorés ; le compte reprend le nom, l'e-mail et le téléphone du client ; réponse `Cache-Control: no-store`.
- `GET /admin/users` accepte `filter[client_id]` ; `PATCH /admin/users/{id}` avec `is_active=true` sur un compte dont le client est inactif → 422.

## 5. Produits, familles, prix, photos

### Ressources

`ProductFamily` : `{ "id": 2, "code": "JUS", "name": "Jus", "parent_id": null, "sort_order": 10, "products_count": 4 }`

`ProductPhoto`
```json
{ "id": 31, "is_main": true, "urls": { "thumb": "/media/31/conversions/p0042-thumb.webp", "medium": "…-medium.webp", "large": "…-large.webp" } }
```

`PriceRef` : `{ "price_ht": 900.0, "price_ttc": 1071.0, "unit_price_ht": 150.0, "valid_from": "2026-10-01" }` — `unit_price_ht` = prix de l'UV ÷ unités par UV (indicatif).

`Product`
```json
{ "id": 42, "sku": "P0042", "name": "Jus orange 1 L", "short_name": "Orange 1 L",
  "family": { "id": 2, "code": "JUS", "name": "Jus" },
  "format": "1 L", "sales_unit_label": "Fardeau 6 × 1 L", "units_per_sales_unit": 6,
  "sales_units_per_pallet": 80, "ean": "6130000000420", "vat_rate": 19.0,
  "is_active": true, "is_new": false, "is_featured": false, "sort_order": 10,
  "price": PriceRef | null,
  "next_price": { "price_ht": 950.0, "valid_from": "2026-11-01" } | null,
  "photos": [ProductPhoto],
  "created_at": "…", "updated_at": "…" }
```
`photos` triées : la principale d'abord.

`ProductPrice` : `{ "id": 9, "price_ht": 900.0, "valid_from": "2026-10-01", "valid_to": "2026-10-31", "created_by": AssignmentUser|null, "created_at": "…" }`

### Points d'accès

| Méthode | Chemin | Corps / filtres | Réponse |
|---|---|---|---|
| GET | `/products` | `filter[search]` (code, désignation), `filter[family_id]`, `filter[is_active]` (personnel seulement) ; tri `sort_order` (défaut), `name`, `sku` | liste paginée de `Product` ; un compte client ne reçoit que les actifs |
| GET | `/products/{id}` | — | `Product` ; produit inactif pour un client → 404 |
| POST | `/products` | `sku` (requis, unique, `^[A-Za-z0-9_-]{1,30}$`, majuscules), `name` (requis), `short_name` (défaut : `name` tronqué à 60), `family_id` (requis), `format`, `sales_unit_label` (requis), `units_per_sales_unit` (requis, ≥ 1), `sales_units_per_pallet` (≥ 1), `ean` (8 à 14 chiffres), `vat_rate` (requis, 0 à 100), `is_active`, `is_new`, `is_featured`, `sort_order` | 201 `Product` |
| PATCH | `/products/{id}` | mêmes champs sauf `sku` | `Product` |
| GET | `/products/{id}/prices` | — | `{ "data": [ProductPrice] }`, plus récents d'abord ; interdit au rôle client |
| POST | `/products/{id}/prices` | `price_ht` (requis, ≥ 0, 2 décimales), `valid_from` (requis, `AAAA-MM-JJ`) | 201 `ProductPrice` (via `PriceBook::setPrice` ; même date d'effet → remplacée) |
| POST | `/products/{id}/media` | multipart : `photo` (requis, JPEG/PNG/WebP, 5 Mo), `main` (bool) | 201 `ProductPhoto` ; 5 photos déjà → 422 sur `photo` |
| PATCH | `/products/{id}/media/{mediaId}` | `main: true` | `{ "data": [ProductPhoto] }` réordonnées |
| DELETE | `/products/{id}/media/{mediaId}` | — | 204 |
| GET | `/product-families` | — | `{ "data": [ProductFamily] }` triées par `sort_order` |
| POST | `/admin/product-families` | `code` (unique, majuscules), `name`, `parent_id`, `sort_order` | 201 `ProductFamily` |
| PATCH | `/admin/product-families/{id}` | `name`, `parent_id` (pas elle-même ni une descendante), `sort_order` | `ProductFamily` |

Photos (§9.8) : type réel contrôlé (pas seulement l'extension), image **ré-encodée** avant stockage (métadonnées supprimées), variantes WebP 200 / 600 / 1 200 px ; disque `media` servi à `/media` (lien `public/media`, et alias nginx dans l'image web). Une photo remplacée change d'adresse : cache d'un an possible.

## 6. Imports Excel (§8.3, Annexes B.3 – B.5)

### Ressource `Import`
```json
{ "id": 9, "type": "CLIENTS", "type_label": "Clients", "status": "READY",
  "original_name": "clients.xlsx",
  "rows_total": 1520, "rows_processed": 1520, "rows_ok": 1520, "rows_error": 0,
  "summary": { "create": 1200, "update": 300, "unchanged": 20 },
  "errors": [ { "row": 14, "column": "code_region", "message": "Code région inconnu : EST2" } ],
  "errors_truncated": false,
  "preview": [ { "row": 2, "key": "C0012", "action": "update", "changes": { "name": { "old": "A", "new": "B" } } } ],
  "preview_truncated": false,
  "has_error_report": false,
  "user": AssignmentUser,
  "created_at": "…", "analyzed_at": "…", "applied_at": null, "failure_message": null }
```
`status` : `PENDING` → `ANALYZING` → `READY` (aucune erreur) ou `INVALID` (au moins une erreur) → `APPLIED` ; `FAILED` en cas d'incident technique. `errors` : 500 premières ; `preview` : 200 premières lignes modifiées (`action` : `create` | `update`) ; les compteurs sont toujours complets.

### Points d'accès

| Méthode | Chemin | Réponse |
|---|---|---|
| GET | `/imports/templates/{clients\|products\|prices}` | gabarit `.xlsx` : en-têtes de l'annexe B, pré-rempli avec les données actuelles |
| POST | `/imports` | multipart `type` (`CLIENTS`/`PRODUCTS`/`PRICES`) + `file` → 202 `Import` ; l'analyse part en file d'attente (`App\Jobs\AnalyzeImport`) |
| GET | `/imports` | `filter[type]`, `filter[status]` ; tri `-created_at` ; les siens (tous pour l'Admin) |
| GET | `/imports/{id}` | `Import` (suivi de progression par interrogation) |
| GET | `/imports/{id}/error-report` | fichier d'origine annoté : colonne « erreurs » ajoutée et cellules fautives surlignées ; 404 s'il n'y en a pas |
| POST | `/imports/{id}/apply` | `INVALID` → 409 `IMPORT_HAS_ERRORS` ; autre statut que `READY` → 409 `INVALID_TRANSITION` ; sinon tout-ou-rien dans une transaction, données revalidées (une nouvelle erreur → `INVALID` + 409 `IMPORT_HAS_ERRORS`) → `Import` `APPLIED` |

Fichier : `.xlsx` seulement (`.xls`, `.xlsm` et tout classeur contenant des macros refusés), 5 Mo maximum, première feuille, ligne 1 = en-têtes (casse et espaces ignorés), colonnes inconnues ignorées, formules lues comme valeurs calculées, stockage hors de la partie publique (`storage/app/private/imports`).

Colonnes (`*` obligatoire dans l'en-tête et à la création) :

| Type | Clé | Colonnes |
|---|---|---|
| Clients (B.3) | `code_client` | `code_client*`, `raison_sociale*`, `enseigne`, `categorie` (code ou libellé existant), `code_region*` (région active), `adresse`, `wilaya`, `commune`, `telephone`, `email`, `nif`, `nis`, `rc`, `article_imposition`, `latitude`, `longitude`, `statut` (`ACTIF`/`BLOQUE`/`INACTIF`) |
| Produits (B.4) | `code_produit` | `code_produit*`, `designation*`, `designation_courte`, `code_famille*`, `format`, `libelle_uv*`, `unites_par_uv*`, `uv_par_palette`, `ean`, `taux_tva*`, `statut` (`ACTIF`/`INACTIF`), `ordre_affichage` |
| Prix (B.5) | `code_produit` + `date_effet` | `code_produit*` (produit existant), `prix_ht*`, `date_effet*` (`JJ/MM/AAAA` ou date Excel) |

Règles :
- une ligne par clé ; doublon → erreur « déjà présent ligne N » ;
- mise à jour : une cellule **vide** laisse la valeur inchangée ; une colonne absente aussi ;
- clients : changement de région via `ClientRegistry` (effet à J+1) ; `statut` `BLOQUE` sans motif → motif « Import Excel du JJ/MM/AAAA » ;
- prix : via `PriceBook::setPrice` (même date d'effet → remplacée) ;
- messages d'erreur au format du §8.1 (« Ligne 14, colonne code_region : … ») ;
- application journalisée : une entrée `imports` / `applied` avec le résumé, et les changements de chaque fiche regroupés par `batch_uuid`.

## 7. Écrans (PWA)

| Rôle | Chemin | Contenu |
|---|---|---|
| Admin | `/admin/regions`, `/admin/regions/:id` | Régions (liste, création, modification) ; détail : titulaire, suppléants, ADV, historique, ajout et fin d'affectation ; onglet Dépôts |
| Admin | `/admin/clients`, `/admin/clients/nouveau`, `/admin/clients/:id` | Clients (filtres, recherche), création, fiche (modification, changement de région programmé, blocage avec motif, comptes, création de compte), sélection multiple → fiches d'accès PDF ; onglet Catégories |
| Admin | `/admin/produits`, `/admin/produits/nouveau`, `/admin/produits/:id` | Produits (vignette, prix HT et TTC, statut), création, fiche (modification, photos : envoi, principale, suppression ; prix : historique et prix futur) ; onglet Familles |
| Admin | `/admin/imports`, `/admin/imports/:id` | Gabarits, dépôt, progression, rapport d'erreurs et fichier annoté, aperçu des changements, application après confirmation, historique |
| ADV | `/adv/regions`, `/adv/clients`, `/adv/clients/:id`, `/adv/produits`, `/adv/produits/:id` | Mêmes écrans en lecture seule (les actions apparaissent selon les permissions) |
| Régional | `/regional/clients`, `/regional/clients/:id` | **Mes clients** : recherche, statut, appel en un geste (`tel:`), fiche (EF-CLI-03) |
| Client | `/profil` | Carte « Mon entreprise » : sa fiche en lecture |

## 8. Répartition du travail (fichiers possédés)

Chaque lot de travail ne crée ou ne modifie **que** ses fichiers. Les fichiers communs (migrations, modèles, services partagés, `routes/api.php`, `types.ts`, navigation, routes de la PWA) sont fournis par la fondation ; un besoin de modification s'y signale au lieu d'être fait.

| Lot de travail | Fichiers |
|---|---|
| A — Régions | `Api/V1/RegionController`, `Api/V1/Admin/DepotController`, `Api/V1/Admin/RegionAssignmentController`, requêtes `Http/Requests/Regions/*`, ressources `RegionResource`, `DepotResource`, `AssignmentResource`, `Policies/RegionPolicy`, `CurrentUserResource` (périmètre), `lang/fr/regions.php`, `tests/Feature/Regions/*` |
| B — Clients | `Api/V1/ClientController`, `Api/V1/ClientCategoryController`, `Api/V1/Admin/ClientAccountController`, `Api/V1/Admin/UserController`, `Http/Requests/Clients/*`, `ClientResource`, `ClientCategoryResource`, `Policies/ClientPolicy`, `Policies/UserPolicy`, `Http/Requests/Admin/StoreUserRequest`, `UpdateUserRequest`, `UserResource`, `resources/views/pdf/client-access.blade.php`, `lang/fr/clients.php`, `tests/Feature/Clients/*`, `tests/Feature/Admin/UsersTest.php` |
| C — Produits | `Api/V1/ProductController`, `ProductFamilyController`, `ProductMediaController`, `ProductPriceController`, `Http/Requests/Products/*`, `ProductResource`, `ProductFamilyResource`, `ProductPhotoResource`, `ProductPriceResource`, `Policies/ProductPolicy`, `Domain/Referentials/ProductPhotos`, `docker/web/default.conf` (alias `/media`), `docker-compose.yml` (volume en lecture seule pour `web`), `docker/php/Dockerfile` (lien `public/media`), `composer.json` (script `setup` : `storage:link`), `lang/fr/products.php`, `tests/Feature/Products/*` |
| D — Imports | `Api/V1/ImportController`, `Domain/Imports/*` (sauf les deux énumérations), `Jobs/AnalyzeImport`, `ImportResource`, `Policies/ImportPolicy`, `lang/fr/imports.php`, `tests/Feature/Imports/*` |
| E1 — Écrans régions et clients | `frontend/src/modules/backoffice/regions/**`, `…/backoffice/clients/**`, `…/backoffice/shared/**` (composants communs du back-office), `frontend/src/shared/i18n/fr/backoffice/{common,regions,clients}.ts` |
| E2 — Écrans catalogue et imports | `frontend/src/modules/backoffice/products/**`, `…/backoffice/imports/**`, `frontend/src/shared/i18n/fr/backoffice/{products,imports}.ts` |
| F — Écrans terrain | `frontend/src/modules/regional/clients/**`, `frontend/src/modules/profile/MyCompanyCard.tsx`, `frontend/src/modules/profile/ProfilePage.tsx`, `frontend/src/shared/i18n/fr/field.ts` |
