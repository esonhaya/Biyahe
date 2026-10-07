# Batch 018 — planner scale and resumable network audit

Batch 018 adds bounded engineering tooling for the expanded Metro Cebu route
atlas. It does not acquire or reimport the pedestrian graph and it does not
promote OSM route geometry into service, boarding, alighting, transfer, fare,
or field evidence.

## Planner instrumentation

The optional request-scoped `JourneyPlanningContext` profile separates nearby
stop lookup, direct search, transfer expansion, two-transfer expansion,
pedestrian routing, disruption matching, ranking, and total time. It also
counts loaded patterns, candidate stops, states, transfer candidates, and
pedestrian cache reuse. Normal public journey responses are unchanged.

Run one bounded case or the deterministic profile set with PostgreSQL/PostGIS:

    cd api
    DB_CONNECTION=pgsql DB_HOST=/path/to/socket DB_PORT=55432 \
      DB_DATABASE=biyahe DB_USERNAME=... \
      php artisan biyahe:batch018:profile --case=colon-ayala --json

The planner uses request-local pattern/association indexes, batch-loaded
pattern data, request-local transfer-connection reuse, and existing local
pedestrian-route caching. These are derived caches; canonical database rows
remain the source of truth. The transfer cap remains two and search states
remain bounded. When the configured result limit is already filled by
direct/one-transfer MIXED candidates, no active operational overlay exists,
and no active pattern is VERIFIED, a rank-proof bound defers two-transfer
expansion; that bound is disabled whenever trust or disruption state could
change the ordering.

## Resumable 47-pair audit

The explicit release matrix is run sequentially and checkpointed after each
pair. Runtime state is intentionally ignored at
`api/storage/app/batch018/<namespace>/`; it is not production data and must
not be committed.

    php artisan biyahe:batch018:audit --fresh --limit=1 --json
    php artisan biyahe:batch018:audit --resume --json

The manifest includes a network fingerprint, audit version, bounded per-pair
metadata, and atomic result files. A compatible resume skips `COMPLETE` pairs;
an incompatible dataset/matrix refuses to resume. `FAILED` and `RUNNING`
states remain visible rather than being counted as complete.

Generate claim-level service, sequence, boarding, transfer, field-survey-task,
pedestrian-gap, and data-quality priorities from completed checkpoint data:

    php artisan biyahe:batch018:intelligence --limit=50 --json

The generated field survey entries are planned tasks (`is_evidence=false`).
No claim is promoted by the audit. Pedestrian gaps distinguish stops outside
the active bounded OSM graph from in-bounds access uncertainty; coordinates
are never moved automatically.

## Release boundaries

The full matrix is a coverage characterization, not proof that every mapped
route operates. The current atlas is broadly routable but remains MIXED or
UNVERIFIED at the journey level. Nearby and Route Explorer queries remain
bounded by their existing API limits and radius validation. Raw GPS remains
client-side/foreground-only, and synthetic advisories, reports, field
observations, and evidence promotions must be absent from production.

Use real PostgreSQL/PostGIS for the final Doctor and spatial/network gate;
SQLite is only a fast relational test harness.
