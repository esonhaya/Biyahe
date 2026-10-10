#!/usr/bin/env python3
"""Fail-closed validation for the Batch042 readiness and execution report."""

from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys

ALLOWED = {"VERIFIED", "IMPLEMENTED_UNTESTED", "BLOCKED_RESOURCE", "BLOCKED_DEPENDENCY", "NOT_RUN", "FAILED"}
REQUIRED_CATEGORIES = {
    "source_provenance", "graph_identity", "graph_integrity", "coverage",
    "adapter_contract", "real_cebu_corpus", "journey_preview", "ride_mode",
    "saved_journeys", "search", "can_i_ride", "map_mobile", "typescript",
    "lint", "portable_kit", "postgresql_staging", "field_safety",
}
EXPECTED_GRAPH_SHA256 = "29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8"
EXPECTED_GRAPH_FINGERPRINT = "a0d9f1cb8289cf1f9bde3905d1ad8a57124ee41120153b4efc1f5b31e4c03ecd"
EXPECTED_COVERAGE = {"stops": (503, 436, 67), "places": (37, 30, 7)}


class DoctorError(ValueError):
    pass


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def _require_sha(value: object, field: str) -> str:
    if not isinstance(value, str) or re.fullmatch(r"[0-9a-f]{64}", value) is None:
        raise DoctorError(f"{field} must be a lowercase SHA256")
    return value


