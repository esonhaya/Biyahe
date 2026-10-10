#!/usr/bin/env python3
"""Build a source-pinned Doctor V43 from the current Batch043 evidence ledger."""

from __future__ import annotations

import argparse
from datetime import datetime, timezone
import ctypes
import errno
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
DEFAULT_LEDGER = ROOT / "docs/reports/batch043-work-ledger.json"
DEFAULT_OUTPUT = ROOT / "docs/reports/batch043-doctor-v43.json"
SOURCE_PATHS = [
    "api/app/Console/Commands/VerifyOfflineWalkingCorpus.php",
    "api/app/Http/Controllers/Api/DevelopmentOfflineWalkingPreviewController.php",
    "api/app/Services/Routing/OfflineJourneyWalkingPreview.php",
    "api/app/Services/Routing/OfflinePedestrianGraphRouter.php",
    "api/app/Services/Routing/OfflinePedestrianRouteGeometryContract.php",
    "api/app/Services/Routing/OfflineWalkingCorpusSelection.php",
    "api/config/offline_walking_preview.php",
    "api/phpunit.xml",
    "api/routes/api.php",
    "api/composer.json",
    "api/composer.lock",
    "api/scripts/check-batch042-static-syntax.py",
    "api/scripts/check-batch043-staging-preflight.sh",
    "api/scripts/compare-offline-corpus.py",
    "api/scripts/package-batch041-verification-kit.py",
    "api/scripts/run-bounded-process.sh",
    "api/scripts/run-offline-corpus-verification.py",
    "api/scripts/test_offline_corpus_report_writer.php",
    "api/scripts/run-portable-verification.py",
    "api/scripts/run-portable-verification.sh",
    "api/scripts/test_compare_offline_corpus.py",
    "api/scripts/test_offline_corpus_verification.py",
    "api/scripts/test_portable_verification.py",
    "api/scripts/refresh-batch043-doctor.py",
    "api/scripts/validate-batch043-doctor.py",
    "api/scripts/test_validate_batch043_doctor.py",
    "api/tests/Unit/BoundedProcessGuardTest.php",
    "api/tests/Unit/OfflineJourneyWalkingPreviewTest.php",
    "api/tests/Unit/OfflinePedestrianGraphRouterTest.php",
    "api/tests/Unit/OfflinePedestrianRouteGeometryContractTest.php",
    "api/tests/Unit/OfflineWalkingCorpusSelectionTest.php",
    "api/tests/Support/OfflinePedestrianGraphFixture.php",
    "docs/reports/batch032-cebu-coverage.json",
    "docs/reports/batch041-evidence-provenance.json",
    "docs/testing/batch043-test-plan.json",
    "web/package.json",
    "web/package-lock.json",
    "web/src/app/page.tsx",
    "web/src/components/CommuteCard.tsx",
    "web/src/components/MapView.tsx",
    "web/src/components/RideMode.tsx",
    "web/src/lib/api.ts",
    "web/src/lib/canIRidePresentation.ts",
    "web/src/lib/commuteInstructions.ts",
    "web/src/lib/journeyExplanation.ts",
    "web/src/lib/journeyMapPresentation.ts",
    "web/src/lib/latestPlaceSearch.ts",
    "web/src/lib/offlineWalkingPreviewState.ts",
    "web/src/lib/requestGate.ts",
    "web/src/lib/rideMode.ts",
    "web/src/lib/savedJourneys.ts",
    "web/src/lib/searchKeyboard.ts",
    "web/scripts/offline-walking-preview-harness.mjs",
    "web/scripts/offline-walking-preview-state-harness.mjs",
    "web/scripts/commute-card-harness.mjs",
    "web/scripts/ride-mode-harness.mjs",
    "web/scripts/can-i-ride-harness.mjs",
    "web/scripts/place-search-harness.mjs",
    "web/scripts/search-keyboard-harness.mjs",
]


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def build_report(ledger: dict[str, object], revision: str, root: Path = ROOT) -> dict[str, object]:
    if re.fullmatch(r"[0-9a-f]{40}", revision) is None:
        raise ValueError("--tested-revision must be a full lowercase Git commit hash")
    try:
        current_revision = subprocess.check_output(["git", "-C", str(root), "rev-parse", "HEAD"], text=True).strip()
    except (OSError, subprocess.CalledProcessError) as error:
        raise ValueError("Doctor V43 generation requires a Git checkout") from error
    if current_revision != revision:
        raise ValueError("tested revision must equal the current checkout HEAD")

    fingerprints: dict[str, str] = {}
    for relative in SOURCE_PATHS:
        source = root / relative
        if not source.is_file():
            raise ValueError(f"fingerprinted source file is missing: {relative}")
        committed = subprocess.check_output(["git", "-C", str(root), "show", f"{revision}:{relative}"])
        digest = sha256(source)
        if hashlib.sha256(committed).hexdigest() != digest:
            raise ValueError(f"working source differs from tested Git revision: {relative}")
        fingerprints[relative] = digest

    categories = ledger.get("doctor_categories")
    tests = ledger.get("test_results")
    evidence = ledger.get("doctor_evidence")
    if not isinstance(categories, dict) or not isinstance(tests, dict) or not isinstance(evidence, dict):
        raise ValueError("work ledger must provide doctor_categories, test_results, and doctor_evidence")
    category_rows = []
    for name, row in sorted(categories.items()):
        if not isinstance(row, dict):
            raise ValueError(f"Doctor category {name} must be an object")
        category_rows.append({"name": name, "status": row.get("status"), "evidence": row.get("evidence")})

    report = {
        "format": "biyahe-doctor-v43-evidence-v1",
        "batch": 43,
        "assessed_at_utc": datetime.now(timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z"),
        "source_revision": {
            "executable": revision,
            "tested": revision,
            "documentation_checkpoint_note": "Later Doctor, work-ledger, staging-documentation, portable-kit, and final-report commits are documentation/evidence only; executable source fingerprints remain pinned here.",
        },
        "source_fingerprints": fingerprints,
        "categories": category_rows,
        "evidence": evidence,
        "tests": tests,
        "release_gates": {
            "live_production_graph_integration": "BLOCKED",
            "postgresql_staging": "BLOCKED",
            "field_safety": "BLOCKED",
            "private_beta": "BLOCKED",
            "production_activation": "NO",
        },
    }
    return report


def write_new(path: Path, document: dict[str, object]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(path.name + ".building")
    if path.exists() or path.is_symlink() or temporary.exists() or temporary.is_symlink():
        raise ValueError(f"refusing to overwrite Doctor output or temporary path: {path}")
    descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8") as stream:
            json.dump(document, stream, ensure_ascii=False, indent=2, sort_keys=True)
            stream.write("\n")
            stream.flush()
            os.fsync(stream.fileno())
        publish_no_clobber(temporary, path)
    except Exception:
        temporary.unlink(missing_ok=True)
        raise
    temporary.unlink(missing_ok=True)


def publish_no_clobber(source: Path, destination: Path) -> None:
    if hasattr(os, "link"):
        os.link(source, destination)
        return
    libc = ctypes.CDLL(None, use_errno=True)
    if hasattr(libc, "renameat2"):
        renameat2 = libc.renameat2
        renameat2.argtypes = (ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint)
        renameat2.restype = ctypes.c_int
        if renameat2(-100, os.fsencode(source), -100, os.fsencode(destination), 1) == 0:
            return
        error_number = ctypes.get_errno()
        if error_number not in {errno.ENOSYS, errno.EINVAL, getattr(errno, "EOPNOTSUPP", errno.ENOSYS)}:
            raise OSError(error_number, os.strerror(error_number), str(destination))
    link = libc.link
    link.argtypes = (ctypes.c_char_p, ctypes.c_char_p)
    link.restype = ctypes.c_int
    if link(os.fsencode(source), os.fsencode(destination)) != 0:
        error_number = ctypes.get_errno()
        raise OSError(error_number, os.strerror(error_number), str(destination))


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--tested-revision", required=True)
    parser.add_argument("--ledger", type=Path, default=DEFAULT_LEDGER)
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    args = parser.parse_args(argv)
    try:
        ledger = json.loads(args.ledger.read_text(encoding="utf-8"))
        if not isinstance(ledger, dict):
            raise ValueError("work ledger must be a JSON object")
        report = build_report(ledger, args.tested_revision, ROOT)
        # Validate before publication; the output does not fingerprint itself.
        validator_path = Path(__file__).with_name("validate-batch043-doctor.py")
        validator_spec = importlib.util.spec_from_file_location("batch043_doctor_validator", validator_path)
        if validator_spec is None or validator_spec.loader is None:
            raise ValueError("Doctor V43 validator cannot be loaded")
        validator = importlib.util.module_from_spec(validator_spec)
        validator_spec.loader.exec_module(validator)
        validator.validate_doctor(report, ROOT)
        write_new(args.output, report)
        print(f"DOCTOR_V43={args.output}")
        print(f"EXECUTABLE_SOURCE_REVISION={args.tested_revision}")
        print(f"SOURCE_FINGERPRINTS={len(report['source_fingerprints'])}")
        print(f"TEST_RECORDS={len(report['tests'])}")
        return 0
    except (OSError, json.JSONDecodeError, ValueError, subprocess.CalledProcessError) as error:
        print(f"DOCTOR_V43_REFRESH_ERROR={error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
