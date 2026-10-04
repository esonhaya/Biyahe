# BIYAHE API

This directory contains the Laravel 13 REST API, PostgreSQL/PostGIS transport
model, Filament administration panel, reviewed Cebu dataset, importer, and
PHPUnit tests.

## Local setup

    cp .env.example .env
    composer install
    php artisan key:generate
    php artisan migrate --seed
    php artisan serve

Configure DB_CONNECTION=pgsql and a PostgreSQL database with PostGIS before
running migrations. SQLite is supported only as a relational test fallback;
it cannot validate PostGIS behavior or serve the spatial production API.

Create an administrator explicitly with php artisan make:filament-user.
There is no default account and no public registration endpoint. The panel is
available at /admin.

## Commands

    php artisan test
    php artisan biyahe:data:validate cebu-central-network-2026-10-05.json
    php artisan biyahe:data:import cebu-central-network-2026-10-05.json --dry-run
    php artisan biyahe:data:import cebu-central-network-2026-10-05.json
    php artisan biyahe:doctor

The import command validates the complete dataset before opening a transaction.
Validation errors return a non-zero exit and write nothing. Successful
re-imports are deterministic and non-destructive: unchanged records are
skipped, changed records are updated, and omitted records are not deleted.

Doctor returns PASS, WARN, FAIL, or BLOCKED. It returns a non-zero exit for
critical data failures and keeps missing PostgreSQL/PostGIS distinguishable
from corrupt transport data. It never claims a spatial PASS on SQLite.

The public API is versioned under /api/v1. Default collections hide demo and
retired records; trusted_only=1 selects only non-demo VERIFIED records.
Distances and nearby-query radii are expressed in meters.

See the repository documentation for the data format, OSM/ODbL obligations,
domain model, map review behavior, and verification lifecycle.
