# Image PHP commune aux services app (PHP-FPM), worker, scheduler et reverb (§14.2).

FROM composer:2 AS vendor
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
# gd (PhpSpreadsheet, photos) et exif (photos) manquent à l'image composer ;
# l'image finale ci-dessous les installe.
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist \
    --ignore-platform-req=ext-gd --ignore-platform-req=ext-exif
COPY backend/ ./
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts

FROM php:8.3-fpm-bookworm

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql intl zip gd exif bcmath pcntl opcache \
 && apt-get update \
 && apt-get install -y --no-install-recommends libfcgi-bin procps \
 && rm -rf /var/lib/apt/lists/*

COPY docker/php/php.ini "$PHP_INI_DIR/conf.d/zz-ramymaak.ini"
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-ramymaak.conf
COPY docker/php/entrypoint.sh /usr/local/bin/ramymaak-entrypoint
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /app ./
# Photos produits (§9.8) : disque « media » (storage/app/public/media, dans le
# volume « storage ») et lien public/media vers lui, pour les adresses /media
# générées par l'application ; en production, le conteneur web les sert
# directement depuis le volume. Les photos et le lien d'un poste de
# développement éventuellement copiés sont écartés : un volume neuf est
# initialisé avec le contenu de l'image.
RUN rm -f .env \
 && php artisan package:discover --ansi \
 && rm -rf public/media storage/app/public/media \
 && mkdir -p storage/app/public/media \
 && php artisan storage:link --no-ansi \
 && chown -R www-data:www-data storage bootstrap/cache

USER www-data
ENTRYPOINT ["ramymaak-entrypoint"]
CMD ["php-fpm"]
