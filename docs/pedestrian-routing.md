# Pedestrian routing

## Boundary and behavior

Journey planning calls the Biyahe-owned `PedestrianRouter` interface. The
initial provider is a bounded local OSM graph in PostGIS; the routing provider
can be replaced without binding journey code to a commercial API. No paid
service, routing server, Redis, or tile-server routing request is required.

PostGIS proximity queries discover candidate stops. For each small candidate
set, the local provider snaps to the nearest walkable OSM node within 25 m and
runs a bounded, direction-aware Dijkstra search. If no node is within that
limit, it may use one bounded (10 m maximum) virtual anchor on a nearby edge
whose OSM semantics explicitly support pedestrian access. Primary/secondary
edges need an explicit sidewalk or foot-access tag; unqualified service roads
are not anchors. The anchor connector is checked against mapped barriers and
is never a fabricated graph edge. It has configurable edge,
visited-node, distance, and per-request cache limits. Each HTTP/planning scope
memoizes identical requests; no persistent route cache is used. Access and
egress are bounded to 2,000 m, explicit transfers to 600 m, and initial
candidate discovery remains bounded by the journey planner's existing limits.
Planner metrics distinguish candidate pairs, uncached route attempts, paths
found, request-cache hits, and routing time.

The imported graph includes selected roads, paths, footways, pedestrian
streets, steps, and service roads. Explicit `access`/`foot` prohibitions,
conditional access restrictions, mapped blocking barrier nodes, motorways,
unsupported access values, and unsupported road classes are excluded.
Ambiguous private driveways and explicitly private/emergency service ways
require an explicit affirmative `foot` tag to be included.
`oneway:foot` and `foot:forward` /
`foot:backward` restrictions are represented. An OSM graph path is not a
guarantee of a sidewalk, safe crossing, open gate, accessibility, or current
ground conditions; those facts are not inferred. OSM highway geometry may be
the roadway centerline rather than a mapped footway/sidewalk. Only tagged
barrier nodes in the extract are available to block an approximate graph
connector; unmapped barriers remain a coverage limitation.

OSM crossing tags are retained on their referenced graph nodes, and explicit
`footway=crossing`/crossing way attributes are retained as edge metadata. A
crossing tag or geometric line intersection does not create a connection by
itself: graph connectivity requires an explicitly shared OSM node/way
reference. No legal or safe crossing is inferred from intersecting geometry.

Successful legs expose separate `LineString` geometry and `PEDESTRIAN_NETWORK`
distance. When a point is connected by a short endpoint or edge-anchor snap,
the leg reports `PEDESTRIAN_NETWORK_WITH_APPROXIMATE_CONNECTORS` and warns
that the straight connector is not part of the displayed network geometry.
Walking duration is only an estimate: network distance divided by
`BIYAHE_WALKING_SPEED_METERS_PER_SECOND` (default 1.2 m/s), not a traffic- or
condition-aware ETA.

Without a graph, outside its extent, or when routing is unavailable, geometry
and duration are absent. A straight-line fallback is explicitly tagged
`STRAIGHT_LINE_ESTIMATE`; it is not a walking route. The API also exposes an
access classification: `NETWORK_ROUTE`, `GRAPH_COVERAGE_GAP`,
`NO_WALKABLE_ACCESS`, or `ROUTING_UNAVAILABLE`. `NO_ROUTE` means no path was
found in the extracted graph and does not prove that no legal path exists,
because map coverage can be incomplete. A route longer than the configured
walk bound is not offered as feasible. These statuses never alter transit
verification, boarding precision, or transfer evidence.

## Data provenance and rebuild

