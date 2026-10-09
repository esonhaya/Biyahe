# Offline Cebu pedestrian graph staging and recovery

This workflow builds and validates the OSM graph in SQLite. It does not need
PostgreSQL, and preparing an artifact does not activate it. A published file is
created only after both full-source streams exit cleanly, checkpoint fingerprints
match their rows, and the independent validator accepts the SQLite database.

## Pipeline and restart boundaries

The source identity is the local PBF SHA-256, source URL and filename, declared
OSM snapshot, Osmium version, and configured Cebu bounds. The current pinned
Central Visayas source is
`https://download.openstreetmap.fr/extracts/asia/philippines/central_visayas.osm.pbf`,
snapshot `2026-10-07T01:00:21Z`, SHA-256
`787cac20ecc586b754b1a464643a040566d486457c6a37bc0b11ce683bc66d4d`.
The filename in the URL must be `central_visayas.osm.pbf`; verify the exact
endpoint spelling and local hash before starting.

The pipeline uses three durable graph phases:

1. **Way index.** A complete XML stream counts source nodes, ways, and relations;
   stores only in-scope node coordinates; and writes pedestrian-way references
   to SQLite. After the whole stream closes, a disk-backed join selects ways
   with at least one adjacent in-scope pair. This delayed selection handles
   ways that appear before their referenced nodes. It retains every reference
   for each selected way and writes the unique required-node ID set. Relevant
   restriction totals are recorded here.
2. **Reference resolution.** A second complete source stream resolves only the
   required IDs by probing the SQLite ID index. It does not keep Osmium's
   requested-ID set in memory. It also records each pedestrian-relevant turn
   restriction and its ordered, typed relation members in SQLite. Referenced
   coordinates outside the coverage bounds are retained for topology checks,
   while edges are emitted only for in-scope endpoints.
3. **Graph build and validation.** A deterministic transaction creates ordered
   segments, direction flags, WGS84 geometry, weakly connected-component
   counts, and a content fingerprint. The independent validator then checks
   source identity, phase fingerprints, SQLite integrity, references, access,
   direction, geometry, components, and routing limitations before an atomic
   no-clobber publication.

Each completed source phase has a SQLite checkpoint containing the source
SHA-256, snapshot, schema version, phase identity, completion marker, record
counts, and a deterministic row fingerprint. A checkpoint is reused only after
its provenance, row counts, and fingerprint verify. A closed XML root is not
enough: the wrapper must also observe a zero Osmium producer exit. A killed or
truncated stream is never marked complete. PBF byte offsets are not resume
points.

An interrupted way-index stream has no completed phase to resume; start it with
a **new output path**, preserving the old `.building` file for diagnosis. Once
the way-index phase is producer-verified, `--resume` reruns an interrupted
reference pass from that checkpoint. Partial resolved IDs and restriction rows
are cleared before replay. Once reference resolution is complete, a resume
skips both source streams and deterministically rebuilds any interrupted graph
phase. A completed `.building` file can be validated and published by resume;
the final path is still absent until validation passes.

The stream parser reads one OSM element at a time. Way references are staged in
SQLite rather than collected across the source in PHP arrays. Relation members
are spooled through a temporary disk-backed SQLite table, with a per-relation
limit of 100,000. SQLite uses an 8 MiB page cache, file-backed temporary data,
full synchronous writes, delete journaling, and bounded commits. The extra
pending-way and relation-spool writes trade disk space and I/O for bounded RAM.
There is no safe automatic threshold relaxation: default resource floors are
393,216 KiB `MemAvailable`, 1,048,576 KiB free disk, and 262,144 KiB process-tree
RSS. Swap, `Cached`, and `SReclaimable` are recorded as context, not attributed
to Osmium by default.

## Pedestrian coverage and known limits

The selector accepts configured walkable highway classes and supported
`access`, `foot`, `foot:forward`, and `foot:backward` values. `oneway:foot`
supports `yes`, `no`, `1`, `0`, `true`, `false`, `-1`, and `reverse`; ordinary
`oneway` alone does not impose a walking direction. Unsupported access values,
private service roads without explicit foot access, and ways with conditional
access tags (`foot:conditional`, `access:conditional`, directional variants, or
`oneway:foot:conditional`) are excluded wholesale. Conditional schedules are
not evaluated. Other advanced access semantics remain unsupported.