def validate_doctor(document: object, repo_root: Path) -> dict[str, object]:
    if not isinstance(document, dict) or document.get("format") != "biyahe-doctor-v42-evidence-v1":
        raise DoctorError("unsupported Doctor V42 format")
    if document.get("batch") != 42:
        raise DoctorError("Doctor V42 batch identity is missing")

    source = document.get("source_revision")
    if not isinstance(source, dict):
        raise DoctorError("source revision pins are missing")
    executable_revision = source.get("executable")
    tested_revision = source.get("tested")
    for key, revision in (("executable", executable_revision), ("tested", tested_revision)):
        if not isinstance(revision, str) or re.fullmatch(r"[0-9a-f]{40}", revision) is None:
            raise DoctorError(f"{key} source revision must be a full Git commit")
    if executable_revision != tested_revision:
        raise DoctorError("the reported tests must pin the executable source revision they tested")
    if repo_root.joinpath(".git").exists():
        result = subprocess.run(["git", "-C", str(repo_root), "cat-file", "-e", f"{executable_revision}^{{commit}}"], capture_output=True)
        if result.returncode:
            raise DoctorError("pinned executable source revision is absent from this Git repository")

    fingerprints = document.get("source_fingerprints")
    if not isinstance(fingerprints, dict) or not fingerprints:
        raise DoctorError("source fingerprints are required")
    for relative, digest in fingerprints.items():
        if not isinstance(relative, str) or relative.startswith("/") or ".." in Path(relative).parts:
            raise DoctorError(f"unsafe source fingerprint path: {relative}")
        expected = _require_sha(digest, f"source_fingerprints.{relative}")
        source_path = repo_root / relative
        if not source_path.is_file() or sha256(source_path) != expected:
            raise DoctorError(f"source fingerprint mismatch or missing file: {relative}")

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
        raise DoctorError("the pinned graph identity differs from the Batch041 source")
    if graph.get("expected_fingerprint") != EXPECTED_GRAPH_FINGERPRINT or graph.get("fingerprint_status") != "HISTORICAL_EXPECTATION_NOT_RECOMPUTED":
        raise DoctorError("the graph fingerprint must remain identified as an unrecomputed historical expectation")
    if graph.get("status") not in ALLOWED:
        raise DoctorError("graph identity has an invalid status")
    if graph.get("status") == "VERIFIED" and graph.get("actual_sha256") != EXPECTED_GRAPH_SHA256:
        raise DoctorError("graph identity cannot be verified with a different or absent SHA256")
    if graph.get("status") == "VERIFIED":
        observations = graph.get("observations")
        if not isinstance(observations, list) or not observations:
            raise DoctorError("verified graph identity needs a current read-only hash observation")
        for observation in observations:
            if (not isinstance(observation, dict)
                or observation.get("method") not in {"read_only_sha256", "corpus_preflight_sha256"}
                or observation.get("sha256") != EXPECTED_GRAPH_SHA256
                or not isinstance(observation.get("path"), str)
                or not isinstance(observation.get("observed_at_utc"), str)):
                raise DoctorError("graph identity observation is malformed or mismatched")
    graph_integrity = evidence.get("graph_integrity")
    if not isinstance(graph_integrity, dict) or graph_integrity.get("status") == "VERIFIED" and graph_integrity.get("method") != "sqlite_integrity_check_read_only":
        raise DoctorError("graph integrity needs an explicit read-only integrity check")
    if graph_integrity.get("status") != "VERIFIED" and category_map["graph_integrity"]["status"] == "VERIFIED":
        raise DoctorError("graph integrity category cannot be verified without integrity evidence")

    coverage = evidence.get("stored_record_coverage")
    if not isinstance(coverage, dict):
        raise DoctorError("stored-record coverage evidence is required")
    for key, (total, snappable, unsnappable) in EXPECTED_COVERAGE.items():
        row = coverage.get(key)
        if not isinstance(row, dict) or (row.get("total"), row.get("snappable_within_25m"), row.get("unsnappable")) != (total, snappable, unsnappable):
            raise DoctorError(f"pinned historical {key} coverage counts changed")
        if row.get("scope") != "STORED_RECORDS_ONLY" or row.get("current_batch_replay") not in {"NOT_REQUIRED", "NOT_RUN"}:
            raise DoctorError(f"{key} evidence must remain stored-record scoped")
    coverage_category = category_map["coverage"]
    if coverage_category["status"] == "VERIFIED" and coverage.get("validation_status") != "VERIFIED_CURRENT_FIXTURES":
        raise DoctorError("coverage can be VERIFIED only after current fixture validation")
    if coverage.get("geographic_population_sidewalk_field_claims") != "NONE":
        raise DoctorError("stored-record coverage cannot claim geographic, population, sidewalk, or field coverage")
    coverage_sha = _require_sha(coverage.get("source_report_sha256"), "stored_record_coverage.source_report_sha256")
    coverage_source = repo_root / str(coverage.get("source_report", ""))
    if not coverage_source.is_file() or sha256(coverage_source) != coverage_sha:
        raise DoctorError("the pinned stored-record coverage report changed or is missing")

    reconciliation = evidence.get("reconciliation")
    if not isinstance(reconciliation, dict):
        raise DoctorError("provenance reconciliation is missing")
    restrictions = reconciliation.get("vehicle_only_restrictions")
    if not isinstance(restrictions, dict) or (restrictions.get("total"), restrictions.get("stored"), restrictions.get("omitted")) != (648, 619, 29):
        raise DoctorError("vehicle-only restriction reconciliation must remain 648 = 619 + 29")
    quality = reconciliation.get("graph_quality")
    if not isinstance(quality, dict) or quality.get("measured_values") != {"long_edges": 26, "boundary_nodes": 944, "crossing_tagged_nodes": 1510}:
        raise DoctorError("measured graph quality values are missing or changed")
    if quality.get("unmeasured_legacy_defaults_are_zero_facts") is not False:
        raise DoctorError("unmeasured legacy defaults cannot be reported as measured zero facts")
    provenance_sha = _require_sha(reconciliation.get("provenance_registry_sha256"), "reconciliation.provenance_registry_sha256")
    provenance_path = repo_root / "docs/reports/batch041-evidence-provenance.json"
    if not provenance_path.is_file() or sha256(provenance_path) != provenance_sha:
        raise DoctorError("the pinned evidence provenance registry changed or is missing")

    transfers = evidence.get("transfer_verification")
    if not isinstance(transfers, dict) or transfers.get("unverified_count") != 2 or transfers.get("field_verified") is not False:
        raise DoctorError("transfer evidence cannot claim field verification")

    tests = document.get("tests")
    if not isinstance(tests, dict):
        raise DoctorError("current Batch042 test evidence is missing")
    for name, result in tests.items():
        if not isinstance(result, dict) or result.get("status") not in ALLOWED:
            raise DoctorError(f"test {name} has an invalid status record")
        if result["status"] == "VERIFIED" and result.get("executed") is not True:
            raise DoctorError(f"test {name} cannot be VERIFIED without a current execution")
        if result.get("executed") is True:
            fingerprint = _require_sha(result.get("command_fingerprint_sha256"), f"tests.{name}.command_fingerprint_sha256")
            command = result.get("command")
            cwd = result.get("cwd")
            files = result.get("input_files")
            environment = result.get("fingerprint_environment")
            tool_versions = result.get("tool_versions")
            if not isinstance(command, list) or not all(isinstance(item, str) for item in command):
                raise DoctorError(f"test {name} is missing its exact command")
            if not isinstance(cwd, str) or not isinstance(files, list) or not isinstance(environment, dict) or not isinstance(tool_versions, dict):
                raise DoctorError(f"test {name} is missing command fingerprint inputs")
            canonical_files = []
            for item in files:
                if not isinstance(item, dict) or not isinstance(item.get("path"), str):
                    raise DoctorError(f"test {name} has a malformed input fingerprint")
                relative = item["path"]
                if relative.startswith("/") or ".." in Path(relative).parts:
                    raise DoctorError(f"test {name} has an unsafe input path")
                digest = item.get("sha256")
                if not isinstance(digest, str) or re.fullmatch(r"[0-9a-f]{64}", digest) is None:
                    raise DoctorError(f"test {name} input {relative} is missing a SHA256")
                source_path = repo_root / relative
                if not source_path.is_file() or sha256(source_path) != digest:
                    raise DoctorError(f"test {name} input fingerprint changed: {relative}")
                canonical_files.append({"path": relative, "sha256": digest})
            fingerprint_payload = {"files": canonical_files, "command": command, "cwd": cwd,
                                   "environment": environment, "tools": tool_versions}
            actual_fingerprint = hashlib.sha256(json.dumps(fingerprint_payload, sort_keys=True, separators=(",", ":")).encode()).hexdigest()
            if actual_fingerprint != fingerprint:
                raise DoctorError(f"test {name} command fingerprint does not match its recorded inputs")
            if result["status"] == "VERIFIED":
                if result.get("resource_guard") == "PASS":
                    guard_environment = result.get("guard_environment")
                    if result.get("memavailable_floor_kib") != 524288 or result.get("swapfree_floor_kib") != 131072:
                        raise DoctorError(f"test {name} changed the fixed resource floors")
                    if not isinstance(guard_environment, dict) or guard_environment.get("BIYAHE_RESOURCE_BREACH_SAMPLES") != "1":
                        raise DoctorError(f"test {name} did not use first-breach cancellation")
                    if (result.get("worker_min_memavailable_kib") is None
                        or result["worker_min_memavailable_kib"] < 524288
                        or result.get("worker_min_swapfree_kib") is None
                        or result["worker_min_swapfree_kib"] < 131072):
                        raise DoctorError(f"test {name} passed without proving the required worker resource minimums")
            output_path = result.get("output_path")
            output_sha_value = result.get("output_sha256")
            if result["status"] == "VERIFIED" or output_sha_value is not None:
                output_sha = _require_sha(output_sha_value, f"tests.{name}.output_sha256")
                if not isinstance(output_path, str) or not (repo_root / output_path).is_file() or sha256(repo_root / output_path) != output_sha:
                    raise DoctorError(f"test {name} evidence output is missing or changed")
            resource_sha_value = result.get("resource_log_sha256")
            if resource_sha_value is not None:
                resource_sha = _require_sha(resource_sha_value, f"tests.{name}.resource_log_sha256")
                resource_path = result.get("resource_log")
                if not isinstance(resource_path, str) or not (repo_root / resource_path).is_file() or sha256(repo_root / resource_path) != resource_sha:
                    raise DoctorError(f"test {name} resource log is missing or changed")
        if result.get("historical_only") is True and result["status"] == "VERIFIED":
            raise DoctorError(f"test {name} cannot promote historical evidence to current verification")

    gates = document.get("release_gates")
    required_gates = {
        "live_production_graph_integration": "BLOCKED",
        "postgresql_staging": "BLOCKED",
        "field_safety": "BLOCKED",
        "private_beta": "BLOCKED",
        "production_activation": "NO",
    }
    if not isinstance(gates, dict) or any(gates.get(key) != value for key, value in required_gates.items()):
        raise DoctorError("release gates must keep production, staging, beta, and field readiness blocked")

    return {
        "format": "biyahe-doctor-v42-validation-v1",
        "status": "PASS_STATIC_DOCTOR_CONTRACT",
        "categories": len(category_map),
        "source_revision": executable_revision,
        "fingerprinted_sources": len(fingerprints),
        "tests": len(tests),
        "graph_replay": category_map["real_cebu_corpus"]["status"],
        "production_activation": "NO",
        "private_beta": "BLOCKED",
    }


def main(argv=None) -> int:
    root = Path(__file__).resolve().parents[2]
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--doctor", type=Path, default=root / "docs/reports/batch042-doctor-v42.json")
    parser.add_argument("--repo-root", type=Path, default=root)
    args = parser.parse_args(argv)
    try:
        document = json.loads(args.doctor.read_text(encoding="utf-8"))
        result = validate_doctor(document, args.repo_root.resolve())
        print(json.dumps(result, sort_keys=True))
        return 0
    except (OSError, json.JSONDecodeError, DoctorError) as error:
        print(f"DOCTOR_V42_VALIDATION=FAIL: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
