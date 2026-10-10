# Biyahe Batch044 portable verification kit v6

This kit pins repository revision `0697cca967af424a80afea046a7f0ab48581b597`. It includes the relevant source, fixtures, offline runner, and evidence reports. It excludes the SQLite graph, OSM PBF, PostgreSQL data, credentials, caches, and runtime dependencies.

## Run a mode

From this directory, run `bash verify-kit.sh MODE`. Available modes are:

| Mode | Work |
|---|---|
| `STATIC` | Provenance, coverage, comparator, Doctor, staging config/smoke, geometry and report-writer contracts, syntax, JSON, and diff checks |
| `FRONTEND` | Preview, commute card, Ride Mode, search, Can-I-Ride, ledger, TypeScript, and ESLint checks |
| `ADAPTER` | Focused walking adapter PHPUnit tests |
| `CORPUS` | Read-only replay against the pinned Cebu SQLite graph, if it is present and its SHA256 matches |
| `FULL` | Runs the modes in order and records blocked dependencies or resource gates per suite |

`verify-kit.sh` validates its manifest and every `SHA256SUMS` entry before invoking the runner. By default, commands use the included `source-snapshot`; Doctor V44 pins executable source separately from documentation checkpoints. `--repo-root CHECKOUT` is an optional exact-revision cross-check. `--output-root DIR` selects an evidence root; each run uses a unique directory and refuses to overwrite evidence.

The package includes checksum-verified output and resource logs referenced by the pinned Doctor report, relocated under `source-snapshot/evidence/` so report validation works without the original host cache.

Run an individual suite with `bash verify-kit.sh --mode FRONTEND --suite commute-card`. Corpus cases use zero-based indices 0–9 and can be bounded with `--corpus-cases 0` or `--corpus-cases 0,3`. External hosts may override the default graph location with `--graph-path /srv/biyahe/cebu-walk-central-visayas-20261007-v3.sqlite`. A selected subset is recorded as partial and never promoted to a full ten-case PASS. The graph path is passed through to Laravel after SHA256 verification, then SQLite `quick_check(1)` runs via a read-only immutable connection.

Each run writes an atomic `run-status.json`, individual command output, source/tool/command fingerprints, resource logs for guarded work, and an evidence checksum list. Interrupted `RUNNING` records recover to `NOT_RUN`; the runner never infers PASS from partial output. Guarded suites use five samples spaced five seconds apart, the fixed 524288 KiB memory and 131072 KiB swap floors, and first-breach cancellation. No database service is started.

## Dependencies and evidence boundaries

Python 3, Bash, `sha256sum`, `git` (only with `--repo-root`), and the repository-defined helper tools are detected before their suites run. Node dependencies are not installed by this kit. PHP/Laravel dependencies are not bundled. Missing tools or lockfile dependencies are reported as `BLOCKED_DEPENDENCY`; a resource refusal is `BLOCKED_RESOURCE`. `FULL` is only a sequence of these statuses and does not imply every external dependency was available.

The Cebu graph is expected at `~/.cache/biyahe/batch030/cebu-walk-central-visayas-20261007-v3.sqlite`, SHA256 `29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8`. For corpus mode, the owned-worker guard takes five resource samples before checking graph metadata or hashing the file. A fresh read-only SHA256 must match before Laravel opens SQLite. The graph is never rebuilt or modified and the runner never scans a PBF. A historical `9 found / 1 disconnected` result is not treated as a current replay.

The Linux Compose staging stack, explicit bootstrap gates, redacted API smoke tool, and external Playwright browser tests are included. Package execution itself never starts Compose or PostgreSQL. Real graph replay requires an operator-supplied graph artifact, five passing resource samples, and preinstalled PHP SQLite dependencies. The historical 9 found / 1 disconnected outcome is not a current run. Field transfer safety, private beta, and production activation remain independent evidence gates. See `docs/deployment/batch044-linux-staging-handoff.md` before any external staging action.

For the external browser workflow, install the pinned npm dependencies with `npm ci`, then install Chromium with `npx playwright install chromium`. Browser installation is separate from dependency installation. Run `npm run test:browser` against an isolated staging origin; actual saved-journey/Ride Mode checks require operator-supplied canonical place queries and a real planner result.