`sidewalk`, `footway`, `crossing`, bridge, tunnel, surface, and related tags are
retained as properties where present; sidewalk tags do not create separate
geometry. Steps are represented as ordinary graph segments without elevation
or accessibility costs. A bridge or tunnel does not create a connection by
geometric overlap. Two crossing lines with different OSM node IDs remain
disconnected, and the pipeline never invents a connector. A way crossing the
coverage boundary retains its outside references, but only consecutive
in-scope, walkable node pairs become edges; no replacement edge is drawn at the
boundary.

Turn-restriction relations are classified by mode before they affect walking
readiness. OSM's generic `type=restriction` turn rules do not apply to
pedestrians unless the relation uses the foot-specific restriction form;
`except=foot` is also recorded as an explicit exemption. A generic vehicle
conditional remains vehicle-only. Foot/pedestrian conditionals, unknown mode
keys, or conflicting mode/exemption tags block routing because schedules and
turn transitions are not evaluated. Via-node, via-way, nested, and malformed
member shapes are reported independently from mode applicability.

New source passes retain every relation with a restriction tag and checkpoint
the inventory-policy version. The Batch030 v3 artifact predates that complete
capture policy, so validation requires its source-scan audit sidecar before it
can be considered routing-ready. Its source-bound report is in
`docs/reports/batch031-cebu-restriction-audit.json`; the sidecar travels beside
the SQLite graph. The v3 artifact itself is unchanged. If a future source has
an applicable or ambiguous pedestrian restriction, the importer rejects it;
the node-based router does not claim to enforce relation transitions. Weak
component counts still do not prove that every directed journey is routable.

## Termux setup and source verification

Install or provide PHP with XMLReader and PDO SQLite, Osmium Tool, Bash, and the
Laravel dependencies already used by the repository. Do not replace shared
libraries. The Termux runtime wrapper drops the inherited Codex
`LD_LIBRARY_PATH` and uses `$PREFIX/lib` for Osmium. Check the source hash and
runtime before the bounded command:

```sh
cd /data/data/com.termux/files/home/Biyahe/api
PBF="$HOME/.cache/biyahe/source/central_visayas.osm.pbf"
OUT_DIR="$HOME/.cache/biyahe/cebu-graph-20261007"
EXPECTED_SHA256=787cac20ecc586b754b1a464643a040566d486457c6a37bc0b11ce683bc66d4d
SOURCE_URL=https://download.openstreetmap.fr/extracts/asia/philippines/central_visayas.osm.pbf
SNAPSHOT=2026-10-07T01:00:21Z

mkdir -p "$OUT_DIR"
printf '%s  %s\n' "$EXPECTED_SHA256" "$PBF" | sha256sum -c -
source scripts/osmium-runtime.sh
run_osmium --version
OSMIUM_VERSION="$(run_osmium --version | sed -nE 's/.*osmium version ([0-9]+\.[0-9]+(\.[0-9]+)?).*/\1/p' | head -n 1)"
```

The pipeline wrapper verifies the SHA-256 again before each run. It does not
download or upload PBFs. Keep the source, output, progress files, and resource
logs outside Git. Check free disk and `MemAvailable` before starting; do not run
PostgreSQL or another source-processing job at the same time.

## Start, monitor, inspect, and resume

Use a new final path for a new build. The bounded runner samples process-tree
RSS, memory availability, cached/reclaimable pages, swap, disk, SQLite/journal
growth, and parser progress. It terminates the worker after repeated resource
budget breaches and preserves the `.building` checkpoint and logs.

```sh
OUTPUT="$OUT_DIR/cebu-walk.sqlite"
RESOURCE_LOG="$OUT_DIR/build.log"
PID_FILE="$OUT_DIR/producer.pid"
BIYAHE_RESOURCE_WATCH_FILE="$OUTPUT.building" \
BIYAHE_RESOURCE_PROGRESS_FILE="$OUTPUT.progress" \
  scripts/run-bounded-process.sh \
    --operation offline-graph-stage \
    --disk-path "$OUT_DIR" \
    --cancel-pid-file "$PID_FILE" \
    --cancel-match osmium \
    --log "$RESOURCE_LOG" \
    -- scripts/prepare-offline-pedestrian-graph.sh \
      "$PBF" "$OUTPUT" "$SOURCE_URL" "$EXPECTED_SHA256" \
      "$SNAPSHOT" "$OSMIUM_VERSION" "$PID_FILE"
```

