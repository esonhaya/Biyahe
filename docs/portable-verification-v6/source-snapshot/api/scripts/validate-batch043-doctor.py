#!/usr/bin/env python3
"""Fail-closed validation for the Batch043 execution and readiness report."""

from __future__ import annotations

import argparse
from datetime import datetime
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys

ALLOWED = {"VERIFIED", "IMPLEMENTED_UNTESTED", "BLOCKED_RESOURCE", "BLOCKED_DEPENDENCY", "NOT_RUN", "FAILED"}
REQUIRED_CATEGORIES = {
    "source_provenance", "stored_record_coverage", "graph_identity", "graph_integrity", "real_cebu_corpus",
    "adapter_real_graph", "walking_geometry", "resource_behavior", "frontend_preview",
    "ride_mode", "saved_journeys", "search", "can_i_ride", "typescript", "lint",
    "map_mobile", "portable_verification_v5", "external_staging", "field_safety",
}
EXPECTED_GRAPH_SHA256 = "29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8"
EXPECTED_GRAPH_FINGERPRINT = "a0d9f1cb8289cf1f9bde3905d1ad8a57124ee41120153b4efc1f5b31e4c03ecd"
EXPECTED_COVERAGE_SHA256 = "6caf101177904bee6fda1b12cab9c5cda36ddaf7ecc08b0d9778c0517123c81b"
EXPECTED_PROVENANCE_SHA256 = "882fd1ae2c7ab8f2e1edab87c9122911f62c4568513bbe6c8875263958dc1bb6"


class DoctorError(ValueError):
    pass


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def require_sha(value: object, field: str) -> str:
    if not isinstance(value, str) or re.fullmatch(r"[0-9a-f]{64}", value) is None:
        raise DoctorError(f"{field} must be a lowercase SHA256")
    return value


