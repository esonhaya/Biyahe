#!/usr/bin/env python3
"""Build a hash-pinned Batch042 verification kit with explicit offline test modes."""

from __future__ import annotations

import argparse
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path, PurePosixPath
import shutil
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
DEFAULT_OUTPUT = ROOT / "docs/portable-verification-v4"

FILES = [
    "api/composer.json", "api/composer.lock", "api/app/Console/Commands/VerifyOfflineWalkingCorpus.php",
    "api/app/Http/Controllers/Api/DevelopmentOfflineWalkingPreviewController.php",
    "api/app/Services/Routing/OfflineJourneyWalkingPreview.php",
    "api/app/Services/Routing/OfflinePedestrianGraphRouter.php",
    "api/app/Services/Routing/OfflinePedestrianRouteGeometryContract.php",
    "api/app/Services/Routing/OfflineWalkingPreviewException.php",
    "api/config/offline_walking_preview.php", "api/routes/api.php", "api/phpunit.xml",
    "api/scripts/check-batch042-static-syntax.py", "api/scripts/compare-offline-corpus.py", "api/scripts/evidence_provenance.py",
    "api/scripts/offline-evidence-smoke.py", "api/scripts/package-batch041-verification-kit.py",
    "api/scripts/run-bounded-process.sh", "api/scripts/run-portable-verification.py",
    "api/scripts/test_portable_verification.py",
    "api/scripts/run-portable-verification.sh", "api/scripts/test_offline_route_geometry_contract.php",
    "api/scripts/summarize-offline-coverage.py", "api/scripts/test_compare_offline_corpus.py",
    "api/scripts/test_evidence_provenance.py", "api/scripts/test_offline_evidence_smoke.py",
    "api/scripts/test_summarize_offline_coverage.py", "api/scripts/test_validate_batch041_doctor.py",
    "api/scripts/test_validate_batch042_doctor.py", "api/scripts/validate-batch041-doctor.py",
    "api/scripts/validate-batch041-provenance.py", "api/scripts/validate-batch042-doctor.py",
    "api/scripts/refresh-batch042-doctor.py",
    "api/tests/Support/OfflinePedestrianGraphFixture.php",
    "api/tests/Unit/OfflineJourneyWalkingPreviewTest.php",
    "api/tests/Unit/OfflinePedestrianGraphRouterTest.php",
    "api/tests/Unit/OfflinePedestrianRouteGeometryContractTest.php",
    "docs/batch040-validation-scheduler.md", "docs/batch041-test-and-resource-plan.md",
    "docs/offline-walking-preview-development.md",
    "docs/reports/batch031-cebu-graph-quality-and-member-review.json",
    "docs/reports/batch031-cebu-restriction-audit.json",
    "docs/reports/batch031-cebu-restriction-source-scan.json",
    "docs/reports/batch032-cebu-coverage.json", "docs/reports/batch032-cebu-doctor-v32.json",
    "docs/reports/batch040-coverage-diagnostics.json",
    "docs/reports/batch041-doctor-v41.json", "docs/reports/batch041-evidence-provenance.json",
    "docs/reports/batch041-recovery-validation.json", "docs/reports/batch041-evidence-reconciliation.md",
    "docs/reports/batch042-doctor-v42.json", "docs/reports/batch042-frontend-architecture.md",
    "docs/reports/batch042-work-ledger.json", "docs/testing/batch042-test-plan.json",
    "docs/testing/batch041-test-plan.json",
    "web/package.json", "web/package-lock.json", "web/tsconfig.json", "web/eslint.config.mjs",
    "web/scripts/can-i-ride-harness.mjs",
    "web/scripts/commute-card-harness.mjs", "web/scripts/offline-walking-preview-harness.mjs",
    "web/scripts/offline-walking-preview-state-harness.mjs", "web/scripts/ride-mode-harness.mjs",
    "web/scripts/ux-contract-harness.mjs", "web/scripts/validation-ledger-harness.mjs",
    "web/scripts/request-gate-harness.mjs", "web/scripts/search-keyboard-harness.mjs",
    "web/scripts/place-search-harness.mjs", "web/scripts/validation-ledger.mjs",
    "web/src/app/page.tsx", "web/src/app/page.module.css",
    "web/src/components/CommuteCard.tsx", "web/src/components/DisruptionNotice.tsx",
    "web/src/components/MapView.tsx", "web/src/components/ReplanPanel.tsx", "web/src/components/RideMode.tsx",
    "web/src/lib/api.ts", "web/src/lib/canIRidePresentation.ts", "web/src/lib/commuteInstructions.ts",
    "web/src/lib/journeyExplanation.ts", "web/src/lib/journeyMapPresentation.ts",
    "web/src/lib/latestPlaceSearch.ts", "web/src/lib/offlineWalkingPreviewState.ts",
    "web/src/lib/requestGate.ts", "web/src/lib/rideMode.ts", "web/src/lib/savedJourneys.ts",
    "web/src/lib/searchKeyboard.ts",
]

