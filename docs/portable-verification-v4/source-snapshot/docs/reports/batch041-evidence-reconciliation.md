# Batch041 evidence reconciliation

This reconciliation reuses only committed Batch031/032/040 reports and their producer code. It did not open the PBF, start PostgreSQL, or read/rebuild the graph in Batch041. Report payloads do not embed creation timestamps; the dates below are repository commit times, not claimed execution times. Exact file hashes and metric definitions are in `batch041-evidence-provenance.json`.

## Restriction inventory

The complete Batch031 source tag scan (Osmium 1.19.1, wrapped runtime, clean producer exit) matched 648 source relations. The v3 graph's capture predicate persisted 619. The source scan enumerates the 29 omitted relations: 28 have only generic `restriction:conditional` and one only `restriction:psv`. All source classifications total 648 vehicle-only; stored member-form counts apply to 619 only. These are separate denominators, not competing counts. The 648 source count and 619 stored count are both bound to PBF SHA-256 `787cac20…66d4d` and graph fingerprint `a0d9f1cb…4c03ecd`; the coverage report also pins the graph artifact SHA-256.

| Evidence | Report hash | Commit time | Scope |
|---|---|---|---|
| Batch031 restriction source scan | `ba04117f…ff0ea6` | `2026-10-09T09:09:07+08:00` | 648 tag-filtered source relations, including 29 omitted from v3 storage |
| Batch031 restriction audit | `14671f5c…7e6c` | `2026-10-09T09:09:07+08:00` | Source mode classification plus stored-member audit; relation member forms describe 619 stored records |
| Batch032 coverage report | `6caf1011…7123c81b` | `2026-10-09T10:13:34+08:00` | Immutable SQLite v3 identity and stored coverage/restriction inventory |

## Graph-quality fields

The old Batch032 `quality_summary` fields report zero for long edges, boundary nodes, and crossing-tagged nodes. At revision `90a40fc`, `OfflinePedestrianCoverageAudit` read optional metadata values with `?? 0`. The `AuditOfflinePedestrianCoverage` command attached the detailed SQLite quality audit afterward but did not replace that earlier summary. The zeros are therefore default field values, not measured zero counts. Revision `2d1641d` later changed the producer to populate these summary fields from detailed audit results; the immutable Batch032 report was not rewritten.

The Batch031 source-bound read-only graph audit reports 26 graph edge segments with `length_meters > 250`, 944 nodes within 0.0005 degrees of any source-bound latitude/longitude edge, and 1,510 nodes with a nonempty `crossing` tag. These exact filters are recorded in the registry. A mapped crossing tag or source-backed long edge is not a safety observation.

## Coverage scope

The Batch032 evidence has 503 stop records and 37 place records, all with stored coordinates in valid numeric ranges; 436 stops and 30 places snapped within the pinned 25 m rule. Those numbers describe stored records against this graph snapshot. They do not measure population, geographic completeness, sidewalks, boarding access, or field conditions. Two transfer records have graph paths and both retain `UNVERIFIED_RECORD` trust.

The ten stored route cases contain nine paths and one disconnected result. Two different transfer journeys share the descriptive label `transfer_stop_to_stop`; their ordered indexes and endpoint IDs distinguish them. The carried-forward report is not a Batch041 route replay.

## Reproducible validation

Run `python3 api/scripts/validate-batch041-provenance.py` to verify source file hashes, report formats, snapshot/graph identities, metric pointers, denominator arithmetic, and the registry hash pinned in code. The coverage summarizer requires that validation and includes its registry hash and reconciliation status in output. A changed source file, changed metric value, missing claim definition, or repeated metric identity fails closed.