After a stop, preserve the files and inspect the checkpoint read-only:

```sh
php artisan biyahe:doctor --static \
  --offline-checkpoint="$OUTPUT.building" \
  --offline-resource-log="$RESOURCE_LOG" \
  --source-path="$PBF"
```

Static Doctor does not connect to PostgreSQL. Its overall exit status can remain
`BLOCKED` for live database checks; read the individual offline source, phase,
checksum, graph, and resource rows. A v2 checkpoint can be migrated only after
the source identity and integrity check pass. If old reference rows lack the
new restriction dependencies, migration preserves the completed way-index
phase and replays reference resolution.

If Doctor reports a reusable `WAY_INDEX_COMPLETE`, partial reference, or
`SOURCE_COMPLETE` state, use the same source identity, output path, and a new
PID/log path with `--resume`:

```sh
RESUME_LOG="$OUT_DIR/resume.log"
RESUME_PID="$OUT_DIR/resume-producer.pid"
BIYAHE_RESOURCE_WATCH_FILE="$OUTPUT.building" \
BIYAHE_RESOURCE_PROGRESS_FILE="$OUTPUT.progress" \
  scripts/run-bounded-process.sh \
    --operation offline-graph-resume \
    --disk-path "$OUT_DIR" \
    --cancel-pid-file "$RESUME_PID" \
    --cancel-match osmium \
    --log "$RESUME_LOG" \
    -- scripts/prepare-offline-pedestrian-graph.sh \
      "$PBF" "$OUTPUT" "$SOURCE_URL" "$EXPECTED_SHA256" \
      "$SNAPSHOT" "$OSMIUM_VERSION" "$RESUME_PID" --resume
```

To stop after both verified source passes and review before graph generation,
set `BIYAHE_OSM_STOP_AFTER_SOURCE=1` on the first command. It leaves a
`SOURCE_COMPLETE` `.building` file. Unset the variable and resume to build,
validate, and publish. Never resume a `SOURCE_PASS_PENDING_PRODUCER` phase:
the earlier producer's exit result is unknown, so restart that source phase to
a new output path.

## Validate and transfer an artifact

An artifact is valid only when the independent command reports
`artifact_ready=yes`. `WARN` means structural checks passed but restriction
inventory or semantics still block routing import.

For the legacy Batch030 v3 file, create its artifact-adjacent sidecar once if
it is missing, using the committed source-scan evidence. The output path must
be new; keep the committed report as the reviewed evidence and write any
regenerated report under the cache:

```sh
php artisan biyahe:pedestrian:audit-restrictions \
  --graph="$HOME/.cache/biyahe/batch030/cebu-walk-central-visayas-20261007-v3.sqlite" \
  --source-path="$PBF" \
  --source-scan-evidence=../docs/reports/batch031-cebu-restriction-source-scan.json \
  --output="$HOME/.cache/biyahe/batch030/cebu-restriction-audit-generated.json" \
  --sidecar="$HOME/.cache/biyahe/batch030/cebu-walk-central-visayas-20261007-v3.sqlite.restriction-audit.json"
php artisan biyahe:pedestrian:validate-offline \
  "$HOME/.cache/biyahe/batch030/cebu-walk-central-visayas-20261007-v3.sqlite" \
  --source-path="$PBF"
sha256sum "$HOME/.cache/biyahe/batch030/cebu-walk-central-visayas-20261007-v3.sqlite"
```

The SQLite file uses the v3 schema, ordinary SQLite tables, WGS84 numeric
coordinates, and WKT strings; it requires no PostGIS or custom SQLite
extension. Transfer only a completed artifact after validation. Transfer the
source PBF separately if the destination must independently recheck the source
hash. On the destination, verify the transferred artifact SHA-256, run SQLite
`PRAGMA integrity_check`, and run the validator against the matching local PBF.
The graph fingerprint, source SHA-256, snapshot, schema version, and counts
travel inside the artifact metadata. Do not use a different snapshot to satisfy
the source check.

