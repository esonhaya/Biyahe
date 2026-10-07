# Batch 015 — production activation, Pulse, and explicit replan

Batch 015 exercises the Batch 014 disruption/report schema against the
project's local PostgreSQL/PostGIS runtime, then exposes two bounded commuter
features:

- **Biyahe Pulse** summarizes reviewed active advisories separately from
  accepted but non-authoritative commuter signals.
- **Find another way** calls the existing `JourneyPlanner` through
  `POST /api/v1/journeys/replan`. It never changes the selected journey until
  the commuter explicitly chooses an alternative.

Operational advisories are overlays. They do not mutate routes, patterns,
stops, fares, boarding precision, transfer verification, evidence, or the
pedestrian graph. Public reports require review and explicit promotion before
they can create a temporary advisory; they cannot independently hard-close a
service.

## Live validation

The local runtime used by the Cebu graph is intentionally separate from the
default SQLite test harness. From `api/`, use the persisted project runtime:

```sh
PGDATA=/data/data/com.termux/files/home/Biyahe/api/.runtime/postgres
RUNTIME=/data/data/com.termux/files/home/Biyahe/api/.runtime
pg_ctl -D "$PGDATA" -o "-p 55432 -k $RUNTIME" -l "$RUNTIME/postgres.log" -w start

APP_ENV=production DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432 \
DB_DATABASE=biyahe DB_USERNAME=u0_a664 DB_PASSWORD= \
php artisan migrate --force --no-ansi

APP_ENV=production DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432 \
DB_DATABASE=biyahe DB_USERNAME=u0_a664 DB_PASSWORD= \
php artisan biyahe:batch015-smoke --no-ansi

APP_ENV=production DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432 \
DB_DATABASE=biyahe DB_USERNAME=u0_a664 DB_PASSWORD= \
php artisan biyahe:doctor --no-ansi
```

`biyahe:batch015-smoke` wraps reviewed advisory creation, PostGIS viewport
matching, journey/Can-I-Ride impact, public report review/promotion, and all
supported advisory geometry types in one rollback-only transaction. It must
finish with `SYNTHETIC_PERSISTENT_ROWS_AFTER=0`.

The public read endpoints are:

```text
GET  /api/v1/pulse?filter=ALL|ROUTES|STOPS|AREAS|MAJOR
POST /api/v1/journeys/replan
```

Pulse's `NO_KNOWN_ACTIVE_ADVISORIES` status means only that no reviewed
temporary advisory is known; it is not a claim that every service is normal.
Offline saved journeys remain snapshots and cannot learn current Pulse data or
search a new route without connectivity.
