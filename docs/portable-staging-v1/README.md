# BIYAHE Cebu graph staging package v1

This package carries the already validated Batch030 SQLite graph, the exact
source PBF required by the importer checksum gate, the verified Batch024
PostgreSQL custom dump, Batch030's complete source restriction audit, Batch032's
ten real Cebu journey cases, and a bundle of the committed importer and routing
code. It does not rebuild the graph,
activate pedestrian routing, or establish field safety. The PBF is verified
by checksum only; do not run Osmium during this workflow.

The source restriction audit accounts for 648 relations: 619 stored in the
v3 graph and 29 source relations omitted by its v3 capture predicate. The
source-bound audit classifies all 648 as vehicle-only, with zero explicit
pedestrian-applicable, ambiguous, or unsupported-applicable relations. It
records 33 generic conditional restrictions; these are not dynamically
evaluated as vehicle rules by the pedestrian router. The package preserves
that limitation and the underlying relation evidence.

OSM-derived data remains subject to ODbL-1.0. Preserve the included OpenStreetMap
attribution and source snapshot `2026-10-07T01:00:21Z` when redistributing.

## Rebuild the package from the verified Termux assets

Run only after committing the package source code and creating a new Git bundle
that contains the current `HEAD`. The builder refuses to overwrite its target,
requires package source files to match that committed revision, checks free
space before copying, streams SHA-256 checksums, and verifies every copied
file. It also requires the graph's `.restriction-audit.json` sidecar.

```sh
GRAPH="$HOME/.cache/biyahe/batch030/cebu-walk-central-visayas-20261007-v3.sqlite"
SOURCE="$HOME/storage/external-1/Termux-Archive/Biyahe/OSM/central_visayas-20261007.osm.pbf"
BACKUP="$HOME/storage/external-1/Termux-Archive/Biyahe/Database-Backups/biyahe-batch024-20261008.dump"
BUNDLE="/path/to/new/biyahe-batch036-core.bundle"
CORE_COMMIT="$(git rev-parse HEAD)"
DESTINATION="$HOME/storage/external-1/Termux-Archive/Biyahe/Portable-Staging-v1/20261009T120000Z"
bash api/scripts/package-portable-staging.sh \
  --destination "$DESTINATION" --graph "$GRAPH" --source "$SOURCE" \
  --backup "$BACKUP" --bundle "$BUNDLE" --core-commit "$CORE_COMMIT"
```

Choose a new destination directory for every build. If an interrupted `.building`
directory exists, inspect and preserve it; the builder intentionally refuses
to overwrite it.

## Package verification

Run from the package directory:

```sh
bash scripts/verify-portable-staging-package.sh .
```

The verifier checks the fixed graph, PBF, and backup identities; every
manifest file hash; exact artifact-to-file hash binding; safe package paths;
the repository bundle and declared core commit; and required members. It never
starts or connects to PostgreSQL. The verifier requires Python 3, Git, and
SHA-256 support from Python's standard library.

The repository bundle contains committed code only. Obtain the repository
revision using the bundled Git history and check that the checkout is clean:

```sh
CORE_COMMIT="$(python3 -c 'import json; print(json.load(open("manifest.json"))["repository"]["core_commit"])')"
git clone repository/biyahe-core.bundle "$HOME/src/biyahe-portable-stage"
git -C "$HOME/src/biyahe-portable-stage" checkout "$CORE_COMMIT"
test "$(git -C "$HOME/src/biyahe-portable-stage" rev-parse HEAD)" = "$CORE_COMMIT"
test -z "$(git -C "$HOME/src/biyahe-portable-stage" status --porcelain)"
```

Install the locked Composer dependencies in that checkout. Do not run the
project's generic `composer run setup` script: it also runs migrations and
frontend setup. No frontend build or package installation is needed for graph
staging. Do not copy a production `.env` file into this checkout.

```sh
CHECKOUT="$HOME/src/biyahe-portable-stage"
cd "$CHECKOUT/api"
composer install --no-interaction --no-progress
```

## Host requirements

- Linux under a non-root account; PHP 8.3 or newer with `pdo_pgsql`,
  `pdo_sqlite`, and `sqlite3` extensions; repository Composer dependencies.
