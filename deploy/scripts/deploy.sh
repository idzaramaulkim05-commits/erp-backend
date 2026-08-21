#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="/var/www/ioms"
BACKEND_DIR="$APP_ROOT/backend"
FRONTEND_DIR="$APP_ROOT/frontend"

cd "$BACKEND_DIR"
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan migrate --force
php artisan storage:link || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

cd "$FRONTEND_DIR"
npm ci
npm run build

sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart ioms-worker
sudo systemctl reload php8.3-fpm
sudo systemctl reload nginx
