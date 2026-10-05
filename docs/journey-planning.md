# Journey planning

Batch 003 provides the first bounded A-to-B planner for the reviewed Cebu
network. It intentionally favors explainable candidate generation over a city
road graph.

## Request and response

The public endpoint is:

    GET /api/v1/journeys?from_place_id=ID&to_place_id=ID

Optional `access_radius` and `transfer_radius` values are bounded by server
configuration. The default maximum access radius is 1,000 meters and the
default maximum transfer radius is 600 meters. Results are capped and ordered
deterministically.

The response contains resolved places, journey candidates, legs, route and
pattern direction, boarding and alighting stops, trust metadata, provenance,
fare status, warnings, and a machine-readable no-route reason. Unknown place
IDs and invalid parameters use the normal JSON error shape. No-route results
distinguish missing nearby boarding/alighting points, `NO_KNOWN_JOURNEY`, and
`ROUTING_DATA_NOT_READY` when matching data exists but is stale, draft, or
otherwise not eligible for guidance.

## Candidate generation

1. The API resolves active, non-demo canonical places.
2. PostGIS finds bounded nearby boarding and alighting stops using geography
   `ST_DWithin` and `ST_Distance`. SQLite tests use the same WGS 84 haversine
   fallback but do not represent production spatial behavior.
3. Direct candidates require one active, non-demo route pattern that serves a
   boarding stop before an alighting stop in the stored sequence. Boarding and
   alighting permissions are enforced, so an opposite-direction pattern cannot
   be suggested backwards.
4. One-transfer candidates use either a shared stop or an explicit
   `transfer_relationships` record. Batch 003 does not infer every nearby
   point to be a safe transfer. The Cebu B. Rodriguez Extension ↔ Fuente
   relationship is a directional, curated, UNVERIFIED straight-line candidate
   and is shown with uncertainty. The `biyahe:transfers:candidates` command
   finds additional bounded review leads without auto-verifying them.
5. Candidates are deduplicated and ranked before the result limit is applied.

The web review surface still exposes route patterns, direction, permissions,
and ordered stops so an administrator can inspect the facts behind a journey
without promoting them to VERIFIED.

The application performs transit sequence reasoning. It does not load a road
graph, use pgRouting, or attempt unrestricted multi-transfer search.

## Walking and geometry

Walking legs are separate from ride legs and expose meters as a
`straight_line_estimate`. They do not contain a pedestrian polyline or turn
instruction. The interface shows the access, transfer, and egress points but
does not draw a made-up street path. A future pedestrian routing provider can
replace the distance-estimate adapter without changing the journey leg
contract.

Ride geometry is the reviewed pattern `MultiLineString` in SRID 4326. Map
overlays show route geometry and stop points distinctly; origin, destination,
boarding, alighting, and transfer points are separate symbols.

## Trust and guidance

Routing excludes demo, draft, stale, and retired records. UNVERIFIED pattern
associations remain usable during network development, but the journey derives
`VERIFIED`, `MIXED`, or `UNVERIFIED` and includes a plain-language warning.
The current real journeys are normally `MIXED`: OSM place/stop geography and
route identity are reviewed, while exact passenger stop service associations
still need stronger evidence.

Ride legs expose the boarding note, nearby landmark when recorded, boarding
and alighting association trust, route direction, stop sequences, and a
plain-language instruction to wait near the mapped point. Generic OSM platform
members are explicitly described as unconfirmed curbside boarding leads.

The rank policy is lexicographic and deterministic:

1. verified data before mixed data before unverified data;
2. fewer transit transfers;
3. lower total walking estimate;
4. fewer ride stops;
5. stable journey identifier as a final tie-breaker.

The planner has no reliable schedule, traffic, or speed model and therefore
does not invent an ETA. No production fare is currently supported; the API
returns `Fare unavailable`, never zero. Driver cues use a recorded place alias
or a supported transfer landmark; a generic platform member is not turned into
a fabricated local phrase. Get-off guidance says “near” rather than claiming
exact street-side precision. Stale fare evidence remains unavailable in
journey responses.

## Network requirements and future work

Adding a service requires reviewed place/stop facts, a route or pattern
source, ordered pattern-stop evidence, and explicit verification state. Use
the dataset importer rather than editing migrations. Use UNVERIFIED when a
source proves a route exists but not the exact ordered passenger service.

Future batches may add better transfer evidence, schedules, accessibility
rules, fare products, and a real pedestrian router. They must not silently
turn straight-line distances into walking directions or promote an incomplete
pattern to VERIFIED.

`biyahe:network:audit` runs a bounded developer audit over known canonical
place pairs. It reports pairs examined, direct and one-transfer coverage,
unknown pairs, and invariant errors such as backwards rides, excess transfers,
or trust leakage. It is a coverage diagnostic, not a claim that every Cebu
place pair should be routable.