OpenStreetMap data is available under the ODbL and requires attribution to
OpenStreetMap contributors; see the [OpenStreetMap France extract
catalogue](https://download.openstreetmap.fr/extracts/asia/philippines/) and the
[OpenStreetMap copyright page](https://www.openstreetmap.org/copyright).
Pedestrian data has separate geographic provenance; it is never transport
evidence. It cannot verify a jeepney route, stop, boarding practice, transfer,
fare, or signboard.

The pinned Cebu extent is `10.282, 123.860` to `10.381, 123.935` (south, west,
north, east). A review of the current production dataset found all 48 mapped
stops and all 22 mapped places with coordinates inside these bounds. The
extent covers Cebu City's current mapped transport area plus a modest access
margin; it does not include Cebu Province. Do not expand it or import extra
regions without a transport-coverage review.

### Rebuild or update

Use the date-stamped Central Visayas regional PBF from OpenStreetMap France, not
a consumer map service or a public tile server. The updater verifies the
source MD5 sidecar, captures `Last-Modified`, and records the exact URL, source
filename, checksum, snapshot, ODbL license, attribution, extraction bounds,
and Osmium version. The source was published as
`central_visayas.osm.pbf` with `Last-Modified: 2026-10-05 00:57:57 UTC` and
MD5 `a3d5d3fb3666005f018b078bd3a7981d`. `osmium extract
--strategy=complete_ways` preserves all nodes needed by ways crossing the
extract boundary. The preceding `tags-filter` reduces memory use while
retaining highway ways and tagged barriers/crossings; Biyahe's allow-list then
excludes unsupported road types and ambiguous/restricted pedestrian access.
See the [Osmium Tool manual](https://osmcode.org/osmium-tool/manual.html)
for extraction and filtering semantics.

The MD5 and `Last-Modified` values above describe the historical Batch 010
source snapshot; that exact PBF is not present in the current local recovery
cache. Batch 023 found a different, locally cached source snapshot at
`api/.runtime/recovery-osm-verified-20261007/central_visayas.osm.pbf`:
28,757,299 bytes, MD5
`6531fa7af52b5565f13bfa68acff7a37`, SHA-256
`787cac20ecc586b754b1a464643a040566d486457c6a37bc0b11ce683bc66d4d`.
Its URL is the same Central Visayas OSM France endpoint; its PBF replication
timestamp is `2026-10-07T01:00:21Z` (sequence `7318108`), with latest object
timestamp `2026-10-07T00:02:08Z`. Its regional bounds are
`120.5055069,6.221537,126.678755,14.6246966`; the Cebu extraction bounds below
are `123.860,10.282,123.935,10.381`. The extract is ODbL-1.0, © OpenStreetMap
contributors. This is a newer source snapshot, not the historical graph or
its exact input. It would create a new graph identity. No bounded extraction
or graph import from it has completed; the regional filter was stopped when
available memory fell below the safety floor.

### Disk-backed streaming preparation

For low-memory preparation, `biyahe:pedestrian:prepare-osm-stream` accepts
OSM XML on stdin, indexes node coordinates in a temporary SQLite database
with a 16 MiB SQLite page cache, and keeps only in-scope node IDs in PHP
memory. It emits ways only when they contain an in-scope adjacent node pair,
while retaining every reference in each emitted way. Output publication is
atomic, capped by the existing 20 MB / 100,000 node / 25,000 way limits, and
the source PBF's MD5 and SHA-256 are computed and checked before use. Temporary
SQLite and JSON files are created beside the requested output, removed after a
clean run, and never used as activated graph data. An interrupted process can
leave hidden scratch files; inspect them and confirm no preparation process is
running before removing them.

First validate a small Carbon-area output. `--bounds` marks it `test_only`, and
the graph importer rejects it, so it cannot be activated accidentally:

```sh
cd api
SOURCE="$PWD/.runtime/recovery-osm-verified-20261007/central_visayas.osm.pbf"
OUTPUT="$PWD/.runtime/b024-carbon-test.json"
set -o pipefail
LD_LIBRARY_PATH="$PREFIX/lib${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}" \
  osmium cat --no-progress -f osm -o - "$SOURCE" |
php -d memory_limit=256M artisan biyahe:pedestrian:prepare-osm-stream - \
  --output="$OUTPUT" --source-path="$SOURCE" \
  --source-url=https://download.openstreetmap.fr/extracts/asia/philippines/central_visayas.osm.pbf \
  --source-md5=6531fa7af52b5565f13bfa68acff7a37 \
  --source-sha256=787cac20ecc586b754b1a464643a040566d486457c6a37bc0b11ce683bc66d4d \
  --source-timestamp=2026-10-07T01:00:21Z --osmium-version=1.19.1 \
  --bounds=10.2900,123.8979,10.2940,123.9019
```

This command still reads the regional PBF once; it avoids Osmium's extract
ID tables and spills the source node index to disk. Do not use `--buffer-data`
or the prior `tags-filter` setup. Batch 024 has tested the selector and graph
import semantics on a synthetic fixture, but has not run this pipeline over
the PBF, measured its peak RSS, or imported a real graph. The downstream JSON
importer still has bounded in-memory arrays; a full import requires healthy
PostgreSQL, a restored staging database, and a separate resource gate. Remove
stale hidden scratch files only after confirming no stream preparation is
running.

Prerequisites: `curl`, `md5sum`, Osmium Tool, PHP/Laravel dependencies, and an
application database configured for PostgreSQL with PostGIS. The recovered
Batch 010 uses the OpenStreetMap France source at
`https://download.openstreetmap.fr/extracts/asia/philippines/central_visayas.osm.pbf`.
Its data is licensed under ODbL-1.0. The updater checks the file against the
source MD5 sidecar and records the source timestamp and checksum in the
imported dataset. Future refreshes should preserve the exact URL, timestamp,
and checksum used for the installed dataset.

```sh
cd api
BIYAHE_DB_CONNECTION=pgsql \
BIYAHE_DB_HOST=127.0.0.1 BIYAHE_DB_PORT=5432 \
BIYAHE_DB_DATABASE=biyahe_batch023_staging BIYAHE_DB_USERNAME="$(id -un)" \
BIYAHE_PHP_MEMORY_LIMIT=512M \
BIYAHE_PEDESTRIAN_DATA_DIR="$PWD/.runtime/b023-central-visayas-staging" \
  bash scripts/update-cebu-pedestrian-graph.sh 20261007
```

The updater runs migrations and activates the imported graph, so run it only
against a disposable staging database restored from a verified current-state
backup. It requires an explicit `BIYAHE_DB_DATABASE` containing a test,
staging, or batch marker, clears any inherited `DB_URL`, and refuses the
production-named `biyahe` database and production-named targets. Review and
back up the live database separately before any scoped production activation.

The script downloads/resumes the configured Central Visayas PBF, verifies its
MD5, filters the regional graph and clips the bounded graph with Osmium,
applies pending migrations,
prepares bounded import JSON, then runs the transactional import, status
check, and Doctor. It retains
rebuild artifacts in the configured cache directory, outside Git; no raw PBF
or large extract is committed. To reproduce an exact installed dataset, use
its recorded source URL and checksum. A refreshed snapshot gets a new stable
dataset ID and metadata. A failed validation/import leaves the prior active
dataset in service. `php artisan biyahe:pedestrian:status --json` exposes the
active snapshot, bounds, counts, provenance, graph validation, weak
connectivity summary, and the active dataset's last successful import
metrics. Failed import attempts are reported to the command output/log and
never replace the active dataset.

The prepared import is capped at 20 MB, 100,000 nodes, 25,000 ways, and 100,000
edges. Inputs are validated for coordinates, duplicate IDs, complete way
references, supported access tags, scope, and metadata before writing. The
active flag changes in the same transaction as the dataset rows. Reimporting
identical prepared JSON reuses its stable dataset ID; failed imports do not
deactivate a valid graph. If a pinned extract exceeds limits, stop and review
the extent and limits rather than silently clipping away transport coverage.

## Precision, privacy, and tests

Boarding guidance preserves the stored precision: verified exact point,
boarding area with bay/curbside uncertainty, approximate area, or unknown
service-area location. Alighting remains “near” a mapped stop; no curb or
side is invented. Walking-path confidence and transport trust are separate.
Mapped proximity cannot verify transfer usability.

Routing stores static OSM network data and the path calculated for a request;
it does not collect user GPS, background locations, or movement history.
Origin/destination coordinates are request inputs, not a retained trace.

`tests/Feature/PedestrianRoutingTest.php` creates only explicitly synthetic
graph fixtures in an isolated test database. They exercise detours, disconnected
components, one-way foot access, bounded search, idempotent import, and missing
coverage. They do not create field observations or production transport facts.

The current development environment may not have PostGIS or an active Cebu
extract. In that state the API intentionally returns labelled straight-line
estimates with no walking geometry or duration; an empty graph is not reported
as operational pedestrian routing.