## Laptop or temporary-host fallback

The same source-backed pipeline runs on another Linux host; no database or paid
hosting is needed to build the artifact. Check out the exact repository commit,
install its existing PHP dependencies and SQLite/XML extensions, install
Osmium, and obtain the PBF from the documented source endpoint yourself. Verify
the canonical SHA-256 above. Run `scripts/osmium-runtime.sh` on Termux; on a
normal host it invokes the installed Osmium binary directly unless `PREFIX`
identifies a package runtime. Use the same bounded runner, source URL, snapshot,
version, output identity, and validation commands. Do not lower resource floors
to force a run. Transfer the completed SQLite file back to the phone or a later
isolated staging host only after its checksum and independent validation pass.

The portable artifact does not contain API credentials or a database dump. The
source binary stays local unless its owner transfers it manually.

## Optional isolated database staging

Database staging is a later, separate operation. Use a newly initialized,
disposable PostgreSQL cluster under an explicit Batch033 cache directory, on a
loopback-only port and socket. Keep `PGDATA` off the SD card. The staging
command checks the server's live `data_directory`, loopback host, port, and
database name before importing. The generic `import-offline` command also
requires `BIYAHE_ISOLATED_PGDATA` to match a path of the form
`~/.cache/biyahe/batch033-isolated-*/data`; this prevents a staging-named
database on an existing cluster from passing the importer boundary.

The importer verifies the graph SHA-256, source PBF checksums, graph
fingerprint, and restriction capability before writes. It streams bounded
SQLite cursors with 100-node and 40-edge batches by default. Each batch and its
last source key, committed counts, batch counters, and progress metadata commit
together. The checkpoint binds the graph/source hashes, graph fingerprint,
graph schema, importer schema, expected counts, deterministic ordering, and
last imported node or way/segment key. Dataset states include `PENDING`,
`IMPORTING`, `INTERRUPTED`, `FAILED`, `STAGED_INACTIVE`, and `VALIDATED`; every
state remains inactive. The ordinary router reads active datasets only, and
the staging diagnostic router accepts only a hash-matched `VALIDATED` dataset
with complete source-to-database node, edge, direction, property, and geometry
checks.

After interruption, the same verified source and graph can resume from the
last committed node or edge key only after the importer compares every staged
row in that committed prefix with the SQLite source in bounded pages. It also
checks row counts, phase, batch counts, last keys, and checkpoint identity.
Inconsistent checkpoints are rejected without deleting their rows. Legacy
partial datasets without these prefix proofs are not resumed automatically;
after inspecting their inactive state, an operator must explicitly pass
`--restart-incomplete` to discard only that matching dataset's node/edge rows
and replay from the source beginning. A completed identical artifact is
revalidated and reused; conflicting identities or unrecognized states are
rejected. Per-batch failures roll back the current transaction and leave the
dataset inactive. Transport and field-observation rows are not modified.

