#!/usr/bin/env python3
"""Build Doctor V42 from current fingerprinted runner records."""

from __future__ import annotations

import argparse
import ast
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
from datetime import datetime, timezone

ROOT = Path(__file__).resolve().parents[2]
SOURCE_FILES = [
    "web/src/app/page.tsx", "web/src/app/page.module.css", "web/src/app/globals.css",
    "web/src/components/CommuteCard.tsx", "web/src/components/DisruptionNotice.tsx",
    "web/src/components/MapView.tsx", "web/src/components/NearbyRoutes.tsx",
    "web/src/components/BiyahePulse.tsx", "web/src/components/PersonalCommuteTools.tsx",
    "web/src/components/FieldMode.tsx",
    "web/src/components/ReplanPanel.tsx",
    "web/src/components/RideMode.tsx", "web/src/lib/api.ts",
    "web/src/lib/canIRidePresentation.ts", "web/src/lib/commuteInstructions.ts",
    "web/src/lib/journeyExplanation.ts", "web/src/lib/journeyMapPresentation.ts",
    "web/src/lib/latestPlaceSearch.ts", "web/src/lib/nearbyLookup.ts", "web/src/lib/offlineWalkingPreviewState.ts",
    "web/src/lib/requestGate.ts", "web/src/lib/rideMode.ts", "web/src/lib/savedJourneys.ts",
    "web/src/lib/searchKeyboard.ts", "web/scripts/offline-walking-preview-harness.mjs",
    "web/scripts/offline-walking-preview-state-harness.mjs", "web/scripts/commute-card-harness.mjs",
    "web/scripts/ride-mode-harness.mjs", "web/scripts/request-gate-harness.mjs",
    "web/scripts/place-search-harness.mjs", "web/scripts/search-keyboard-harness.mjs",
    "web/scripts/can-i-ride-harness.mjs", "web/scripts/ux-contract-harness.mjs", "web/scripts/nearby-harness.mjs",
    "web/scripts/validation-ledger.mjs", "web/scripts/validation-ledger-harness.mjs",
    "web/public/sw.js", "web/public/manifest.webmanifest",
    "web/package.json", "web/package-lock.json", "web/tsconfig.json", "web/eslint.config.mjs",
    "api/composer.json", "api/composer.lock", "api/phpunit.xml", "api/routes/api.php",
    "api/config/offline_walking_preview.php",
    "api/app/Console/Commands/VerifyOfflineWalkingCorpus.php",
    "api/app/Http/Controllers/Api/DevelopmentOfflineWalkingPreviewController.php",
    "api/app/Services/Routing/OfflineJourneyWalkingPreview.php",
    "api/app/Services/Routing/OfflinePedestrianGraphRouter.php",
    "api/app/Services/Routing/OfflinePedestrianRouteGeometryContract.php",
    "api/app/Services/Routing/OfflineWalkingPreviewException.php",
    "api/tests/Support/OfflinePedestrianGraphFixture.php",
    "api/tests/Unit/OfflineJourneyWalkingPreviewTest.php",
    "api/tests/Unit/OfflinePedestrianGraphRouterTest.php",
    "api/tests/Unit/OfflinePedestrianRouteGeometryContractTest.php",
    "api/scripts/evidence_provenance.py", "api/scripts/validate-batch041-provenance.py",
    "api/scripts/test_evidence_provenance.py", "api/scripts/summarize-offline-coverage.py",
    "api/scripts/test_summarize_offline_coverage.py", "api/scripts/offline-evidence-smoke.py",
    "api/scripts/test_offline_evidence_smoke.py", "api/scripts/compare-offline-corpus.py",
    "api/scripts/test_compare_offline_corpus.py", "api/scripts/test_offline_route_geometry_contract.php",
    "docs/testing/batch041-test-plan.json",
    "api/scripts/run-bounded-process.sh", "api/scripts/run-portable-verification.py",
    "api/scripts/test_portable_verification.py",
    "api/scripts/check-batch042-static-syntax.py", "api/scripts/package-batch041-verification-kit.py",
    "api/scripts/refresh-batch042-doctor.py", "api/scripts/validate-batch042-doctor.py",
    "api/scripts/test_validate_batch042_doctor.py", "docs/testing/batch042-test-plan.json",
]
GRAPH_SHA256 = "29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8"
ALLOWED = {"VERIFIED", "IMPLEMENTED_UNTESTED", "BLOCKED_RESOURCE", "BLOCKED_DEPENDENCY", "NOT_RUN", "FAILED"}
CHILD_INPUTS = {
    "place-search-harness.mjs": ["web/scripts/place-search-harness.mjs", "web/src/lib/latestPlaceSearch.ts"],
    "search-keyboard-harness.mjs": ["web/scripts/search-keyboard-harness.mjs", "web/src/lib/searchKeyboard.ts"],
    "can-i-ride-harness.mjs": ["web/scripts/can-i-ride-harness.mjs", "web/src/lib/canIRidePresentation.ts", "web/src/app/page.tsx"],
    "ux-contract-harness.mjs": [
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
        "web/src/lib/api.ts", "web/public/sw.js", "web/public/manifest.webmanifest",
    ],
    "validation-ledger-harness.mjs": ["web/scripts/validation-ledger-harness.mjs", "web/scripts/validation-ledger.mjs"],
}


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z")


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def verify_portable_static_run(output_path: Path, manifest_path: Path) -> dict:
    package_root = manifest_path.parent
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    if manifest.get("format") != "biyahe-portable-offline-verification-kit-v4" or manifest.get("batch") != 42:
        raise ValueError("portable package manifest identity is invalid")
    sums_path = package_root / "SHA256SUMS"
    expected_files: dict[str, str] = {}
    for line_number, line in enumerate(sums_path.read_text(encoding="utf-8").splitlines(), 1):
        match = re.fullmatch(r"([0-9a-f]{64})  ([^\n]+)", line)
        if not match:
            raise ValueError(f"portable SHA256SUMS line {line_number} is malformed")
        digest, relative = match.groups()
        if relative.startswith("/") or ".." in Path(relative).parts or relative in expected_files:
            raise ValueError(f"portable SHA256SUMS line {line_number} has an unsafe path")
        expected_files[relative] = digest
    actual_files = {path.relative_to(package_root).as_posix() for path in package_root.rglob("*")
                    if path.is_file() and path.name != "SHA256SUMS"}
    if actual_files != set(expected_files):
        raise ValueError("portable package inventory differs from SHA256SUMS")
    for relative, expected in expected_files.items():
        path = package_root / relative
        if path.is_symlink() or not path.is_file() or sha256(path) != expected:
            raise ValueError(f"portable package checksum mismatch: {relative}")

    output_text = output_path.read_text(encoding="utf-8", errors="replace")
    evidence_match = re.search(r"(?m)^EVIDENCE=(.+)$", output_text)
    run_dir_match = re.search(r"(?m)^RUN_DIRECTORY=(.+)$", output_text)
    if "MODE=STATIC" not in output_text or "OVERALL=PASS" not in output_text or not evidence_match or not run_dir_match:
        raise ValueError("portable verification output does not identify a successful STATIC run")
    status_path = Path(evidence_match.group(1).strip())
    run_dir = Path(run_dir_match.group(1).strip())
    if status_path.parent != run_dir:
        raise ValueError("portable run-status path does not match its recorded run directory")
    run_status = json.loads(status_path.read_text(encoding="utf-8"))
    if (run_status.get("mode") != "STATIC" or run_status.get("status") != "PASS"
        or run_status.get("executable_source_revision") != manifest.get("executable_source_revision")):
        raise ValueError("portable run status is not bound to this package revision")
    suites = run_status.get("suites")
    if not isinstance(suites, list) or not suites or any(row.get("status") != "PASS" for row in suites):
        raise ValueError("portable STATIC run contains a failed, blocked, or unrun suite")
    checksum_record = run_status.get("evidence_checksums", {})
    checksum_file = Path(checksum_record.get("path", ""))
    if not checksum_file.is_file() or sha256(checksum_file) != checksum_record.get("sha256"):
        raise ValueError("portable run evidence checksum file is missing or changed")
    for line_number, line in enumerate(checksum_file.read_text(encoding="utf-8").splitlines(), 1):
        match = re.fullmatch(r"([0-9a-f]{64})  ([^\n]+)", line)
        if not match:
            raise ValueError(f"portable run evidence checksum line {line_number} is malformed")
        digest, relative = match.groups()
        artifact = checksum_file.parent / relative
        if relative.startswith("/") or ".." in Path(relative).parts or not artifact.is_file() or sha256(artifact) != digest:
            raise ValueError(f"portable run evidence checksum failed: {relative}")
    for row in suites:
        artifact = Path(row.get("output_path", ""))
        if not artifact.is_file() or sha256(artifact) != row.get("output_sha256"):
            raise ValueError(f"portable suite output is missing or changed: {row.get('id')}")
        for item in row.get("input_files", []):
            relative = item.get("path")
            source = package_root / "source-snapshot" / str(relative)
            if not source.is_file() or sha256(source) != item.get("sha256"):
                raise ValueError(f"portable suite source fingerprint changed: {relative}")
    input_files = [{"path": (package_root / relative).relative_to(ROOT).as_posix(), "sha256": digest}
                   for relative, digest in sorted(expected_files.items())]
    input_files.append({"path": sums_path.relative_to(ROOT).as_posix(), "sha256": sha256(sums_path)})
    input_files.sort(key=lambda item: item["path"])
    command = ["bash", "verify-kit.sh", "STATIC"]
    cwd = str(package_root)
    fingerprint_environment = {key: os.environ.get(key, "") for key in ("PATH", "LANG", "LC_ALL", "TZ")}
    tool_versions = {}
    for tool in ("bash", "python3", "sha256sum"):
        executable = shutil.which(tool)
        if not executable:
            tool_versions[tool] = "MISSING"
        else:
            resolved = Path(executable).resolve()
            tool_versions[tool] = f"path={resolved};sha256={sha256(resolved)}"
    fingerprint_payload = {"files": input_files, "command": command, "cwd": cwd,
                          "environment": fingerprint_environment, "tools": tool_versions}
    return {"status": "VERIFIED", "executed": True, "historical_only": False,
            "command": command, "cwd": cwd,
            "input_files": input_files, "tool_versions": tool_versions,
            "fingerprint_environment": fingerprint_environment,
            "command_fingerprint_sha256": hashlib.sha256(json.dumps(fingerprint_payload, sort_keys=True, separators=(",", ":")).encode()).hexdigest(),
            "output_path": str(output_path), "output_sha256": sha256(output_path),
            "manifest_path": str(manifest_path), "manifest_sha256": sha256(manifest_path),
            "run_status_path": str(status_path), "run_status_sha256": sha256(status_path),
            "source_revision": manifest["repository_revision"],
            "resource_guard": "NOT_REQUIRED_STATIC_ONLY"}


