#!/usr/bin/env bash
# Déploiement d'une version étiquetée sur le serveur (§14.4).
#
#   scripts/deploy.sh v1.0.0
#
# Retour arrière : relancer le script avec l'étiquette précédente (les
# migrations restent compatibles avec la version précédente).
# Mises en production en dehors de la plage 06:00 – 20:00.
set -euo pipefail

TAG="${1:?Usage : scripts/deploy.sh <étiquette>}"
cd "$(dirname "$0")/.."

if [[ ! -f .env ]]; then
  echo "Fichier .env absent : copier .env.example et le compléter." >&2
  exit 1
fi

hour=$(TZ=Africa/Algiers date +%H)
if (( 10#$hour >= 6 && 10#$hour < 20 )) && [[ "${FORCE_DAYTIME:-0}" != "1" ]]; then
  echo "Il est ${hour} h à Alger : déploiement refusé entre 06:00 et 20:00 (FORCE_DAYTIME=1 pour passer outre)." >&2
  exit 1
fi

echo "==> Récupération de ${TAG}"
git fetch --tags --quiet
git checkout --quiet "refs/tags/${TAG}"

export RAMYMAAK_VERSION="${TAG}"

echo "==> Construction des images"
docker compose build

echo "==> Base de données"
docker compose up -d --wait mysql
docker compose run --rm --no-deps app php artisan migrate --force
docker compose run --rm --no-deps app php artisan db:seed --class='Database\Seeders\RolesAndPermissionsSeeder' --force

echo "==> Redémarrage des services (workers et Reverb compris)"
docker compose up -d --remove-orphans --wait

echo "==> Version ${TAG} en service"
docker compose ps
