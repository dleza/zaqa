#!/usr/bin/env sh
set -eu

APP_DIR="/var/www/html"

mkdir -p \
  "$APP_DIR/storage/app/private" \
  "$APP_DIR/storage/app/public" \
  "$APP_DIR/storage/framework/cache/data" \
  "$APP_DIR/storage/framework/sessions" \
  "$APP_DIR/storage/framework/views" \
  "$APP_DIR/storage/logs" \
  "$APP_DIR/bootstrap/cache"

if [ ! -e "$APP_DIR/public/storage" ]; then
  ln -s "$APP_DIR/storage/app/public" "$APP_DIR/public/storage" || true
fi

chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" || true

exec "$@"
