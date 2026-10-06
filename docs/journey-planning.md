# Journey planning

Biyahe uses a bounded transit search over the reviewed Cebu network. It
favors explainable transit decisions over a city road graph and never treats
an estimated distance as a pedestrian route or an ETA.

## Request and response

The public endpoint is:

    GET /api/v1/journeys?from_place_id=ID&to_place_id=ID

Optional `access_radius`, `transfer_radius`, `max_transfers`, and `limit`
values are bounded by server configuration. The default maximum access radius
is 1,000 meters, the default maximum transfer radius is 600 meters, and the
default public transfer cap is two. Results are capped and ordered
deterministically. A public request cannot raise the transfer cap above two.

The response contains resolved places, journey candidates, legs, route and
pattern direction, boarding and alighting stops, trust metadata, provenance,
fare status, ranking reasons, warnings, and a machine-readable no-route
reason. Unknown place IDs and invalid parameters use the normal JSON error
shape. No-route results distinguish missing nearby boarding/alighting points,
`NO_KNOWN_JOURNEY`, and `ROUTING_DATA_NOT_READY` when matching data exists but
is stale, draft, or otherwise not eligible for guidance.

## Candidate generation

1. The API resolves active, non-demo canonical places.
2. PostGIS finds bounded nearby boarding and alighting candidates using
   geography `ST_DWithin` and `ST_Distance`. These are proximity/discovery
   operations only, not pedestrian routes. SQLite tests use the same WGS 84
   haversine fallback but do not represent production spatial behavior.
3. Direct candidates require one active, non-demo route pattern that serves a
   boarding stop before an alighting stop in the stored sequence. Boarding and
   alighting permissions are enforced, so an opposite-direction pattern cannot
   be suggested backwards.
4. Initial graph states contain a valid ride from a nearby boarding stop to a
   later alighting stop. A state may expand only through the same mapped stop
   or an active explicit `transfer_relationships` record whose pattern
   references, direction, and walking bound match. Nearby stops from the
   review command are candidates, not automatic transfers.
5. The graph expands with a deterministic bounded search. It stops at two
   transfers, does not reuse a route or pattern, caps patterns, states,
   transfer relationships, generated journeys, and final alternatives, and
   keeps only conservative non-dominated states for the same useful routing
   key. A state is dominated only when another has no more transfers, walking,
   or ride-stop burden and has no worse trust.
6. Terminal states produce `DIRECT`, `ONE_TRANSFER`, or `TWO_TRANSFER`
   journeys. Meaningful boarding stops, pattern sequence, transfer points, and
   alighting stops form the journey identity, so equivalent alternatives are
   not repeated.
7. Candidates are deduplicated and ranked before the result limit is applied.

When the transfer cap allows it, the planner also searches bounded
two-transfer alternatives even when a lower-transfer candidate exists. This
matters when the longer option has better-trusted service or a materially
lower walking burden. State dominance, generated-candidate bounds, stable
deduplication, and ranking keep obviously dominated alternatives out of the
final list.

The web review surface still exposes route patterns, direction, permissions,
and ordered stops so an administrator can inspect the facts behind a journey
without promoting them to VERIFIED.

The application performs transit sequence reasoning. It does not load a road
graph, use pgRouting, or attempt unrestricted multi-transfer search. The
bounded search is intentionally small enough for a public read-only endpoint;
it is not a general graph engine.

## Walking and geometry

Walking legs remain distinct from ride legs. If a bounded local OSM
pedestrian graph is installed, the planner computes access, egress, and
explicit-transfer paths over walkable edges and returns separate GeoJSON
`LineString` geometry, network distance, a configurable walking-time estimate,
provider/source, status, endpoint precision, and limitations. MapLibre renders
these paths as dashed walking lines, distinct from transit geometry. The local
shortest-path search, import bounds, licensing, and rebuild steps are in
`docs/pedestrian-routing.md`.

When no graph is installed, outside its bounds, or a route cannot be computed,
the API may return a `STRAIGHT_LINE_ESTIMATE` for rough discovery/comparison.
It has no walking geometry or walking-time estimate and the UI labels it as a
straight-line estimate, never as a walkable route. `NO_ROUTE` means that the
current mapped graph has no connected path; because OSM coverage can be
incomplete, it is not proof that no legal path exists. `NO_WALKABLE_ACCESS`
responses state this limitation. Candidates exceeding the configured
network-walk bound are not presented as feasible.