def atomic_write(path: Path, value: object) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".tmp", dir=path.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as stream:
            json.dump(value, stream, ensure_ascii=False, indent=2, sort_keys=True)
            stream.write("\n")
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
    finally:
        try:
            os.unlink(temporary)
        except FileNotFoundError:
            pass


def map_status(status: str | None) -> str:
    return {"PASS": "VERIFIED", "FAIL": "FAILED", "BLOCKED": "BLOCKED_RESOURCE",
            "BLOCKED_RESOURCE": "BLOCKED_RESOURCE", "BLOCKED_DEPENDENCY": "BLOCKED_DEPENDENCY",
            "NOT_RUN": "NOT_RUN", "RUNNING": "NOT_RUN"}.get(status or "NOT_RUN", "NOT_RUN")


def find_suite(runs: list[dict], suite_id: str, prefer_completed: bool = True) -> dict | None:
    latest = None
    for run in reversed(runs):
        for suite in reversed(run.get("suites", [])):
            if suite.get("id") == suite_id:
                result = dict(suite)
                result["_run_source_revision"] = run.get("executable_source_revision", run.get("source_revision"))
                result["_run_directory"] = run.get("run_directory")
                if latest is None:
                    latest = result
                if not prefer_completed or suite.get("status") in {"PASS", "FAIL"}:
                    return result
    return latest


