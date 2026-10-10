#!/usr/bin/env python3
"""Run the pinned Biyahe verification suites with durable, honest evidence."""

from __future__ import annotations

import ast
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import time
from datetime import datetime, timezone

GRAPH_SHA256 = "29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8"
MEM_FLOOR = 524288
SWAP_FLOOR = 131072
GUARD_ENV = {
    "BIYAHE_RESOURCE_MIN_MEMAVAILABLE_KB": str(MEM_FLOOR),
    "BIYAHE_RESOURCE_MIN_SWAPFREE_KB": str(SWAP_FLOOR),
    "BIYAHE_RESOURCE_MAX_PROCESS_TREE_RSS_KB": "262144",
    "BIYAHE_RESOURCE_PREFLIGHT_SAMPLES": "5",
    "BIYAHE_RESOURCE_PREFLIGHT_INTERVAL_SECONDS": "5",
    "BIYAHE_RESOURCE_BREACH_SAMPLES": "1",
    "BIYAHE_RESOURCE_POLL_SECONDS": "1",
    "BIYAHE_RESOURCE_MAX_DURATION_SECONDS": "1800",
}
TOOL_FINGERPRINT_CACHE: dict[str, str] = {}


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z")


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def atomic_json(path: Path, value: object) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".tmp", dir=path.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as stream:
            json.dump(value, stream, indent=2, ensure_ascii=False, sort_keys=True)
            stream.write("\n")
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
    finally:
        try:
            os.unlink(temporary)
        except FileNotFoundError:
            pass


def canonical_hash(value: object) -> str:
    return sha256_bytes(json.dumps(value, sort_keys=True, separators=(",", ":")).encode())


def parse_child_command_results(output_text: str) -> list[dict[str, object]]:
    """Recover completed child commands from the resource guard's worker log."""
    results: list[dict[str, object]] = []
    for line in output_text.splitlines():
        worker_marker = "worker: "
        candidate = line.split(worker_marker, 1)[1] if worker_marker in line else line
        if not candidate.startswith("CHILD_COMMAND=") or " EXIT=" not in candidate:
            continue
        command_text, exit_text = candidate[len("CHILD_COMMAND="):].rsplit(" EXIT=", 1)
        try:
            child_argv = ast.literal_eval(command_text)
            child_exit = int(exit_text)
            if not isinstance(child_argv, list) or not all(isinstance(item, str) for item in child_argv):
                raise ValueError("child command is not a string argument list")
        except (ValueError, SyntaxError):
            results.append({"command_text": command_text, "status": "FAIL", "exit_code": None})
            continue
        results.append({"argv": child_argv, "status": "PASS" if child_exit == 0 else "FAIL",
                        "exit_code": child_exit})
    return results


def file_fingerprint(root: Path, relative_paths: list[str], command: list[str], cwd: Path,
                     environment: dict[str, str]) -> tuple[str, list[dict[str, str]], dict[str, str]]:
    files: list[dict[str, str]] = []
    for relative in sorted(set(relative_paths)):
        path = root / relative
        if not path.is_file():
            files.append({"path": relative, "sha256": "MISSING"})
        else:
            files.append({"path": relative, "sha256": sha256_file(path)})
    tools: dict[str, str] = {}
    requested_tools = {command[0], "python3", "node", "php", "bash", "npm", "composer"}
    for tool in sorted(requested_tools):
        resolved = shutil.which(tool)
        if not resolved:
            tools[tool] = "MISSING"
            continue
        executable = Path(resolved).resolve()
        try:
            digest = TOOL_FINGERPRINT_CACHE.get(str(executable))
            if digest is None:
                digest = sha256_file(executable)
                TOOL_FINGERPRINT_CACHE[str(executable)] = digest
            tools[tool] = f"path={executable};sha256={digest}"
        except OSError:
            tools[tool] = f"path={executable};sha256=UNREADABLE"
    tools["python_runtime"] = sys.version.split()[0]
    package_lock = cwd / "package-lock.json"
    if package_lock.is_file():
        try:
            lock = json.loads(package_lock.read_text(encoding="utf-8"))
            packages = lock.get("packages", {})
            for package_name in ("typescript", "eslint"):
                package = packages.get(f"node_modules/{package_name}", {})
                tools[f"locked:{package_name}"] = str(package.get("version", "MISSING"))
        except (OSError, json.JSONDecodeError):
            tools["package-lock"] = "UNREADABLE"
    digest = canonical_hash({"files": files, "command": command, "cwd": str(cwd),
                             "environment": environment, "tools": tools})
    return digest, files, tools


def graph_hash(path: Path) -> str:
    return sha256_file(path)


