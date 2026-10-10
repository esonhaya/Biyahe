#!/usr/bin/env python3
"""Bounded syntax and JSON checks for the Batch042 evidence source set."""

from __future__ import annotations

import argparse
import ast
import json
from pathlib import Path
import shutil
import subprocess
import sys


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, required=True)
    root = parser.parse_args().root.resolve()
    errors: list[str] = []
    checked = {"python": 0, "bash": 0, "php": 0, "javascript": 0, "json": 0}

    python_paths = [
        "api/scripts/run-portable-verification.py",
        "api/scripts/check-batch042-static-syntax.py",
        "api/scripts/package-batch041-verification-kit.py",
        "api/scripts/refresh-batch042-doctor.py",
        "api/scripts/test_portable_verification.py",
        "api/scripts/evidence_provenance.py",
        "api/scripts/compare-offline-corpus.py",
        "api/scripts/validate-batch041-doctor.py",
        "api/scripts/validate-batch042-doctor.py",
        "api/scripts/test_validate_batch042_doctor.py",
    ]
    for relative in python_paths:
        path = root / relative
        if not path.is_file():
            continue
        try:
            ast.parse(path.read_text(encoding="utf-8"), filename=relative)
            checked["python"] += 1
        except (OSError, SyntaxError, UnicodeError) as error:
            errors.append(f"PYTHON {relative}: {error}")

    for relative in ["api/scripts/run-bounded-process.sh", "api/scripts/run-portable-verification.sh"]:
        path = root / relative
        if not path.is_file() or shutil.which("bash") is None:
            continue
        result = subprocess.run(["bash", "-n", str(path)], text=True, capture_output=True)
        checked["bash"] += 1
        if result.returncode:
            errors.append(f"BASH {relative}: {result.stderr.strip()}")

    for relative in [
        "api/scripts/test_offline_route_geometry_contract.php",
        "api/app/Services/Routing/OfflineJourneyWalkingPreview.php",
        "api/app/Services/Routing/OfflinePedestrianGraphRouter.php",
        "api/app/Services/Routing/OfflinePedestrianRouteGeometryContract.php",
        "api/app/Http/Controllers/Api/DevelopmentOfflineWalkingPreviewController.php",
    ]:
        path = root / relative
        if not path.is_file() or shutil.which("php") is None:
            continue
        result = subprocess.run(["php", "-l", str(path)], text=True, capture_output=True)
        checked["php"] += 1
        if result.returncode:
            errors.append(f"PHP {relative}: {(result.stdout + result.stderr).strip()}")

    javascript_paths = [
        "web/scripts/offline-walking-preview-harness.mjs",
        "web/scripts/offline-walking-preview-state-harness.mjs",
        "web/scripts/commute-card-harness.mjs",
        "web/scripts/ride-mode-harness.mjs",
        "web/scripts/request-gate-harness.mjs",
        "web/scripts/validation-ledger.mjs",
        "web/scripts/validation-ledger-harness.mjs",
        "web/scripts/can-i-ride-harness.mjs",
        "web/scripts/ux-contract-harness.mjs",
        "web/scripts/place-search-harness.mjs",
        "web/scripts/search-keyboard-harness.mjs",
    ]
    if shutil.which("node"):
        for relative in javascript_paths:
            path = root / relative
            if not path.is_file():
                continue
            result = subprocess.run(["node", "--check", str(path)], text=True, capture_output=True)
            checked["javascript"] += 1
            if result.returncode:
                errors.append(f"JAVASCRIPT {relative}: {(result.stdout + result.stderr).strip()}")

    json_paths = [
        "web/package.json",
        "docs/reports/batch041-evidence-provenance.json",
        "docs/reports/batch041-doctor-v41.json",
        "docs/reports/batch042-doctor-v42.json",
        "docs/reports/batch042-work-ledger.json",
        "docs/testing/batch042-test-plan.json",
    ]
    for relative in json_paths:
        path = root / relative
        if not path.is_file():
            continue
        try:
            json.loads(path.read_text(encoding="utf-8"))
            checked["json"] += 1
        except (OSError, json.JSONDecodeError, UnicodeError) as error:
            errors.append(f"JSON {relative}: {error}")

    if (root / ".git").exists() and shutil.which("git"):
        result = subprocess.run(["git", "-C", str(root), "diff", "--check"], text=True, capture_output=True)
        if result.returncode:
            errors.append(f"GIT_DIFF_CHECK: {(result.stdout + result.stderr).strip()}")
        else:
            print("GIT_DIFF_CHECK=PASS")
    else:
        print("GIT_DIFF_CHECK=NOT_APPLICABLE_SOURCE_SNAPSHOT")

    for kind, count in checked.items():
        print(f"{kind.upper()}_SYNTAX_OR_PARSE_CHECKS={count}")
    for error in errors:
        print(f"ERROR={error}", file=sys.stderr)
    print(f"STATIC_SYNTAX={'PASS' if not errors else 'FAIL'}")
    return 0 if not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
