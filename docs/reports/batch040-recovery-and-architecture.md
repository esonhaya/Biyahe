# Batch040 recovery and architecture

## Recovery baseline

- Repository: `Biyahe`, branch and HEAD recorded in the Batch040 ledger.
- Batch039 changes were intentionally uncommitted. The tracked diff and untracked source/report archive are preserved at `~/storage/external-1/Termux-Archive/Biyahe/Batch040-Recovery/20261009T153110Z`; its manifest and copied hashes were checked before this batch changed code.
- Six historical Doctor reports remain untracked and untouched.
- The immutable Batch030 SQLite artifact remains source-bound to the 2026-10-07 Central Visayas snapshot. The graph SHA-256 was checked before edits.
- No PostgreSQL cluster, PBF scan, graph rebuild, or production activation is part of this work.

## Existing integration map

| Area | Existing path | Batch040 focus | Evidence boundary |
|---|---|---|---|
| Offline graph routing | `OfflinePedestrianGraphRouter`, SQLite v3 | Validate format, schema, counts, fingerprint and geometry contract before returning the existing route result | Local development preview only |
| Preview endpoint | `POST /dev/offline-walking-preview` | Restrict to explicitly enabled local API, validate bounded JSON and serialize bounded responses | Does not replace the production journey API |
| Journey preview UI | `CommuteCard` callback from selected journey | Bind asynchronous response to structural journey identity; make stale responses inert and allow removing the overlay | Preview does not rerank or persist the journey |
| Route/map geometry | Existing `journeyRouteFeatures` and `MapView` | Preserve graph geometry provenance; never draw approximate endpoint connectors as graph edges | OSM geometry is not field safety evidence |
| Saved journeys | `savedJourneys.ts`, v1 local snapshot | Reject a development preview at the persistence boundary, including programmatic calls | Existing v1 snapshots remain readable |
| Ride Mode | `rideMode.ts`, manual transitions, local session v1 | Validate persisted session consistency and document legal transitions | No live arrival or safety claim |
| Validation | `validation-ledger.mjs` and Batch039 corpus replay | Keep evidence hashes and blocked states; add deterministic stored-evidence diagnostics | Resource-gated graph replay remains separate |
| Coverage | `docs/reports/batch032-cebu-coverage.json` | Generate carried-forward diagnostics without opening or rebuilding the graph | It reports stored transport trust and graph connectivity, not field access |

## Key constraints

The preview adapter reuses `OfflinePedestrianGraphRouter`; it does not implement another search algorithm. The router opens the immutable SQLite artifact and applies the 25 m snap rule, directionality, mapped barriers, and component checks. Batch040 adds an optional cooperative deadline checked between SQL phases and bounded PHP loops. PDO SQLite cannot preempt an in-progress statement, so this is a best-effort duration bound, not a hard wall-clock timeout. A browser abort prevents stale UI application, but it does not claim that server work is forcibly interrupted.

The pinned coverage evidence has known count discrepancies. Its `quality_summary` reports zero for long edges, boundary nodes, and crossing-tagged nodes while the source-bound `quality_evidence.counts` reports 26, 944, and 1,510. It also stores 619 restriction relations while the Batch039 narrative reports 648 classified vehicle-only relations. New diagnostics surface both disagreements without editing the historical report or pretending the counts have been reconciled.

## Recovery and verification

Use the Batch040 work ledger for task state. The immutable graph and Batch032 report are inputs; diagnostic outputs must use new paths and no-clobber publication. Before running PHP or frontend functional groups, use the existing 512 MiB MemAvailable and 128 MiB SwapFree floors. If either fails, mark those checks BLOCKED and continue static or fixture work that does not require the graph. PostgreSQL staging and field verification remain external/field-dependent gates.
