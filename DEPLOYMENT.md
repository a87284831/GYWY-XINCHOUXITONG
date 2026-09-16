# Laravel Production Deployment

## Prerequisites

- PHP 8.2+ with `fileinfo`, `mbstring`, `openssl`, `pdo_mysql`, `zip`, and `gd`
- Composer 2.x
- MySQL 8.x
- Nginx with PHP-FPM

## Deploy on Baota

1. Create a MySQL database and a restricted database user in Baota.
2. Upload the project to `/www/wwwroot/payroll`.
3. Copy `.env.example` to `.env` and set `APP_URL`, `DB_*`, and `LEGACY_DATA_PATH`.
4. Run `IMPORT_LEGACY=1 bash laravel-app/deploy.sh` once for the initial JSON import. Later deployments use `bash laravel-app/deploy.sh` without importing old data again.
5. Create the Baota website with document root `/www/wwwroot/payroll/laravel-app/public`.
6. Select PHP 8.2+ and enable the required extensions.
7. Enable HTTPS and set `client_max_body_size` to at least 50m.
8. Add a daily database backup and a separate backup for `storage/app/private`.

The existing `static/` UI is copied into `laravel-app/public`. The Laravel API uses the same `/api/*` paths and `X-Token` header, so no frontend rebuild is required for the current interface.

## First deployment

The deployment script runs migrations on every deploy. Legacy JSON import is opt-in with `IMPORT_LEGACY=1` and is idempotent for the migration tables. Do not use that flag against a production database that already contains newer edits unless a backup has been taken.

The Laravel application is the production entry point. Keep a database backup and a release archive before upgrades; rollback is performed by restoring the previous Laravel release and database backup.

## Health check

Open `/up`. A successful response is `Application up`.
