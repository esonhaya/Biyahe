# BIYAHE

Biyahe is an open-source Philippine public-transport companion designed to
reduce commuter uncertainty with verified local transport knowledge.

Batch 001 establishes the first Cebu City development slice:

- a Laravel 13 / PostgreSQL + PostGIS API under api/;
- a Next.js 16 / React / TypeScript / MapLibre web app under web/;
- authenticated Filament administration for the transport records; and
- a small, explicitly artificial fixture for places, aliases, stops, routes,
  directional patterns, geometry, and fare foundations.

Fixture records are DRAFT and marked as demo data. They are structural
examples, not authoritative Cebu service information. Full A-to-B journey
planning is intentionally deferred.

## Quick start

The API needs PHP 8.3+, Composer, PostgreSQL with PostGIS, and the PHP
pdo_pgsql extension. The web app needs Node.js 20.9+ and npm. Docker,
systemd, and paid services are not required for development.

    cd api
    cp .env.example .env
    composer install
    php artisan key:generate
    # Configure PostgreSQL/PostGIS in .env.
    php artisan migrate --seed
    php artisan biyahe:doctor
    php artisan serve

In another terminal:

    cd web
    cp .env.example .env.local
    npm install
    npm run dev

The web app defaults to http://127.0.0.1:8000/api/v1. Create an admin
explicitly with php artisan make:filament-user; no default credentials are
committed.

## Documentation

- Architecture: docs/architecture.md
- Development: docs/development.md
- Domain model: docs/domain-model.md
- Maps: docs/maps.md
- Data verification: docs/data-verification.md

## License

BIYAHE is licensed under the GNU Affero General Public License, version 3 or
later. See LICENSE.
