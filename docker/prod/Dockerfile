# syntax=docker/dockerfile:1
# Produkční image Konšelu: assety (Vite) + závislosti bez dev balíčků + PHP 8.4 s Apachem.
# Sestavuje se z kořene repozitáře: docker build -f docker/prod/Dockerfile .
# (stack v Portaineru: compose.prod.yaml)

# --- 1) CSS a JS do www/assets ---
FROM node:22-alpine AS assets
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY assets ./assets
RUN npm run build

# --- 2) PHP s rozšířeními (sdílený základ) ---
FROM php:8.4-apache AS base

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        sqlite3 \
        libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" intl zip gd opcache pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

# --- 3) Composer závislosti (vrstva se přestaví jen při změně composer.lock) ---
FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist

# --- 4) Výsledný image ---
FROM base

ENV APACHE_DOCUMENT_ROOT=/app/www \
    TZ=Europe/Prague

RUN a2enmod rewrite headers \
    && sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's!/var/www/!/app/!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

COPY docker/prod/php.ini /usr/local/etc/php/conf.d/zz-konsel.ini
COPY docker/php/apache.conf /etc/apache2/conf-available/konsel.conf
RUN a2enconf konsel

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /build/www/assets ./www/assets

RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && rm -f config/local.neon \
    && mkdir -p temp log var \
    && chown -R www-data:www-data temp log var

COPY --chmod=755 docker/prod/entrypoint.sh /usr/local/bin/konsel-entrypoint
COPY --chmod=755 docker/prod/scheduler.sh /usr/local/bin/konsel-scheduler
COPY --chmod=755 docker/prod/konsel.sh /usr/local/bin/konsel

ENTRYPOINT ["konsel-entrypoint"]
CMD ["apache2-foreground"]
