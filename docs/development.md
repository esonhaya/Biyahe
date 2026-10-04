# Development

## Requirements

- Node.js 20.9+, npm, and network access for the first web install.
- PHP 8.3+, Composer, and Laravel's required PHP extensions.
- PostgreSQL with PostGIS and PHP pdo_pgsql for the real API.

Docker and systemd are optional, not required. Termux development is
supported with compatible PHP, Node, PostgreSQL, and PostGIS packages.

## API

    cd api
    cp .env.example .env
    composer install
    php artisan key:generate

Create a PostgreSQL database and enable PostGIS:

    CREATE EXTENSION postgis;

Set DB_CONNECTION=pgsql, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, and
DB_PASSWORD in api/.env, then run:

    php artisan migrate --seed
    php artisan test
    php artisan biyahe:doctor
    php artisan serve

Use php artisan make:filament-user to create a local administrator. Do not
use development credentials in production. Set APP_ENV=production,
APP_DEBUG=false, a unique APP_KEY, and a strong database password when
deploying.

## Web

    cd web
    cp .env.example .env.local
    npm install
    npm run dev

Set NEXT_PUBLIC_API_URL if the API is not at the default
http://127.0.0.1:8000/api/v1. Run npm run lint, npm run typecheck, and
npm run build before release. The build script selects Webpack for
Termux/Android where Turbopack native bindings are unavailable.

## Environment limitation

The API's real geographic behavior requires a running PostgreSQL server,
PostGIS extension files, and pdo_pgsql. A PHP SQLite driver is not a
substitute. When those dependencies are unavailable, tests may use SQLite for
relational behavior, while health and Doctor explicitly report
BLOCKED_BY_ENVIRONMENT for PostGIS checks.
