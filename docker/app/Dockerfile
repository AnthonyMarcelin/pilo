# ── Stage 1 : Node.js — build des assets Vite / PWA ────────────────────────
FROM node:20-alpine AS node-builder
WORKDIR /build

# Dépendances npm séparées pour profiter du cache Docker
COPY package.json package-lock.json ./
RUN npm ci --quiet

# Sources Vite (config + ressources + icônes PWA)
COPY vite.config.js postcss.config.js tailwind.config.js jsconfig.json ./
COPY resources/ resources/
COPY public/ public/

RUN npm run build
# Résultat : /build/public/build/ (assets hashés + SW workbox + manifest PWA)

# ── Stage 2 : PHP application ────────────────────────────────────────────────
FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    sqlite-dev \
    oniguruma-dev \
    libzip-dev \
    freetype-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    icu-dev \
    curl \
    bash \
    autoconf \
    g++ \
    make \
    # ImageMagick + HEIC/HEIF (photos iPhone — libheif requis pour le delegate HEIC)
    imagemagick \
    imagemagick-dev \
    libheif \
    libheif-dev

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) \
      pdo_sqlite bcmath mbstring zip gd pcntl intl

RUN pecl install redis imagick \
 && docker-php-ext-enable redis imagick

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Assets Vite buildés (stage node-builder) stockés HORS de /var/www/html
# pour ne PAS être écrasés par le volume ./:/var/www/html du compose.
# L'entrypoint les copie dans public/build/ à chaque démarrage.
COPY --from=node-builder /build/public/build /pilo_build

# Répertoire persistant pour SQLite (volume sqlite_data monté ici au runtime)
RUN mkdir -p /var/sqlite && chown www-data:www-data /var/sqlite

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .

RUN composer dump-autoload --optimize \
 && mkdir -p storage/logs storage/framework/{cache,sessions,views} bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R 775 storage bootstrap/cache

# Augmente les limites d'upload PHP (photos iPhone 2-4 Mo, ordonnances jusqu'à ~15 Mo).
# Cohérent avec File::max(15*1024) dans les règles de validation Laravel
# et client_max_body_size 30m dans la conf nginx.
RUN printf 'upload_max_filesize = 25M\npost_max_size = 30M\n' \
    > /usr/local/etc/php/conf.d/uploads.ini

# Entrypoint : gère composer/assets/SQLite/permissions avant de lancer le process
COPY docker/app/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 9000
ENTRYPOINT ["/entrypoint.sh"]
CMD ["php-fpm"]