- PostgreSQL 18.x and PostGIS 3.6.x. PostgreSQL 18.6/PostGIS 3.6.4 was used
  for an earlier isolated backup restore; other majors are unverified.
- Python 3, Git, PostgreSQL client/server tools (`initdb`, `pg_ctl`, `psql`,
  `createdb`, `pg_restore`, `pg_config`), `setsid`, `ss`, and `sha256sum`.
- Before every guarded operation, at least 512 MiB `MemAvailable`, 128 MiB
  `SwapFree`, and 1 GiB free disk. The guard takes three samples five seconds
  apart. Keep its existing worker RSS limit and resource floors; do not lower
  them to force a run.

The package is a reproducible runbook, not evidence of a completed host run.
Keep the package read-only. Put the temporary cluster, socket, logs, and output
evidence on internal host storage, never on an SD card or the production
cluster's filesystem.

## Isolated host workflow

Run one stage at a time. Replace the example run ID with a new value containing
only letters, digits, `_`, and `-`. These commands are written for Bash. Stop
on any failed command, package mismatch, version mismatch, count mismatch, or
resource-guard exit.

### 1. Verify inputs and prepare a private run directory

```sh
PKG="$(pwd)"
CHECKOUT="$HOME/src/biyahe-portable-stage"
RUN_ID="20261009T120000Z"
RUN_ROOT="$HOME/.cache/biyahe/batch033-isolated-portable-$RUN_ID"
mkdir -m 700 -p "$RUN_ROOT/socket"
chmod 700 "$RUN_ROOT" "$RUN_ROOT/socket"
test ! -e "$RUN_ROOT/data"
bash "$PKG/scripts/verify-portable-staging-package.sh" "$PKG" \
  --report "$RUN_ROOT/package-verification.json"
sha256sum "$PKG/assets/cebu-walk-central-visayas-20261007-v3.sqlite" \
  "$PKG/assets/central_visayas-20261007.osm.pbf" \
  "$PKG/assets/biyahe-batch024-20261008.dump"
php -v
php -m | grep -E '^(PDO|pdo_pgsql|pdo_sqlite|sqlite3)$'
pg_config --version
pg_config --sharedir
pg_config --pkglibdir
```

Compare those results with `manifest.json` and the hashes in its `artifacts`
object. Confirm `git -C "$CHECKOUT" rev-parse HEAD` matches the manifest core
commit, `pg_config --version` is PostgreSQL 18, and `pg_config --sharedir`/
`pg_config --pkglibdir` point to the intended installation containing the
PostGIS 3.6 extension files. Check `ss -ltn` and choose a currently unused
loopback port. The example below uses `56439`; if it is occupied, choose a new
port and record it in the run evidence.

Define a helper that applies the same sustained resource guard to each
long-running command. The cancellation PID path and logs must be new for each
call. The command is isolated in an owned process group; the guard does not
terminate unrelated processes.

```sh
PORT=56439
PGDATA="$RUN_ROOT/data"
SOCKET="$RUN_ROOT/socket"
PG_SUPERUSER="$(id -un)"
DB_NAME="biyahe_batch036_scratch"
DB_USER="biyahe_batch036_stage"
GUARD_SEQ=0
guard() {
  op="$1"; shift
  GUARD_SEQ=$((GUARD_SEQ + 1))
  BIYAHE_RESOURCE_MIN_MEMAVAILABLE_KB=524288 \
  BIYAHE_RESOURCE_MIN_SWAPFREE_KB=131072 \
  BIYAHE_RESOURCE_MIN_DISKFREE_KB=1048576 \
  BIYAHE_RESOURCE_PREFLIGHT_SAMPLES=3 \
  BIYAHE_RESOURCE_PREFLIGHT_INTERVAL_SECONDS=5 \
  bash "$CHECKOUT/api/scripts/run-bounded-process.sh" \
    --operation "$op" --disk-path "$RUN_ROOT" \
    --cancel-pid-file "$RUN_ROOT/$GUARD_SEQ.cancel.pid" \
    --cancel-match artisan --log "$RUN_ROOT/$GUARD_SEQ-$op.guard.log" -- "$@"
}
```

