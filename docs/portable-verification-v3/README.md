# Biyahe Batch041 portable evidence and adapter kit v3

This package pins repository revision `0bd2324cdcdbd9d5146e00a8020bb913dada572c` and carries the relevant PHP adapter, frontend contract, tests, reports, and provenance registry as a reviewable source snapshot. The SQLite graph, OSM PBF, PostgreSQL dump, credentials, databases, and runtime caches are **not included**.

## Inputs kept separate

| Artifact | Expected SHA-256 | Use |
|---|---|---|
| `cebu-walk-central-visayas-20261007-v3.sqlite` | `29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8` | Optional real graph replay after resource preflight |
| `central_visayas-20261007.osm.pbf` | `787cac20ecc586b754b1a464643a040566d486457c6a37bc0b11ce683bc66d4d` | Source provenance only; this kit never scans it |
| Batch024 dump | `e55af400fde88d0a3434b194e0625e204eb0a2726374eabb8286c152b11a4a92` | Not needed for this kit; only an isolated external staging workflow |

## Clean-room verification

1. Check out the full repository revision in `manifest.json`. Do not copy `.env`, credentials, sessions, caches, databases, or working state.
2. Run `bash /path/to/portable-verification-v3/verify-kit.sh --repo-root "$CHECKOUT"`. It checks package inventory, SHA256SUMS, exact Git revision, byte identity of source snapshots, and a no-database evidence smoke.
3. The smoke checks only immutable JSON reports, metric provenance, and the carried-forward 503/37 record summary. It does not open SQLite/PBF, route a journey, or contact PostgreSQL.
4. With Python 3 and PHP, the bounded report fixtures and pure geometry contract smoke can run from the pinned checkout using the commands in `manifest.json`. The geometry smoke covers seven deterministic cases without Laravel or SQLite. The synthetic comparator fixtures are not real route results.
5. Real graph replay is separate: first hash the transferred SQLite artifact, then enforce the resource floors in `docs/testing/batch041-test-plan.json`, and write a fresh report path. Never replace a pinned report or graph.
6. Collect host/tool versions and new evidence separately. Do not call a static smoke an adapter run or a Cebu route replay.

## Limits and recovery

Python 3 standard library, Bash, `git`, and `sha256sum` are needed for package verification. PHP/Laravel dependencies and Node are needed only for their corresponding test commands, using repository lockfiles. No package installation occurs in `verify-kit.sh`.

The package verification result is static-only. PostgreSQL staging, external-host execution, field safety, transfer verification, production activation, and private-beta readiness remain unproven. On a hash mismatch, missing report, unsupported format, or resource-floor failure, stop and preserve outputs. Delete only a new, private disposable verification directory after confirming all owned processes exited.
