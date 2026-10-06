# Batch 011 pedestrian access-gap audit

Audit date: 2026-10-07. This is an investigation of the persisted bounded
Cebu graph, not a transport-evidence or field-survey result.

## Recovered graph and policy

- Active dataset: `osm-cebu-walk-07555e8a0e284772e2c16120c1d66075`
- Source: OpenStreetMap France Central Visayas extract, ODbL-1.0,
  snapshot `2026-10-05 00:57:57`, bounded by `10.282–10.381 N / 123.860–123.935 E`
- Graph: 43,720 nodes and 45,104 edges; persisted provenance and validation
  remain intact after the source-cache cleanup.
- Node snap remains 25 m. Threshold audit: 25 m = 41/17 connected stops/places;
  35 m = 47/19; 50 m = 48/20; 75 m = 48/22. The wider thresholds would use
  long endpoint connectors and were not adopted.
- New policy: when no node is within 25 m, at most one 10 m edge projection is
  considered. It requires walkable endpoints, allowed OSM semantics, and no
  mapped barrier within 1.5 m of the connector. Bare secondary/primary/service
  edges without explicit sidewalk/foot access remain unresolved.

## Stop gaps

Distances are nearest edge projection / nearest node in metres. All seven
stops were `UNKNOWN` boarding precision and `UNVERIFIED` transport state;
none was moved or had transport trust changed.

| Entity | Coordinate | Edge / node | Audit outcome |
|---|---|---:|---|
| `stop-osm-10250495172` (22) | 10.3109833,123.8972602 | 0.38 / 33.42 | `CONNECTED_SAFELY`; sidewalk footway; mapped nearby gates remain a warning |
| `stop-osm-10250495181` (23) | 10.3043833,123.9062685 | 6.56 / 31.95 | `OSM_DATA_GAP`; secondary has no sidewalk/foot tag |
| `stop-osm-10250495225` (24) | 10.2968851,123.8979945 | 7.15 / 36.48 | `CONNECTED_SAFELY`; secondary has `sidewalk=both`; nearby gates remain a warning |
| `stop-osm-13761865119` (35) | 10.3108932,123.9087717 | 7.18 / 25.66 | `CONNECTED_SAFELY`; primary has `sidewalk=both`; nearby gates remain a warning |
| `stop-osm-13761865123` (37) | 10.3127987,123.9117725 | 4.57 / 34.43 | `CONNECTED_SAFELY`; primary has `sidewalk=both` |
| `stop-osm-14048307462` (40) | 10.3179474,123.8865700 | 4.03 / 32.38 | `CONNECTED_SAFELY`; residential edge; nearby gate remains a warning |
| `stop-osm-14104740296` (47) | 10.3709914,123.9250021 | 6.18 / 31.59 | `OSM_DATA_GAP`; unpaved service edge has no explicit pedestrian access |

The remaining two are conservative unresolved cases, not proof that access
does not exist. No importer or extraction defect was found: relevant OSM
highways, sidewalk/foot tags, barriers, crossings, bridge/tunnel properties,
and directionality are retained by the generic importer. No coordinate change
had supporting repository evidence.

## Place gaps

Places do not have a boarding-precision enum; their stored location is the
place coordinate. No place coordinate was changed.

| Entity | Coordinate | Edge / node | Audit outcome |
|---|---|---:|---|
| Ayala Center Cebu (4) | 10.3180958,123.9051572 | 50.06 / 50.06 | unresolved landmark access; nearest footway exceeds 10 m anchor policy |
| Cebu Normal University (17) | 10.3016486,123.8967853 | 36.00 / 36.00 | unresolved landmark access; nearby gate is mapped |
| Gaisano Country Mall (19) | 10.3396500,123.9105413 | 45.37 / 51.89 | unresolved landmark access; nearest footway exceeds policy |
| North Reclamation Area (23) | 10.3113996,123.9177809 | 25.32 / 25.56 | unresolved broad-landmark access; nearest service edge exceeds policy |
| SM City Cebu (24) | 10.3113996,123.9177809 | 25.32 / 25.56 | unresolved broad-landmark access; same stored coordinate/evidence |

The active graph remains separate from jeepney service, route pattern,
boarding/alighting practice, transfer validity, fare, signboard, and schedule
evidence. Connected walking access therefore does not verify transport facts.

## API and validation semantics

Walking responses distinguish `NETWORK_ROUTE`, `STRAIGHT_LINE_ESTIMATE`,
`NO_WALKABLE_ACCESS`, `GRAPH_COVERAGE_GAP`, and `ROUTING_UNAVAILABLE`. User
guidance says walking access could not be confirmed from map data when coverage
is insufficient; it does not claim that no legal pedestrian path exists.

Doctor V11 checks the bounded anchor configuration and keeps incomplete OSM
coverage as a warning/label rather than an integrity failure. The detailed
gap list is intentionally not a production trust mutation.