The database name must include `scratch` and must not include `prod` or
`production`. The importer also checks loopback host, port, database name,
cluster data directory, and PostgreSQL's reported `data_directory` at runtime.
Its required data path is exactly under
`$HOME/.cache/biyahe/batch033-isolated-*/data`.

### 2. Initialize only a new disposable PostgreSQL cluster

Reconfirm `$RUN_ROOT/data` does not exist and that the selected port is free.
The new cluster uses a private socket and loopback TCP only. `initdb` local
peer authentication is scoped to the user-owned mode-0700 directory; remote
and loopback TCP authentication use SCRAM. PostgreSQL settings are bounded.

```sh
guard initdb initdb --data-checksums --auth-local=peer \
  --auth-host=scram-sha-256 --username="$PG_SUPERUSER" --pgdata="$PGDATA"
```

Take a sustained three-sample resource gate immediately before starting the
server. Start the PostgreSQL daemon directly so it can outlive the bounded
worker session. The command still names only the new PGDATA and its private
socket. If startup fails, preserve its logs and stop; do not reuse another
cluster or repeatedly restart it.

```sh
guard postgres-start-preflight true
pg_ctl -D "$PGDATA" -l "$RUN_ROOT/postgresql.log" \
  -o "-h 127.0.0.1 -p $PORT -k $SOCKET -c max_connections=8 -c shared_buffers=64MB -c work_mem=4MB -c maintenance_work_mem=16MB -c max_parallel_workers=0 -c dynamic_shared_memory_type=mmap -c huge_pages=off -c jit=off" \
  -w start >"$RUN_ROOT/postgres-start.log" 2>&1
```

Create one temporary staging login with a random password. The role is a
superuser only inside this new disposable cluster because extension setup may
require it. Do not reuse that credential elsewhere. The here-document sends
the password to `psql` over standard input rather than putting it in command
arguments. Keep the shell session private and unset the password after use.

```sh
DB_PASSWORD="$(python3 -c 'import secrets; print(secrets.token_hex(32))')"
psql --host="$SOCKET" --port="$PORT" --username="$PG_SUPERUSER" --dbname=postgres <<SQL
CREATE ROLE "$DB_USER" LOGIN SUPERUSER PASSWORD '$DB_PASSWORD';
SQL
createdb --host="$SOCKET" --port="$PORT" --username="$PG_SUPERUSER" --owner="$DB_USER" "$DB_NAME"
```

Restore the verified Batch024 custom archive into this empty scratch database.
Use no owner/privilege restoration and do not use a clean option. Then verify
the transport baseline before running graph migrations or import:

```sh
export PGHOST=127.0.0.1 PGPORT="$PORT" PGUSER="$DB_USER" PGPASSWORD="$DB_PASSWORD"
guard restore pg_restore --exit-on-error --no-owner --no-privileges \
  --dbname="$DB_NAME" "$PKG/assets/biyahe-batch024-20261008.dump"
psql --dbname="$DB_NAME" --tuples-only --no-align -c \
  "SELECT (SELECT count(*) FROM stops), (SELECT count(*) FROM places), (SELECT count(*) FROM transfer_relationships);"
```

Require `503|37|2`. Verify the connected server with `SHOW server_version` and
`SELECT PostGIS_Full_Version()`. If any count or version differs, stop and
preserve the evidence. Do not restore into a database that is not empty.

### 3. Run only the pending ordinary migrations and isolated tests

Pass explicit connection values to each Laravel command so the checkout's
`.env` cannot select another database. Use an unused config-cache path under
the run directory. First inspect migration status; run ordinary pending
migrations only after reviewing their names. Never use a destructive reset or
refresh command.

```sh
export APP_ENV=staging APP_CONFIG_CACHE="$RUN_ROOT/empty-config-cache.php" DB_URL=
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT="$PORT" DB_DATABASE="$DB_NAME"
export DB_USERNAME="$DB_USER" DB_PASSWORD DB_SOCKET=
export BIYAHE_ISOLATED_PGDATA="$PGDATA"
cd "$CHECKOUT/api"
guard migration-status php artisan migrate:status
guard migrations php artisan migrate --force
```

