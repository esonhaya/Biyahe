# Batch 014 — temporary commute awareness

Batch 014 adds a short-lived operational overlay. It is deliberately
separate from the canonical route, pattern, stop, fare, evidence, and
pedestrian datasets.

## Local workflow

From `api/`:

```sh
php artisan migrate --force
php artisan test --filter DisruptionAwarenessTest
php artisan biyahe:doctor
```

The web client can then be checked from `web/` with:

```sh
npm run typecheck
npm run lint
npm run build
```

No OSM download or pedestrian graph import is required for this batch.

## Advisory lifecycle

An administrator creates a `DRAFT` advisory, attaches an existing
provenance source and affected entities, then schedules or activates it.
Activation records the reviewer and an audit row. `ends_at` is evaluated on
every active query, so an expired advisory stops affecting public responses
and planning even if its stored status has not yet been changed to
`EXPIRED`. Resolve/reject actions preserve the history and never edit
canonical transport rows.

Public commuter reports start as `SUBMITTED`. Admin review can move them to
`UNDER_REVIEW`, `ACCEPTED_AS_SIGNAL`, `REJECTED`, `DUPLICATE`, or `EXPIRED`.
Promotion to an advisory is an explicit reviewed action. A promoted public
report keeps `source_class=PUBLIC_REPORT`, is warning-only, and cannot hard
close a route or stop. It is not a field observation or transport evidence.
Fare observations remain reviewable signals and cannot be promoted into service
advisories. Promotion writes an explicit advisory review audit row.

## Public API

* `GET /api/v1/disruptions` — current, provenance-backed advisories, with
  bounded route/pattern/stop/place filters.
* `GET /api/v1/disruptions/{id}` — one currently active advisory.
* `POST /api/v1/commuter-reports` — bounded report intake. It accepts an
  explicitly submitted point only; it never starts location tracking.

The planner decorates matching journeys with an operational impact. Reviewed
hard categories from sufficiently trusted sources may temporarily remove a
candidate. Warning-only advisories remain eligible and are ranked below an
otherwise comparable unaffected journey. Public report advisories are always
warning-only. No advisory changes route geometry, pattern stops, fares,
boarding precision, transfer verification, or canonical evidence.

The browser displays impacts in search results, Commute Cards, Can I Ride,
Ride Mode, and MapLibre. Saved journeys retain only the advisory snapshot
captured with the journey; offline UI labels it stale and does not claim
current disruption knowledge. Ride Mode refresh is manual and bounded.