def parse_child_command_results(output_text: str) -> list[dict[str, object]]:
    children: list[dict[str, object]] = []
    for line in output_text.splitlines():
        worker_marker = "worker: "
        candidate = line.split(worker_marker, 1)[1] if worker_marker in line else line
        if not candidate.startswith("CHILD_COMMAND=") or " EXIT=" not in candidate:
            continue
        command_text, exit_text = candidate[len("CHILD_COMMAND="):].rsplit(" EXIT=", 1)
        try:
            argv = ast.literal_eval(command_text)
            exit_code = int(exit_text)
            if not isinstance(argv, list) or not all(isinstance(item, str) for item in argv):
                continue
        except (ValueError, SyntaxError):
            continue
        children.append({"argv": argv, "exit_code": exit_code,
                         "status": "PASS" if exit_code == 0 else "FAIL"})
    return children


def find_child(runs: list[dict], suite_id: str, fragment: str) -> tuple[dict | None, dict | None]:
    latest_parent = None
    for run in reversed(runs):
        parent = find_suite([run], suite_id, prefer_completed=False)
        if not parent:
            continue
        if latest_parent is None:
            latest_parent = parent
        output_value = parent.get("output_path")
        output_digest = parent.get("output_sha256")
        children = []
        child_record_conflict = False
        if isinstance(output_value, str) and isinstance(output_digest, str):
            output_path = Path(output_value)
            if output_path.is_file() and sha256(output_path) == output_digest:
                children = parse_child_command_results(output_path.read_text(encoding="utf-8", errors="replace"))
        recorded_children = parent.get("child_command_results")
        if isinstance(recorded_children, list):
            def normalized(items: list[dict]) -> list[tuple[object, object, object]]:
                return [(item.get("argv"), item.get("status"), item.get("exit_code"))
                        for item in items if isinstance(item, dict)]
            if normalized(recorded_children) != normalized(children):
                child_record_conflict = True
        for child in children:
            argv = child.get("argv", [])
            if any(fragment in str(part) for part in argv):
                result = dict(child)
                marker = next((name for name in CHILD_INPUTS if any(name in str(part) for part in argv)), None)
                expected_paths = CHILD_INPUTS.get(marker, [])
                recorded = {item.get("path"): item for item in parent.get("input_files", []) if isinstance(item, dict)}
                missing = sorted(path for path in expected_paths if path not in recorded or recorded[path].get("sha256") == "MISSING")
                result["_input_files"] = [recorded[path] for path in sorted(expected_paths) if path in recorded]
                result["_missing_inputs"] = missing
                result["_record_conflict"] = child_record_conflict
                return parent, result
    return latest_parent, None


