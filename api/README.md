# BIYAHE API

This directory contains the Laravel 13 REST API, PostgreSQL/PostGIS transport
model, Filament administration panel, seed fixture, and PHPUnit tests.

## Local setup

    cp .env.example .env
    composer install
    php artisan key:generate
    php artisan migrate --seed
    php artisan serve

Configure DB_CONNECTION=pgsql and a PostgreSQL database with PostGIS before
running migrations. SQLite is supported only as a test fallback; it cannot
validate PostGIS behavior or serve the spatial production API.

Create an administrator explicitly with php artisan make:filament-user.
There is no default account and no public registration endpoint. The panel is
available at /admin.

## Commands

    php artisan test
    php artisan biyahe:doctor

The doctor command returns BLOCKED_BY_ENVIRONMENT and a non-zero exit when
PostgreSQL/PostGIS or pdo_pgsql is unavailable; it never claims a spatial
PASS on SQLite.

The public API is versioned under /api/v1. See the repository documentation
for the domain model, map behavior, and verification lifecycle.