def validate_doctor(document: object, repo_root: Path) -> dict[str, object]:
    if not isinstance(document, dict) or document.get("format") != "biyahe-doctor-v43-evidence-v1":
        raise DoctorError("unsupported Doctor V43 format")
    if document.get("batch") != 43:
        raise DoctorError("Doctor V43 batch identity is missing")

    source = document.get("source_revision")
    if not isinstance(source, dict):
        raise DoctorError("source revision pins are missing")
    executable = source.get("executable")
    tested = source.get("tested")
    for field, revision in (("executable", executable), ("tested", tested)):
        if not isinstance(revision, str) or re.fullmatch(r"[0-9a-f]{40}", revision) is None:
            raise DoctorError(f"{field} source revision must be a full Git commit")
    if executable != tested:
        raise DoctorError("tested source revision must equal the pinned executable revision")
    if (repo_root / ".git").exists():
        try:
            subprocess.run(["git", "-C", str(repo_root), "cat-file", "-e", f"{executable}^{{commit}}"],
                           check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        except (OSError, subprocess.CalledProcessError) as error:
            raise DoctorError("pinned executable source revision is absent from this Git repository") from error

    fingerprints = document.get("source_fingerprints")
    if not isinstance(fingerprints, dict) or not fingerprints:
        raise DoctorError("source fingerprints are required")
    for relative, value in fingerprints.items():
        if not isinstance(relative, str) or relative.startswith("/") or ".." in Path(relative).parts:
            raise DoctorError(f"unsafe source fingerprint path: {relative}")
        expected = require_sha(value, f"source_fingerprints.{relative}")
        source_path = repo_root / relative
        if not source_path.is_file() or sha256(source_path) != expected:
            raise DoctorError(f"source fingerprint mismatch or missing file: {relative}")
        if (repo_root / ".git").exists():
            try:
                committed = subprocess.check_output(["git", "-C", str(repo_root), "show", f"{executable}:{relative}"])
            except (OSError, subprocess.CalledProcessError) as error:
                raise DoctorError(f"fingerprinted source is absent from the tested revision: {relative}") from error
            if hashlib.sha256(committed).hexdigest() != expected:
                raise DoctorError(f"tested revision source fingerprint differs: {relative}")

    categories = document.get("categories")
    if not isinstance(categories, list):
        raise DoctorError("categories must be an array")
    category_map: dict[str, dict[str, object]] = {}
    for row in categories:
        if not isinstance(row, dict) or not isinstance(row.get("name"), str) or row["name"] in category_map:
            raise DoctorError("malformed or duplicate category")
        if row.get("status") not in ALLOWED:
            raise DoctorError(f"category {row['name']} has an invalid status")
        if not isinstance(row.get("evidence"), str) or not row["evidence"].strip():
            raise DoctorError(f"category {row['name']} needs an evidence explanation")
        category_map[row["name"]] = row
    missing = REQUIRED_CATEGORIES - set(category_map)
    if missing:
        raise DoctorError(f"required categories missing: {', '.join(sorted(missing))}")

    evidence = document.get("evidence")
    if not isinstance(evidence, dict):
        raise DoctorError("evidence section is missing")
    graph = evidence.get("graph")
    if not isinstance(graph, dict) or graph.get("expected_sha256") != EXPECTED_GRAPH_SHA256:
        raise DoctorError("pinned graph SHA256 differs from the approved artifact identity")
    if graph.get("expected_fingerprint") != EXPECTED_GRAPH_FINGERPRINT:
        raise DoctorError("pinned graph fingerprint differs from the approved artifact identity")
    if graph.get("status") not in ALLOWED or category_map["graph_identity"]["status"] != graph.get("status"):
        raise DoctorError("graph identity status is missing or inconsistent")
    if graph.get("status") == "VERIFIED":
        if graph.get("actual_sha256") != EXPECTED_GRAPH_SHA256 or not isinstance(graph.get("observed_at_utc"), str):
            raise DoctorError("verified graph identity needs a current matching SHA256 observation")
    elif graph.get("actual_sha256") is not None:
        raise DoctorError("an unverified graph must not contain an unverified actual SHA256 claim")
    if graph.get("graph_modified") is not False or graph.get("pbf_rescanned") is not False:
        raise DoctorError("graph modification and PBF rescanning must both be false")

    integrity = evidence.get("graph_integrity")
    if not isinstance(integrity, dict) or integrity.get("status") not in ALLOWED:
        raise DoctorError("graph integrity status is missing")
    if category_map["graph_integrity"]["status"] != integrity["status"]:
        raise DoctorError("graph integrity status is inconsistent")
    if integrity["status"] == "VERIFIED" and (integrity.get("method") != "sqlite_integrity_check_read_only"
                                                  or integrity.get("result") != "ok"):
        raise DoctorError("graph integrity can be verified only by a passing read-only SQLite integrity check")

    corpus = evidence.get("real_cebu_corpus")
    if not isinstance(corpus, dict) or corpus.get("cases_expected_total") != 10:
        raise DoctorError("ten-case corpus execution evidence is required")
    counts = [corpus.get(key) for key in ("cases_executed", "cases_pass", "cases_fail", "cases_blocked", "cases_not_selected")]
    if any(not isinstance(value, int) or isinstance(value, bool) or value < 0 for value in counts):
        raise DoctorError("corpus case counts must be nonnegative integers")
    executed, passed, failed, blocked, not_selected = counts
    if passed + failed != executed or executed + blocked + not_selected != 10:
        raise DoctorError("corpus execution, blocked, and not-selected case counts do not reconcile")
    if category_map["real_cebu_corpus"]["status"] != corpus.get("status") or corpus.get("status") not in ALLOWED:
        raise DoctorError("corpus status is missing or inconsistent")
    if corpus.get("status") == "VERIFIED" and (executed, passed, failed, blocked, not_selected) != (10, 10, 0, 0, 0):
        raise DoctorError("full corpus verification requires ten current matching cases")
    if corpus.get("complete_corpus") is not (executed == 10 and not_selected == 0):
        raise DoctorError("corpus completeness flag does not match executed scope")
    indices = corpus.get("case_indices_attempted")
    if not isinstance(indices, list) or any(not isinstance(index, int) or not 0 <= index < 10 for index in indices):
        raise DoctorError("corpus attempted case indices are malformed")

    coverage = evidence.get("stored_record_coverage")
    if not isinstance(coverage, dict) or coverage.get("scope") != "STORED_RECORDS_ONLY":
        raise DoctorError("coverage must remain explicitly limited to stored records")
    if coverage.get("stops") != {"total": 503, "snappable_within_25m": 436, "unsnappable": 67}:
        raise DoctorError("pinned stop coverage counts changed")
    if coverage.get("places") != {"total": 37, "snappable_within_25m": 30, "unsnappable": 7}:
        raise DoctorError("pinned place coverage counts changed")
    if coverage.get("geographic_population_sidewalk_field_claims") != "NONE":
        raise DoctorError("stored-record coverage cannot assert geographic, population, sidewalk, or field coverage")
    require_sha(coverage.get("source_report_sha256"), "stored_record_coverage.source_report_sha256")
    if coverage.get("source_report_sha256") != EXPECTED_COVERAGE_SHA256 or \
            category_map["stored_record_coverage"]["status"] not in ALLOWED:
        raise DoctorError("stored-record coverage must remain pinned to the approved historical report")
    coverage_path = repo_root / "docs/reports/batch032-cebu-coverage.json"
    if not coverage_path.is_file() or sha256(coverage_path) != EXPECTED_COVERAGE_SHA256:
        raise DoctorError("pinned stored-record coverage source report changed or is missing")

    reconciliation = evidence.get("reconciliation")
    if not isinstance(reconciliation, dict):
        raise DoctorError("evidence reconciliation is missing")
    if reconciliation.get("vehicle_only_restrictions") != {"total": 648, "stored": 619, "omitted": 29}:
        raise DoctorError("vehicle-only restriction counts must reconcile as 648 = 619 + 29")
    if reconciliation.get("measured_graph_quality") != {"long_edges": 26, "boundary_nodes": 944, "crossing_tagged_nodes": 1510}:
        raise DoctorError("historically measured graph quality diagnostics are missing")
    if reconciliation.get("unmeasured_legacy_defaults_are_zero_facts") is not False:
        raise DoctorError("unmeasured legacy defaults must not be treated as zero-valued facts")
    if reconciliation.get("provenance_registry_sha256") != EXPECTED_PROVENANCE_SHA256:
        raise DoctorError("provenance registry fingerprint changed")
    provenance_path = repo_root / "docs/reports/batch041-evidence-provenance.json"
    if not provenance_path.is_file() or sha256(provenance_path) != EXPECTED_PROVENANCE_SHA256:
        raise DoctorError("pinned provenance registry changed or is missing")
    transfers = evidence.get("transfer_verification")
    if not isinstance(transfers, dict) or transfers.get("unverified_count") != 2 or transfers.get("field_verified") is not False:
        raise DoctorError("both transfers must remain explicitly physically unverified")

    resource = evidence.get("resource_preflight")
    if not isinstance(resource, dict) or resource.get("memavailable_floor_kib") != 524288 \
            or resource.get("swapfree_floor_kib") != 131072 or resource.get("breach_samples") != 1 \
            or resource.get("process_tree_rss_cap_kib") != 262144:
        raise DoctorError("resource policy differs from the fixed Batch043 safety limits")
    samples = resource.get("samples")
    if not isinstance(samples, list) or len(samples) < 5:
        raise DoctorError("five spaced resource samples are required")
    timestamps = [row.get("timestamp_utc") for row in samples if isinstance(row, dict)]
    if len(timestamps) != len(samples) or any(not isinstance(value, str) for value in timestamps):
        raise DoctorError("resource sample timestamps are malformed")
    try:
        parsed_timestamps = [datetime.fromisoformat(value.replace("Z", "+00:00")) for value in timestamps]
    except ValueError as error:
        raise DoctorError("resource sample timestamp is not ISO-8601") from error
    if any((right - left).total_seconds() < 5 for left, right in zip(parsed_timestamps, parsed_timestamps[1:])):
        raise DoctorError("resource samples must be spaced at least five seconds apart")
    if resource.get("preflight_status") not in {"PASS", "BLOCKED_RESOURCE"}:
        raise DoctorError("resource preflight result must distinguish pass from a resource block")
    if resource.get("preflight_status") == "PASS":
        if resource.get("minimum_memavailable_kib", 0) < 524288 or resource.get("minimum_swapfree_kib", 0) < 131072:
            raise DoctorError("passing resource preflight falls below a fixed floor")
    elif resource.get("minimum_memavailable_kib", 0) >= 524288 and resource.get("minimum_swapfree_kib", 0) >= 131072:
        raise DoctorError("resource preflight is blocked without a demonstrated floor breach")

    tests = document.get("tests")
    if not isinstance(tests, dict):
        raise DoctorError("machine-readable test records are required")
    for name, row in tests.items():
        if not isinstance(row, dict) or row.get("status") not in ALLOWED:
            raise DoctorError(f"test {name} has an invalid status")
        command = row.get("command")
        if not isinstance(command, list) or not command or not all(isinstance(arg, str) for arg in command):
            raise DoctorError(f"test {name} must record its exact command")
        require_sha(row.get("command_fingerprint_sha256"), f"tests.{name}.command_fingerprint_sha256")
        input_files = row.get("input_files")
        if not isinstance(input_files, list):
            raise DoctorError(f"test {name} must record its input files")
        for item in input_files:
            if not isinstance(item, dict) or not isinstance(item.get("path"), str):
                raise DoctorError(f"test {name} has a malformed input file record")
            relative = item["path"]
            if relative.startswith("/") or ".." in Path(relative).parts:
                raise DoctorError(f"test {name} has an unsafe input path")
            expected = require_sha(item.get("sha256"), f"tests.{name}.input_files.{relative}")
            source_path = repo_root / relative
            if not source_path.is_file() or sha256(source_path) != expected:
                raise DoctorError(f"verified test {name} input changed: {relative}")
        environment = row.get("fingerprint_environment")
        tools = row.get("tool_versions")
        cwd = row.get("cwd")
        if not isinstance(environment, dict) or not isinstance(tools, dict) or not isinstance(cwd, str):
            raise DoctorError(f"test {name} is missing environment, tool, or cwd fingerprint inputs")
        fingerprint_payload = {"files": input_files, "command": command, "cwd": cwd,
                               "environment": environment, "tools": tools}
        calculated_fingerprint = hashlib.sha256(
            json.dumps(fingerprint_payload, sort_keys=True, separators=(",", ":")).encode()).hexdigest()
        if calculated_fingerprint != row["command_fingerprint_sha256"]:
            raise DoctorError(f"test {name} command fingerprint does not match its recorded inputs")
        if row["status"] == "VERIFIED":
            if row.get("executed") is not True or row.get("exit_code") != 0:
                raise DoctorError(f"test {name} cannot be VERIFIED without a current passing execution")
            if not isinstance(row.get("tool_versions"), dict) or not isinstance(row.get("elapsed_milliseconds"), (int, float)):
                raise DoctorError(f"test {name} is missing tool versions or runtime")
            output_path = row.get("output_path")
            output_sha = require_sha(row.get("output_sha256"), f"tests.{name}.output_sha256")
            if not isinstance(output_path, str) or not Path(output_path).is_file() or sha256(Path(output_path)) != output_sha:
                raise DoctorError(f"test {name} output evidence is missing or changed")
        elif row.get("output_sha256") is not None:
            output_sha = require_sha(row.get("output_sha256"), f"tests.{name}.output_sha256")
            output_path = row.get("output_path")
            if not isinstance(output_path, str) or not Path(output_path).is_file() or sha256(Path(output_path)) != output_sha:
                raise DoctorError(f"test {name} partial output evidence is missing or changed")
        if row["status"] == "BLOCKED_RESOURCE" and row.get("resource_gate_evidence") is None:
            raise DoctorError(f"blocked test {name} must reference resource-gate evidence")

    gates = document.get("release_gates")
    required_gates = {
        "live_production_graph_integration": "BLOCKED",
        "postgresql_staging": "BLOCKED",
        "field_safety": "BLOCKED",
        "private_beta": "BLOCKED",
        "production_activation": "NO",
    }
    if not isinstance(gates, dict) or any(gates.get(key) != value for key, value in required_gates.items()):
        raise DoctorError("production, PostgreSQL, field, and beta release gates must remain blocked")

    return {
        "format": "biyahe-doctor-v43-validation-v1",
        "status": "PASS_STATIC_DOCTOR_CONTRACT",
        "categories": len(category_map),
        "source_revision": executable,
        "fingerprinted_sources": len(fingerprints),
        "tests": len(tests),
        "corpus_cases_executed": executed,
        "corpus_completeness": corpus["complete_corpus"],
        "production_activation": "NO",
        "private_beta": "BLOCKED",
    }


def main(argv: list[str] | None = None) -> int:
    root = Path(__file__).resolve().parents[2]
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--doctor", type=Path, default=root / "docs/reports/batch043-doctor-v43.json")
    parser.add_argument("--repo-root", type=Path, default=root)
    args = parser.parse_args(argv)
    try:
        document = json.loads(args.doctor.read_text(encoding="utf-8"))
        print(json.dumps(validate_doctor(document, args.repo_root.resolve()), sort_keys=True))
        return 0
    except (OSError, json.JSONDecodeError, DoctorError) as error:
        print(f"DOCTOR_V43_VALIDATION_ERROR={error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
