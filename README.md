# IOMS Internal Ops Backend

Backend ini menjalankan API Laravel 11 untuk aplikasi internal admin ops IOMS ISP dengan PostgreSQL, Sanctum, queue worker, audit log, dan workflow operasional inti.

## Stack

- Laravel 11
- PostgreSQL
- Sanctum bearer token
- Queue `database`
- Storage publik Laravel untuk upload bukti

## Local Setup

1. Copy `.env.example` menjadi `.env`.
2. Isi `APP_KEY` dengan `php artisan key:generate`.
3. Buat database PostgreSQL dan sesuaikan `DB_*`.
4. Install dependency: `composer install`.
5. Jalankan migrasi dan seed demo lokal:
   `php artisan migrate --seed`
6. Buat symbolic link storage:
   `php artisan storage:link`
7. Jalankan server lokal:
   `php artisan serve --host=127.0.0.1 --port=8000`

## Environment Penting

- `APP_ENV`, `APP_DEBUG`, `APP_URL`
- `FRONTEND_URL`
- `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- `QUEUE_CONNECTION`, `CACHE_STORE`, `FILESYSTEM_DISK`
- `MAIL_*`
- `PROCUREMENT_MANAGEMENT_THRESHOLD`
- `LOGIN_RATE_LIMIT`, `LOGIN_RATE_LIMIT_WINDOW`
- `TRUSTED_PROXIES`
- `APP_ENABLE_DEMO_SEED`

## Produksi

Target deploy fase pertama:

- `Nginx` menyajikan build React dan meneruskan `/api` ke Laravel
- `PHP-FPM`
- `PostgreSQL`
- `Supervisor` untuk queue worker
- `Let's Encrypt` untuk HTTPS

Artefak contoh ada di:

- `deploy/nginx/ioms.conf`
- `deploy/supervisor/ioms-worker.conf`
- `deploy/scripts/deploy.sh`

## Checklist Deploy VPS

1. Buat user non-root, misalnya `ioms`.
2. Clone frontend dan backend ke `/var/www/ioms`.
3. Isi `.env` backend dengan kredensial produksi.
4. Jalankan `composer install --no-dev`.
5. Jalankan `php artisan migrate --force`.
6. Jalankan `php artisan storage:link`.
7. Jalankan `php artisan config:cache` dan `php artisan route:cache`.
8. Build frontend dengan `npm ci && npm run build`.
9. Pasang konfigurasi nginx dan supervisor dari folder `deploy`.
10. Aktifkan SSL dengan Let's Encrypt.
11. Smoke test: login, dashboard, create ticket, approve procurement, logout.

## Operasional

- Restart queue worker:
  `sudo supervisorctl restart ioms-worker`
- Lihat log Laravel:
  `tail -f storage/logs/laravel.log`
- Backup database:
  `pg_dump -Fc ioms_internal_ops > backup.dump`
- Rollback migrasi terakhir:
  `php artisan migrate:rollback --step=1`

## Seed Demo

`DatabaseSeeder` tidak otomatis memuat data demo di produksi. Demo seed hanya aktif di environment `local`, `testing`, atau saat `APP_ENABLE_DEMO_SEED=true`.
