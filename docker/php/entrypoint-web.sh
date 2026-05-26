#!/usr/bin/env sh
set -eu

# Reuse the standard Laravel container init (storage dirs + public/storage symlink).
if command -v docker-entrypoint >/dev/null 2>&1; then
  docker-entrypoint true >/dev/null 2>&1 || true
fi

# Start PHP-FPM (background), then keep Nginx in the foreground (PID 1).
php-fpm -D
exec nginx -g 'daemon off;'

