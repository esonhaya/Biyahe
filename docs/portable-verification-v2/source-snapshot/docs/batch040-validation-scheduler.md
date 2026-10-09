# Batch040 validation ledger and guarded scheduler

`web/scripts/validation-ledger.mjs` now binds a reusable PASS to the check ID, source fingerprint, dependency/config fingerprint, runner/tool version, runtime version, exact command fingerprint, passing resource outcome, and SHA-256 of the evidence file. Older entries remain readable but cannot satisfy the new identity, so they are not reused as verified results.

Writes use a same-directory exclusive lock, a unique temporary file, file sync and atomic rename. A scheduler lock prevents a second guarded check from launching at the same time. The newest matching `RUNNING`, `FAIL`, `BLOCKED`, or `NOT_RUN` result prevents reuse of an earlier PASS. Missing or changed evidence fails reuse. Corrupt ledgers fail closed.

## Schedule one check

The JSON input contains identity and a literal argument array. Do not put credentials or shell snippets in it. `commandFingerprint` is SHA-256 over the canonical JSON `argv` array.

```json
{
  "checkId": "offline-ui-regressions",
  "sourceFingerprint": "sha256-of-graph-and-pinned-evidence",
  "configFingerprint": "sha256-of-package-lock-and-relevant-config",
  "toolVersion": "batch040-regression-script-v1",
  "runtimeVersion": "node-vX / php-vY",
  "commandFingerprint": "sha256-of-canonical-argv-array",
  "command": {
    "operation": "offline-ui-tests",
    "diskPath": "/data/data/com.termux/files/home",
    "cancelPidFile": "/data/data/com.termux/files/home/.cache/biyahe/validation/run.pid",
    "cancelMatch": "offline-ui-tests",
    "logPath": "/data/data/com.termux/files/home/.cache/biyahe/validation/run.log",
    "argv": ["bash", "api/scripts/run-batch040-regressions.sh"]
  }
}
```

Run it with `node web/scripts/validation-ledger.mjs run check.json`. The scheduler calls `api/scripts/run-bounded-process.sh` using the owned isolated process group and hard-codes the Batch040 floors: 524288 KiB `MemAvailable` and 131072 KiB `SwapFree`, sampled three times five seconds apart. It does not inherit a lower floor from the environment. The guard launches one process group, terminates only that group on a breach, and retains its log. A failed preflight is recorded as `BLOCKED`; it is not a test failure and cannot be reused as PASS.

Use unique log and cancellation paths per execution. A `RUNNING` ledger entry left by process death is treated as interrupted and cannot reuse older evidence. Inspect its log and choose a fresh output path before a deliberate retry.

This schedules a command but does not make the command's assertions correct. Actual outcomes and evidence must be reviewed, and browser interaction, external PostgreSQL staging, route parity, field safety, and beta status remain separate gates.
