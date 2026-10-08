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
    php artisan biyahe:data:validate cebu-network-expansion-2026-10-05.json
    php artisan biyahe:data:import cebu-network-expansion-2026-10-05.json --dry-run
    php artisan biyahe:evidence:report --json --pairs=24 --max-transfers=2
    php artisan test
    php artisan biyahe:doctor
    php artisan biyahe:transfers:candidates --city=cebu-city
    php artisan biyahe:network:audit --pairs=50
    curl -H 'Accept: application/json' 'http://127.0.0.1:8000/api/v1/journeys?from_place_id=...&to_place_id=...'
    php artisan serve

After reviewing a dataset and its dry-run report, import it without
`--dry-run`. Imports are deterministic upserts keyed by stable identifiers;
validation must pass before any database write. Use the evidence report to
review claim-level coverage and prioritize records for verification.

Doctor runs one bounded O/D sample plus one Can-I-Ride check so it remains
practical on Termux/mobile hardware. Use `biyahe:network:audit` with larger
explicit pair bounds for broader coverage analysis; neither check assumes
universal network connectivity.

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

For a bounded Termux observation, run `bash api/scripts/observe-postgres.sh`.
It observes an already-running server and never restarts or stops it. To allow
one normal startup when the server is down, pass `--start`; the script checks
available memory, free swap, disk space, and the PID file first. Defaults are
850 MiB available RAM, 256 MiB free swap, and 1 GiB free disk; the minimums can
be set with `BIYAHE_PG_MIN_MEMAVAILABLE_KB`,
`BIYAHE_PG_MIN_SWAPFREE_KB`, and `BIYAHE_PG_MIN_DISKFREE_KB`. Each run writes a
private timestamped observation log under `api/.runtime` with readiness,
process, memory, swap, disk, and only newly appended PostgreSQL log lines.
The default observation is five minutes at 15-second intervals; both can be
bounded with `--duration-seconds` and `--interval-seconds`. A PID file for a
live or uncertain process blocks startup. If its PID is absent, the observer
leaves the file for PostgreSQL to handle during ordinary startup. The observer
never edits cluster files. If the server exits, the log classifies only
evidence appended during that observation; missing evidence remains unknown.
The observer does not kill, restart, or automatically shut down PostgreSQL.

Android may pause Termux when it is backgrounded. If that is disrupting a
deliberate local database session, review Android's app battery settings for
Termux and adjust them manually if appropriate. The repository does not change
device settings.

If the package mirror does not contain PostGIS, do not compile a large stack
just to force a local PASS. Keep Doctor's BLOCKED result truthful and use CI
or a conventional Linux host for the real integration suite.

## Journey planner checks

The planner is intentionally bounded: access/egress searches are capped at
1,000 meters, explicit transfer relationships at 600 meters, and public
searches at two transit transfers. Run the focused checks while changing
routing code:

    php artisan test --compact tests/Feature/JourneyApiTest.php tests/Feature/Batch005RoutingTest.php tests/Feature/PostgisTransportTest.php tests/Unit/JourneyRankerTest.php

Check the developer network audit and a route decision with:

    php artisan biyahe:network:audit --places=22 --pairs=60
    curl -H 'Accept: application/json' 'http://127.0.0.1:8000/api/v1/can-i-ride?route_code=17B&from_place_id=...&to_place_id=...'

Use the PostGIS environment for the `PostgisTransportTest` cases. A successful
SQLite run still skips the spatial assertions and does not prove geography
queries or route geometry behavior.

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