Run the importer, route-parity, readiness, and process-guard fixtures only
with `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, and
empty `DB_URL`. They must not inherit the staging PostgreSQL target. The
importer fixture covers controlled rollback, interrupted checkpoint
continuation, idempotency, conflict rejection, and incomplete-data invisibility.

```sh
(
  export APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL=
  unset DB_HOST DB_PORT DB_USERNAME DB_PASSWORD BIYAHE_ISOLATED_PGDATA PGHOST PGPORT PGUSER PGPASSWORD
  guard importer-fixtures php artisan test --filter=OfflinePedestrianGraphImportTest
)
(
  export APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL=
  unset DB_HOST DB_PORT DB_USERNAME DB_PASSWORD BIYAHE_ISOLATED_PGDATA PGHOST PGPORT PGUSER PGPASSWORD
  guard routing-fixtures php artisan test --filter='(OfflinePedestrianGraphReadinessV36Test|OfflinePedestrianGraphRouterTest|OfflinePedestrianRouteParityAuditTest|BoundedProcessGuardTest)'
)
```

If a guard blocks, keep the logs and do not retry immediately. Fixture results
are not real PostgreSQL import or route-parity evidence.

Validate the packaged SQLite graph and its PBF hash once on the host before
creating the database. This reads the existing artifact and hashes the source;
it does not decode or rescan the PBF. The graph file is read-only, and the
complete restriction inventory in this snapshot avoids writing any sidecar
next to it.

```sh
guard offline-graph-validation php artisan biyahe:pedestrian:validate-offline \
  "$PKG/assets/cebu-walk-central-visayas-20261007-v3.sqlite" \
  --source-path="$PKG/assets/central_visayas-20261007.osm.pbf" \
  --source-sha256=787cac20ecc586b754b1a464643a040566d486457c6a37bc0b11ce683bc66d4d \
  >"$RUN_ROOT/offline-graph-validation.txt"
```

Require graph/source hashes, fingerprint, node/edge counts, restriction
inventory, `artifact_ready=yes`, and `routing_ready=yes` to match the package
manifest and Batch032 evidence. Stop on any mismatch.

### 4. Stage the real graph inactive and verify it twice

Ensure the graph and PBF files remain unchanged and read-only. The importer
rechecks both hashes and graph provenance. It streams ordered SQLite cursors
in bounded transactions, validates each committed prefix before resuming, and
does not expose an incomplete dataset to routing.

```sh
export BIYAHE_RESOURCE_PROGRESS_FILE="$RUN_ROOT/import-progress.log"
guard graph-import php artisan biyahe:pedestrian:stage-offline-graph \
  --graph="$PKG/assets/cebu-walk-central-visayas-20261007-v3.sqlite" \
  --graph-sha256=29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8 \
  --source="$PKG/assets/central_visayas-20261007.osm.pbf" \
  --source-sha256=787cac20ecc586b754b1a464643a040566d486457c6a37bc0b11ce683bc66d4d \
  --database="$DB_NAME" --port="$PORT" --pgdata="$PGDATA"
```

Require `76,685` nodes, `78,225` edges, `import_state=VALIDATED`, and
`is_active=false`. Query the dataset metadata and per-dataset row counts. Its
`staging_integrity` fields must state exact node, edge, direction, geometry,
and graph-fingerprint matches. Run the identical import once more using a new
progress file; it must validate and reuse the same inactive dataset with no
duplicate rows. A mismatching artifact must be rejected, never merged.

Use this read-only query to identify the exact staged dataset and verify the
row totals. Require one row, `false`, `VALIDATED`, `true`, and
`76685|78225|true|true|true` in the corresponding result columns. Save the
returned dataset ID for the parity command:

```sh
psql --dbname="$DB_NAME" --tuples-only --no-align -c \
  "SELECT d.id,d.is_active,d.node_count,d.edge_count,d.metadata->>'import_state',d.metadata->>'routing_ready',(SELECT count(*) FROM pedestrian_network_nodes n WHERE n.dataset_id=d.id),(SELECT count(*) FROM pedestrian_network_edges e WHERE e.dataset_id=d.id),d.metadata->'staging_integrity'->>'nodes_match',d.metadata->'staging_integrity'->>'edges_match',d.metadata->'staging_integrity'->>'directionality_match',d.metadata->'staging_integrity'->>'geometry_match',d.metadata->'staging_integrity'->>'graph_fingerprint' FROM pedestrian_network_datasets d WHERE d.metadata->>'graph_sha256'='29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8';"