```sh
RUN_ID="$(date -u +%Y%m%d%H%M%S)"
STAGE_ROOT="$HOME/.cache/biyahe/batch033-isolated-$RUN_ID"
BIYAHE_ISOLATED_PGDATA="$STAGE_ROOT/data"
DB_PORT=56433
test ! -e "$STAGE_ROOT" || { echo "Choose a new RUN_ID" >&2; exit 1; }
if pg_isready -h 127.0.0.1 -p "$DB_PORT" -d postgres >/dev/null 2>&1; then
  echo "Choose a loopback port with no PostgreSQL listener" >&2
  exit 1
fi
mkdir -p "$STAGE_ROOT/socket"
initdb -D "$BIYAHE_ISOLATED_PGDATA" --username="$(id -un)" --locale=C --encoding=UTF8 --auth-local=trust --auth-host=trust
cat >> "$BIYAHE_ISOLATED_PGDATA/postgresql.auto.conf" <<EOF
listen_addresses='127.0.0.1'
port=$DB_PORT
unix_socket_directories='$STAGE_ROOT/socket'
max_connections=8
shared_buffers='16MB'
work_mem='1MB'
maintenance_work_mem='8MB'
autovacuum_work_mem='8MB'
max_parallel_workers=0
max_worker_processes=2
dynamic_shared_memory_type='mmap'
huge_pages=off
jit=off
EOF
pg_ctl -D "$BIYAHE_ISOLATED_PGDATA" -l "$STAGE_ROOT/server.log" -w start
pg_isready -h 127.0.0.1 -p "$DB_PORT"

export BIYAHE_ISOLATED_PGDATA
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT
export DB_DATABASE=biyahe_batch033_staging DB_USERNAME="$(id -un)" DB_PASSWORD=
export DB_URL=
createdb -h 127.0.0.1 -p "$DB_PORT" -U "$DB_USERNAME" "$DB_DATABASE"
pg_restore --exit-on-error --no-owner --no-privileges \
  -h 127.0.0.1 -p "$DB_PORT" -U "$DB_USERNAME" -d "$DB_DATABASE" "$BACKUP"
psql -h 127.0.0.1 -p "$DB_PORT" -U "$DB_USERNAME" -d "$DB_DATABASE" \
  -c 'SELECT (SELECT count(*) FROM stops) AS stops, (SELECT count(*) FROM places) AS places, (SELECT count(*) FROM transfer_relationships) AS transfers;'
php artisan migrate --force

export BIYAHE_RESOURCE_PROGRESS_FILE="$HOME/.cache/biyahe/batch033-import-progress.log"
export BIYAHE_RESOURCE_MIN_MEMAVAILABLE_KB=524288
export BIYAHE_RESOURCE_MIN_SWAPFREE_KB=131072
export BIYAHE_RESOURCE_BREACH_SAMPLES=1

php artisan biyahe:pedestrian:stage-offline-graph \
  --graph="$OUTPUT" --graph-sha256="$GRAPH_SHA256" \
  --source="$PBF" --source-sha256="$SOURCE_SHA256" \
  --database="$DB_DATABASE" --port="$DB_PORT" \
  --pgdata="$BIYAHE_ISOLATED_PGDATA"
pg_ctl -D "$BIYAHE_ISOLATED_PGDATA" -m fast -w stop
```

Run the command through `scripts/run-bounded-process.sh` with a new log and
worker cancellation marker. The wrapper requires three resource samples,
spaced five seconds apart by default, before it launches the command. Each
sample records MemAvailable, SwapFree, disk space, visible process RSS and
VmSwap, PID ownership, process group/session, and PostgreSQL RSS. Any sample
below a configured floor blocks launch; do not lower a floor to force staging.
Set `BIYAHE_RESOURCE_PREFLIGHT_SAMPLES` and
`BIYAHE_RESOURCE_PREFLIGHT_INTERVAL_SECONDS` only when changing the sampling
window is justified and documented.

The wrapper starts the worker behind a private-session handshake and verifies
that its process group differs from the parent shell before releasing it. On a
resource breach it signals only that owned group with SIGTERM, waits the
configured grace period, and uses SIGKILL only for remaining members of the
same verified group. The parent shell and sibling processes stay outside that
group. The guard log records the worker's exit status and signal; the complete
worker output remains beside it as `<log>.worker.log`. The monitor also samples
PHP's process-tree RSS and aggregate PostgreSQL RSS during work; progress and
filesystem free-space changes are recorded. Measure the isolated cluster's
`du -sk` before and after the import for database-directory growth. If the guard
blocks before launch, do not lower its floor to force staging. Do not start
PostgreSQL on a resource-unstable device just to exercise import.

After a `VALIDATED` result, run
`biyahe:pedestrian:audit-staged-route-parity` against the existing Batch032
journey corpus. It selects an inactive dataset by ID, rechecks the graph and
source identities and isolated `data_directory`, then compares status, snap
node IDs, reason families, network and total distance, geometry, and estimate
labeling. Parity is unverified until this command completes against a real
staging import.

`biyahe:doctor-v33` consumes static Batch032 and Batch033 evidence only; it
does not connect to PostgreSQL. Missing real import, rollback, idempotency,
recovery, parity, or field evidence remains `BLOCKED`.

Production activation is not part of artifact preparation or this staging
command. It requires a separately reviewed source validation, restored and
verified backup, isolated staging audit, routing review (including restrictions
and connected components), release approval, monitoring, and a tested rollback
plan. No database credentials belong in this guide, source tree, or artifact.
