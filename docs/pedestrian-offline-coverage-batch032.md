# Batch032 offline Cebu pedestrian coverage

`reports/batch032-cebu-coverage.json` is the source-bound, machine-readable coverage and journey report for the immutable Batch030 v3 graph. It records the graph/PBF/backup identities, every stop and place ID with stored coordinates and trust state, snap result and component, ten deterministic journey cases, actual OSM graph paths for successful routes, and source-reference quality checks. It deliberately contains no stop/place names or field-observation claims.

## Reproduction

1. Verify the Batch024 dump against its expected SHA-256 and restore it into a new, isolated PostgreSQL/PostGIS test database. Keep the existing Termux PostgreSQL directories untouched.
2. Set `PGHOST=127.0.0.1`, the isolated server's `PGPORT`, and a `PGDATABASE` whose name clearly contains `staging`, `test`, `sandbox`, or `scratch`.
3. Export the coordinate/provenance-only input with `api/scripts/export-offline-pedestrian-coverage-points.sh VERIFIED_DUMP EXPECTED_SHA256 NEW_JSON_PATH`. It verifies the backup hash and refuses non-loopback or production-named databases. Batch032 used a read-only export from the restored dump before this helper was added; the helper's syntax, production-name refusal, and backup-hash refusal were checked without restarting PostgreSQL. The export file and staging credentials remain outside Git.
4. From `api/`, invoke `php artisan biyahe:pedestrian:audit-offline-coverage` with `--graph`, `--graph-sha256`, `--source-path`, `--source-sha256`, `--backup-path`, `--backup-sha256`, `--transport-data`, `--restriction-audit` pointing at `docs/reports/batch031-cebu-restriction-audit.json`, and a new `--output` path. The command revalidates SQLite integrity, source identity, graph rows, topology, geometry, and the restriction audit before routing. It does not decode or rescan the PBF.
5. Run `php artisan biyahe:doctor-v32` with the same graph/source/restriction evidence, plus the completed coverage report and isolated staging attempt report. Doctor V32 is static and does not connect to PostgreSQL.

## Routing and evidence boundaries

The offline router opens SQLite read-only. Its temporary RTree indexes use SQLite file-backed temp storage with an 8 MiB cache; component IDs are built deterministically from OSM node IDs. A route uses the nearest walkable node within 25 m, rejects an approximate straight-line snap connector that passes within 1.5 m of a mapped blocked barrier node, and never creates virtual edge anchors. It runs direction-aware Dijkstra with a 2 km per-corpus-case distance bound, 50,000-edge query limit, and 20,000 visited-node limit. A failed search returns a reason code and no geometry or duration. Successful geometry consists only of source graph nodes. A 1.2 m/s walking-speed assumption produces a clearly labeled estimate, not an exact ETA.

Component labels describe undirected graph connectivity; direction-aware search can still fail within one component. No route bridges disconnected components. Unmapped barriers, sidewalk quality, safe crossings, access gates, accessibility, and current field conditions remain unknown.

The bound Batch031 audit classifies all 648 source restriction relations as vehicle-only for this snapshot, with zero pedestrian-applicable or mode-ambiguous relations. Conditional and malformed vehicle relation limitations remain in the source audit. The offline engine does not implement state-aware turn restrictions; its routing capability depends on that complete, snapshot-bound classification and must be reevaluated for another source snapshot.

## Batch032 measurements

The report found routes in 9 of 10 cases, including an explicit disconnected-component rejection and a route through source way 95348306 segment 0 (258.13 m). Stop/place snapping is not universal: 436/503 stops and 30/37 places fall within the 25 m policy; three stops and one place snap to smaller components, and the rest have no eligible snap. Two stored transfer records have graph paths, but both records remain unverified. Coverage does not upgrade their trust or establish a safe physical interchange.

The ten-case corpus latency summary is P50 61 ms / P95 482 ms. These timings cover each bounded route request after SQLite’s temporary spatial/component indexes have been initialized; they do not include that one-time setup or the full artifact validator. The route using a long edge is retained as a review signal, not a safety approval.

All 26 edges longer than 250 m match consecutive source-way references, stored direction and endpoint geometry; none is flagged as a graph/source mismatch. The report also records 944 boundary nodes, 1,510 crossing-tag nodes, 171 clipped ways, 1,740 required outside-scope source nodes, zero duplicate way/segment identities, and zero edges touching blocked nodes. These are source/topology findings, not field safety validation; the component and boundary/crossing/clipping samples are deterministic.

A new isolated PostgreSQL 18.6/PostGIS 3.6.4 cluster restored the verified backup. The real importer attempt stopped at the 16 MiB free-swap guard after 13 seconds. No graph rows were visible afterward; real artifact staging and offline/staged parity remain unverified. The isolated cluster is stopped. Production activation remains blocked.
