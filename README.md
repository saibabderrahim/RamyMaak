# RamyMaak

Plateforme B2B de prise de commandes sous quotas — Ramy (GROUP-SAFA).

Chaîne quotidienne : **ADV** (quota région × produit) → **Régional** (bornes par client) → **Client** (commande) → validation par le régional → préparation et expédition par l'ADV.

- Spécification : [docs/specification-technique-v1.0.md](docs/specification-technique-v1.0.md)
- Notes de livraison : [lot 1 — socle](docs/lot-1-socle.md), [lot 2 — référentiels](docs/lot-2-referentiels.md), [lot 3 — quotas ADV](docs/lot-3-quotas-adv.md), [lot 4 — répartition](docs/lot-4-repartition.md), [lot 5 — commandes client](docs/lot-5-commandes-client.md), [lot 6 — validation et ADV](docs/lot-6-validation-adv.md), [lot 7 — charte Ramy](docs/lot-7-charte-ramy.md), [lot 8 — PWA avancée](docs/lot-8-pwa-avancee.md) ; contrats d'API : [lot 2](docs/lot-2-contrat-api.md), [lot 3](docs/lot-3-contrat-api.md), [lot 4](docs/lot-4-contrat-api.md), [lot 5](docs/lot-5-contrat-api.md), [lot 6](docs/lot-6-contrat-api.md), [lot 8](docs/lot-8-contrat-api.md)
- Déploiement : [docs/deploiement.md](docs/deploiement.md)

## Organisation du dépôt (§9.3)

| Dossier | Contenu |
|---|---|
| [`backend/`](backend/README.md) | API Laravel 12 (PHP 8.3) : règles métier, authentification, tâches de fond |
| [`frontend/`](frontend/README.md) | PWA React 19 + TypeScript : modules client, régional, ADV, Admin |
| `docker/` | Images (PHP, serveur web interne), configuration nginx de l'hôte |
| `docker-compose.yml` | Services du serveur dédié : web, app, worker, scheduler, reverb, mysql |
| `scripts/deploy.sh` | Déploiement d'une version étiquetée |
| `.github/workflows/ci.yml` | Intégration continue |
| `docs/` | Spécification, notes de lot |

## Développement local

Prérequis : PHP 8.3 (extensions pdo_mysql, intl, zip, gd avec WebP, exif), Composer, Node.js 22, MySQL 8.4 (ou SQLite).

```bash
# API — http://localhost:8000
cd backend
cp .env.example .env        # ajuster DB_* (ou DB_CONNECTION=sqlite)
composer setup              # dépendances, clé, lien des photos, migrations, données de démonstration
composer dev

# PWA — http://localhost:5173 (relaie /api et /sanctum vers l'API)
cd frontend
npm ci
npm run dev
```

Comptes de démonstration (hors production) — mot de passe `RamyMaak2026demo` : `admin`, `adv`, `regional` (titulaire de la région CENTRE), `c0012` (client C0012). Le jeu de démonstration comprend aussi régions, clients, produits et prix. Les imports Excel sont analysés par la file d'attente : lancer aussi `php artisan queue:work` dans un second terminal.

## Contrôles

| | Commandes |
|---|---|
| API | `composer lint` · `composer test` (dans `backend/`) |
| PWA | `npm run lint` · `npm run typecheck` · `npm test` · `npm run build` (dans `frontend/`) |

La CI exécute en plus les tests de l'API sur MySQL 8.4, l'audit des dépendances, la construction des images et le démarrage de la pile complète.

## Recette et production (Docker Compose)

Installation d'un serveur, HTTPS, premier compte Admin, livraisons et retour arrière : **[docs/deploiement.md](docs/deploiement.md)**.

En résumé, une fois le serveur préparé et `.env` rempli :

```bash
scripts/deploy.sh v0.2.0          # FORCE_DAYTIME=1 hors production (déploiement interdit 06:00 – 20:00)
docker compose run --rm --no-deps app php artisan ramymaak:create-admin a.benali --name="Amina Benali"
```

L'application écoute sur `127.0.0.1:8090` et le WebSocket sur `127.0.0.1:8091`, derrière le nginx de l'hôte ([`docker/nginx-host/ramymaak.conf`](docker/nginx-host/ramymaak.conf)).
