# ---------- 1. Build frontendu (React/Vite) ----------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
# Ziggy/vite potrzebują vendor tylko przy SSR – zwykły build wystarczy
RUN npm run build

# ---------- 2. Zależności PHP ----------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

# ---------- 3. Obraz aplikacji (PHP-FPM) ----------
FROM php:8.3-fpm-alpine AS app
RUN apk add --no-cache icu-libs libzip libpng freetype libjpeg-turbo mysql-client gzip \
    && apk add --no-cache --virtual .build icu-dev libzip-dev libpng-dev freetype-dev libjpeg-turbo-dev $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_mysql bcmath intl zip gd opcache pcntl \
    && apk del .build

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-adpilot.ini

WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build

RUN chown -R www-data:www-data storage bootstrap/cache \
    && ln -sfn /var/www/html/storage/app/public /var/www/html/public/storage

USER www-data
CMD ["php-fpm"]

# ---------- 4. Nginx z plikami publicznymi ----------
FROM nginx:1.27-alpine AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=vendor /app/public /var/www/html/public
COPY --from=assets /app/public/build /var/www/html/public/build
# logo i inne pliki z panelu (wolumen storage współdzielony z aplikacją)
RUN ln -sfn /var/www/html/storage/app/public /var/www/html/public/storage
