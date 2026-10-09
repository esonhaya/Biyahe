# Batch040 portable offline verification kit

This kit prepares a clean Linux checkout to verify the local walking adapter, replay the ten stored Cebu cases, and summarize pinned coverage evidence. It includes a source snapshot of the relevant scripts, tests, route contract, frontend adapters, and pinned evidence report so the inputs can be reviewed independently. It does **not** contain the graph, PBF, PostgreSQL dump, credentials, PGDATA, or a claim that another host ran the checks.

The manifest pins repository revision `b723f78653d76e1796f2980e7a414b29782ae01b`. The package is kept as a separate adjacent artifact/commit so its source snapshot can be compared with that exact checkout.

## Pinned inputs

| Input | Required filename | SHA-256 | Purpose |
|---|---|---|---|
| SQLite v3 pedestrian graph | `cebu-walk-central-visayas-20261007-v3.sqlite` | `29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8` | Offline preview and real route corpus replay |
| Central Visayas PBF snapshot | `central_visayas-20261007.osm.pbf` | `787cac20ecc586b754b1a464643a040566d486457c6a37bc0b11ce683bc66d4d` | Provenance reference; no PBF scan is needed for these offline checks |
| Batch024 PostgreSQL dump | `biyahe-batch024-20261008.dump` | `e55af400fde88d0a3434b194e0625e204eb0a2726374eabb8286c152b11a4a92` | Required only for the separate isolated staging workflow |
| Batch032 coverage evidence | `batch032-cebu-coverage.json` | `6caf101177904bee6fda1b12cab9c5cda36ddaf7ecc08b0d9778c0517123c81b` | Source IDs, snap results, route corpus and graph capability |

Obtain graph and dump from the existing verified Batch036 SD package or other independently verified copies. This kit intentionally does not copy multi-gigabyte source files. Hash every artifact after transfer. The offline adapter/corpus run needs the graph and committed coverage report; it does not need PostgreSQL or the PBF. Real database staging requires the separate Batch036 procedure and a disposable Linux PostgreSQL/PostGIS cluster.

## Host requirements

- Linux with PHP and SQLite PDO support, Composer dependencies from `api/composer.json`, Python 3 standard library, Node.js compatible with the repository's `--experimental-strip-types` harness commands, and Bash.
- A disposable checkout at the exact revision in `manifest.json`.
- For the optional separate staging workflow only: PostgreSQL 18 and PostGIS 3.6 (or versions explicitly supported by project migrations), isolated PGDATA, socket, port and credentials. Never point it at an existing application cluster.
- Enough memory and disk for SQLite route-index initialization. On Termux retain the 512 MiB `MemAvailable` and 128 MiB `SwapFree` floors; this kit does not lower them.

## Clean-room sequence

1. Create a fresh checkout at the revision in `manifest.json`; do not copy local `.env`, credentials, sessions, caches or database directories.
2. Run `bash /path/to/portable-verification-v1/verify-kit.sh --repo-root "$CHECKOUT"`. It validates the package inventory, checksums, full Git revision, and byte identity between packaged source copies and the checkout. It does not run graph or database checks.
3. Copy only the required graph and evidence files to a private work directory. Verify each SHA-256 against the manifest. The PBF and dump are not needed for offline adapter/corpus checks.
4. Install normal repository dependencies on the Linux host, then run the adapter unit test and the bounded frontend harnesses below. Record command output and host/tool versions as new evidence.
5. Configure `BIYAHE_OFFLINE_GRAPH_PATH` and `BIYAHE_OFFLINE_COVERAGE_REPORT` to verified copies. Keep the local preview opt-in disabled except in a local development server. Its 8-second route budget is cooperative: PDO SQLite statements cannot be interrupted in progress, and a browser abort only prevents stale UI updates. Keep the adapter loopback-only.
6. Replay the ten stored cases to a new output path and run `compare-offline-corpus.py`. Require all ten cases to match status, reason, distance within 0.02 m, component, geometry direction/continuity, ordered edge identities and snapped endpoints.
7. Generate coverage diagnostics from the pinned report. Review the quality-summary discrepancy; the report deliberately preserves both values.
8. If database staging is independently authorized on the external host, follow `docs/portable-staging-v1/README.md` in a separate disposable cluster. Verify the restored baseline is 503 stops, 37 places and 2 transfers, keep the graph inactive, and do not activate production routing.
9. Collect reports, checksums, exact commit, environment and resource observations in a new evidence directory. Label absent host execution as `NOT_RUN`.

## Offline verification commands

From the pinned repository root, with dependencies already present (the source snapshot in this package is for comparison; execute the checkout copy):

```sh
(cd "$CHECKOUT/api" && php artisan test --filter=OfflineJourneyWalkingPreviewTest)
(cd "$CHECKOUT/api" && php artisan test --filter=OfflinePedestrianGraphRouterTest)
node --experimental-strip-types web/scripts/offline-walking-preview-harness.mjs
node --experimental-strip-types web/scripts/commute-card-harness.mjs
node --experimental-strip-types web/scripts/ride-mode-harness.mjs
node --experimental-strip-types web/scripts/validation-ledger-harness.mjs
python3 api/scripts/summarize-offline-coverage.py \
  --output-dir="$RUN_ROOT/coverage-diagnostics"

(cd api && php artisan biyahe:pedestrian:verify-offline-corpus \
  --output="$RUN_ROOT/offline-corpus.json")

python3 api/scripts/compare-offline-corpus.py \
  --actual="$RUN_ROOT/offline-corpus.json" \
  --output="$RUN_ROOT/corpus-comparison.json"

bash api/scripts/run-batch040-regressions.sh
```

Run graph and PHPUnit checks behind `web/scripts/validation-ledger.mjs run` with a new log path, cancellation PID path, and exact command fingerprint. That scheduler delegates to the owned-process resource guard and retains the fixed 512 MiB/128 MiB floors. It permits one guarded check at a time and never reuses a PASS after its command/runtime/config/source/evidence identity changes. Record each command independently; a passing fixture harness is not a real graph replay.

## Expected evidence and limits

- Stored coverage: 436/503 stops and 30/37 places snappable within 25 m; 67 stops and 7 places unsnappable.
- Ten pinned route cases: 9 found and 1 component-disconnected in prior evidence. Re-run status must be recorded separately.
- Both graph-connected transfer records remain `UNVERIFIED` for a physical interchange.
- Graph geometry is OSM-derived evidence. It does not verify sidewalks, crossings, barriers omitted from OSM, boarding access or current field conditions.
- A successful offline replay does not prove live API integration, PostgreSQL staging, field safety, private-beta readiness or production activation.

## Failure and cleanup

Hash mismatch, unsupported schema/capability, missing graph, corrupt evidence, or resource-guard failure must stop the affected check. Preserve logs and partial diagnostic evidence; do not regenerate the graph or silently replace pinned inputs. Remove only a disposable run directory created by that host, after confirming the command and any owned worker have exited. Never point cleanup at a repository, shared cache, SD archive, or existing PostgreSQL cluster.