def make_test(name: str, row: dict | None, child: dict | None = None, root: Path = ROOT) -> dict:
    if row is None:
        return {"status": "NOT_RUN", "executed": False, "reason": "no current runner record was supplied"}
    outer_status = str(row.get("status", "NOT_RUN"))
    input_files = child.get("_input_files", []) if child is not None else row.get("input_files", [])
    command = child.get("argv") if child is not None else row.get("command")
    fingerprint_environment = row.get("fingerprint_environment", {})
    tool_versions = row.get("tool_versions", {})
    if child is not None:
        command_tool_names = {str(command[0]), "python3", "node", "php", "bash", "npm", "composer"}
        tool_versions = {key: value for key, value in tool_versions.items()
                         if key in command_tool_names or key == "python_runtime" or key.startswith("locked:") or key == "package-lock"}
        fingerprint_payload = {"files": input_files, "command": command, "cwd": row.get("cwd"),
                               "environment": fingerprint_environment, "tools": tool_versions}
        command_fingerprint = hashlib.sha256(json.dumps(
            fingerprint_payload, sort_keys=True, separators=(",", ":")).encode()).hexdigest()
    else:
        command_fingerprint = row.get("input_fingerprint_sha256")
    if child is not None:
        attempted_child_status = map_status(child.get("status"))
        status = "NOT_RUN" if child.get("_missing_inputs") else attempted_child_status
    elif outer_status in {"BLOCKED_RESOURCE", "BLOCKED_DEPENDENCY", "NOT_RUN"}:
        status = map_status(outer_status)
    else:
        status = map_status(outer_status)
    invalidations = [f"missing_child_input:{path}" for path in (child or {}).get("_missing_inputs", [])]
    if child is not None and child.get("_record_conflict"):
        invalidations.append("conflicting_child_result_record")
    if status in {"VERIFIED", "FAILED"}:
        for item in input_files:
            relative = Path(str(item.get("path", "")))
            if relative.is_absolute() or ".." in relative.parts:
                invalidations.append(f"unsafe_input_path:{relative}")
                continue
            if relative.as_posix() == "docs/reports/batch042-doctor-v42.json":
                invalidations.append("self_referential_doctor_report_input")
                continue
            source_path = root / relative
            expected = item.get("sha256")
            if not source_path.is_file():
                invalidations.append(f"missing_input:{relative}")
            elif expected != sha256(source_path):
                invalidations.append(f"changed_input:{relative}")
        output_path_value = row.get("output_path")
        output_sha = row.get("output_sha256")
        if status == "VERIFIED" and output_sha is None:
            invalidations.append("missing_evidence_output_hash")
        if output_sha is not None:
            if not isinstance(output_path_value, str) or not Path(output_path_value).is_file():
                invalidations.append("missing_evidence_output")
            elif output_sha != sha256(Path(output_path_value)):
                invalidations.append("changed_evidence_output")
    attempted_status = status
    if invalidations:
        status = "NOT_RUN"
    output_sha = row.get("output_sha256")
    result = {
        "status": status,
        "executed": status in {"VERIFIED", "FAILED"},
        "historical_only": False,
        "command_fingerprint_sha256": command_fingerprint,
        "input_files": input_files,
        "tool_versions": tool_versions,
        "fingerprint_environment": fingerprint_environment,
        "cwd": row.get("cwd"),
        "output_path": row.get("output_path"),
        "output_sha256": None if invalidations else output_sha,
        "resource_guard": row.get("resource_guard", "NOT_REQUIRED"),
        "guard_environment": row.get("guard_environment", {}),
        "memavailable_floor_kib": row.get("memavailable_floor_kib"),
        "swapfree_floor_kib": row.get("swapfree_floor_kib"),
        "resource_log": row.get("resource_log"),
        "resource_log_sha256": row.get("resource_log_sha256"),
        "worker_min_memavailable_kib": row.get("worker_min_memavailable_kib"),
        "worker_min_swapfree_kib": row.get("worker_min_swapfree_kib"),
        "worker_peak_rss_kib": row.get("worker_peak_rss_kib"),
        "worker_peak_postgres_rss_kib": row.get("worker_peak_postgres_rss_kib"),
        "started_at": row.get("started_at"),
        "ended_at": row.get("ended_at"),
        "run_source_revision": row.get("_run_source_revision"),
        "run_directory": row.get("_run_directory"),
    }
    if child is not None:
        result.update({"command": command, "parent_status": outer_status,
                       "parent_exit_code": row.get("exit_code"),
                       "child_command": child.get("argv"),
                       "child_result_recorded": True,
                       "execution_observed_status": child.get("status")})
    if invalidations:
        result.update({"attempted_status": attempted_status, "attempted_output_sha256": output_sha,
                       "evidence_invalidated": invalidations,
                       "reason": "recorded result inputs or output no longer match current evidence"})
    if child is not None:
        result.update({"exit_code": child.get("exit_code"), "child_command": child.get("argv")})
    else:
        result.update({"exit_code": row.get("exit_code"), "command": row.get("command"),
                       "test_count": row.get("test_count"), "assertion_count": row.get("assertion_count"),
                       "reported_check_markers": row.get("reported_check_markers"),
                       "failure_count": row.get("failure_count")})
    if row.get("missing_dependencies"):
        result["missing_dependencies"] = row["missing_dependencies"]
    if row.get("failure"):
        result["failure"] = row["failure"]
    return result


