# Batch040 validation ledger and guarded scheduler

`web/scripts/validation-ledger.mjs` binds a reusable PASS to the check ID, source and test inputs, configuration, runner/tool version, runtime version, exact command identity, passing resource outcome, and SHA-256 of the evidence file. Build `sourceFingerprint` with `fingerprintValidationInputs({ sourceFiles, testFiles, configurationFiles })`; use `fingerprintFileSet` for configuration-only sets. Missing files fail fingerprinting. Older entries remain readable but cannot satisfy the new identity, so they are not reused as verified results.

Writes use a same-directory exclusive lock, a unique temporary file, file sync and atomic rename. A scheduler lock prevents a second guarded check from launching at the same time. The newest matching `RUNNING`, `FAIL`, `BLOCKED`, or `NOT_RUN` result prevents reuse of an earlier PASS. Missing or changed evidence fails reuse. Corrupt ledgers fail closed.

## Schedule one check

The JSON input contains identity and a literal argument array, resolved working directory, and declared non-secret environment map. Do not put credentials or shell snippets in it. `commandFingerprint` is SHA-256 over canonical JSON containing `argv`, `cwd`, and `environment`; changing any of these invalidates reuse.

```json
{
  "checkId": "offline-ui-regressions",
  "sourceFingerprint": "sha256-of-graph-and-pinned-evidence",
  "configFingerprint": "sha256-of-package-lock-and-relevant-config",
  "toolVersion": "batch040-regression-script-v1",
  "runtimeVersion": "node-vX / php-vY",
  "commandFingerprint": "sha256-of-argv-cwd-and-declared-environment",
  "command": {
    "operation": "offline-ui-tests",
    "diskPath": "/data/data/com.termux/files/home",
    "cancelPidFile": "/data/data/com.termux/files/home/.cache/biyahe/validation/run.pid",
    "cancelMatch": "offline-ui-tests",
    "logPath": "/data/data/com.termux/files/home/.cache/biyahe/validation/run.log",
    "argv": ["bash", "api/scripts/run-batch040-regressions.sh"],
    "cwd": "/data/data/com.termux/files/home/Biyahe",
    "environment": {"PYTHONDONTWRITEBYTECODE": "1"}
  }
}
```

Run it with `node web/scripts/validation-ledger.mjs run check.json`. The scheduler calls `api/scripts/run-bounded-process.sh` using the owned isolated process group and fixes the floors at 524288 KiB `MemAvailable` and 131072 KiB `SwapFree`, with five preflight samples five seconds apart and cancellation after the first live breach. Declared environment values cannot lower those limits. The guard launches one process group, terminates only that group on a breach, and retains its log. A failed preflight is recorded as `BLOCKED`; it is not a test failure and cannot be reused as PASS.

Use unique log and cancellation paths per execution. A `RUNNING` ledger entry left by process death is treated as interrupted and cannot reuse older evidence. Inspect its log; when the interrupted attempt is understood, close it explicitly with `node web/scripts/validation-ledger.mjs recover-interrupted check.json`. Recovery appends `NOT_RUN` and never infers PASS. Choose a fresh output path before a deliberate retry.

This schedules a command but does not make the command's assertions correct. Actual outcomes and evidence must be reviewed, and browser interaction, external PostgreSQL staging, route parity, field safety, and beta status remain separate gates.
