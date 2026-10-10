#!/usr/bin/env python3
"""Run the pinned Biyahe verification suites with durable, honest evidence."""

from __future__ import annotations

import ast
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
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


def command_specs(root: Path, mode: str, run_dir: Path) -> list[dict[str, object]]:
    api = root / "api"
    web = root / "web"
    static: list[dict[str, object]] = [
        {"id": "source-provenance", "argv": [sys.executable, "api/scripts/validate-batch041-provenance.py"], "cwd": root, "inputs": ["api/scripts/evidence_provenance.py", "api/scripts/validate-batch041-provenance.py", "docs/reports/batch041-evidence-provenance.json"]},
        {"id": "provenance-fixtures", "argv": [sys.executable, "api/scripts/test_evidence_provenance.py"], "cwd": root, "inputs": ["api/scripts/evidence_provenance.py", "api/scripts/test_evidence_provenance.py", "docs/reports/batch041-evidence-provenance.json", "docs/reports/batch041-evidence-reconciliation.md"]},
        {"id": "coverage-fixtures", "argv": [sys.executable, "api/scripts/test_summarize_offline_coverage.py"], "cwd": root, "inputs": ["api/scripts/summarize-offline-coverage.py", "api/scripts/test_summarize_offline_coverage.py"]},
        {"id": "coverage-pinned-summary", "argv": [sys.executable, "api/scripts/summarize-offline-coverage.py", "--output-dir", str(run_dir / "coverage-pinned-summary")], "cwd": root, "inputs": ["api/scripts/summarize-offline-coverage.py", "api/scripts/evidence_provenance.py", "docs/reports/batch032-cebu-coverage.json", "docs/reports/batch041-evidence-provenance.json"]},
        {"id": "static-evidence-smoke", "argv": [sys.executable, "api/scripts/test_offline_evidence_smoke.py"], "cwd": root, "inputs": ["api/scripts/offline-evidence-smoke.py", "api/scripts/test_offline_evidence_smoke.py", "docs/reports/batch041-evidence-provenance.json"]},
        {"id": "corpus-comparator-fixtures", "argv": [sys.executable, "api/scripts/test_compare_offline_corpus.py"], "cwd": root, "inputs": ["api/scripts/compare-offline-corpus.py", "api/scripts/test_compare_offline_corpus.py"]},
        {"id": "doctor-v42-contract", "argv": [sys.executable, "api/scripts/test_validate_batch042_doctor.py"], "cwd": root, "inputs": ["api/scripts/validate-batch042-doctor.py", "api/scripts/test_validate_batch042_doctor.py", "docs/reports/batch042-doctor-v42.json"]},
        {"id": "doctor-v42-report-validator", "argv": [sys.executable, "api/scripts/validate-batch042-doctor.py"], "cwd": root, "inputs": ["api/scripts/validate-batch042-doctor.py", "api/scripts/evidence_provenance.py", "docs/reports/batch042-doctor-v42.json"]},
        {"id": "portable-runner-contract", "argv": [sys.executable, "api/scripts/test_portable_verification.py"], "cwd": root, "inputs": ["api/scripts/run-portable-verification.py", "api/scripts/test_portable_verification.py"]},
        {"id": "php-geometry-contract", "argv": ["php", "api/scripts/test_offline_route_geometry_contract.php"], "cwd": root, "inputs": ["api/scripts/test_offline_route_geometry_contract.php", "api/app/Services/Routing/OfflinePedestrianRouteGeometryContract.php"]},
        {"id": "syntax-and-json", "argv": [sys.executable, "api/scripts/check-batch042-static-syntax.py", "--root", str(root)], "cwd": root, "inputs": ["api/scripts/check-batch042-static-syntax.py", "api/scripts/run-portable-verification.py", "web/src/app/page.tsx", "web/src/components/CommuteCard.tsx", "web/src/lib/offlineWalkingPreviewState.ts", "web/src/lib/requestGate.ts", "web/src/lib/savedJourneys.ts", "web/scripts/offline-walking-preview-state-harness.mjs", "web/scripts/validation-ledger.mjs", "web/package.json", "docs/reports/batch042-doctor-v42.json", "docs/reports/batch042-work-ledger.json", "docs/testing/batch042-test-plan.json"]},
    ]
    frontend = [
        {"id": "preview-state", "argv": ["node", "--experimental-strip-types", "scripts/offline-walking-preview-state-harness.mjs"], "cwd": web, "inputs": ["web/src/lib/offlineWalkingPreviewState.ts", "web/scripts/offline-walking-preview-state-harness.mjs", "web/src/lib/api.ts"]},
        {"id": "preview-contract", "argv": ["node", "--experimental-strip-types", "scripts/offline-walking-preview-harness.mjs"], "cwd": web, "inputs": ["web/src/components/CommuteCard.tsx", "web/src/lib/offlineWalkingPreviewState.ts", "web/scripts/offline-walking-preview-harness.mjs"]},
        {"id": "commute-card", "argv": ["node", "--experimental-strip-types", "scripts/commute-card-harness.mjs"], "cwd": web, "inputs": ["web/src/lib/savedJourneys.ts", "web/src/lib/commuteInstructions.ts", "web/scripts/commute-card-harness.mjs"]},
        {"id": "ride-mode", "argv": ["node", "--experimental-strip-types", "scripts/ride-mode-harness.mjs"], "cwd": web, "inputs": ["web/src/lib/rideMode.ts", "web/scripts/ride-mode-harness.mjs"]},
        {"id": "request-gate", "argv": ["node", "--experimental-strip-types", "scripts/request-gate-harness.mjs"], "cwd": web, "inputs": ["web/src/lib/requestGate.ts", "web/scripts/request-gate-harness.mjs"]},
        {"id": "search-and-can-i-ride", "argv": [sys.executable, "-c", "import subprocess,sys; cs=[['node','--experimental-strip-types','scripts/place-search-harness.mjs'],['node','--experimental-strip-types','scripts/search-keyboard-harness.mjs'],['node','--experimental-strip-types','scripts/can-i-ride-harness.mjs'],['node','scripts/ux-contract-harness.mjs'],['node','scripts/validation-ledger-harness.mjs']]; failed=0\nfor c in cs:\n p=subprocess.run(c); print('CHILD_COMMAND='+repr(c)+' EXIT='+str(p.returncode), flush=True); failed |= p.returncode != 0\nsys.exit(1 if failed else 0)"], "cwd": web, "inputs": ["web/src/app/page.module.css", "web/src/lib/latestPlaceSearch.ts", "web/src/lib/searchKeyboard.ts", "web/src/lib/canIRidePresentation.ts", "web/scripts/place-search-harness.mjs", "web/scripts/search-keyboard-harness.mjs", "web/scripts/can-i-ride-harness.mjs", "web/scripts/ux-contract-harness.mjs", "web/scripts/validation-ledger-harness.mjs", "web/scripts/validation-ledger.mjs"]},
        {"id": "typescript", "argv": ["npm", "run", "typecheck"], "cwd": web, "inputs": ["web/package.json", "web/package-lock.json", "web/tsconfig.json", "web/src/app/page.tsx", "web/src/components/CommuteCard.tsx", "web/src/components/RideMode.tsx", "web/src/lib/offlineWalkingPreviewState.ts", "web/src/lib/requestGate.ts", "web/src/lib/savedJourneys.ts", "web/src/lib/rideMode.ts", "web/src/lib/canIRidePresentation.ts"]},
        {"id": "eslint", "argv": ["npm", "run", "lint", "--", "src/app/page.tsx", "src/components/CommuteCard.tsx", "src/components/RideMode.tsx", "src/lib/offlineWalkingPreviewState.ts", "src/lib/requestGate.ts", "src/lib/savedJourneys.ts", "src/lib/rideMode.ts", "src/lib/canIRidePresentation.ts"], "cwd": web, "inputs": ["web/package.json", "web/package-lock.json", "web/eslint.config.mjs", "web/src/app/page.tsx", "web/src/components/CommuteCard.tsx", "web/src/components/RideMode.tsx", "web/src/lib/offlineWalkingPreviewState.ts", "web/src/lib/requestGate.ts", "web/src/lib/savedJourneys.ts", "web/src/lib/rideMode.ts", "web/src/lib/canIRidePresentation.ts"]},
    ]
    adapter = [{"id": "adapter-phpunit", "argv": ["php", "artisan", "test", "--filter=OfflinePedestrianRouteGeometryContractTest|OfflinePedestrianGraphRouterTest|OfflineJourneyWalkingPreviewTest"], "cwd": api, "inputs": ["api/composer.lock", "api/phpunit.xml", "api/app/Services/Routing/OfflineJourneyWalkingPreview.php", "api/app/Services/Routing/OfflinePedestrianGraphRouter.php", "api/tests/Unit/OfflineJourneyWalkingPreviewTest.php", "api/tests/Unit/OfflinePedestrianGraphRouterTest.php", "api/tests/Unit/OfflinePedestrianRouteGeometryContractTest.php", "api/tests/Support/OfflinePedestrianGraphFixture.php"]}]
    corpus = [{"id": "real-cebu-corpus", "argv": ["php", "artisan", "biyahe:pedestrian:verify-offline-corpus", f"--output={run_dir / 'real-cebu-corpus.json'}"], "cwd": api, "inputs": ["api/composer.lock", "api/app/Console/Commands/VerifyOfflineWalkingCorpus.php", "api/scripts/compare-offline-corpus.py", "docs/testing/batch041-test-plan.json"]}]
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
        guarded = identifier in {"preview-state", "preview-contract", "commute-card", "ride-mode", "request-gate", "search-and-can-i-ride", "typescript", "eslint", "adapter-phpunit", "real-cebu-corpus"}
        effective_environment = {"PYTHONDONTWRITEBYTECODE": "1", **(GUARD_ENV if guarded else {})}
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
        if on_update:
            on_update(results, record)
        if output_path.exists():
            record.update(status="NOT_RUN", failure="no_clobber_output_exists", ended_at=utc_now())
            results.append(record)
            if on_update:
                on_update(results, None)
            continue
        missing = [argv[0]] if not (Path(argv[0]).is_absolute() and Path(argv[0]).exists()) and shutil.which(argv[0]) is None else []
        missing.extend(f"source input {entry['path']}" for entry in input_files if entry["sha256"] == "MISSING")
        if identifier in {"preview-state", "preview-contract", "commute-card", "ride-mode", "request-gate", "search-and-can-i-ride"}:
            if not shutil.which("node"):
                missing.append("node")
        if identifier == "syntax-and-json":
            missing.extend(f"{tool} required for static syntax checks" for tool in ("node", "php", "bash") if not shutil.which(tool))
        if identifier in {"typescript", "eslint"}:
            if not shutil.which("npm"):
                missing.append("npm")
        if identifier in {"adapter-phpunit", "real-cebu-corpus"} and not (root / "api/vendor/autoload.php").is_file():
            missing.append("api/vendor/autoload.php")
        if identifier == "typescript" and not (root / "web/node_modules/.bin/tsc").is_file():
            missing.append("web/node_modules/.bin/tsc")
        if identifier == "eslint" and not (root / "web/node_modules/.bin/eslint").is_file():
            missing.append("web/node_modules/.bin/eslint")
        if identifier in {"doctor-v42-contract", "doctor-v42-report-validator"} and not (root / "docs/reports/batch042-doctor-v42.json").is_file():
            missing.append("docs/reports/batch042-doctor-v42.json")
        if missing:
            record.update(status="BLOCKED_DEPENDENCY", missing_dependencies=missing, ended_at=utc_now())
            results.append(record)
            if on_update:
                on_update(results, None)
            continue
        if identifier == "real-cebu-corpus":
            graph = Path.home() / ".cache/biyahe/batch030/cebu-walk-central-visayas-20261007-v3.sqlite"
            record["expected_graph_sha256"] = GRAPH_SHA256
            record["graph_path"] = str(graph)
            if not graph.is_file():
                record.update(status="BLOCKED_DEPENDENCY", failure="pinned_graph_missing", ended_at=utc_now())
                results.append(record)
                if on_update:
                    on_update(results, None)
                continue
            actual = graph_hash(graph)
            record["actual_graph_sha256"] = actual
            record["graph_hash_observed_at_utc"] = utc_now()
            if actual != GRAPH_SHA256:
                record.update(status="BLOCKED_DEPENDENCY", failure="pinned_graph_identity_mismatch", ended_at=utc_now())
                results.append(record)
                if on_update:
                    on_update(results, None)
                continue
            record["graph_identity"] = "VERIFIED_SHA256_READ_ONLY"

        command = argv
        environment = os.environ.copy()
        if guarded:
            safe_id = identifier.replace("_", "-")
            cancel_path = run_dir / f"{safe_id}.cancel-pid"
            guard_log = run_dir / f"{safe_id}.resource.log"
            token = f"biyahe-batch042-{safe_id}"
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
            unsupported_node = "--experimental-strip-types" in output_text and any(fragment in output_text.lower() for fragment in ("bad option", "unknown option", "not supported"))
            if unsupported_node:
                status, guard_status = "BLOCKED_DEPENDENCY", "PASS" if guarded else "NOT_REQUIRED"
                record["failure"] = "Node.js does not support --experimental-strip-types"
            elif guarded and (exit_code in {4, 6} or "command_not_started=yes" in resource_text or "stopped_for_resource_budget" in resource_text):
                status, guard_status = "BLOCKED_RESOURCE", "BLOCKED"
            else:
                status, guard_status = ("PASS" if exit_code == 0 else "FAIL"), ("PASS" if guarded else "NOT_REQUIRED")
            record.update(status=status, exit_code=exit_code, resource_guard=guard_status,
                          output_path=str(output_path), output_sha256=sha256_file(output_path), ended_at=utc_now())
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
            child_results = []
            for line in output_text.splitlines():
                if not line.startswith("CHILD_COMMAND=") or " EXIT=" not in line:
                    continue
                command_text, exit_text = line[len("CHILD_COMMAND="):].rsplit(" EXIT=", 1)
                try:
                    child_argv = ast.literal_eval(command_text)
                    child_exit = int(exit_text)
                except (ValueError, SyntaxError):
                    child_results.append({"command_text": command_text, "status": "FAILED", "exit_code": None})
                    continue
                child_results.append({"argv": child_argv, "status": "PASS" if child_exit == 0 else "FAIL", "exit_code": child_exit})
            if child_results:
                record["child_command_results"] = child_results
            record["test_count"] = parse_test_count(output_text)
            record["assertion_count"] = parse_assertion_count(output_text)
            record["reported_check_markers"] = parse_check_marker_count(output_text)
            record["failure_count"] = 0 if status == "PASS" else (parse_failure_marker_count(output_text) if status == "FAIL" else None)
        except OSError as error:
            record.update(status="FAIL", failure=f"unable to execute command: {error}", ended_at=utc_now())
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


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--mode", choices=["STATIC", "FRONTEND", "ADAPTER", "CORPUS", "FULL"], default="STATIC")
    parser.add_argument("--suite", help="run only one named suite available in the selected mode")
    parser.add_argument("--repo-root", type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument("--output-root", type=Path, default=Path.home() / ".cache/biyahe/batch042/portable-verification")
    parser.add_argument("--source-revision", help="verified Git revision for a packaged source snapshot without .git metadata")
    args = parser.parse_args()
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
            specs = select_specs(command_specs(root, args.mode, run_dir), args.suite)
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
        current["execution_claims"] = {"graph_replay": "PASS" if any(row["id"] == "real-cebu-corpus" and row["status"] == "PASS" for row in results) else "NOT_RUN_OR_BLOCKED",
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