Walking distance is kept separate from transport evidence and journey trust.
An OSM path cannot verify a route, boarding practice, stop sequence, or
transfer. A transfer relationship remains subject to transport evidence even
when a pedestrian connection is mapped. Unknown or area-level boarding
precision remains visible; reaching a mapped coordinate never implies a
verified curb, bay, or drop-off side.

Ride geometry is the reviewed pattern `MultiLineString` in SRID 4326. Map
overlays show route geometry and stop points distinctly; origin, destination,
boarding, alighting, and transfer points are separate symbols.

## Trust, ranking, and guidance

Routing excludes demo, draft, stale, and retired records. UNVERIFIED pattern
associations remain usable during network development, but the journey derives
`VERIFIED`, `MIXED`, or `UNVERIFIED` and includes a plain-language warning.
The current real journeys are normally `MIXED`: OSM place/stop geography and
route identity are reviewed, while exact passenger stop service associations
still need stronger evidence.

Ride legs expose the boarding note, nearby landmark when recorded, boarding
and alighting association trust, route direction, stop sequences, and
precision-aware instructions. Exact boarding is claimed only for a verified
exact point; area-level, approximate, and unknown locations are described at
their actual precision. Alighting guidance says “near” and does not claim a
verified curb or street side. Generic OSM platform members remain unconfirmed
curbside boarding leads.

The rank policy is lexicographic and deterministic:

1. verified data before mixed data before unverified data;
2. fewer transit transfers;
3. lower total walking burden, preferring computed network distance when
   available and explicitly labelled straight-line estimates otherwise;
4. fewer ride stops;
5. stable journey identifier as a final tie-breaker.

Each response also includes plain-language ranking reasons such as `Direct
ride`, `Fewer transfers`, `Shortest walking estimate`, or `Less verified
information`. Numeric internal scores are not exposed because the policy is
comparative rather than an ETA model.

The planner has no reliable schedule, traffic, or speed model and therefore
does not invent an ETA. A current general traditional-PUJ fare rule is stored
from government reporting but remains unverified and is not Cebu route
specific; the API returns `Fare unavailable`, never zero, because fare
distance and route applicability are not established. Driver cues use a recorded place alias
or a supported transfer landmark; a generic platform member is not turned into
a fabricated local phrase. Get-off guidance says “near” rather than claiming
exact street-side precision. Stale fare evidence remains unavailable in
journey responses.

## Can I Ride?

The commuter decision endpoint is:

    GET /api/v1/can-i-ride?route_code=17B&from_place_id=ID&to_place_id=ID

It returns `YES`, `NO`, `DIRECTION_DEPENDENT`, or `UNKNOWN`. `YES` requires a
usable directional pattern, a nearby boarding point, a reachable later
alighting point, and permitted boarding/alighting associations. Route identity
alone is never enough. A direction-dependent answer explains the opposite
pattern without claiming that it is usable from the current side. The result
trust is derived from the route, pattern, stops, and service associations and
cannot be more trusted than those facts.

## Network audit

`biyahe:network:audit` runs a bounded developer audit over known canonical
place pairs and a small Can-I-Ride sample. It reports direct, one-transfer,
two-transfer, unsupported, and trust-distribution metrics, plus duplicate
journeys, walking-bound violations, loops, direction violations, stale
leakage, trust leakage, and planner errors. It is a coverage diagnostic, not a
claim that every Cebu place pair should be routable. Doctor V6 uses one O/D
pair and one Can-I-Ride smoke check for runtime practicality; use the bounded
audit command for broader O/D coverage. Doctor V5 treats
deterministic invariant corruption as a failure and incomplete coverage as a
warning. Doctor V6 also checks fact-scoped provenance and evidence freshness;
see `docs/evidence-verification.md` for the journey verification policy.

## Network requirements and future work

Adding a service requires reviewed place/stop facts, a route or pattern
source, ordered pattern-stop evidence, and explicit verification state. Use
the dataset importer rather than editing migrations. Use UNVERIFIED when a
source proves a route exists but not the exact ordered passenger service.

Future work may add better transfer evidence, schedules, accessibility rules,
and fare products. It must not silently turn straight-line distances into
walking directions, promote an incomplete pattern to VERIFIED, or expand this
bounded search into unrestricted road or multi-transfer navigation.
