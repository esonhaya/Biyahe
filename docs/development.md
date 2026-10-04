# Development

## Requirements

- Node.js 20.9+, npm, and network access for the first web install.
- PHP 8.3+, Composer, and Laravel's required PHP extensions.
- PostgreSQL with PostGIS and PHP pdo_pgsql for the real API.

The PostGIS integration suite is the source of truth for spatial behavior.
SQLite is useful for fast relational tests but cannot validate geometry
columns, spatial indexes, or geography distances.

## Conventional Linux

Install PostgreSQL, PostGIS, and the PHP PostgreSQL extension from the
distribution packages. Create a database, enable the extension, and grant
the development user access:

    createdb biyahe
    psql biyahe -c "CREATE EXTENSION postgis"

Then:

    cd api
    cp .env.example .env
    composer install
    php artisan key:generate
    php artisan migrate:fresh --seed
    php artisan biyahe:data:validate cebu-central-network-2026-10-05.json
    php artisan biyahe:data:import cebu-central-network-2026-10-05.json --dry-run
    php artisan test
    php artisan biyahe:doctor
    php artisan serve

Use php artisan make:filament-user to create a local administrator. Do not
use development credentials in production. Set APP_ENV=production,
APP_DEBUG=false, a unique APP_KEY, and a strong database password when
deploying.

## Termux

The practical Termux packages are:

    pkg update
    pkg install postgresql postgis php-pgsql

Confirm the runtime before testing:

    php -m | grep -E 'pdo_pgsql|pgsql'
    psql --version
    postgres --version
    geos-config --version

Termux does not provide systemd. An isolated user-owned cluster avoids root
requirements and avoids touching unrelated PostgreSQL data:

    mkdir -p api/.runtime
    initdb -D api/.runtime/postgres
    cd api
    pg_ctl -D "$PWD/.runtime/postgres" -o "-p 55432 -k $PWD/.runtime" -l "$PWD/.runtime/postgres.log" start
    createdb -h "$PWD/.runtime" -p 55432 biyahe
    psql -h "$PWD/.runtime" -p 55432 -d biyahe -c "CREATE EXTENSION postgis"

Set DB_CONNECTION=pgsql, DB_HOST=127.0.0.1, DB_PORT=55432, DB_DATABASE=biyahe,
DB_USERNAME, and DB_PASSWORD for Laravel. Stop the isolated server with:

    pg_ctl -D api/.runtime/postgres stop

If the package mirror does not contain PostGIS, do not compile a large stack
just to force a local PASS. Keep Doctor's BLOCKED result truthful and use CI
or a conventional Linux host for the real integration suite.

## Web

    cd web
    cp .env.example .env.local
    npm ci
    npm run dev

Set NEXT_PUBLIC_API_URL if the API is not at the default
http://127.0.0.1:8000/api/v1. Run npm run lint, npm run typecheck, and
npm run build before release. The build script selects Webpack for
Termux/Android where Turbopack native bindings may be unavailable.

## CI

GitHub Actions workflow .github/workflows/ci.yml provisions the
postgis/postgis service, installs pdo_pgsql, runs migrations and the reviewed
seed, executes the PostGIS integration and backend suites, runs Doctor, and
then runs web lint, typecheck, and production build. Local Termux installation
is not a prerequisite for CI.
