#!/usr/bin/env python3
"""Build a hash-pinned, no-database Batch041 verification kit."""

from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath
import shutil
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
DEFAULT_OUTPUT = ROOT / "docs/portable-verification-v3"

FILES = [
    "api/composer.json", "api/composer.lock", "api/app/Console/Commands/VerifyOfflineWalkingCorpus.php",
    "api/app/Http/Controllers/Api/DevelopmentOfflineWalkingPreviewController.php",
    "api/app/Services/Routing/OfflineJourneyWalkingPreview.php",
    "api/app/Services/Routing/OfflinePedestrianGraphRouter.php",
    "api/app/Services/Routing/OfflinePedestrianRouteGeometryContract.php",
    "api/app/Services/Routing/OfflineWalkingPreviewException.php",
    "api/config/offline_walking_preview.php", "api/routes/api.php",
    "api/scripts/compare-offline-corpus.py", "api/scripts/evidence_provenance.py",
    "api/scripts/offline-evidence-smoke.py", "api/scripts/package-batch041-verification-kit.py",
    "api/scripts/test_offline_route_geometry_contract.php",
    "api/scripts/summarize-offline-coverage.py", "api/scripts/test_compare_offline_corpus.py",
    "api/scripts/test_evidence_provenance.py", "api/scripts/test_offline_evidence_smoke.py",
    "api/scripts/test_summarize_offline_coverage.py", "api/scripts/test_validate_batch041_doctor.py",
    "api/scripts/validate-batch041-doctor.py", "api/scripts/validate-batch041-provenance.py",
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
    "docs/reports/batch041-recovery-validation.json",
    "docs/reports/batch041-evidence-reconciliation.md", "docs/testing/batch041-test-plan.json",
    "web/package.json", "web/package-lock.json", "web/scripts/commute-card-harness.mjs",
    "web/scripts/offline-walking-preview-harness.mjs", "web/scripts/ride-mode-harness.mjs",
    "web/scripts/ux-contract-harness.mjs", "web/scripts/validation-ledger-harness.mjs",
    "web/scripts/search-keyboard-harness.mjs",
    "web/scripts/validation-ledger.mjs", "web/src/app/page.tsx",
    "web/src/components/CommuteCard.tsx", "web/src/components/RideMode.tsx",
    "web/src/lib/api.ts", "web/src/lib/commuteInstructions.ts",
    "web/src/lib/journeyExplanation.ts", "web/src/lib/rideMode.ts", "web/src/lib/savedJourneys.ts",
]

VERIFY_SCRIPT = r'''#!/usr/bin/env bash
set -euo pipefail
kit_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root=""
while (($#)); do
  case "$1" in
    --repo-root) (($# >= 2)) || { printf '%s\n' 'ERROR: --repo-root requires a path.' >&2; exit 2; }; repo_root="$2"; shift 2 ;;
    --help) printf 'Usage: %s [--repo-root CHECKOUT]\n' "$0"; exit 0 ;;
    *) printf 'ERROR: unknown option: %s\n' "$1" >&2; exit 2 ;;
  esac
done
for tool in sha256sum python3 git; do
  command -v "$tool" >/dev/null 2>&1 || { printf 'ERROR: missing required tool: %s\n' "$tool" >&2; exit 127; }
done
if [[ -z "$repo_root" ]]; then repo_root="$(cd "$kit_dir/../.." && pwd)"; fi
repo_root="$(cd "$repo_root" && pwd)"
cd "$kit_dir"
python3 - "$kit_dir" <<'PY'
import json, sys
from pathlib import Path, PurePosixPath
root=Path(sys.argv[1]).resolve()
manifest=json.loads((root/'manifest.json').read_text())
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
PY
sha256sum -c SHA256SUMS
python3 -m json.tool manifest.json >/dev/null
python3 - "$kit_dir" "$repo_root" <<'PY'
import hashlib,json,subprocess,sys
from pathlib import Path,PurePosixPath
kit,repo=map(lambda s:Path(s).resolve(),sys.argv[1:])
m=json.loads((kit/'manifest.json').read_text())
actual=subprocess.check_output(['git','-C',str(repo),'rev-parse','HEAD'],text=True).strip()
if actual!=m.get('repository_revision'): raise SystemExit(f'ERROR: expected source revision {m.get("repository_revision")}, got {actual}')
for relative in m['required_checkout_paths']:
    p=PurePosixPath(relative)
    checkout=repo.joinpath(*p.parts)
    packaged=kit/'source-snapshot'
    for part in p.parts: packaged=packaged/part
    if not checkout.is_file() or not packaged.is_file(): raise SystemExit(f'ERROR: missing checkout or snapshot file {relative}')
    if hashlib.sha256(checkout.read_bytes()).digest()!=hashlib.sha256(packaged.read_bytes()).digest(): raise SystemExit(f'ERROR: source snapshot differs from pinned checkout: {relative}')
if m.get('large_artifacts_included') is not False: raise SystemExit('ERROR: large source artifacts must not be bundled')
PY
export PYTHONDONTWRITEBYTECODE=1
smoke_output="$(python3 source-snapshot/api/scripts/offline-evidence-smoke.py)"
printf '%s\n' "$smoke_output" | python3 -c 'import json,sys; r=json.load(sys.stdin); valid=(r.get("status")=="PASS_STATIC_EVIDENCE_ONLY" and r.get("execution_boundaries",{}).get("sqlite_opened") is False and r.get("stored_corpus",{}).get("current_replay_executed") is False); print("NO_DATABASE_SMOKE=PASS_STATIC_EVIDENCE_ONLY" if valid else "ERROR: no-database smoke output is incomplete or claims execution"); sys.exit(0 if valid else 1)'
printf 'PORTABLE_KIT_STATIC_VERIFICATION=PASS\nREAL_GRAPH_REPLAY=NOT_RUN\nPOSTGRESQL_STAGING=NOT_RUN\nEXTERNAL_HOST_EXECUTION=NOT_RUN\nFIELD_VERIFICATION=NOT_RUN\n'
'''

