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
    # ImageMagick + support HEIC/HEIF (conversion photos iPhone)
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

# Répertoire persistant pour SQLite (volume sqlite_data monté ici au runtime).
# Initialisé avec la bonne propriété pour que www-data puisse écrire le fichier .sqlite.
RUN mkdir -p /var/sqlite && chown www-data:www-data /var/sqlite

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .

RUN composer dump-autoload --optimize \
 && mkdir -p storage/logs storage/framework/{cache,sessions,views} bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R 775 storage bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
