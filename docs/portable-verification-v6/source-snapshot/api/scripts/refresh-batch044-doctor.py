#!/usr/bin/env python3
"""Build Doctor V44 with executable-source and validation-evidence pins."""

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
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
DEFAULT_LEDGER = ROOT / "docs/reports/batch044-work-ledger.json"
DEFAULT_OUTPUT = ROOT / "docs/reports/batch044-doctor-v44.json"
SOURCE_PATHS = [
    ".dockerignore", ".github/workflows/ci.yml", ".github/workflows/external-cebu-corpus.yml",
    ".github/workflows/external-staging-browser.yml",
    "api/config/app.php", "api/config/database.php", "api/config/cors.php", "api/config/offline_walking_preview.php",
    "api/routes/api.php", "api/composer.json", "api/composer.lock",
    "api/app/Http/Controllers/Api/HealthController.php", "api/app/Http/Controllers/Api/ReadinessController.php",
    "api/app/Http/Controllers/Api/JourneyController.php", "api/app/Http/Controllers/Api/CanIRideController.php",
    "api/app/Http/Controllers/Api/PlaceSearchController.php", "api/app/Services/DeploymentReadinessService.php",
    "api/app/Http/Controllers/Api/DevelopmentOfflineWalkingPreviewController.php",
    "api/app/Services/Routing/OfflineJourneyWalkingPreview.php", "api/app/Services/Routing/OfflinePedestrianGraphRouter.php",
    "api/app/Services/Routing/OfflinePedestrianRouteGeometryContract.php", "api/app/Services/Routing/OfflineWalkingCorpusSelection.php",
    "api/scripts/run-bounded-process.sh", "api/scripts/run-offline-corpus-verification.py",
    "api/scripts/run-portable-verification.py", "api/scripts/package-batch041-verification-kit.py",
    "api/scripts/check-batch042-static-syntax.py", "api/scripts/staging-bootstrap.sh",
    "api/scripts/staging-api-smoke.py", "api/scripts/validate-staging-config.py",
    "api/scripts/test_staging_config.py", "api/scripts/test_staging_api_smoke.py",
    "api/scripts/staging-db-init-sql.py", "api/scripts/test_staging_db_init_sql.py",
    "api/scripts/test-staging-secret-config.php", "api/scripts/test_offline_corpus_verification.py",
    "api/scripts/test_portable_verification.py", "api/scripts/refresh-batch044-doctor.py",
    "api/scripts/validate-batch044-doctor.py", "api/scripts/test_validate_batch044_doctor.py",
    "deploy/staging/.env.example", "deploy/staging/compose.yaml", "deploy/staging/nginx.conf.template",
    "deploy/staging/api.Dockerfile", "deploy/staging/api-entrypoint.sh", "deploy/staging/web.Dockerfile",
    "deploy/staging/.gitignore",
    "web/package.json", "web/package-lock.json", "web/tsconfig.json", "web/eslint.config.mjs", "web/next.config.ts",
    "web/playwright.config.ts", "web/e2e/staging-journey.spec.ts",
    "web/src/app/page.module.css", "web/src/app/globals.css",
    "web/src/app/page.tsx", "web/src/components/CommuteCard.tsx", "web/src/components/MapView.tsx",
    "web/src/components/RideMode.tsx", "web/src/lib/api.ts", "web/src/lib/canIRidePresentation.ts",
    "web/src/lib/commuteInstructions.ts", "web/src/lib/journeyExplanation.ts", "web/src/lib/journeyMapPresentation.ts",
    "web/src/lib/latestPlaceSearch.ts", "web/src/lib/offlineWalkingPreviewState.ts", "web/src/lib/requestGate.ts",
    "web/src/lib/rideMode.ts", "web/src/lib/savedJourneys.ts", "web/src/lib/searchKeyboard.ts",
    "web/scripts/offline-walking-preview-harness.mjs", "web/scripts/offline-walking-preview-state-harness.mjs",
    "web/scripts/commute-card-harness.mjs", "web/scripts/ride-mode-harness.mjs", "web/scripts/request-gate-harness.mjs",
    "web/scripts/can-i-ride-harness.mjs", "web/scripts/place-search-harness.mjs", "web/scripts/search-keyboard-harness.mjs",
]
SOURCE_PATHS = list(dict.fromkeys(SOURCE_PATHS + [
    path.relative_to(ROOT).as_posix()
    for path in sorted((ROOT / "web/src").rglob("*"))
    if path.is_file() and path.suffix in {".ts", ".tsx"}
]))


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def build_report(ledger: dict[str, Any], revision: str, root: Path = ROOT) -> dict[str, Any]:
    if re.fullmatch(r"[0-9a-f]{40}", revision) is None:
        raise ValueError("--tested-revision must be a full lowercase Git commit hash")
    try:
        current = subprocess.check_output(["git", "-C", str(root), "rev-parse", "HEAD"], text=True).strip()
    except (OSError, subprocess.CalledProcessError) as error:
        raise ValueError("Doctor V44 generation requires a Git checkout") from error
    if current != revision:
        raise ValueError("tested revision must equal current checkout HEAD")

    fingerprints: dict[str, str] = {}
    for relative in SOURCE_PATHS:
        source = root / relative
        if not source.is_file():
            raise ValueError(f"fingerprinted source is missing: {relative}")
        committed = subprocess.check_output(["git", "-C", str(root), "show", f"{revision}:{relative}"])
        digest = sha256(source)
        if hashlib.sha256(committed).hexdigest() != digest:
            raise ValueError(f"working source differs from tested commit: {relative}")
        fingerprints[relative] = digest

    categories = ledger.get("doctor_categories")
    tests = ledger.get("test_results")
    evidence = ledger.get("doctor_evidence")
    if not isinstance(categories, dict) or not isinstance(tests, dict) or not isinstance(evidence, dict):
        raise ValueError("ledger must provide doctor_categories, test_results, and doctor_evidence")
    category_rows = []
    for name, row in sorted(categories.items()):
        if not isinstance(row, dict):
            raise ValueError(f"Doctor category {name} must be an object")
        category_rows.append({"name": name, "status": row.get("status"), "evidence": row.get("evidence")})

    report = {
        "format": "biyahe-doctor-v44-evidence-v1",
        "batch": 44,
        "assessed_at_utc": datetime.now(timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z"),
        "source_revision": {
            "executable": revision,
            "tested": revision,
            "documentation_checkpoint_note": "Later Doctor, work-ledger, handoff-documentation, package, and final-report commits contain no changed executable inputs; every executable/configuration fingerprint remains pinned to this revision.",
        },
        "source_fingerprints": fingerprints,
        "categories": category_rows,
        "evidence": evidence,
        "tests": tests,
        "release_gates": {
            "live_graph_integration": "BLOCKED",
            "postgresql_staging": "BLOCKED",
            "field_safety": "BLOCKED",
            "private_beta": "BLOCKED",
            "production_activation": "NO",
        },
    }

    validator_path = Path(__file__).with_name("validate-batch044-doctor.py")
    spec = importlib.util.spec_from_file_location("batch044_doctor_validator", validator_path)
    if spec is None or spec.loader is None:
        raise ValueError("Doctor V44 validator cannot be loaded")
    validator = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(validator)
    validator.validate_doctor(report, root)
    return report


def write_new(path: Path, report: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(path.name + ".building")
    if path.exists() or path.is_symlink() or temporary.exists() or temporary.is_symlink():
        raise ValueError(f"refusing to overwrite Doctor output or temporary: {path}")
    descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8") as stream:
            json.dump(report, stream, ensure_ascii=False, indent=2, sort_keys=True)
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
    else:
        libc = ctypes.CDLL(None, use_errno=True)
        if hasattr(libc, "renameat2"):
            renameat2 = libc.renameat2
            renameat2.argtypes = (ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint)
            renameat2.restype = ctypes.c_int
            if renameat2(-100, os.fsencode(source), -100, os.fsencode(destination), 1) == 0:
                directory_fd = os.open(destination.parent, os.O_RDONLY)
                try:
                    os.fsync(directory_fd)
                finally:
                    os.close(directory_fd)
                return
            error_number = ctypes.get_errno()
            if error_number == errno.EEXIST:
                raise FileExistsError(error_number, os.strerror(error_number), str(destination))
            if error_number not in {errno.ENOSYS, errno.EINVAL, getattr(errno, "EOPNOTSUPP", errno.ENOSYS)}:
                raise OSError(error_number, os.strerror(error_number), str(destination))
        link = libc.link
        link.argtypes = (ctypes.c_char_p, ctypes.c_char_p)
        link.restype = ctypes.c_int
        if link(os.fsencode(source), os.fsencode(destination)) != 0:
            error_number = ctypes.get_errno()
            if error_number == errno.EEXIST:
                raise FileExistsError(error_number, os.strerror(error_number), str(destination))
            raise OSError(error_number, os.strerror(error_number), str(destination))
    directory_fd = os.open(destination.parent, os.O_RDONLY)
    try:
        os.fsync(directory_fd)
    finally:
        os.close(directory_fd)


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
        write_new(args.output, report)
        print(f"DOCTOR_V44={args.output}")
        print(f"EXECUTABLE_SOURCE_REVISION={args.tested_revision}")
        print(f"SOURCE_FINGERPRINTS={len(report['source_fingerprints'])}")
        print(f"TEST_RECORDS={len(report['tests'])}")
        return 0
    except (OSError, json.JSONDecodeError, ValueError, subprocess.CalledProcessError) as error:
        print(f"DOCTOR_V44_REFRESH_ERROR={error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