VERIFY_SCRIPT = r'''#!/usr/bin/env bash
set -euo pipefail
kit_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
mode=STATIC
suite=""
repo_root=""
output_root="${HOME}/.cache/biyahe/batch042/portable-verification"
while (($#)); do
  case "$1" in
    STATIC|FRONTEND|ADAPTER|CORPUS|FULL) mode="$1"; shift ;;
    --mode) (($# >= 2)) || { printf '%s\n' 'ERROR: --mode requires a value.' >&2; exit 2; }; mode="$2"; shift 2 ;;
    --suite) (($# >= 2)) || { printf '%s\n' 'ERROR: --suite requires a value.' >&2; exit 2; }; suite="$2"; shift 2 ;;
    --repo-root) (($# >= 2)) || { printf '%s\n' 'ERROR: --repo-root requires a path.' >&2; exit 2; }; repo_root="$2"; shift 2 ;;
    --output-root) (($# >= 2)) || { printf '%s\n' 'ERROR: --output-root requires a path.' >&2; exit 2; }; output_root="$2"; shift 2 ;;
    --help) printf 'Usage: %s [STATIC|FRONTEND|ADAPTER|CORPUS|FULL] [--suite SUITE_ID] [--repo-root PINNED_CHECKOUT] [--output-root DIR]\n' "$0"; exit 0 ;;
    *) printf 'ERROR: unknown option: %s\n' "$1" >&2; exit 2 ;;
  esac
done
case "$mode" in STATIC|FRONTEND|ADAPTER|CORPUS|FULL) ;; *) printf 'ERROR: unsupported mode: %s\n' "$mode" >&2; exit 2 ;; esac
for tool in sha256sum python3; do
  command -v "$tool" >/dev/null 2>&1 || { printf 'ERROR: missing required tool: %s\n' "$tool" >&2; exit 127; }
done
cd "$kit_dir"
python3 - "$kit_dir" <<'PY'
import json, sys
from pathlib import Path, PurePosixPath
root=Path(sys.argv[1]).resolve()
manifest=json.loads((root/'manifest.json').read_text())
if manifest.get('format') != 'biyahe-portable-offline-verification-kit-v4' or manifest.get('batch') != 42:
    raise SystemExit('ERROR: unsupported portable verification manifest')
listed=set()
for n,line in enumerate((root/'SHA256SUMS').read_text().splitlines(),1):
    if len(line)<67 or line[64:66]!='  ': raise SystemExit(f'ERROR: malformed checksum line {n}')
    digest,name=line[:64],line[66:]
    p=PurePosixPath(name)
    if len(digest)!=64 or any(c not in '0123456789abcdef' for c in digest) or p.is_absolute() or '..' in p.parts or name in listed:
        raise SystemExit(f'ERROR: unsafe checksum entry {n}')
    listed.add(name)
    target=root.joinpath(*p.parts)
    if target.is_symlink() or not target.is_file(): raise SystemExit(f'ERROR: missing or symlinked package file: {name}')
actual={p.relative_to(root).as_posix() for p in root.rglob('*') if p.is_file() and p.name!='SHA256SUMS'}
if actual!=listed: raise SystemExit(f'ERROR: checksum inventory mismatch unlisted={sorted(actual-listed)} missing={sorted(listed-actual)}')
for relative in manifest.get('included_files',[]):
    p=PurePosixPath(relative)
    if p.is_absolute() or '..' in p.parts or not root.joinpath(*p.parts).is_file(): raise SystemExit(f'ERROR: missing or unsafe included path {relative}')
for relative in manifest.get('required_checkout_paths',[]):
    p=PurePosixPath(relative)
    if p.is_absolute() or '..' in p.parts: raise SystemExit(f'ERROR: unsafe checkout path {relative}')
for relative,digest in manifest.get('executable_source_fingerprints',{}).items():
    p=PurePosixPath(relative)
    if p.is_absolute() or '..' in p.parts or len(digest)!=64: raise SystemExit(f'ERROR: unsafe executable fingerprint {relative}')
    source=root/'source-snapshot'
    for part in p.parts: source=source/part
    if not source.is_file() or hashlib.sha256(source.read_bytes()).hexdigest()!=digest: raise SystemExit(f'ERROR: executable source fingerprint mismatch: {relative}')
if manifest.get('large_artifacts_included') is not False: raise SystemExit('ERROR: large source artifacts must not be bundled')
PY
sha256sum -c SHA256SUMS
python3 -m json.tool manifest.json >/dev/null
source_revision="$(python3 -c 'import json; print(json.load(open("manifest.json"))["executable_source_revision"])')"
if [[ -z "$repo_root" ]]; then
  repo_root="$kit_dir/source-snapshot"
else
  command -v git >/dev/null 2>&1 || { printf '%s\n' 'ERROR: git is required with --repo-root.' >&2; exit 127; }
  repo_root="$(cd "$repo_root" && pwd)"
  python3 - "$kit_dir" "$repo_root" <<'PY'
import hashlib,json,subprocess,sys
from pathlib import Path,PurePosixPath
kit,repo=map(lambda s:Path(s).resolve(),sys.argv[1:])
m=json.loads((kit/'manifest.json').read_text())
actual=subprocess.check_output(['git','-C',str(repo),'rev-parse','HEAD'],text=True).strip()
if actual!=m.get('repository_revision'): raise SystemExit(f'ERROR: expected source revision {m.get("repository_revision")}, got {actual}')
source=m.get('executable_source_revision')
if subprocess.run(['git','-C',str(repo),'merge-base','--is-ancestor',source,actual]).returncode: raise SystemExit('ERROR: executable source revision is not an ancestor of the checkout')
for relative in m['required_checkout_paths']:
    p=PurePosixPath(relative)
    checkout=repo.joinpath(*p.parts)
    packaged=kit/'source-snapshot'
    for part in p.parts: packaged=packaged/part
    if not checkout.is_file() or not packaged.is_file(): raise SystemExit(f'ERROR: missing checkout or snapshot file {relative}')
    if hashlib.sha256(checkout.read_bytes()).digest()!=hashlib.sha256(packaged.read_bytes()).digest(): raise SystemExit(f'ERROR: source snapshot differs from pinned checkout: {relative}')
for relative,expected in m.get('executable_source_fingerprints',{}).items():
    checkout=repo.joinpath(*PurePosixPath(relative).parts)
    committed=subprocess.check_output(['git','-C',str(repo),'show',f'{source}:{relative}'])
    if not checkout.is_file() or hashlib.sha256(checkout.read_bytes()).hexdigest()!=expected or hashlib.sha256(committed).hexdigest()!=expected:
        raise SystemExit(f'ERROR: executable source differs from its pinned revision: {relative}')
PY
fi
export PYTHONDONTWRITEBYTECODE=1
runner_args=(--mode "$mode" --repo-root "$repo_root" --output-root "$output_root" --source-revision "$source_revision")
if [[ -n "$suite" ]]; then runner_args+=(--suite "$suite"); fi
exec python3 "$repo_root/api/scripts/run-portable-verification.py" "${runner_args[@]}"
'''