def combine(*statuses: str) -> str:
    if "FAILED" in statuses:
        return "FAILED"
    for state in ("BLOCKED_RESOURCE", "BLOCKED_DEPENDENCY"):
        if state in statuses:
            return state
    return "VERIFIED" if statuses and all(status == "VERIFIED" for status in statuses) else "IMPLEMENTED_UNTESTED"


def implemented_status(status: str) -> str:
    return "IMPLEMENTED_UNTESTED" if status == "NOT_RUN" else status


def category(name: str, status: str, evidence: str) -> dict[str, str]:
    if status not in ALLOWED:
        raise ValueError(f"invalid Doctor status {status} for {name}")
    return {"name": name, "status": status, "evidence": evidence}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--run-status", action="append", type=Path, default=[])
    parser.add_argument("--executable-revision")
    parser.add_argument("--portable-output", type=Path)
    parser.add_argument("--portable-manifest", type=Path)
    parser.add_argument("--graph-path", type=Path, help="read-only graph file to hash for identity evidence")
    parser.add_argument("--output", type=Path, default=ROOT / "docs/reports/batch042-doctor-v42.json")
    args = parser.parse_args()
    try:
        runs = [json.loads(path.read_text(encoding="utf-8")) for path in args.run_status]
        repository_head = subprocess.check_output(["git", "-C", str(ROOT), "rev-parse", "HEAD"], text=True).strip()
        revision = args.executable_revision or repository_head
        if len(revision) != 40 or any(char not in "0123456789abcdef" for char in revision):
            raise ValueError("executable source revision must be a full lowercase Git commit hash")
        if subprocess.run(["git", "-C", str(ROOT), "cat-file", "-e", f"{revision}^{{commit}}"], check=False).returncode:
            raise ValueError(f"executable source revision is not an available Git commit: {revision}")
        if subprocess.run(["git", "-C", str(ROOT), "merge-base", "--is-ancestor", revision, repository_head], check=False).returncode:
            raise ValueError(f"executable source revision is not an ancestor of repository HEAD: {revision}")
        for run in runs:
            executed_revision = run.get("executable_source_revision", run.get("source_revision"))
            if executed_revision is not None and (len(executed_revision) != 40 or any(char not in "0123456789abcdef" for char in executed_revision)):
                raise ValueError(f"runner executable revision is malformed: {executed_revision}")

        tests: dict[str, dict] = {
            "source_provenance_validator": make_test("source_provenance_validator", find_suite(runs, "source-provenance")),
            "provenance_fixtures": make_test("provenance_fixtures", find_suite(runs, "provenance-fixtures")),
            "coverage_fixtures": make_test("coverage_fixtures", find_suite(runs, "coverage-fixtures")),
            "coverage_pinned_summary": make_test("coverage_pinned_summary", find_suite(runs, "coverage-pinned-summary")),
            "static_evidence_smoke": make_test("static_evidence_smoke", find_suite(runs, "static-evidence-smoke")),
            "corpus_comparator": make_test("corpus_comparator", find_suite(runs, "corpus-comparator-fixtures")),
            "php_geometry_smoke": make_test("php_geometry_smoke", find_suite(runs, "php-geometry-contract")),
            "preview_state": make_test("preview_state", find_suite(runs, "preview-state")),
            "preview_contract": make_test("preview_contract", find_suite(runs, "preview-contract")),
            "commute_cards_and_saved_v1": make_test("commute_cards_and_saved_v1", find_suite(runs, "commute-card")),
            "ride_mode": make_test("ride_mode", find_suite(runs, "ride-mode")),
            "request_gate": make_test("request_gate", find_suite(runs, "request-gate")),
        }
        for name, fragment in (("place_search", "place-search-harness"), ("search_keyboard", "search-keyboard-harness"),
                               ("can_i_ride", "can-i-ride-harness"), ("ux_contract", "ux-contract-harness"),
                               ("validation_ledger", "validation-ledger-harness")):
            parent, child = find_child(runs, "search-and-can-i-ride", fragment)
            tests[name] = make_test(name, parent, child)
        tests["typescript"] = make_test("typescript", find_suite(runs, "typescript") or find_suite(runs, "typecheck-and-eslint"))
        tests["eslint"] = make_test("eslint", find_suite(runs, "eslint"))
        tests["adapter_phpunit"] = make_test("adapter_phpunit", find_suite(runs, "adapter-phpunit"))
        tests["real_cebu_corpus"] = make_test("real_cebu_corpus", find_suite(runs, "real-cebu-corpus"))
        tests["portable_runner_contract"] = make_test("portable_runner_contract", find_suite(runs, "portable-runner-contract"))
        tests["browser_visual"] = {"status": "NOT_RUN", "executed": False, "reason": "no browser execution recorded"}

        portable = {"status": "NOT_RUN", "executed": False, "reason": "kit verification output was not supplied"}
        if args.portable_output and args.portable_manifest:
            output_path = args.portable_output.resolve()
            manifest_path = args.portable_manifest.resolve()
            if not output_path.is_file() or not manifest_path.is_file():
                raise ValueError("portable verification output or manifest is missing")
            portable = verify_portable_static_run(output_path, manifest_path)
        tests["portable_kit"] = portable

        provenance_status = tests["source_provenance_validator"]["status"]
        coverage_ok = tests["coverage_fixtures"]["status"] == "VERIFIED" and tests["coverage_pinned_summary"]["status"] == "VERIFIED"
        graph_row = find_suite(runs, "real-cebu-corpus")
        graph_observations = []
        if graph_row and graph_row.get("actual_graph_sha256"):
            graph_observations.append({"method": "corpus_preflight_sha256", "path": graph_row.get("graph_path"),
                "sha256": graph_row.get("actual_graph_sha256"), "observed_at_utc": graph_row.get("graph_hash_observed_at_utc")})
        if args.graph_path:
            graph_path = args.graph_path.resolve()
            if not graph_path.is_file():
                raise ValueError(f"graph identity file is missing: {graph_path}")
            graph_observations.append({"method": "read_only_sha256", "path": str(graph_path),
                "sha256": sha256(graph_path), "size_bytes": graph_path.stat().st_size, "observed_at_utc": utc_now()})
        observed_graph_hashes = [observation["sha256"] for observation in graph_observations]
        graph_actual = observed_graph_hashes[-1] if observed_graph_hashes else None
        graph_status = "VERIFIED" if observed_graph_hashes and all(value == GRAPH_SHA256 for value in observed_graph_hashes) else ("FAILED" if observed_graph_hashes else "NOT_RUN")
        categories = [
            category("source_provenance", provenance_status, "Current Batch042 registry validator and provenance fixtures determine this status; the registry itself binds historical source artifacts."),
            category("graph_identity", graph_status, "A current read-only SHA256 observation identifies the immutable SQLite file. Identity does not assert SQLite integrity or routing success."),
            category("graph_integrity", "NOT_RUN", "SQLite integrity_check was not run; graph identity alone does not establish internal integrity."),
            category("coverage", "VERIFIED" if coverage_ok else combine(tests["coverage_fixtures"]["status"], tests["coverage_pinned_summary"]["status"]), "Pinned 503/436/67 stops and 37/30/7 places are stored-record counts only; no geographic, population, sidewalk, or field coverage is claimed."),
            category("adapter_contract", implemented_status(tests["adapter_phpunit"]["status"]), "Status reflects the current focused adapter PHPUnit result. Pure geometry smoke is listed separately."),
            category("real_cebu_corpus", tests["real_cebu_corpus"]["status"], "Current bounded replay only; the historical 9 found / 1 disconnected result is not reused."),
            category("journey_preview", combine(tests["preview_state"]["status"], tests["preview_contract"]["status"]), "Current state-transition and component request-contract harnesses; unavailable browser behavior remains unverified."),
            category("ride_mode", implemented_status(tests["ride_mode"]["status"]), "Current manual-progress harness only; no GPS or field arrival claim."),
            category("saved_journeys", implemented_status(tests["commute_cards_and_saved_v1"]["status"]), "Current snapshot-v1 and commute-card harness, including corruption and preview persistence exclusion."),
            category("search", implemented_status(combine(tests["place_search"]["status"], tests["search_keyboard"]["status"])), "Current request cancellation and keyboard-selection harnesses using the existing search engine."),
            category("can_i_ride", implemented_status(tests["can_i_ride"]["status"]), "Current yes/no/unknown presentation fixture using the existing decision engine."),
            category("map_mobile", "IMPLEMENTED_UNTESTED", "Static CSS and map-source inspection only. Browser viewports 320, 360, and 390 px were not executed."),
            category("typescript", implemented_status(tests["typescript"]["status"]), "Current TypeScript compiler result from the guarded frontend runner."),
            category("lint", implemented_status(tests["eslint"]["status"]), "Current focused ESLint result from the guarded frontend runner."),
            category("portable_kit", combine(implemented_status(tests["portable_runner_contract"]["status"]), implemented_status(tests["portable_kit"]["status"])), "The runner contract fixtures and any supplied local STATIC package execution are independently fingerprinted. No external Linux host execution is claimed."),
            category("postgresql_staging", "BLOCKED_DEPENDENCY", "PostgreSQL was not started or contacted; real staging needs a separate controlled host."),
            category("field_safety", "BLOCKED_DEPENDENCY", "No field evidence was collected; both transfer records remain unverified for physical interchange."),
        ]

        coverage_path = ROOT / "docs/reports/batch032-cebu-coverage.json"
        provenance_path = ROOT / "docs/reports/batch041-evidence-provenance.json"
        historical_doctor_path = ROOT / "docs/reports/batch041-doctor-v41.json"
        historical_graph_fingerprint = json.loads(historical_doctor_path.read_text(encoding="utf-8"))["source_and_graph"]["graph_fingerprint"]
        report = {
            "format": "biyahe-doctor-v42-evidence-v1", "batch": 42, "assessed_at_utc": utc_now(),
            "source_revision": {"executable": revision, "tested": revision,
                "documentation_checkpoint_note": "Later report and portable-kit commits are documentation-only and are identified by the Batch042 final report; this report does not pin its own commit."},
            "source_fingerprints": {relative: sha256(ROOT / relative) for relative in SOURCE_FILES},
            "categories": categories,
            "tests": tests,
            "evidence": {
                "reconciliation": {
                    "provenance_registry_sha256": sha256(provenance_path),
                    "vehicle_only_restrictions": {"total": 648, "stored": 619, "omitted": 29},
                    "graph_quality": {"measured_values": {"long_edges": 26, "boundary_nodes": 944, "crossing_tagged_nodes": 1510}, "unmeasured_legacy_defaults_are_zero_facts": False}
                },
                "graph": {"path": "$HOME/.cache/biyahe/batch030/cebu-walk-central-visayas-20261007-v3.sqlite",
                    "expected_sha256": GRAPH_SHA256, "expected_fingerprint": historical_graph_fingerprint,
                    "fingerprint_status": "HISTORICAL_EXPECTATION_NOT_RECOMPUTED",
                    "actual_sha256": graph_actual, "status": graph_status,
                    "observations": graph_observations,
                    "observed_at_utc": graph_observations[-1].get("observed_at_utc") if graph_observations else None,
                    "graph_modified": False, "pbf_rescanned": False},
                "graph_integrity": {"status": "NOT_RUN", "method": None},
                "stored_record_coverage": {
                    "source_report": "docs/reports/batch032-cebu-coverage.json", "source_report_sha256": sha256(coverage_path),
                    "validation_status": "VERIFIED_CURRENT_FIXTURES" if coverage_ok else "NOT_RUN",
                    "stops": {"total": 503, "snappable_within_25m": 436, "unsnappable": 67, "scope": "STORED_RECORDS_ONLY", "current_batch_replay": "NOT_RUN"},
                    "places": {"total": 37, "snappable_within_25m": 30, "unsnappable": 7, "scope": "STORED_RECORDS_ONLY", "current_batch_replay": "NOT_RUN"},
                    "geographic_population_sidewalk_field_claims": "NONE"},
                "transfer_verification": {"unverified_count": 2, "field_verified": False,
                    "status": "BLOCKED_DEPENDENCY", "evidence_scope": "stored graph connectivity does not establish physical transfer safety"},
                "historical_results_only": {"batch041_corpus": {"cases": 10, "routes_found": 9, "disconnected": 1, "current_replay": False}},
            },
            "release_gates": {"live_production_graph_integration": "BLOCKED", "postgresql_staging": "BLOCKED",
                "field_safety": "BLOCKED", "private_beta": "BLOCKED", "production_activation": "NO"},
        }
        args.output.parent.mkdir(parents=True, exist_ok=True)
        atomic_write(args.output, report)
        print(f"DOCTOR_V42={args.output}")
        print(f"EXECUTABLE_SOURCE_REVISION={revision}")
        print(f"CATEGORIES={len(categories)} TEST_RECORDS={len(tests)}")
        print(f"GRAPH_IDENTITY={graph_status} REAL_CORPUS={tests['real_cebu_corpus']['status']}")
        return 0
    except (OSError, json.JSONDecodeError, subprocess.CalledProcessError, ValueError, KeyError) as error:
        print(f"DOCTOR_V42_REFRESH=FAIL: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
