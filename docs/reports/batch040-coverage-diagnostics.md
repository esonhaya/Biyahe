# Batch040 Cebu coverage diagnostics

Status: `CARRIED_FORWARD_NOT_RECOMPUTED`.

This summary records the existing hash-pinned Batch032 evidence. Batch040's resource guard stopped the functional group before the coverage summarizer or the ten-case corpus replay ran. These counts are not a new graph scan or a fresh replay.

- Graph: 76,685 nodes, 78,225 edges, 432 walkable components.
- Stops: 436 of 503 snap within the fixed 25 m policy; 433 are in the largest walkable component and 3 are in smaller components. The other 67 are unsnappable under that policy.
- Places: 30 of 37 snap; 7 remain unsnappable. Their component distribution was not recomputed.
- Transfers: both stored transfer pairs have a graph path. Both remain `UNVERIFIED` as physical interchanges.
- Restriction inventory: the hash-pinned coverage report records 619 vehicle-only relations; the Batch039 summary reports 648 classified vehicle-only. The count difference is unresolved and is preserved here instead of being silently reconciled.
- Stored Cebu corpus: historical evidence reports 9 routes found and 1 disconnected case. Batch040 replay was not run.
- Quality evidence: 26 edges exceed 250 m (maximum 867 m), with 944 boundary nodes and 1,510 crossing-tagged nodes in the detailed evidence. The historical quality summary instead reports zero for these fields; the conflict remains unresolved.

The report does not assign a geographic “weak area” from component counts or administrative identifiers. It does not establish sidewalks, crossing conditions, fare evidence, physical access, transfer safety, or current field conditions. Source geometry remains OSM-derived and unverified in the field.

Machine-readable record: [batch040-coverage-diagnostics.json](batch040-coverage-diagnostics.json).