```

```sh
export BIYAHE_RESOURCE_PROGRESS_FILE="$RUN_ROOT/import-repeat-progress.log"
guard graph-import-repeat php artisan biyahe:pedestrian:stage-offline-graph \
  --graph="$PKG/assets/cebu-walk-central-visayas-20261007-v3.sqlite" \
  --graph-sha256=29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8 \
  --source="$PKG/assets/central_visayas-20261007.osm.pbf" \
  --source-sha256=787cac20ecc586b754b1a464643a040566d486457c6a37bc0b11ce683bc66d4d \
  --database="$DB_NAME" --port="$PORT" --pgdata="$PGDATA"
```

### 5. Compare the ten real Cebu routes

Find the single inactive dataset ID by its exact graph SHA-256 and require its
metadata to be `VALIDATED`, `routing_ready=true`, and `activation=NOT_ACTIVATED`.
Run the staged audit with the included Batch032 corpus. The command verifies
the graph/PBF identities and limits the snap policy to 25 m.

```sh
guard route-parity php artisan biyahe:pedestrian:audit-staged-route-parity \
  --corpus="$PKG/evidence/batch032-cebu-coverage.json" \
  --dataset-id=DATASET_ID \
  --graph-sha256=29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8 \
  --source-sha256=787cac20ecc586b754b1a464643a040566d486457c6a37bc0b11ce683bc66d4d \
  --pgdata="$PGDATA" --port="$PORT" --output="$RUN_ROOT/route-parity.json"
```

Require all ten cases to match. Preserve per-case results, reason codes,
snapped endpoints, component IDs, path distances, geometries, and corpus hash.
The offline corpus baseline is nine routes found and one disconnected route;
this does not verify transfer safety. Run the static Doctor against saved
coverage, Batch035 Doctor, package-verification, and validation evidence. The
validation JSON must state actual lint and fixture outcomes; do not infer PASS
from package verification. Do not mark private beta ready from package
verification or fixture success.

The `validation` input is a JSON object with `php_lint`, `bash_syntax`, and
`fixture_tests` values of `PASS`, `BLOCKED`, or `FAIL`, plus
`importer_code_present: true`. Generate it from captured command outcomes. If a
command was not run or was stopped by its guard, record `BLOCKED`.

```sh
guard doctor-v36 php artisan biyahe:doctor-v36 \
  --coverage="$PKG/evidence/batch032-cebu-coverage.json" \
  --doctor-v35="$PKG/evidence/batch035-doctor-v35.json" \
  --package-manifest="$PKG/manifest.json" \
  --package-verification="$RUN_ROOT/package-verification.json" \
  --validation="$RUN_ROOT/batch036-validation.json" \
  --output="$RUN_ROOT/doctor-v36.json"
```

### 6. Record evidence and stop safely

Record host/tool versions, package and artifact hashes, restored transport
counts, migration status, graph counts, staging metadata, repeat-import
result, route-parity report, resource logs, and `production_activation=NO` in
a new evidence directory. Do not place credentials in that report.

Stop the disposable cluster cleanly and retain it until the evidence has been
reviewed:

```sh
pg_ctl -D "$PGDATA" -m fast -w stop >"$RUN_ROOT/postgres-stop.log" 2>&1
unset DB_PASSWORD PGPASSWORD
```

Only after confirming the exact `$RUN_ROOT` belongs to this run, PostgreSQL
has stopped, the evidence is copied, and no process uses its files may that
one disposable directory be removed. Never point cleanup commands at a
wildcard, the package, an existing application cluster, or the SD card.

## Release boundaries

Static package verification may PASS while real PostgreSQL staging and route
parity remain BLOCKED. Keep static and host-execution evidence separate. The
graph's 25 m snap results do not prove access, boarding points, sidewalks,
crossing legality, accessibility, or current conditions. The two stored
transfer locations remain UNVERIFIED. Private beta and production activation
remain blocked until their independent evidence gates pass.
