# syntax=docker/dockerfile:1.6

ARG PHP_VERSION=8.2
ARG NODE_VERSION=20

FROM node:${NODE_VERSION}-alpine AS frontend
WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.js tsconfig.json ./
COPY resources ./resources
COPY public ./public

RUN npm run build

FROM php:${PHP_VERSION}-fpm-bookworm AS app
WORKDIR /var/www/html

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        unzip \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libzip-dev \
    ; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        zip \
    ; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY . .

RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader

COPY --from=frontend /app/public/build ./public/build

RUN set -eux; \
    mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; \
    chown -R www-data:www-data storage bootstrap/cache

COPY docker/php/entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint

ENTRYPOINT ["docker-entrypoint"]
CMD ["php-fpm"]

FROM nginx:1.27-alpine AS nginx
WORKDIR /var/www/html

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public

RUN ln -sf /var/www/html/storage/app/public /var/www/html/public/storage

# Single-container target (Render/other PaaS): Nginx + PHP-FPM in one container.
# Render does not support Docker Compose networking, so `fastcgi_pass app:9000` will fail.
FROM app AS web
WORKDIR /var/www/html

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends nginx; \
    rm -rf /var/lib/apt/lists/*; \
    rm -f /etc/nginx/sites-enabled/default /etc/nginx/sites-available/default || true

COPY docker/nginx/default.single.conf /etc/nginx/conf.d/default.conf
COPY docker/php/entrypoint-web.sh /usr/local/bin/docker-entrypoint-web
RUN chmod +x /usr/local/bin/docker-entrypoint-web

EXPOSE 80
ENTRYPOINT ["docker-entrypoint-web"]
