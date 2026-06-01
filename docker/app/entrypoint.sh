#!/bin/sh
# Entrypoint commun aux services `app` et `queue`.
# Tourne en root (PID 1) — fait la mise en place puis exec le processus cible.
#
# Gère :
#   1. Vendor Composer  — le volume ./:/var/www/html écrase le vendor buildé dans l'image
#   2. Assets Vite      — copiés depuis /pilo_build (stage Node, hors volume)
#   3. SQLite           — création du fichier + permissions au premier démarrage
#   4. Storage/cache    — permissions pour www-data
set -e

cd /var/www/html

echo "[pilo] === entrypoint ==="

# ── 1. Vendor Composer ──────────────────────────────────────────────────────
# Le volume ./:/var/www/html masque le vendor buildé dans l'image.
# On réinstalle uniquement si vendor/autoload.php est absent.
if [ ! -f vendor/autoload.php ]; then
    echo "[pilo] vendor absent — composer install..."
    composer install \
        --no-dev \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader \
        --quiet
    echo "[pilo] composer install terminé."
fi

# ── 2. Assets Vite ──────────────────────────────────────────────────────────
# Buildés dans l'image (stage Node → /pilo_build), copiés dans public/build/
# à chaque démarrage pour rester synchrones avec l'image courante.
# public/build est dans .gitignore → non présent dans le volume monté.
#
# SKIP_ASSET_COPY=1 : positionné sur le service `queue` dans docker-compose.yml
# pour éviter la race condition entre `app` et `queue` qui tournent tous les deux
# cet entrypoint au démarrage. Seul `app` gère la copie ; `queue` n'a pas besoin
# de servir des assets statiques (c'est nginx/web qui les sert).
if [ -d /pilo_build ] && [ "${SKIP_ASSET_COPY:-0}" != "1" ]; then
    rm -rf public/build
    cp -r /pilo_build public/build
fi

# ── 3. SQLite — création + permissions ──────────────────────────────────────
mkdir -p /var/sqlite
if [ ! -f /var/sqlite/database.sqlite ]; then
    echo "[pilo] SQLite : création de /var/sqlite/database.sqlite..."
    touch /var/sqlite/database.sqlite
fi
# chmod sur le répertoire aussi : nécessaire pour les fichiers WAL (-shm, -wal)
chown www-data:www-data /var/sqlite /var/sqlite/database.sqlite
chmod 775 /var/sqlite
chmod 664 /var/sqlite/database.sqlite

# ── 4. Permissions Laravel ──────────────────────────────────────────────────
mkdir -p storage/logs \
         storage/framework/cache \
         storage/framework/sessions \
         storage/framework/views \
         bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

echo "[pilo] démarrage : $*"
exec "$@"
