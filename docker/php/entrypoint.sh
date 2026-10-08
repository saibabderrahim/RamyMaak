#!/bin/sh
# Mise en cache de la configuration au démarrage : les variables
# d'environnement du conteneur sont alors connues (§14.4).
set -e

if [ -z "$APP_KEY" ]; then
  echo "APP_KEY manquante : voir .env.example à la racine du dépôt." >&2
  exit 1
fi

php artisan config:cache --no-ansi -q
php artisan route:cache --no-ansi -q
php artisan event:cache --no-ansi -q

exec "$@"
