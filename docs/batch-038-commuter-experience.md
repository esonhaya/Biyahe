# Batch 038: journey evidence and progress correction

## Existing capability map

| Area | Existing implementation | Batch 038 action |
| --- | --- | --- |
| Place discovery | Origin-first, debounced place search, keyboard selection, cancellation, retry | Reused; no duplicate search system |
| Planner and route comparison | Direct/transfer results, route filters, planner-provided ranking reasons | Ranking remains unchanged; evidence explanation added separately |
| Journey cards and map | Ordered commute steps, selected journey, source geometry only, uncertainty labels | Existing card now discloses route structure, evidence, and data gaps |
| Ride Mode | Local manual progress, optional foreground location, persisted session | Added a bounded previous-step action for correcting an accidental tap |
| Saved and offline journeys | Versioned local snapshots; cached view is distinct from a fresh plan | Reused; no storage-format change |
| Can-I-Ride | Existing API-backed yes/no/unknown flow | Reused; no second route engine |
| Validation | Individual harnesses and a static UX contract check | Added a local fingerprint-bound ledger; execution is subject to the device resource gate |

## Route evidence presentation

`web/src/lib/journeyExplanation.ts` builds a deterministic explanation from the returned journey. It reports the journey structure and planner reasons separately, then describes recorded direction, walking geometry, fare availability, stale records, and explicit warnings. It does not change planner ranking or derive new transit evidence. Straight-line distance is described as context only, and missing geometry does not produce a line.

Cards reveal the explanation in a native disclosure. The compact result keeps the existing at-a-glance boarding/get-off summary and transfer caution.

## Ride Mode progress correction

“Review previous step” reverses one supported manual transition while Ride Mode is active. It can return from walking to not-started, from riding to waiting-to-board, from a ride to its preceding walking step, or from a newly selected walking leg to the previous ride. It cannot move before the first step. The UI states that this edits the local guide only and does not confirm physical movement or location. Boarding-area confirmation now requires the walking step to have been started.

No Ride Mode storage migration is needed; the action does not change the persisted session format.

## Validation ledger

The dependency-free tool in `web/scripts/validation-ledger.mjs` stores runtime state at `~/.cache/biyahe/validation-ledger/batch038.json` by default. Set `BIYAHE_VALIDATION_LEDGER_PATH` to choose another local path. The Batch040 extension binds a result to a check ID, source fingerprint, config/dependency fingerprint, tool version, runtime version, and exact command fingerprint. `PASS`, `FAIL`, `BLOCKED`, and `NOT_RUN` are distinct. A newer blocked, failed, or interrupted result for the same identity prevents reuse of an older pass. Writes use a same-directory lock, temporary file, file sync, and atomic rename. Corrupt or unsupported ledgers fail closed.

Record and query examples:

```sh
node web/scripts/validation-ledger.mjs record check-result.json
node web/scripts/validation-ledger.mjs check check-identity.json
node web/scripts/validation-ledger.mjs run guarded-check.json
```

`record` requires the check/source/config/tool/runtime/command identity plus status, result, and resource outcome; PASS requires a file-backed evidence hash. `run` accepts a literal argv array and delegates execution to the owned process-group guard with fixed 512 MiB MemAvailable and 128 MiB SwapFree floors. See `docs/batch040-validation-scheduler.md`. A scheduled command still needs correct assertions; the ledger does not make a test PASS unless the command exits successfully and its evidence file is bound by SHA-256.

## Evidence and validation limits

The offline Cebu graph and 10-case corpus figures in the Batch 037 baseline are carried forward, not recomputed here. The Android device's observed SwapFree is below the configured 131072 KiB floor. The new focused harnesses, TypeScript check, lint, and browser interaction checks were not run under that condition. No graph, PostgreSQL, source PBF, or field evidence was changed or produced by this batch.
