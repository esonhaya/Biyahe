# Biyahe deployment and release gate

Biyahe has two deployable applications: the Laravel API/Filament app in
`api/` and the Next.js web app in `web/`. The production data dependency is
PostgreSQL with PostGIS; SQLite is only a test harness.

## Prerequisites

- PHP 8.3+, Composer, and `pdo_pgsql`
- PostgreSQL with PostGIS enabled
- Node.js 20.9+ and npm
- persistent storage for PostgreSQL, Laravel storage, and any configured map
  style/tile assets
- HTTPS in front of the API and web app in production. Browser geolocation,
  service workers, and install prompts require a secure context (localhost is
  the development exception).

Configure `api/.env` with the production database, `APP_KEY`, `APP_URL`,
trusted proxy/CORS settings, rate limits, and any operator token used by the
authorized Field Verification Mode. Do not commit `.env`, tokens, or map
provider secrets. Configure `web/.env.local` with the public API URL.

## Release check

From `api/`, run the bounded, read-only prerequisite check against the real
database:

```sh
php artisan migrate:status
php artisan biyahe:release-check --json
php artisan biyahe:doctor
```

The release check requires a reachable database, PostGIS, current migrations,
one valid active pedestrian graph, zero unsupported verified transport claims,
and no synthetic operational/field rows. It does not deploy or modify data.

Run the frontend artifact checks before publishing:

```sh
cd ../web
npm ci
npm run typecheck
npm run lint
npm run build
```

The web shell contains a conservative PWA service worker. It caches the app
shell and bounded static assets, never caches `/api/` responses, and never
intercepts non-GET submissions. Field observations remain in the explicit
local outbox until the API returns a receipt.

## Database backup and restore

Use PostgreSQL's normal tools from a trusted host. These are documented
examples; review the target and backup path before running a restore:

```sh
pg_dump --format=custom --file=biyahe-$(date +%Y%m%d).dump "$DATABASE_URL"
createdb biyahe_restore_review
pg_restore --exit-on-error --dbname=biyahe_restore_review biyahe-YYYYMMDD.dump
```

Keep the versioned transport/evidence JSON, pedestrian source metadata, and
import commands with the deployment artifact. A database dump alone is not a
reproducible source registry.

## Field operations boundary

Field Mode is for an authorized operator and uses planned missions. A mission
can be downloaded as a bounded snapshot, observed offline, and synchronized
with stable client submission IDs. Each optional location is an explicit
foreground point attached to one observation; Ride Mode GPS is never an
evidence source. Observation acceptance and canonical promotion are separate
reviewer actions with before/after audit snapshots.

No real device installation, browser permission, or HTTPS behavior is proven
by local builds. Validate those operationally in the target deployment.