def recover_stale_runs(output_root: Path) -> list[str]:
    """Mark abandoned RUNNING records NOT_RUN after taking the exclusive lock."""
    recovered: list[str] = []
    for status_path in sorted(output_root.glob("*/run-status.json")):
        try:
            current = json.loads(status_path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            continue
        if current.get("status") != "RUNNING":
            continue
        pid = current.get("pid")
        if isinstance(pid, int) and pid > 1:
            try:
                os.kill(pid, 0)
                continue
            except ProcessLookupError:
                pass
            except PermissionError:
                continue
        current["status"] = "NOT_RUN"
        current["recovery"] = {"status": "INTERRUPTED_RUN_RECOVERED", "recovered_at": utc_now(), "reason": "runner process is no longer present; no PASS was inferred"}
        current["ended_at"] = utc_now()
        active = current.get("active_suite")
        if isinstance(active, dict) and active.get("status") == "RUNNING":
            active["status"] = "NOT_RUN"
            active["recovery"] = "interrupted; guarded command outcome was not recovered as PASS"
            partial_path = active.get("output_path")
            if isinstance(partial_path, str) and Path(partial_path).is_file():
                active["partial_output_sha256"] = sha256_file(Path(partial_path))
        for suite in current.get("suites", []):
            if isinstance(suite, dict) and suite.get("status") == "RUNNING":
                suite["status"] = "NOT_RUN"
                suite["recovery"] = "interrupted; guarded command outcome was not recovered as PASS"
        atomic_json(status_path, current)
        recovered.append(str(status_path))
    return recovered


def acquire_lock(output_root: Path) -> Path:
    lock_path = output_root / ".portable-verification.lock"
    payload = f"pid={os.getpid()}\nstarted_at={utc_now()}\n"
    try:
        fd = os.open(lock_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    except FileExistsError as error:
        try:
            contents = lock_path.read_text(encoding="utf-8")
            pid_line = next((line for line in contents.splitlines() if line.startswith("pid=")), "")
            old_pid = int(pid_line[4:])
            os.kill(old_pid, 0)
        except ProcessLookupError:
            lock_path.unlink()
            return acquire_lock(output_root)
        except (OSError, ValueError, StopIteration):
            raise RuntimeError(f"verification lock exists and its owner cannot be confirmed: {lock_path}") from error
        raise RuntimeError(f"another portable verification run owns PID {old_pid}; refusing concurrent execution") from error
    with os.fdopen(fd, "w", encoding="utf-8") as stream:
        stream.write(payload)
        stream.flush()
        os.fsync(stream.fileno())
    return lock_path


def command_specs(root: Path, mode: str, run_dir: Path,
                  corpus_case_indices: list[int] | None = None,
                  corpus_graph_path: Path | None = None) -> list[dict[str, object]]:
    api = root / "api"
    web = root / "web"
    doctor_v43_report = root / "docs/reports/batch043-doctor-v43.json"
    doctor_v43_sources: list[str] = []
    try:
        doctor_document = json.loads(doctor_v43_report.read_text(encoding="utf-8"))
        fingerprints = doctor_document.get("source_fingerprints", {})
        if isinstance(fingerprints, dict):
            doctor_v43_sources = sorted(path for path in fingerprints if isinstance(path, str))
    except (OSError, json.JSONDecodeError):
        pass
    doctor_v44_report = root / "docs/reports/batch044-doctor-v44.json"
    doctor_v44_sources: list[str] = []
    try:
        doctor_document = json.loads(doctor_v44_report.read_text(encoding="utf-8"))
        fingerprints = doctor_document.get("source_fingerprints", {})
        if isinstance(fingerprints, dict):
            doctor_v44_sources = sorted(path for path in fingerprints if isinstance(path, str))
    except (OSError, json.JSONDecodeError):
        pass
    typecheck_sources = sorted(
        path.relative_to(root).as_posix()
        for base in (web / "src",)
        for path in base.rglob("*")
        if path.is_file() and path.suffix in {".ts", ".tsx"}
    )
    if (web / "next.config.ts").is_file():
        typecheck_sources.append("web/next.config.ts")
    syntax_inputs = [
        "api/scripts/check-batch042-static-syntax.py",
        "api/scripts/run-portable-verification.py", "api/scripts/run-offline-corpus-verification.py",
        "api/scripts/test_offline_corpus_verification.py", "api/scripts/package-batch041-verification-kit.py",
        "api/scripts/refresh-batch042-doctor.py", "api/scripts/test_portable_verification.py",
        "api/scripts/evidence_provenance.py", "api/scripts/compare-offline-corpus.py",
        "api/scripts/test_compare_offline_corpus.py", "api/scripts/test_summarize_offline_coverage.py",
        "api/scripts/validate-batch041-doctor.py", "api/scripts/validate-batch042-doctor.py",
        "api/scripts/test_validate_batch042_doctor.py", "api/scripts/refresh-batch043-doctor.py",
        "api/scripts/validate-batch043-doctor.py", "api/scripts/test_validate_batch043_doctor.py",
        "api/scripts/refresh-batch044-doctor.py", "api/scripts/validate-batch044-doctor.py",
        "api/scripts/test_validate_batch044_doctor.py",
        "api/scripts/validate-staging-config.py", "api/scripts/test_staging_config.py",
        "api/scripts/staging-api-smoke.py", "api/scripts/test_staging_api_smoke.py",
        "api/scripts/staging-db-init-sql.py", "api/scripts/test_staging_db_init_sql.py",
        "api/scripts/test-staging-secret-config.php", "api/scripts/staging-bootstrap.sh",
        "deploy/staging/compose.yaml", "deploy/staging/.env.example", "deploy/staging/nginx.conf.template",
        "deploy/staging/api.Dockerfile", "deploy/staging/api-entrypoint.sh", "deploy/staging/web.Dockerfile",
        ".dockerignore", "api/config/app.php", "api/config/database.php", "api/config/cors.php",
        "web/playwright.config.ts", "web/e2e/staging-journey.spec.ts",
        "docs/deployment/batch044-linux-staging-handoff.md",
        "api/scripts/run-bounded-process.sh", "api/scripts/run-portable-verification.sh",
        "api/scripts/check-batch043-staging-preflight.sh", "api/scripts/test_offline_route_geometry_contract.php",
        "api/scripts/test_offline_corpus_report_writer.php",
        "api/app/Services/Routing/OfflineJourneyWalkingPreview.php",
        "api/app/Services/Routing/OfflineWalkingCorpusSelection.php",
        "api/app/Services/Routing/OfflinePedestrianGraphRouter.php",
        "api/app/Services/Routing/OfflinePedestrianRouteGeometryContract.php",
        "api/app/Http/Controllers/Api/DevelopmentOfflineWalkingPreviewController.php",
        "api/app/Console/Commands/VerifyOfflineWalkingCorpus.php",
        "api/tests/Unit/OfflineWalkingCorpusSelectionTest.php", "api/tests/Unit/BoundedProcessGuardTest.php",
        "web/scripts/offline-walking-preview-harness.mjs", "web/scripts/offline-walking-preview-state-harness.mjs",
        "web/scripts/commute-card-harness.mjs", "web/scripts/ride-mode-harness.mjs",
        "web/scripts/request-gate-harness.mjs", "web/scripts/validation-ledger.mjs",
        "web/scripts/validation-ledger-harness.mjs", "web/scripts/can-i-ride-harness.mjs",
        "web/scripts/ux-contract-harness.mjs", "web/scripts/place-search-harness.mjs",
        "web/scripts/search-keyboard-harness.mjs", "web/package.json",
        "docs/reports/batch041-evidence-provenance.json", "docs/reports/batch041-doctor-v41.json",
        "docs/reports/batch042-doctor-v42.json", "docs/reports/batch042-work-ledger.json",
        "docs/testing/batch042-test-plan.json", "docs/reports/batch043-doctor-v43.json",
        "docs/reports/batch043-work-ledger.json", "docs/testing/batch043-test-plan.json",
        "docs/reports/batch044-work-ledger.json", "docs/reports/batch044-doctor-v44.json",
        "docs/testing/batch044-test-plan.json",
        ".github/workflows/ci.yml", ".github/workflows/external-cebu-corpus.yml", ".github/workflows/external-staging-browser.yml",
    ]
    static: list[dict[str, object]] = [
        {"id": "source-provenance", "argv": [sys.executable, "api/scripts/validate-batch041-provenance.py"], "cwd": root, "inputs": ["api/scripts/evidence_provenance.py", "api/scripts/validate-batch041-provenance.py", "docs/reports/batch041-evidence-provenance.json"]},
        {"id": "provenance-fixtures", "argv": [sys.executable, "api/scripts/test_evidence_provenance.py"], "cwd": root, "inputs": ["api/scripts/evidence_provenance.py", "api/scripts/test_evidence_provenance.py", "docs/reports/batch041-evidence-provenance.json", "docs/reports/batch041-evidence-reconciliation.md"]},
        {"id": "coverage-fixtures", "argv": [sys.executable, "api/scripts/test_summarize_offline_coverage.py"], "cwd": root, "inputs": ["api/scripts/summarize-offline-coverage.py", "api/scripts/test_summarize_offline_coverage.py", "api/scripts/compare-offline-corpus.py"]},
        {"id": "coverage-pinned-summary", "argv": [sys.executable, "api/scripts/summarize-offline-coverage.py", "--output-dir", str(run_dir / "coverage-pinned-summary")], "cwd": root, "inputs": ["api/scripts/summarize-offline-coverage.py", "api/scripts/evidence_provenance.py", "docs/reports/batch032-cebu-coverage.json", "docs/reports/batch041-evidence-provenance.json"]},
        {"id": "static-evidence-smoke", "argv": [sys.executable, "api/scripts/test_offline_evidence_smoke.py"], "cwd": root, "inputs": ["api/scripts/offline-evidence-smoke.py", "api/scripts/test_offline_evidence_smoke.py", "docs/reports/batch041-evidence-provenance.json"]},
        {"id": "corpus-comparator-fixtures", "argv": [sys.executable, "api/scripts/test_compare_offline_corpus.py"], "cwd": root, "inputs": ["api/scripts/compare-offline-corpus.py", "api/scripts/test_compare_offline_corpus.py", "docs/reports/batch032-cebu-coverage.json"]},
        {"id": "doctor-v42-contract", "argv": [sys.executable, "api/scripts/test_validate_batch042_doctor.py"], "cwd": root, "inputs": ["api/scripts/refresh-batch042-doctor.py", "api/scripts/validate-batch042-doctor.py", "api/scripts/test_validate_batch042_doctor.py", "docs/reports/batch042-doctor-v42.json"]},
        {"id": "doctor-v42-report-validator", "argv": [sys.executable, "api/scripts/validate-batch042-doctor.py"], "cwd": root, "inputs": ["api/scripts/validate-batch042-doctor.py", "api/scripts/evidence_provenance.py", "docs/reports/batch042-doctor-v42.json"]},
        {"id": "corpus-verification-contract", "argv": [sys.executable, "api/scripts/test_offline_corpus_verification.py"], "cwd": root, "inputs": ["api/scripts/run-offline-corpus-verification.py", "api/scripts/test_offline_corpus_verification.py"]},
        {"id": "doctor-v43-contract", "argv": [sys.executable, "api/scripts/test_validate_batch043_doctor.py"], "cwd": root, "inputs": ["api/scripts/refresh-batch043-doctor.py", "api/scripts/validate-batch043-doctor.py", "api/scripts/test_validate_batch043_doctor.py", "docs/reports/batch032-cebu-coverage.json", "docs/reports/batch041-evidence-provenance.json"]},
        {"id": "doctor-v43-report-validator", "argv": [sys.executable, "api/scripts/validate-batch043-doctor.py"], "cwd": root, "inputs": ["api/scripts/validate-batch043-doctor.py", "docs/reports/batch043-doctor-v43.json", "docs/reports/batch032-cebu-coverage.json", "docs/reports/batch041-evidence-provenance.json", *doctor_v43_sources]},
        {"id": "doctor-v44-contract", "argv": [sys.executable, "api/scripts/test_validate_batch044_doctor.py"], "cwd": root, "inputs": ["api/scripts/refresh-batch044-doctor.py", "api/scripts/validate-batch044-doctor.py", "api/scripts/test_validate_batch044_doctor.py", "docs/reports/batch032-cebu-coverage.json", "docs/reports/batch041-evidence-provenance.json"]},
        {"id": "doctor-v44-report-validator", "argv": [sys.executable, "api/scripts/validate-batch044-doctor.py"], "cwd": root, "inputs": ["api/scripts/validate-batch044-doctor.py", "docs/reports/batch044-doctor-v44.json", "docs/reports/batch032-cebu-coverage.json", "docs/reports/batch041-evidence-provenance.json", *doctor_v44_sources]},
        {"id": "php-corpus-report-writer", "argv": ["php", "api/scripts/test_offline_corpus_report_writer.php"], "cwd": root, "inputs": ["api/composer.lock", "api/app/Console/Commands/VerifyOfflineWalkingCorpus.php", "api/app/Services/Routing/OfflineWalkingCorpusSelection.php", "api/scripts/test_offline_corpus_report_writer.php"]},
        {"id": "portable-runner-contract", "argv": [sys.executable, "api/scripts/test_portable_verification.py"], "cwd": root, "inputs": ["api/scripts/package-batch041-verification-kit.py", "api/scripts/run-portable-verification.py", "api/scripts/test_portable_verification.py"]},
        {"id": "php-geometry-contract", "argv": ["php", "api/scripts/test_offline_route_geometry_contract.php"], "cwd": root, "inputs": ["api/scripts/test_offline_route_geometry_contract.php", "api/app/Services/Routing/OfflinePedestrianRouteGeometryContract.php"]},
        {"id": "staging-config-validation", "argv": [sys.executable, "api/scripts/validate-staging-config.py"], "cwd": root, "inputs": ["api/scripts/validate-staging-config.py", "deploy/staging/compose.yaml", "deploy/staging/.env.example", "deploy/staging/nginx.conf.template", "deploy/staging/api.Dockerfile", "deploy/staging/api-entrypoint.sh", "deploy/staging/web.Dockerfile", ".dockerignore", "api/config/cors.php", "api/scripts/staging-bootstrap.sh", "api/scripts/staging-db-init-sql.py", "web/playwright.config.ts"]},
        {"id": "staging-config-contract", "argv": [sys.executable, "-m", "unittest", "api/scripts/test_staging_config.py"], "cwd": root, "inputs": ["api/scripts/validate-staging-config.py", "api/scripts/test_staging_config.py", "deploy/staging/compose.yaml", "deploy/staging/.env.example", "deploy/staging/nginx.conf.template", "deploy/staging/api.Dockerfile", "deploy/staging/api-entrypoint.sh", "deploy/staging/web.Dockerfile", ".dockerignore", "api/config/cors.php", "api/scripts/staging-bootstrap.sh", "api/scripts/staging-db-init-sql.py", "web/playwright.config.ts"]},
        {"id": "staging-smoke-contract", "argv": [sys.executable, "-m", "unittest", "api/scripts/test_staging_api_smoke.py"], "cwd": root, "inputs": ["api/scripts/staging-api-smoke.py", "api/scripts/test_staging_api_smoke.py"]},
        {"id": "staging-db-init-sql-contract", "argv": [sys.executable, "-m", "unittest", "api/scripts/test_staging_db_init_sql.py"], "cwd": root, "inputs": ["api/scripts/staging-db-init-sql.py", "api/scripts/test_staging_db_init_sql.py"]},
        {"id": "staging-secret-config-contract", "argv": ["php", "api/scripts/test-staging-secret-config.php"], "cwd": root, "inputs": ["api/scripts/test-staging-secret-config.php", "api/config/app.php", "api/config/database.php"]},
        {"id": "syntax-and-json", "argv": [sys.executable, "api/scripts/check-batch042-static-syntax.py", "--root", str(root)], "cwd": root, "inputs": syntax_inputs},
    ]
    frontend = [
        {"id": "preview-state", "argv": ["node", "--experimental-strip-types", "scripts/offline-walking-preview-state-harness.mjs"], "cwd": web, "inputs": ["web/src/lib/offlineWalkingPreviewState.ts", "web/scripts/offline-walking-preview-state-harness.mjs", "web/src/lib/api.ts"]},
        {"id": "preview-contract", "argv": ["node", "--experimental-strip-types", "scripts/offline-walking-preview-harness.mjs"], "cwd": web, "inputs": ["web/src/components/CommuteCard.tsx", "web/src/lib/offlineWalkingPreviewState.ts", "web/scripts/offline-walking-preview-harness.mjs"]},
        {"id": "commute-card", "argv": ["node", "--experimental-strip-types", "scripts/commute-card-harness.mjs"], "cwd": web, "inputs": ["web/src/lib/savedJourneys.ts", "web/src/lib/commuteInstructions.ts", "web/scripts/commute-card-harness.mjs"]},
        {"id": "ride-mode", "argv": ["node", "--experimental-strip-types", "scripts/ride-mode-harness.mjs"], "cwd": web, "inputs": ["web/src/lib/rideMode.ts", "web/scripts/ride-mode-harness.mjs"]},
        {"id": "request-gate", "argv": ["node", "--experimental-strip-types", "scripts/request-gate-harness.mjs"], "cwd": web, "inputs": ["web/src/lib/requestGate.ts", "web/scripts/request-gate-harness.mjs"]},
        {"id": "place-search", "argv": ["node", "--experimental-strip-types", "scripts/place-search-harness.mjs"], "cwd": web, "inputs": ["web/scripts/place-search-harness.mjs", "web/src/lib/latestPlaceSearch.ts"]},
        {"id": "search-keyboard", "argv": ["node", "--experimental-strip-types", "scripts/search-keyboard-harness.mjs"], "cwd": web, "inputs": ["web/scripts/search-keyboard-harness.mjs", "web/src/lib/searchKeyboard.ts"]},
        {"id": "validation-ledger", "argv": ["node", "scripts/validation-ledger-harness.mjs"], "cwd": web, "inputs": ["web/scripts/validation-ledger-harness.mjs", "web/scripts/validation-ledger.mjs"]},
        {"id": "can-i-ride", "argv": ["node", "--experimental-strip-types", "scripts/can-i-ride-harness.mjs"], "cwd": web, "inputs": ["web/scripts/can-i-ride-harness.mjs", "web/src/lib/canIRidePresentation.ts", "web/src/app/page.tsx"]},
        {"id": "ux-contract", "argv": ["node", "scripts/ux-contract-harness.mjs"], "cwd": web, "inputs": [
            "web/scripts/ux-contract-harness.mjs", "web/src/app/page.tsx", "web/src/app/page.module.css",
            "web/src/app/globals.css", "web/src/lib/canIRidePresentation.ts", "web/scripts/can-i-ride-harness.mjs",
            "web/src/components/CommuteCard.tsx", "web/src/lib/journeyExplanation.ts",
            "web/src/lib/commuteInstructions.ts", "web/src/lib/savedJourneys.ts",
            "web/src/lib/latestPlaceSearch.ts", "web/src/lib/searchKeyboard.ts",
            "web/scripts/place-search-harness.mjs", "web/scripts/search-keyboard-harness.mjs",
            "web/src/components/MapView.tsx", "web/src/lib/journeyMapPresentation.ts",
            "web/src/lib/offlineWalkingPreviewState.ts", "web/scripts/offline-walking-preview-state-harness.mjs",
            "web/src/lib/requestGate.ts", "web/scripts/request-gate-harness.mjs",
            "web/src/components/NearbyRoutes.tsx", "web/src/lib/nearbyLookup.ts", "web/scripts/nearby-harness.mjs",
            "web/src/components/BiyahePulse.tsx", "web/src/components/RideMode.tsx", "web/src/lib/rideMode.ts",
            "web/scripts/validation-ledger.mjs", "web/scripts/validation-ledger-harness.mjs",
            "web/scripts/offline-walking-preview-harness.mjs", "web/scripts/commute-card-harness.mjs",
            "api/app/Http/Controllers/Api/DevelopmentOfflineWalkingPreviewController.php",
            "api/app/Services/Routing/OfflineJourneyWalkingPreview.php", "api/routes/api.php",
            "web/src/components/PersonalCommuteTools.tsx", "web/src/components/FieldMode.tsx",
            "web/src/lib/api.ts", "web/public/sw.js", "web/public/manifest.webmanifest"
        ]},
        {"id": "search-and-can-i-ride", "argv": [sys.executable, "-c", "import subprocess,sys; cs=[['node','--experimental-strip-types','scripts/place-search-harness.mjs'],['node','--experimental-strip-types','scripts/search-keyboard-harness.mjs'],['node','--experimental-strip-types','scripts/can-i-ride-harness.mjs'],['node','scripts/ux-contract-harness.mjs'],['node','scripts/validation-ledger-harness.mjs']]; failed=0\nfor c in cs:\n p=subprocess.run(c); print('CHILD_COMMAND='+repr(c)+' EXIT='+str(p.returncode), flush=True); failed |= p.returncode != 0\nsys.exit(1 if failed else 0)"], "cwd": web, "inputs": [
            "web/src/app/page.tsx", "web/src/app/page.module.css", "web/src/app/globals.css",
            "web/src/components/CommuteCard.tsx", "web/src/components/MapView.tsx",
            "web/src/components/NearbyRoutes.tsx", "web/src/components/BiyahePulse.tsx",
            "web/src/components/RideMode.tsx", "web/src/components/PersonalCommuteTools.tsx",
            "web/src/components/FieldMode.tsx", "web/src/lib/api.ts", "web/src/lib/canIRidePresentation.ts",
            "web/src/lib/commuteInstructions.ts", "web/src/lib/journeyExplanation.ts",
            "web/src/lib/journeyMapPresentation.ts", "web/src/lib/latestPlaceSearch.ts",
            "web/src/lib/nearbyLookup.ts", "web/src/lib/offlineWalkingPreviewState.ts",
            "web/src/lib/requestGate.ts", "web/src/lib/rideMode.ts", "web/src/lib/savedJourneys.ts",
            "web/src/lib/searchKeyboard.ts", "web/public/sw.js", "web/public/manifest.webmanifest",
            "web/scripts/place-search-harness.mjs", "web/scripts/search-keyboard-harness.mjs",
            "web/scripts/can-i-ride-harness.mjs", "web/scripts/ux-contract-harness.mjs",
            "web/scripts/validation-ledger-harness.mjs", "web/scripts/validation-ledger.mjs",
            "web/scripts/offline-walking-preview-state-harness.mjs", "web/scripts/request-gate-harness.mjs",
            "web/scripts/nearby-harness.mjs", "web/scripts/offline-walking-preview-harness.mjs",
            "web/scripts/commute-card-harness.mjs", "api/app/Http/Controllers/Api/DevelopmentOfflineWalkingPreviewController.php",
            "api/app/Services/Routing/OfflineJourneyWalkingPreview.php", "api/routes/api.php"
        ]},
        {"id": "typescript", "argv": ["npm", "run", "typecheck"], "cwd": web, "inputs": [
            "web/package.json", "web/package-lock.json", "web/tsconfig.json", "web/next.config.ts",
            "web/node_modules/.bin/tsc", "web/node_modules/typescript/package.json",
            *typecheck_sources,
        ]},
        {"id": "eslint", "argv": ["npm", "run", "lint", "--", "src/app/page.tsx", "src/components/CommuteCard.tsx", "src/components/RideMode.tsx", "src/lib/offlineWalkingPreviewState.ts", "src/lib/requestGate.ts", "src/lib/savedJourneys.ts", "src/lib/rideMode.ts", "src/lib/canIRidePresentation.ts"], "cwd": web, "inputs": ["web/package.json", "web/package-lock.json", "web/eslint.config.mjs", "web/node_modules/.bin/eslint", "web/node_modules/eslint/package.json", "web/node_modules/eslint-config-next/package.json", "web/src/app/page.tsx", "web/src/components/CommuteCard.tsx", "web/src/components/RideMode.tsx", "web/src/lib/offlineWalkingPreviewState.ts", "web/src/lib/requestGate.ts", "web/src/lib/savedJourneys.ts", "web/src/lib/rideMode.ts", "web/src/lib/canIRidePresentation.ts"]},
    ]
    adapter = [{"id": "adapter-phpunit", "argv": ["php", "artisan", "test", "--filter=OfflinePedestrianRouteGeometryContractTest|OfflinePedestrianGraphRouterTest|OfflineJourneyWalkingPreviewTest|OfflineWalkingCorpusSelectionTest"], "cwd": api, "inputs": ["api/composer.lock", "api/phpunit.xml", "api/app/Services/Routing/OfflineJourneyWalkingPreview.php", "api/app/Services/Routing/OfflineWalkingCorpusSelection.php", "api/app/Services/Routing/OfflinePedestrianGraphRouter.php", "api/tests/Unit/OfflineJourneyWalkingPreviewTest.php", "api/tests/Unit/OfflinePedestrianGraphRouterTest.php", "api/tests/Unit/OfflinePedestrianRouteGeometryContractTest.php", "api/tests/Unit/OfflineWalkingCorpusSelectionTest.php", "api/tests/Support/OfflinePedestrianGraphFixture.php"]}]
    corpus_graph = corpus_graph_path or Path.home() / ".cache/biyahe/batch030/cebu-walk-central-visayas-20261007-v3.sqlite"
    corpus_actual = run_dir / "real-cebu-corpus.json"
    corpus_comparison = run_dir / "real-cebu-corpus-comparison.json"
    corpus_argv = [sys.executable, "api/scripts/run-offline-corpus-verification.py",
                   "--graph", str(corpus_graph), "--actual-output", str(corpus_actual),
                   "--comparison-output", str(corpus_comparison)]
    if corpus_case_indices is not None:
        corpus_argv.extend(["--case-indices", ",".join(str(index) for index in corpus_case_indices)])
    corpus = [{"id": "real-cebu-corpus", "argv": corpus_argv, "cwd": root, "inputs": [
        "api/composer.json", "api/composer.lock", "api/app/Console/Commands/VerifyOfflineWalkingCorpus.php",
        "api/app/Services/Routing/OfflineJourneyWalkingPreview.php",
        "api/app/Services/Routing/OfflinePedestrianGraphRouter.php",
        "api/app/Services/Routing/OfflinePedestrianRouteGeometryContract.php",
        "api/app/Services/Routing/OfflineWalkingCorpusSelection.php",
        "api/config/offline_walking_preview.php", "api/scripts/run-offline-corpus-verification.py",
        "api/scripts/compare-offline-corpus.py", "api/scripts/run-bounded-process.sh",
        "api/tests/Unit/OfflineJourneyWalkingPreviewTest.php",
        "api/tests/Unit/OfflinePedestrianGraphRouterTest.php",
        "api/tests/Unit/OfflineWalkingCorpusSelectionTest.php",
        "docs/reports/batch032-cebu-coverage.json", "docs/testing/batch041-test-plan.json",
        "docs/testing/batch043-test-plan.json",
    ], "corpus_case_indices": corpus_case_indices, "corpus_graph_path": str(corpus_graph)}]
    if mode == "STATIC":
        return static
    if mode == "FRONTEND":
        return frontend
    if mode == "ADAPTER":
        return adapter
    if mode == "CORPUS":
        return corpus
    if mode == "FULL":
        return static + frontend + adapter + corpus
    raise ValueError(f"unsupported mode: {mode}")


def select_specs(specs: list[dict[str, object]], suite_id: str | None) -> list[dict[str, object]]:
    if suite_id is None:
        return specs
    selected = [spec for spec in specs if spec["id"] == suite_id]
    if not selected:
        available = ", ".join(str(spec["id"]) for spec in specs)
        raise ValueError(f"suite {suite_id!r} is not available in this mode; choose one of: {available}")
    return selected


def run_specs(root: Path, run_dir: Path, specs: list[dict[str, object]], resource_guard: Path,
              on_update=None) -> list[dict[str, object]]:
    results: list[dict[str, object]] = []
    for spec in specs:
        identifier = str(spec["id"])
        argv = list(spec["argv"])
        cwd = Path(spec["cwd"])
        started = utc_now()
        started_monotonic_ns = time.monotonic_ns()
        guarded = identifier in {"preview-state", "preview-contract", "commute-card", "ride-mode", "request-gate", "place-search", "search-keyboard", "validation-ledger", "can-i-ride", "ux-contract", "search-and-can-i-ride", "typescript", "eslint", "adapter-phpunit", "real-cebu-corpus", "syntax-and-json"}
        effective_environment = {"PYTHONDONTWRITEBYTECODE": "1", **(GUARD_ENV if guarded else {})}
        if identifier == "real-cebu-corpus":
            effective_environment["BIYAHE_RESOURCE_WATCH_FILE"] = str(spec["corpus_graph_path"])
        inherited_environment = {key: os.environ.get(key, "") for key in
                                 ("PATH", "LANG", "LC_ALL", "TZ", "NODE_OPTIONS")}
        fingerprint_environment = {**inherited_environment, **effective_environment}
        fingerprint, input_files, tool_versions = file_fingerprint(
            root, list(spec["inputs"]), argv, cwd, fingerprint_environment)
        output_path = run_dir / f"{identifier}.stdout.txt"
        record: dict[str, object] = {
            "id": identifier, "status": "RUNNING", "command": argv, "cwd": str(cwd),
            "input_fingerprint_sha256": fingerprint, "input_paths": spec["inputs"],
            "input_files": input_files, "tool_versions": tool_versions,
            "fingerprint_environment": fingerprint_environment,
            "started_at": started, "resource_guard": "NOT_REQUIRED",
            "memavailable_floor_kib": MEM_FLOOR, "swapfree_floor_kib": SWAP_FLOOR,
            "guard_environment": effective_environment, "output_path": str(output_path),
        }
        if identifier == "real-cebu-corpus":
            record.update(expected_graph_sha256=GRAPH_SHA256, graph_path=str(spec["corpus_graph_path"]),
                          corpus_case_indices=spec.get("corpus_case_indices"), cases_expected_total=10,
                          corpus_completeness="COMPLETE" if spec.get("corpus_case_indices") is None
                          else "PARTIAL_CASE_SUBSET")

        def finish_record() -> None:
            record.setdefault("ended_at", utc_now())
            record["elapsed_milliseconds"] = round((time.monotonic_ns() - started_monotonic_ns) / 1_000_000, 3)

        if on_update:
            on_update(results, record)
        if output_path.exists():
            record.update(status="NOT_RUN", failure="no_clobber_output_exists", ended_at=utc_now())
            finish_record()
            results.append(record)
            if on_update:
                on_update(results, None)
            continue
        missing = [argv[0]] if not (Path(argv[0]).is_absolute() and Path(argv[0]).exists()) and shutil.which(argv[0]) is None else []
        missing.extend(f"source input {entry['path']}" for entry in input_files if entry["sha256"] == "MISSING")
        if identifier in {"preview-state", "preview-contract", "commute-card", "ride-mode", "request-gate", "place-search", "search-keyboard", "validation-ledger", "can-i-ride", "ux-contract", "search-and-can-i-ride"}:
            if not shutil.which("node"):
                missing.append("node")
        if identifier == "syntax-and-json":
            missing.extend(f"{tool} required for static syntax checks" for tool in ("node", "php", "bash") if not shutil.which(tool))
        if identifier in {"typescript", "eslint"}:
            if not shutil.which("npm"):
                missing.append("npm")
        if identifier in {"adapter-phpunit", "real-cebu-corpus"} and not (root / "api/vendor/autoload.php").is_file():
            missing.append("api/vendor/autoload.php")
        if identifier == "php-corpus-report-writer" and not (root / "api/vendor/autoload.php").is_file():
            missing.append("api/vendor/autoload.php")
        if identifier == "typescript" and not (root / "web/node_modules/.bin/tsc").is_file():
            missing.append("web/node_modules/.bin/tsc")
        if identifier == "eslint" and not (root / "web/node_modules/.bin/eslint").is_file():
            missing.append("web/node_modules/.bin/eslint")
        if identifier in {"doctor-v42-contract", "doctor-v42-report-validator"} and not (root / "docs/reports/batch042-doctor-v42.json").is_file():
            missing.append("docs/reports/batch042-doctor-v42.json")
        if identifier == "doctor-v43-report-validator" and not (root / "docs/reports/batch043-doctor-v43.json").is_file():
            missing.append("docs/reports/batch043-doctor-v43.json")
        if identifier == "doctor-v44-report-validator" and not (root / "docs/reports/batch044-doctor-v44.json").is_file():
            missing.append("docs/reports/batch044-doctor-v44.json")
        if missing:
            record.update(status="BLOCKED_DEPENDENCY", missing_dependencies=missing, ended_at=utc_now())
            finish_record()
            results.append(record)
            if on_update:
                on_update(results, None)
            continue
        command = argv
        environment = os.environ.copy()
        if guarded:
            safe_id = identifier.replace("_", "-")
            cancel_path = run_dir / f"{safe_id}.cancel-pid"
            guard_log = run_dir / f"{safe_id}.resource.log"
            token = f"biyahe-batch044-{safe_id}"
            command = ["bash", str(resource_guard), "--operation", safe_id[:40], "--disk-path", str(run_dir),
                       "--cancel-pid-file", str(cancel_path), "--cancel-match", token,
                       "--log", str(guard_log), "--", *argv]
            environment.update(effective_environment)
            record.update(resource_guard="PENDING", resource_log=str(guard_log))
        else:
            environment.update(effective_environment)
        try:
            with output_path.open("xb") as output:
                result = subprocess.run(command, cwd=cwd, env=environment, stdout=output,
                                        stderr=subprocess.STDOUT, check=False)
            exit_code = result.returncode
            resource_text = ""
            if guarded:
                guard_log_path = Path(str(record["resource_log"]))
                if guard_log_path.is_file():
                    resource_text = guard_log_path.read_text(encoding="utf-8", errors="replace")
            output_text = output_path.read_text(encoding="utf-8", errors="replace")
            actual_graph_match = re.search(r"GRAPH_SHA256=([a-f0-9]{64})", output_text)
            if actual_graph_match:
                actual_graph_sha = actual_graph_match.group(1)
                record["actual_graph_sha256"] = actual_graph_sha
                record["graph_hash_observed_at_utc"] = utc_now()
                record["graph_identity"] = "VERIFIED_SHA256_READ_ONLY" if actual_graph_sha == GRAPH_SHA256 else "MISMATCH"
                record["input_fingerprint_sha256"] = canonical_hash({
                    "guarded_input_fingerprint_sha256": fingerprint,
                    "external_input_fingerprints": [{"path": str(spec.get("corpus_graph_path")), "sha256": actual_graph_sha}],
                })
                record["external_input_fingerprints"] = [{"path": str(spec.get("corpus_graph_path")), "sha256": actual_graph_sha}]
            if identifier == "real-cebu-corpus":
                completeness_match = re.search(r"CORPUS_COMPLETENESS=(COMPLETE|PARTIAL)", output_text)
                if completeness_match:
                    record["corpus_completeness"] = completeness_match.group(1)
                cases_match = re.search(r"CASES=(\d+)/(\d+)", output_text)
                if cases_match:
                    record["cases_executed"] = int(cases_match.group(1))
                    record["cases_expected_total"] = int(cases_match.group(2))
                comparison_match = re.search(r"CORPUS_COMPARISON=(PASS|FAIL)", output_text)
                if comparison_match:
                    record["corpus_comparison"] = comparison_match.group(1)
            status, guard_status, failure = classify_command_result(exit_code, output_text, resource_text, guarded)
            record.update(status=status, exit_code=exit_code, resource_guard=guard_status,
                          output_path=str(output_path), output_sha256=sha256_file(output_path), ended_at=utc_now())
            finish_record()
            if failure is not None:
                record["failure"] = failure
            artifacts = []
            for argument in argv:
                if argument.startswith("--output="):
                    candidate = Path(argument.split("=", 1)[1])
                    if candidate.is_file():
                        artifacts.append({"path": str(candidate), "sha256": sha256_file(candidate), "size_bytes": candidate.stat().st_size})
            if artifacts:
                record["artifacts"] = artifacts
            if guarded:
                record.update(resource_log_sha256=sha256_file(Path(str(record["resource_log"]))) if Path(str(record["resource_log"])).is_file() else None,
                              worker_min_memavailable_kib=find_metric(resource_text, "min_memavailable_kb"),
                              worker_min_swapfree_kib=find_metric(resource_text, "min_swapfree_kb"),
                              worker_peak_rss_kib=find_metric(resource_text, "peak_rss_kb"),
                              worker_peak_postgres_rss_kib=find_metric(resource_text, "peak_postgres_rss_kb"))
            child_results = parse_child_command_results(output_text)
            if child_results:
                record["child_command_results"] = child_results
            record["test_count"] = parse_test_count(output_text)
            record["assertion_count"] = parse_assertion_count(output_text)
            record["reported_check_markers"] = parse_check_marker_count(output_text)
            record["failure_count"] = 0 if status == "PASS" else (parse_failure_marker_count(output_text) if status == "FAIL" else None)
        except OSError as error:
            record.update(status="FAIL", failure=f"unable to execute command: {error}", ended_at=utc_now())
            finish_record()
        results.append(record)
        if on_update:
            on_update(results, None)
    return results


def find_metric(text: str, key: str) -> int | None:
    import re
    qualifying_lines = [line for line in text.splitlines()
                        if "preflight_summary=" in line or "sample_elapsed_seconds=" in line
                        or "operation=complete" in line or "operation=stopped_for_resource_budget" in line]
    values = [value for line in qualifying_lines
              for value in re.findall(rf"(?:^|\s)(?:preflight_summary=)?{key}=([0-9]+)(?:\s|$)", line)]
    return min((int(value) for value in values), default=None) if "min_" in key else max((int(value) for value in values), default=None)


def parse_test_count(output: str) -> int | None:
    import re
    patterns = [r"\bRan\s+(\d+)\s+tests?\b", r"\b(\d+)\s+tests?\b", r"\bTests:\s*(\d+)\b"]
    counts = [int(match.group(1)) for pattern in patterns for match in re.finditer(pattern, output, re.IGNORECASE)]
    return max(counts) if counts else None


def parse_assertion_count(output: str) -> int | None:
    import re
    counts = [int(value) for value in re.findall(r"\b(\d+)\s+assertions?\b", output, re.IGNORECASE)]
    return sum(counts) if counts else None


def parse_check_marker_count(output: str) -> int:
    import re
    return len(re.findall(r"\b[A-Z][A-Z0-9_]*=(?:PASS|FAIL)\b", output))


def parse_failure_marker_count(output: str) -> int | None:
    import re
    failures = len(re.findall(r"\b[A-Z][A-Z0-9_]*=FAIL\b", output))
    return failures or None


def aggregate(results: list[dict[str, object]]) -> str:
    statuses = [str(result["status"]) for result in results]
    if any(status == "FAIL" for status in statuses):
        return "FAIL"
    if any(status == "BLOCKED_RESOURCE" for status in statuses):
        return "BLOCKED_RESOURCE"
    if any(status == "BLOCKED_DEPENDENCY" for status in statuses):
        return "BLOCKED_DEPENDENCY"
    if any(status == "NOT_RUN" for status in statuses):
        return "NOT_RUN"
    return "PASS" if statuses and all(status == "PASS" for status in statuses) else "NOT_RUN"


def classify_command_result(exit_code: int, output_text: str,
                           resource_text: str, guarded: bool) -> tuple[str, str, str | None]:
    unsupported_node = "--experimental-strip-types" in output_text and any(
        fragment in output_text.lower() for fragment in ("bad option", "unknown option", "not supported"))
    if exit_code != 0 and "BLOCKED_DEPENDENCY=" in output_text:
        failure = next((line.split("BLOCKED_DEPENDENCY=", 1)[1] for line in output_text.splitlines()
                        if "BLOCKED_DEPENDENCY=" in line), "dependency gate")
        return "BLOCKED_DEPENDENCY", "PASS" if guarded else "NOT_REQUIRED", failure
    if exit_code != 0 and "BLOCKED_RESOURCE=" in output_text:
        failure = next((line.split("BLOCKED_RESOURCE=", 1)[1] for line in output_text.splitlines()
                        if "BLOCKED_RESOURCE=" in line), "resource gate")
        return "BLOCKED_RESOURCE", "BLOCKED", failure
    if exit_code != 0 and unsupported_node:
        return "BLOCKED_DEPENDENCY", "PASS" if guarded else "NOT_REQUIRED", "Node.js does not support --experimental-strip-types"
    if guarded and (exit_code in {4, 6} or "command_not_started=yes" in resource_text
                    or "stopped_for_resource_budget" in resource_text):
        return "BLOCKED_RESOURCE", "BLOCKED", "resource guard refused or stopped the owned worker"
    return ("PASS" if exit_code == 0 else "FAIL"), ("PASS" if guarded else "NOT_REQUIRED"), None


def corpus_execution_claim(result: dict[str, object] | None) -> str:
    if result is None:
        return "NOT_RUN"
    if result.get("status") != "PASS":
        return str(result.get("status"))
    return "PASS" if result.get("corpus_completeness") == "COMPLETE" else "PARTIAL_CASE_SUBSET_MATCHED"


def parse_corpus_case_indices(value: str | None) -> list[int] | None:
    if value is None:
        return None
    parts = value.split(",")
    if not parts or any(re.fullmatch(r"(?:0|[1-9][0-9]*)", part, flags=re.ASCII) is None for part in parts):
        raise ValueError("--corpus-cases must be comma-separated zero-based indices from 0 through 9")
    indices = [int(part) for part in parts]
    if any(index < 0 or index >= 10 for index in indices):
        raise ValueError("--corpus-cases indices must be between 0 and 9")
    if len(set(indices)) != len(indices):
        raise ValueError("--corpus-cases indices must not be repeated")
    return sorted(indices)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--mode", choices=["STATIC", "FRONTEND", "ADAPTER", "CORPUS", "FULL"], default="STATIC")
    parser.add_argument("--suite", help="run only one named suite available in the selected mode")
    parser.add_argument("--repo-root", type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument("--output-root", type=Path, default=Path.home() / ".cache/biyahe/batch044/portable-verification")
    parser.add_argument("--source-revision", help="verified Git revision for a packaged source snapshot without .git metadata")
    parser.add_argument("--corpus-cases", help="run only selected zero-based Cebu corpus case indices, such as 0,3")
    parser.add_argument("--graph-path", type=Path, help="explicit pinned SQLite graph path for external CORPUS/FULL execution")
    args = parser.parse_args()
    try:
        corpus_case_indices = parse_corpus_case_indices(args.corpus_cases)
    except ValueError as error:
        parser.error(str(error))
    if corpus_case_indices is not None and args.mode not in {"CORPUS", "FULL"}:
        parser.error("--corpus-cases is available only with CORPUS or FULL mode")
    if args.graph_path is not None and args.mode not in {"CORPUS", "FULL"}:
        parser.error("--graph-path is available only with CORPUS or FULL mode")
    root = args.repo_root.resolve()
    output_root = args.output_root.resolve()
    if not (root / "api/scripts/run-bounded-process.sh").is_file():
        print(f"ERROR=resource guard missing under repository root {root}", file=sys.stderr)
        return 2
    output_root.mkdir(parents=True, exist_ok=True)
    lock_path = acquire_lock(output_root)
    try:
        recovered = recover_stale_runs(output_root)
        run_id = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ") + f"-{os.getpid()}"
        run_dir = output_root / run_id
        run_dir.mkdir(parents=False, exist_ok=False)
        status_path = run_dir / "run-status.json"
        current: dict[str, object] = {"format": "biyahe-portable-verification-run-v1", "mode": args.mode,
            "status": "RUNNING", "pid": os.getpid(), "repository_root": str(root), "started_at": utc_now(),
            "run_directory": str(run_dir), "recovered_interrupted_runs": recovered, "suites": []}
        if args.suite:
            current["selected_suite"] = args.suite
        if args.graph_path is not None:
            current["requested_graph_path"] = str(args.graph_path.expanduser().resolve())
        try:
            git_revision = subprocess.check_output(["git", "-C", str(root), "rev-parse", "HEAD"], text=True, stderr=subprocess.DEVNULL).strip()
            current["source_revision"] = git_revision
            executable_revision = args.source_revision or git_revision
            if len(executable_revision) != 40 or any(char not in "0123456789abcdef" for char in executable_revision):
                raise ValueError("executable source revision must be a full lowercase Git commit hash")
            subprocess.run(["git", "-C", str(root), "cat-file", "-e", f"{executable_revision}^{{commit}}"], check=True,
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            if subprocess.run(["git", "-C", str(root), "merge-base", "--is-ancestor", executable_revision, git_revision], check=False).returncode:
                raise ValueError("executable source revision is not an ancestor of repository HEAD")
            current["executable_source_revision"] = executable_revision
        except (OSError, subprocess.CalledProcessError):
            if args.source_revision and (len(args.source_revision) != 40 or any(char not in "0123456789abcdef" for char in args.source_revision)):
                raise ValueError("packaged source revision must be a full lowercase Git commit hash")
            current["source_revision"] = "SOURCE_SNAPSHOT_NO_GIT_METADATA"
            current["executable_source_revision"] = args.source_revision or "SOURCE_SNAPSHOT_NO_GIT_METADATA"
        atomic_json(status_path, current)
        try:
            graph_path = args.graph_path.expanduser().resolve() if args.graph_path is not None else None
            specs = select_specs(command_specs(root, args.mode, run_dir, corpus_case_indices, graph_path), args.suite)
        except ValueError as error:
            current.update(status="NOT_RUN", ended_at=utc_now(), selection_error=str(error))
            atomic_json(status_path, current)
            print(f"SUITE_SELECTION_ERROR={error}", file=sys.stderr)
            return 2

        def persist_progress(done: list[dict[str, object]], active: dict[str, object] | None) -> None:
            current["suites"] = list(done)
            current["active_suite"] = active
            atomic_json(status_path, current)

        results = run_specs(root, run_dir, specs, root / "api/scripts/run-bounded-process.sh", persist_progress)
        current["suites"] = results
        current["active_suite"] = None
        current["status"] = aggregate(results)
        current["ended_at"] = utc_now()
        corpus_result = next((row for row in results if row["id"] == "real-cebu-corpus"), None)
        graph_claim = corpus_execution_claim(corpus_result)
        current["execution_claims"] = {"graph_replay": graph_claim,
            "postgresql": "NOT_RUN", "external_host": "NOT_RUN", "browser": "NOT_RUN", "field_verification": "NOT_RUN"}
        checksum_lines = []
        for evidence_file in sorted(path for path in run_dir.rglob("*") if path.is_file() and path.name not in {"SHA256SUMS", "run-status.json"}):
            checksum_lines.append(f"{sha256_file(evidence_file)}  {evidence_file.relative_to(run_dir).as_posix()}")
        checksum_path = run_dir / "SHA256SUMS"
        checksum_path.write_text("\n".join(checksum_lines) + ("\n" if checksum_lines else ""), encoding="utf-8")
        current["evidence_checksums"] = {"path": str(checksum_path), "sha256": sha256_file(checksum_path), "files": len(checksum_lines)}
        atomic_json(status_path, current)
        print(f"RUN_DIRECTORY={run_dir}")
        print(f"MODE={args.mode}")
        if args.suite:
            print(f"SELECTED_SUITE={args.suite}")
        for result in results:
            print(f"SUITE={result['id']} STATUS={result['status']} INPUT_SHA256={result['input_fingerprint_sha256']}")
        print(f"OVERALL={current['status']}")
        print(f"EVIDENCE={status_path}")
        return 0 if current["status"] == "PASS" else 1
    finally:
        try:
            lock_contents = lock_path.read_text(encoding="utf-8")
            if f"pid={os.getpid()}\n" in lock_contents:
                lock_path.unlink()
        except FileNotFoundError:
            pass


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except KeyboardInterrupt:
        print("RUN_INTERRUPTED=YES; latest status remains RUNNING and must be recovered as NOT_RUN", file=sys.stderr)
        raise SystemExit(130)
    except Exception as error:  # A failed runner must never publish a PASS.
        print(f"RUNNER_ERROR={type(error).__name__}:{error}", file=sys.stderr)
        raise SystemExit(2)