README = """# Biyahe Batch042 portable verification kit v4

This kit pins repository revision `@REVISION@`. It includes the relevant source, fixtures, offline runner, and evidence reports. It excludes the SQLite graph, OSM PBF, PostgreSQL data, credentials, caches, and runtime dependencies.

## Run a mode

From this directory, run `bash verify-kit.sh MODE`. Available modes are:

| Mode | Work |
|---|---|
| `STATIC` | Provenance, coverage, comparator, Doctor, geometry, syntax, JSON, and diff checks |
| `FRONTEND` | Preview, commute card, Ride Mode, search, Can-I-Ride, ledger, TypeScript, and ESLint checks |
| `ADAPTER` | Focused walking adapter PHPUnit tests |
| `CORPUS` | Read-only replay against the pinned Cebu SQLite graph, if it is present and its SHA256 matches |
| `FULL` | Runs the modes in order and records blocked dependencies or resource gates per suite |

`verify-kit.sh` validates its manifest and every `SHA256SUMS` entry before invoking the runner. By default, commands use the included `source-snapshot`. Add `--repo-root CHECKOUT` to require the exact Git revision in the manifest and byte-compare every pinned source path. `--output-root DIR` selects a new evidence root; run directories are unique and existing outputs are never overwritten.

Run one suite with `bash verify-kit.sh --mode FRONTEND --suite commute-card`. Suite identifiers are printed by each mode and recorded in `run-status.json`; a suite unavailable in the selected mode is rejected before execution.

Each run writes an atomic `run-status.json`, individual command output, source/tool/command fingerprints, resource logs for guarded work, and an evidence checksum list. Interrupted `RUNNING` records recover to `NOT_RUN`; the runner never infers PASS from partial output. Guarded suites use five samples spaced five seconds apart, the fixed 524288 KiB memory and 131072 KiB swap floors, and first-breach cancellation. No database service is started.

## Dependencies and evidence boundaries

Python 3, Bash, `sha256sum`, `git` (only with `--repo-root`), and the repository-defined helper tools are detected before their suites run. Node dependencies are not installed by this kit. PHP/Laravel dependencies are not bundled. Missing tools or lockfile dependencies are reported as `BLOCKED_DEPENDENCY`; a resource refusal is `BLOCKED_RESOURCE`. `FULL` is only a sequence of these statuses and does not imply every external dependency was available.

The Cebu graph is expected at `~/.cache/biyahe/batch030/cebu-walk-central-visayas-20261007-v3.sqlite`, SHA256 `29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8`. The runner hashes it before replay and writes a fresh corpus report. It never rebuilds or modifies the graph and never scans a PBF. A historical `9 found / 1 disconnected` result is not treated as a current replay.

Browser execution, PostgreSQL staging, production graph integration, field transfer safety, private beta, and production activation remain separate evidence gates. This kit does not perform or claim them. No package installation, PostgreSQL startup, graph modification, field verification, or automatic push occurs.
"""


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    args = parser.parse_args(argv)
    output = args.output.resolve()
    try:
        if output.exists():
            raise ValueError(f"refusing to overwrite existing package directory: {output}")
        status = subprocess.check_output(["git", "-C", str(ROOT), "status", "--porcelain"], text=True)
        tracked_changes = [line for line in status.splitlines() if not line.endswith(("docs/reports/batch033-doctor-v33.json", "docs/reports/batch034-doctor-v34-coverage.json", "docs/reports/batch034-doctor-v34-current.json", "docs/reports/batch034-doctor-v34-final-evidence.json", "docs/reports/batch034-doctor-v34-final.json", "docs/reports/batch034-doctor-v34.json", "docs/reports/batch039-doctor-v39.json"))]
        if tracked_changes:
            raise ValueError("refusing to package from a dirty tracked checkout")
        revision = subprocess.check_output(["git", "-C", str(ROOT), "rev-parse", "HEAD"], text=True).strip()
        if len(revision) != 40:
            raise ValueError("Git did not return a full repository revision")
        doctor = json.loads((ROOT / "docs/reports/batch042-doctor-v42.json").read_text(encoding="utf-8"))
        executable_revision = doctor.get("source_revision", {}).get("executable")
        if not isinstance(executable_revision, str) or len(executable_revision) != 40:
            raise ValueError("Doctor V42 must pin a full executable source revision before packaging")
        if subprocess.run(["git", "-C", str(ROOT), "merge-base", "--is-ancestor", executable_revision, revision], check=False).returncode:
            raise ValueError("Doctor V42 executable source revision is not an ancestor of the package checkpoint")
        executable_fingerprints = doctor.get("source_fingerprints")
        if not isinstance(executable_fingerprints, dict) or not executable_fingerprints:
            raise ValueError("Doctor V42 executable source fingerprints are required before packaging")
        missing_from_kit = sorted(set(executable_fingerprints) - set(FILES))
        if missing_from_kit:
            raise ValueError(f"portable kit omits executable fingerprint inputs: {', '.join(missing_from_kit)}")
        for relative, expected_digest in executable_fingerprints.items():
            source = ROOT / relative
            committed = subprocess.check_output(["git", "-C", str(ROOT), "show", f"{executable_revision}:{relative}"])
            if not source.is_file() or sha256(source) != expected_digest or hashlib.sha256(committed).hexdigest() != expected_digest:
                raise ValueError(f"executable source fingerprint is not identical to the pinned commit: {relative}")
        missing = [relative for relative in FILES if not (ROOT / relative).is_file()]
        if missing:
            raise ValueError(f"required pinned source files are missing: {', '.join(missing)}")

        output.mkdir(parents=True, exist_ok=False)
        snapshot_root = output / "source-snapshot"
        for relative in FILES:
            source = ROOT / relative
            destination = snapshot_root / PurePosixPath(relative)
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(source, destination)

        manifest = {
            "format": "biyahe-portable-offline-verification-kit-v4",
            "batch": 42,
            "repository_revision": revision,
            "executable_source_revision": executable_revision,
            "executable_source_fingerprints": executable_fingerprints,
            "source_snapshot_created_at": datetime.now(timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z"),
            "large_artifacts_included": False,
            "verification_modes": ["STATIC", "FRONTEND", "ADAPTER", "CORPUS", "FULL"],
            "entrypoint": "verify-kit.sh MODE",
            "artifact_references": {
                "graph": {"name": "cebu-walk-central-visayas-20261007-v3.sqlite", "sha256": "29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8"},
                "source_pbf": {"name": "central_visayas-20261007.osm.pbf", "sha256": "787cac20ecc586b754b1a464643a040566d486457c6a37bc0b11ce683bc66d4d", "needed_for_offline_verification": False},
                "batch024_backup": {"name": "biyahe-batch024-20261008.dump", "sha256": "e55af400fde88d0a3434b194e0625e204eb0a2726374eabb8286c152b11a4a92", "needed_for_no_database_smoke": False}
            },
            "evidence": {
                "coverage_report": {"path": "docs/reports/batch032-cebu-coverage.json", "sha256": sha256(ROOT / "docs/reports/batch032-cebu-coverage.json"), "status": "HISTORICAL_SOURCE_BOUND"},
                "provenance_registry": {"path": "docs/reports/batch041-evidence-provenance.json", "sha256": sha256(ROOT / "docs/reports/batch041-evidence-provenance.json"), "status": "HISTORICAL_SOURCE_BOUND"},
                "graph_fingerprint": doctor.get("evidence", {}).get("graph", {}).get("expected_fingerprint", "UNKNOWN"),
                "graph_fingerprint_status": doctor.get("evidence", {}).get("graph", {}).get("fingerprint_status", "UNKNOWN")
            },
            "runtime_requirements": {"python": "Python 3.10+ standard library", "bash": "required", "git": "required only with --repo-root", "sha256sum": "required", "php_laravel": "required only for adapter PHPUnit and real graph replay", "node": "Node.js with --experimental-strip-types for TypeScript harnesses", "npm_dependencies": "preinstalled from web/package-lock.json for typecheck and ESLint", "composer_dependencies": "preinstalled from api/composer.lock for PHPUnit and corpus replay"},
            "verification_commands": [
                "bash verify-kit.sh STATIC",
                "bash verify-kit.sh FRONTEND",
                "bash verify-kit.sh ADAPTER",
                "bash verify-kit.sh CORPUS",
                "bash verify-kit.sh FULL",
                "bash verify-kit.sh --mode FRONTEND --suite commute-card"
            ],
            "expected_output_formats": ["biyahe-offline-evidence-smoke-v1", "biyahe-evidence-provenance-validation-v1", "biyahe-doctor-v42-validation-v1", "biyahe-portable-verification-run-v1", "biyahe-offline-walking-corpus-v1"],
            "expected_stored_coverage": {"stops": 503, "stops_snappable_within_25m": 436, "places": 37, "places_snappable_within_25m": 30, "transfers_unverified": 2, "historical_corpus_cases": 10, "historical_routes_found": 9, "historical_routes_disconnected": 1},
            "resource_floors_kib": {"memavailable_minimum": 524288, "swapfree_minimum": 131072, "sustained_samples": 5, "sample_spacing_seconds": 5},
            "required_checkout_paths": FILES,
            "included_files": [f"source-snapshot/{relative}" for relative in FILES],
            "execution_claims": {"package_generation": "COMPLETED", "local_verification": "NOT_RUN_AT_PACKAGE_TIME", "real_graph_replay": "NOT_RUN", "external_host_execution": "NOT_RUN", "postgresql": "NOT_RUN", "browser": "NOT_RUN", "field_verification": "NOT_RUN", "production_activation": "NO"}
        }
        (output / "manifest.json").write_text(json.dumps(manifest, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
        (output / "README.md").write_text(README.replace("@REVISION@", revision), encoding="utf-8")
        (output / "verify-kit.sh").write_text(VERIFY_SCRIPT, encoding="utf-8")
        (output / "verify-kit.sh").chmod(0o755)
        inventory = []
        for path in sorted(p for p in output.rglob("*") if p.is_file() and p.name != "SHA256SUMS"):
            inventory.append(f"{sha256(path)}  {path.relative_to(output).as_posix()}")
        (output / "SHA256SUMS").write_text("\n".join(inventory) + "\n", encoding="utf-8")
        print(f"PACKAGE={output}")
        print(f"SOURCE_REVISION={revision}")
        print(f"PACKAGE_FILES={len(inventory)}")
        return 0
    except (OSError, ValueError, subprocess.CalledProcessError) as error:
        print(f"PACKAGE_ERROR={error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
