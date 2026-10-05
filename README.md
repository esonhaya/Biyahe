# BIYAHE

Biyahe is an open-source Philippine public-transport companion designed to
reduce commuter uncertainty with carefully sourced local transport knowledge.

Batch 003 adds a bounded, trust-aware A-to-B planner for the reviewed Cebu
slice. It supports direct rides and explicitly curated one-transfer walking
relationships, while keeping route-pattern associations UNVERIFIED where the
available OSM evidence does not prove the exact passenger stop sequence. The
artificial Batch 001 fixture remains demo-only and is never exposed by default
public queries.

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

The journey endpoint is `GET /api/v1/journeys?from_place_id=&to_place_id=`.
The web home page resolves canonical places, calls this endpoint, and shows
boarding, alighting, transfer, trust, fare, and map information. Walking
distances are straight-line estimates, not pedestrian directions.

## Documentation

- Architecture: docs/architecture.md
- Development: docs/development.md
- Domain model: docs/domain-model.md
- Maps: docs/maps.md
- Data verification: docs/data-verification.md
- Dataset format and import: docs/data-format.md
- Sources and licensing: docs/data-sources.md
- Journey planning: docs/journey-planning.md
- Haya reuse audit: docs/haya-reuse-audit.md

## License

BIYAHE is licensed under the GNU Affero General Public License, version 3 or
later. See LICENSE.
