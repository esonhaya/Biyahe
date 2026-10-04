# BIYAHE

Biyahe is an open-source Philippine public-transport companion designed to
reduce commuter uncertainty with carefully sourced local transport knowledge.

Batch 002 establishes a real PostgreSQL/PostGIS gate, a repeatable
provenance-first dataset importer, and a small Cebu City review network. The
reviewed geographic places and public-transport stops are sourced from
OpenStreetMap. The route and pattern service associations remain UNVERIFIED
until stronger route evidence is collected. The artificial Batch 001 fixture
remains available only as demo data and is never exposed by default public
queries.

The full A-to-B journey planner is intentionally deferred.

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
    php artisan biyahe:data:validate cebu-central-network-2026-10-05.json
    php artisan biyahe:data:import cebu-central-network-2026-10-05.json --dry-run
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

SQLite can exercise relational tests, but it cannot prove spatial behavior.
Doctor reports PostGIS as BLOCKED when the configured database is not a
PostgreSQL/PostGIS connection.

## Documentation

- Architecture: docs/architecture.md
- Development: docs/development.md
- Domain model: docs/domain-model.md
- Maps: docs/maps.md
- Data verification: docs/data-verification.md
- Dataset format and import: docs/data-format.md
- Sources and licensing: docs/data-sources.md

## License

BIYAHE is licensed under the GNU Affero General Public License, version 3 or
later. See LICENSE.
