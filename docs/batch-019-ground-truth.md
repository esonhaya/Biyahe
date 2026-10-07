# Batch 019 — ground truth and pedestrian coverage

Batch 019 is a bounded evidence-and-access campaign for the existing Metro
Cebu atlas. It does not add route facts merely because they improve journey
coverage. The claim queue is derived from the completed Batch 018 audit and
keeps service, direction, sequence, boarding, alighting, transfer, fare,
signboard, and driver-instruction scopes separate.

## Claim queue and field missions

Generate the current queue from the ignored Batch 018 checkpoint:

    cd api
    php artisan biyahe:batch019:ground-truth --fresh --limit=100 --json
    php artisan biyahe:batch019:ground-truth --resume --json

The queue is runtime workflow state under `storage/app/batch019/` and is not
production evidence. Each item has a stable claim ID, current fact state,
impact score, known source IDs, research status, and an explicit
`NO_AUTOMATIC_PROMOTION` gate. Missions are bundled by practical survey area
so one accountable observation run can address several related claims. A
planned mission has `is_evidence=false`; the existing survey validation and
review workflow remains the only route to a reviewed evidence claim.

The Batch 019 remote review found only bounded support for mapped route
identity/general corridor. The Metro Cebu OSM transport page says mapping is
in progress and is not evidence of current dispatch, stop sequence, curbside
boarding, or alighting practice:

<https://wiki.openstreetmap.org/wiki/Public_transportation_in_Metro_Cebu>

The historical Cebu City traffic notice and the route-code article are kept
as reviewed leads with their limitations; neither is used to promote current
13B service or Carbon boarding facts. Unknown boarding precision therefore
remains honest until a claim-specific current source or reviewed field
observation exists.

## Incremental pedestrian graph

The active graph remains a replaceable OSM-derived dataset, separate from
transit trust. Batch 019 acquired only two bounded Overpass windows covering
the demonstrated south-Cebu and Mandaue access gaps. The source artifact was
prepared with osmium, recorded its checksum/timestamp/license, and merged
transactionally on top of the previous graph. Existing OSM node coordinates
are preserved; a materially moved overlapping node is skipped together with
dependent new edges rather than silently moved.

The active graph metadata records the merge base and conflict counts. Run:

    php artisan biyahe:pedestrian:status --json
    php artisan biyahe:doctor

The graph is geographic walking coverage, not transport-service evidence. It
does not verify a route, a stop, a transfer, a boarding side, or a safe curb.
The configured snap/anchor restrictions and OSM access restrictions remain in
force. Transport stop coordinates are never moved to improve graph snaps.

## Planner safeguards

Journey planning uses request-scoped pedestrian, trust, transfer-leg, and
ride-leg caches. These caches derive from canonical database models and reset
for every plan. The transfer cap remains two. A semantic profile snapshot is
the equivalence check for performance changes; timing and cache counters are
developer-only instrumentation and are not part of public journey output.

The 47-pair audit remains sequential and atomic per pair:

    php artisan biyahe:batch018:audit --fresh --namespace=batch019-final-matrix --json
    php artisan biyahe:batch018:audit --resume --namespace=batch019-final-matrix --json

Runtime checkpoints are intentionally ignored and must not be committed.

## Ground-truth boundary

OSM route geometry, pedestrian geometry, proximity, planner output, Ride Mode
GPS, report volume, and a planned field mission cannot promote a transport
fact. A complete `VERIFIED` journey is possible only when every required
service, sequence, boarding, alighting, transfer, place, and walking claim
passes the existing scoped evidence rules. Zero verified journeys is a valid
result when those claims remain unresolved.
