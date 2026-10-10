# Batch041 test and resource plan

The unchanged hard floors are `MemAvailable >= 524288 KiB` and `SwapFree >= 131072 KiB`. Resource-gated commands require five preflight samples, five seconds apart, and use the existing owned-process-group guard. Run only one resource-intensive group at a time. For future guarded runs set `BIYAHE_RESOURCE_BREACH_SAMPLES=1`, so the guard cancels the owned worker and descendants on the first live floor breach. Never lower either floor, and do not repeat a blocked command until resource conditions materially change.

The light deterministic checks use pinned JSON or small fixtures. They do not scan the PBF, rebuild or open the SQLite graph, or contact PostgreSQL. The comparator fixtures use synthetic report-shaped data and are contract tests, not route evidence. The static evidence smoke is explicitly static-only.

## Recovery observations

Five spaced baseline samples passed both floors before guarded work:

| UTC | MemAvailable KiB | SwapFree KiB |
| --- | ---: | ---: |
| 2026-10-09T23:48:58Z | 1,050,908 | 658,932 |
| 2026-10-09T23:49:37Z | 1,044,400 | 572,408 |
| 2026-10-09T23:50:11Z | 969,164 | 451,028 |
| 2026-10-09T23:50:23Z | 1,108,260 | 268,084 |
| 2026-10-09T23:50:57Z | 1,199,744 | 290,700 |

The focused adapter PHPUnit suite passed 22 tests and 75 assertions under the owned-worker guard. Its peak process-tree RSS was 154,092 KiB; minimum live MemAvailable and SwapFree were 872,920 and 368,604 KiB. PostgreSQL RSS was zero. A first run found a mistaken expected status in the disconnected-only fixture; the fixture now expects `UNAVAILABLE`, and an added mixed case checks `PARTIAL` when one walking leg succeeds and another is disconnected.

The grouped frontend harness command was withheld by the five-sample preflight: minimum MemAvailable was 838,872 KiB and minimum SwapFree was 18,964 KiB. No frontend harness, typecheck, lint, or build command was launched afterward. Browser tests were not run because no browser test executable was available; static review is not visual verification.

The ten-case Cebu replay passed preflight, then the live SwapFree floor was breached at 64,988 KiB and again at 27,184 KiB one second later. The existing guard canceled the verified owned process group with SIGTERM after its configured two consecutive breach samples. Cancellation completed without escalation; peak worker-tree RSS was 49,984 KiB, PostgreSQL RSS was zero, and no corpus report was written. This is `BLOCKED_RESOURCE_ABORTED`, not a route failure or a pass. Do not retry under the same resource conditions. The recorded two-sample behavior is historical; future runs use one breach sample as stated above.

Owned-worker RSS was recorded by the guard. The active monitor did not capture per-worker `VmSwap`; the guard did record process swap inventory during preflight. PostgreSQL was never started, and no unrelated process was killed.

The current results, exact commands, statuses, and canonical command fingerprints are in [the machine-readable test plan](testing/batch041-test-plan.json) and [recovery validation record](../reports/batch041-recovery-validation.json). Historical 503/436 and 37/30 coverage describes stored records only. It is not geographic, population, sidewalk, or field coverage. The historical 9-found/1-disconnected corpus result is not a current replay.

The graph was verified by SHA256 against `29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8`; it was not opened or modified during this recovery. No PBF rescan or PostgreSQL startup occurred.
