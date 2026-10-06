# Pedestrian routing

## Boundary and behavior

Journey planning calls the Biyahe-owned `PedestrianRouter` interface. The
initial provider is a bounded local OSM graph in PostGIS; the routing provider
can be replaced without binding journey code to a commercial API. No paid
service, routing server, Redis, or tile-server routing request is required.

PostGIS proximity queries discover candidate stops. For each small candidate
set, the local provider snaps to the nearest walkable OSM node within 12 m and
runs a bounded, direction-aware Dijkstra search. It has configurable edge,
visited-node, distance, and per-request cache limits. Each HTTP/planning scope
memoizes identical requests; no persistent route cache is used. Access and
egress are bounded to 2,000 m, explicit transfers to 600 m, and initial
candidate discovery remains bounded by the journey planner's existing limits.
Planner metrics distinguish candidate pairs, uncached route attempts, paths
found, request-cache hits, and routing time.

The imported graph includes selected roads, paths, footways, pedestrian
streets, steps, and service roads. Explicit `access`/`foot` prohibitions,
barrier nodes, conditional access restrictions, motorways, and unsupported
road classes are excluded. `oneway:foot` and `foot:forward` /
`foot:backward` restrictions are represented. An OSM graph path is not a
guarantee of a sidewalk, safe crossing, open gate, accessibility, or current
ground conditions; those facts are not inferred.

Successful legs expose separate `LineString` geometry and `PEDESTRIAN_NETWORK`
distance. When a point is connected by a short endpoint snap, the leg reports
`PEDESTRIAN_NETWORK_WITH_APPROXIMATE_CONNECTORS` and warns that the straight
connector is not part of the displayed network geometry. Walking duration is
only an estimate: network distance divided by
`BIYAHE_WALKING_SPEED_METERS_PER_SECOND` (default 1.2 m/s), not a traffic- or
condition-aware ETA.

Without a graph, outside its extent, or when routing is unavailable, geometry
and duration are absent. A straight-line fallback is explicitly tagged
`STRAIGHT_LINE_ESTIMATE`; it is not a walking route. `NO_ROUTE` means no path
was found in the extracted graph and does not prove that no legal path exists,
because map coverage can be incomplete. A route longer than the configured
walk bound is not offered as feasible. These statuses never alter transit
verification, boarding precision, or transfer evidence.

## Data provenance and rebuild

OpenStreetMap data is licensed under the ODbL and requires attribution to
OpenStreetMap contributors; see the [OpenStreetMap copyright and license
page](https://www.openstreetmap.org/copyright). Imported datasets retain the
source URL, ODbL identifier, attribution, source snapshot timestamp when
provided, import timestamp, Cebu extent, counts, checksum, and importer
metadata. Pedestrian-map provenance is not transit evidence.

The current configured extent is `10.282, 123.860` to `10.381, 123.935`
(south, west, north, east). Fetch a bounded Overpass JSON extract when the
service is available; do not issue frequent repeated queries to public
instances. The official [Overpass QL documentation](https://wiki.openstreetmap.org/wiki/Overpass_API/Overpass_QL)
describes the query syntax.

From Termux or another machine with `curl`:

```sh
cat > cebu-pedestrians.overpassql <<'EOF'
[out:json][timeout:120];
(
  way["highway"~"^(footway|path|pedestrian|steps|living_street|residential|unclassified|service|track|tertiary|secondary|primary|tertiary_link|secondary_link|primary_link)$"](10.282,123.860,10.381,123.935);
);
(._;>;);
out body;
EOF

curl --fail --max-time 180 \
  -A 'Biyahe Cebu pedestrian-data import (contact: project maintainers)' \
  --data-urlencode data@cebu-pedestrians.overpassql \
  https://overpass-api.de/api/interpreter \
  -o cebu-pedestrians.json
```

If Overpass returns a timeout or rate limit, stop and retry later or use a
cached, legally compatible extract; do not repeatedly hammer public
endpoints. The import is bounded by 20 MB, 50,000 input nodes, 25,000 ways,
and 100,000 resulting edges. The importer accepts one complete, deduplicated
extract for the configured extent; it rejects duplicate source IDs and
missing way nodes. Do not import separate partial tiles as if they were full
Cebu coverage. If this extent exceeds a cap, review a reproducible single
extract and a deliberate cap change before importing; do not silently shrink
the production coverage.

After applying the API migrations to the intended PostGIS database, validate
and import the extract:

```sh
cd api
php artisan migrate --force
php artisan biyahe:pedestrian:import /path/to/cebu-pedestrians.json \
  --source-url='https://overpass-api.de/api/interpreter'
php artisan biyahe:doctor
```

The importer validates coordinates, unique stable source IDs, complete way
references, access restrictions, and scope before a transaction. It stores
one record per directed or bidirectional segment, checksums the input, and
activates the dataset only after the graph has been written successfully.
Reimporting identical bytes reuses the stable dataset ID. Use a bounded export
with full way-node references; malformed or partial data fails without
replacing the currently active graph. Raw extracts are not committed to the
repository.

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
