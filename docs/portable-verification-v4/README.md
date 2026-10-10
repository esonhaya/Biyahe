# Biyahe Batch042 portable verification kit v4

This kit pins repository revision `a91dabe1996f5809d25ebd6b57cfd4bab024a493`. It includes the relevant source, fixtures, offline runner, and evidence reports. It excludes the SQLite graph, OSM PBF, PostgreSQL data, credentials, caches, and runtime dependencies.

## Run a mode

From this directory, run `bash verify-kit.sh MODE`. Available modes are:

| Mode | Work |
|---|---|
| `STATIC` | Provenance, coverage, comparator, Doctor, geometry, syntax, JSON, and diff checks |
| `FRONTEND` | Preview, commute card, Ride Mode, search, Can-I-Ride, ledger, TypeScript, and ESLint checks |
| `ADAPTER` | Focused walking adapter PHPUnit tests |
| `CORPUS` | Read-only replay against the pinned Cebu SQLite graph, if it is present and its SHA256 matches |
| `FULL` | Runs the modes in order and records blocked dependencies or resource gates per suite |

`verify-kit.sh` validates its manifest and every `SHA256SUMS` entry before invoking the runner. By default, commands use the included `source-snapshot`. Add `--repo-root CHECKOUT` to require the exact Git revision in the manifest and compare the pinned checkout with the package. Doctor V42 is source-bound to the pinned commit; its evidence paths are relocated in the package, and the verifier checks that this is the only report change. `--output-root DIR` selects a new evidence root; run directories are unique and existing outputs are never overwritten.

The package includes checksum-verified output and resource logs referenced by the pinned Doctor report, relocated under `source-snapshot/evidence/` so report validation works without the original host cache.

Run one suite with `bash verify-kit.sh --mode FRONTEND --suite commute-card`. Search, Can-I-Ride, UX, and ledger checks can be run separately with `--suite place-search`, `--suite search-keyboard`, `--suite can-i-ride`, `--suite ux-contract`, or `--suite validation-ledger`. Suite identifiers are printed by each mode and recorded in `run-status.json`; a suite unavailable in the selected mode is rejected before execution.

Each run writes an atomic `run-status.json`, individual command output, source/tool/command fingerprints, resource logs for guarded work, and an evidence checksum list. Interrupted `RUNNING` records recover to `NOT_RUN`; the runner never infers PASS from partial output. Guarded suites use five samples spaced five seconds apart, the fixed 524288 KiB memory and 131072 KiB swap floors, and first-breach cancellation. No database service is started.

## Dependencies and evidence boundaries

Python 3, Bash, `sha256sum`, `git` (only with `--repo-root`), and the repository-defined helper tools are detected before their suites run. Node dependencies are not installed by this kit. PHP/Laravel dependencies are not bundled. Missing tools or lockfile dependencies are reported as `BLOCKED_DEPENDENCY`; a resource refusal is `BLOCKED_RESOURCE`. `FULL` is only a sequence of these statuses and does not imply every external dependency was available.

The Cebu graph is expected at `~/.cache/biyahe/batch030/cebu-walk-central-visayas-20261007-v3.sqlite`, SHA256 `29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8`. The runner hashes it before replay and writes a fresh corpus report. It never rebuilds or modifies the graph and never scans a PBF. A historical `9 found / 1 disconnected` result is not treated as a current replay.

Browser execution, PostgreSQL staging, production graph integration, field transfer safety, private beta, and production activation remain separate evidence gates. This kit does not perform or claim them. No package installation, PostgreSQL startup, graph modification, field verification, or automatic push occurs.
