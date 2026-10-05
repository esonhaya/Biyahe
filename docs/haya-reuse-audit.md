# Haya reuse audit

Batch 003 inspected the standalone Haya Doctor repository at
`/data/data/com.termux/files/home/BoardPrep-Doctor`. It was read-only during
the audit. Its working tree was clean before and after inspection, and Biyahe
does not depend on it at runtime, build time, or test time.

## Reviewed candidates

| Candidate | Classification | Decision |
| --- | --- | --- |
| `tools/Doctor/DTO/CheckResult.php` | ADAPT | Adopted the small idea of immutable, structured check results in `api/app/Support/Diagnostics/DoctorCheckResult.php`. Biyahe keeps its Laravel command and adds the `BLOCKED` state needed for database environments. |
| `tools/Doctor/DTO/CheckStatus.php` | PATTERN_ONLY | Its deterministic status normalization informed Biyahe's explicit `PASS`, `WARN`, `FAIL`, and `BLOCKED` vocabulary; the standalone class was not copied. |
| `tools/Doctor/Engine/CheckRunner.php` | PATTERN_ONLY | Exception isolation and duplicate check identity are useful patterns, but Biyahe's checks need Laravel's database and schema container. The existing command remains the better host. |
| `tools/Doctor/Registry/CheckRegistry.php` | PATTERN_ONLY | Deterministic registration and ordering are useful for a future check registry, but a registry would add indirection without reducing Batch 003 routing work. |
| `tools/Doctor/Output/V2ConsoleWriter.php` | HAYA_SPECIFIC | Its project/doctor scopes and remediation output target Haya's snapshot engine, not Biyahe's database Doctor. |
| `tools/Doctor/Diagnostics/` | PATTERN_ONLY | Structured findings and summaries are sound, but Biyahe's current command already reports actionable database labels and must distinguish environmental blocking. |
| `tools/Doctor/Self/Checks/` | HAYA_SPECIFIC | These checks depend on Haya's source snapshot and internal metrics. None were copied. |
| `docs/HD-001-Provenance.md` | PATTERN_ONLY | The explicit source/treatment decision record informed this audit document. No Haya-specific provenance text was reused as application logic. |

## Adopted destination

`DoctorCheckResult` and `DoctorReport` provide deterministic structured
aggregation inside Biyahe Doctor V3. They do not replace the existing
database-aware command, and no symlink, Composer dependency, subtree, or
runtime import points back to Haya.

## License and provenance

The audit did not copy Haya source files wholesale. The adopted behavior is a
small independently written adapter for Biyahe's existing Doctor command.
The Haya repository and its history remain untouched. Cebu data licensing is
documented separately in `docs/data-sources.md`; it remains ODbL-attributed
OpenStreetMap-derived data and is not mixed with Haya code.
