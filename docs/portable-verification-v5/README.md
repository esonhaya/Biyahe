# Biyahe Batch043 portable verification kit v5

This kit pins repository revision `c15d4e09f7c361385bbe3f224f2c4c57742ad81e`. It includes the relevant source, fixtures, offline runner, and evidence reports. It excludes the SQLite graph, OSM PBF, PostgreSQL data, credentials, caches, and runtime dependencies.

## Run a mode

From this directory, run `bash verify-kit.sh MODE`. Available modes are:

| Mode | Work |
|---|---|
| `STATIC` | Provenance, coverage, comparator, Doctor, geometry and report-writer contracts, syntax, JSON, and diff checks |
| `FRONTEND` | Preview, commute card, Ride Mode, search, Can-I-Ride, ledger, TypeScript, and ESLint checks |
| `ADAPTER` | Focused walking adapter PHPUnit tests |
| `CORPUS` | Read-only replay against the pinned Cebu SQLite graph, if it is present and its SHA256 matches |
| `FULL` | Runs the modes in order and records blocked dependencies or resource gates per suite |

`verify-kit.sh` validates its manifest and every `SHA256SUMS` entry before invoking the runner. By default, commands use the included `source-snapshot`. Add `--repo-root CHECKOUT` to require the exact Git revision in the manifest and compare the pinned checkout with the package. Doctor V43 pins the executable source revision separately from later documentation/package commits. `--output-root DIR` selects a new evidence root; run directories are unique and existing outputs are never overwritten.

The package includes checksum-verified output and resource logs referenced by the pinned Doctor report, relocated under `source-snapshot/evidence/` so report validation works without the original host cache.

Run one suite with `bash verify-kit.sh --mode FRONTEND --suite commute-card`. Search, Can-I-Ride, UX, and ledger checks can be run separately with `--suite place-search`, `--suite search-keyboard`, `--suite can-i-ride`, `--suite ux-contract`, or `--suite validation-ledger`. A single stored Cebu case or bounded subset can be selected with `bash verify-kit.sh --mode CORPUS --corpus-cases 0` or `--corpus-cases 0,3`. Case indices are zero-based from 0 through 9. A matching subset is reported as `PARTIAL_CASE_SUBSET_MATCHED`; only a complete ten-case match can claim full corpus PASS. Suite identifiers are printed by each mode and recorded in `run-status.json`; a suite unavailable in the selected mode is rejected before execution.

Each run writes an atomic `run-status.json`, individual command output, source/tool/command fingerprints, resource logs for guarded work, and an evidence checksum list. Interrupted `RUNNING` records recover to `NOT_RUN`; the runner never infers PASS from partial output. Guarded suites use five samples spaced five seconds apart, the fixed 524288 KiB memory and 131072 KiB swap floors, and first-breach cancellation. No database service is started.

## Dependencies and evidence boundaries

Python 3, Bash, `sha256sum`, `git` (only with `--repo-root`), and the repository-defined helper tools are detected before their suites run. Node dependencies are not installed by this kit. PHP/Laravel dependencies are not bundled. Missing tools or lockfile dependencies are reported as `BLOCKED_DEPENDENCY`; a resource refusal is `BLOCKED_RESOURCE`. `FULL` is only a sequence of these statuses and does not imply every external dependency was available.

The Cebu graph is expected at `~/.cache/biyahe/batch030/cebu-walk-central-visayas-20261007-v3.sqlite`, SHA256 `29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8`. For corpus mode, the owned-worker guard takes five resource samples before checking graph metadata or hashing the file. A fresh read-only SHA256 must match before Laravel opens SQLite. The graph is never rebuilt or modified and the runner never scans a PBF. A historical `9 found / 1 disconnected` result is not treated as a current replay.

The external Linux staging runbook and passive preflight are included. They do not provision a host, connect to a database, or start PostgreSQL. Browser execution, PostgreSQL staging, production graph integration, field transfer safety, private beta, and production activation remain separate evidence gates. This kit does not perform or claim them. No package installation, PostgreSQL startup, graph modification, field verification, or automatic push occurs.
