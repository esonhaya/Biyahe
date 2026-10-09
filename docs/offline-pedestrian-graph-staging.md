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

Database staging is a later, separate operation. Configure the application to
use a disposable PostgreSQL staging/test database and pass its exact configured
database name. The importer verifies the artifact and source again, streams
bounded inserts in one transaction, stages the dataset inactive, and does not
modify transport or field-observation records. A failed batch rolls back. An
idempotent retry reuses the staged dataset without activating it.

```sh
php artisan biyahe:pedestrian:import-offline "$OUTPUT" \
  --source-path="$PBF" \
  --staging-database="<exact-isolated-database-name>"
```

Do not start PostgreSQL on a resource-unstable device just to exercise import.
Production activation is not part of artifact preparation or this staging
command. It requires a separately reviewed source validation, restored and
verified backup, isolated staging audit, routing review (including restrictions
and connected components), release approval, monitoring, and a tested rollback
plan. No database credentials belong in this guide, source tree, or artifact.