README = """# Biyahe Batch041 portable evidence and adapter kit v3

This package pins repository revision `@REVISION@` and carries the relevant PHP adapter, frontend contract, tests, reports, and provenance registry as a reviewable source snapshot. The SQLite graph, OSM PBF, PostgreSQL dump, credentials, databases, and runtime caches are **not included**.

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
""".replace("@REVISION@", "{revision}")


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
            "format": "biyahe-portable-offline-verification-kit-v3",
            "batch": 41,
            "repository_revision": revision,
            "source_snapshot": "2026-10-07T01:00:21Z",
            "large_artifacts_included": False,
            "artifact_references": {
                "graph": {"name": "cebu-walk-central-visayas-20261007-v3.sqlite", "sha256": "29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8"},
                "source_pbf": {"name": "central_visayas-20261007.osm.pbf", "sha256": "787cac20ecc586b754b1a464643a040566d486457c6a37bc0b11ce683bc66d4d", "needed_for_offline_verification": False},
                "batch024_backup": {"name": "biyahe-batch024-20261008.dump", "sha256": "e55af400fde88d0a3434b194e0625e204eb0a2726374eabb8286c152b11a4a92", "needed_for_no_database_smoke": False}
            },
            "evidence": {
                "coverage_report_sha256": "6caf101177904bee6fda1b12cab9c5cda36ddaf7ecc08b0d9778c0517123c81b",
                "provenance_registry_sha256": "882fd1ae2c7ab8f2e1edab87c9122911f62c4568513bbe6c8875263958dc1bb6",
                "graph_fingerprint": "a0d9f1cb8289cf1f9bde3905d1ad8a57124ee41120153b4efc1f5b31e4c03ecd"
            },
            "runtime_requirements": {"python": "Python 3 standard library", "bash": "required", "git": "required for revision identity", "sha256sum": "required", "php_laravel": "required only for adapter PHPUnit and real graph replay", "node": "required only for web harnesses"},
            "verification_commands": [
                "bash docs/portable-verification-v3/verify-kit.sh --repo-root \"$CHECKOUT\"",
                "python3 api/scripts/validate-batch041-provenance.py",
                "python3 api/scripts/validate-batch041-doctor.py",
                "python3 api/scripts/test_evidence_provenance.py",
                "python3 api/scripts/test_summarize_offline_coverage.py",
                "python3 api/scripts/test_offline_evidence_smoke.py",
                "python3 api/scripts/test_compare_offline_corpus.py",
                "python3 api/scripts/test_validate_batch041_doctor.py",
                "php api/scripts/test_offline_route_geometry_contract.php",
                "(cd api && php artisan test --filter='OfflinePedestrianRouteGeometryContractTest|OfflinePedestrianGraphRouterTest|OfflineJourneyWalkingPreviewTest')",
                "node --experimental-strip-types web/scripts/offline-walking-preview-harness.mjs",
                "node --experimental-strip-types web/scripts/ride-mode-harness.mjs",
                "node --experimental-strip-types web/scripts/commute-card-harness.mjs",
                "node scripts/ux-contract-harness.mjs",
                "node scripts/validation-ledger-harness.mjs",
                "node --experimental-strip-types web/scripts/search-keyboard-harness.mjs",
                "(cd api && php artisan biyahe:pedestrian:verify-offline-corpus --output=\"$RUN_ROOT/offline-corpus.json\")"
            ],
            "expected_output_formats": ["biyahe-offline-evidence-smoke-v1", "biyahe-evidence-provenance-validation-v1", "biyahe-doctor-v41-validation-v1", "biyahe-offline-walking-corpus-v1"],
            "expected_stored_coverage": {"stops": 503, "stops_snappable_within_25m": 436, "places": 37, "places_snappable_within_25m": 30, "transfers_unverified": 2, "historical_corpus_cases": 10, "historical_routes_found": 9, "historical_routes_disconnected": 1},
            "resource_floors_kib": {"memavailable_minimum": 524288, "swapfree_minimum": 131072, "sustained_samples": 5, "sample_spacing_seconds": 5},
            "required_checkout_paths": FILES,
            "included_files": [f"source-snapshot/{relative}" for relative in FILES],
            "execution_claims": {"termux_static_verification": "NOT_RUN_AT_PACKAGE_TIME", "real_graph_replay": "NOT_RUN", "external_host_execution": "NOT_RUN", "postgresql": "NOT_RUN", "field_verification": "NOT_RUN", "production_activation": "NO"}
        }
        (output / "manifest.json").write_text(json.dumps(manifest, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
        (output / "README.md").write_text(README.format(revision=revision), encoding="utf-8")
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
