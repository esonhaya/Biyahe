#!/usr/bin/env python3
"""Fail-closed validation for Batch 044 staging and release evidence."""

from __future__ import annotations

import argparse
from datetime import datetime
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys
from typing import Any

ALLOWED = {"VERIFIED", "IMPLEMENTED_UNTESTED", "BLOCKED_RESOURCE", "BLOCKED_DEPENDENCY", "NOT_RUN", "FAILED"}
REQUIRED_CATEGORIES = {
    "staging_architecture", "configuration_safety", "database_bootstrap", "api_smoke_framework",
    "external_corpus_workflow", "frontend_build_workflow", "browser_automation_workflow",
    "commuter_reliability", "ci_integration", "portable_verification", "graph_identity",
    "graph_integrity", "real_graph_execution", "postgresql_staging", "field_verification",
    "private_beta", "production_deployment",
}
EXPECTED_GRAPH_SHA256 = "29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8"
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


def require_digest(value: Any, label: str) -> str:
    if not isinstance(value, str) or re.fullmatch(r"[0-9a-f]{64}", value) is None:
        raise DoctorError(f"{label} must be a lowercase SHA256")
    return value


def safe_relative(value: Any, label: str) -> str:
    if not isinstance(value, str) or not value or value.startswith("/") or ".." in Path(value).parts:
        raise DoctorError(f"{label} must be a safe relative path")
    return value


def test_fingerprint(row: dict[str, Any]) -> str:
    payload = {
        "files": row["input_files"], "command": row["command"], "cwd": row["cwd"],
        "environment": row["fingerprint_environment"], "tools": row["tool_versions"],
    }
    return hashlib.sha256(json.dumps(payload, sort_keys=True, separators=(",", ":")).encode()).hexdigest()


def validate_doctor(document: object, repo_root: Path) -> dict[str, object]:
    if not isinstance(document, dict) or document.get("format") != "biyahe-doctor-v44-evidence-v1" or document.get("batch") != 44:
        raise DoctorError("unsupported Doctor V44 report")

    revision = document.get("source_revision")
    if not isinstance(revision, dict):
        raise DoctorError("source revision pins are missing")
    executable, tested = revision.get("executable"), revision.get("tested")
    if not all(isinstance(value, str) and re.fullmatch(r"[0-9a-f]{40}", value) for value in (executable, tested)) or executable != tested:
        raise DoctorError("tested and executable revisions must be the same full Git commit")
    if (repo_root / ".git").exists():
        try:
            subprocess.run(["git", "-C", str(repo_root), "cat-file", "-e", f"{executable}^{{commit}}"], check=True,
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        except (OSError, subprocess.CalledProcessError) as error:
            raise DoctorError("pinned executable revision is absent from Git") from error

    fingerprints = document.get("source_fingerprints")
    if not isinstance(fingerprints, dict) or not fingerprints:
        raise DoctorError("executable source fingerprints are required")
    for relative, value in fingerprints.items():
        relative = safe_relative(relative, "source fingerprint path")
        expected = require_digest(value, f"source_fingerprints.{relative}")
        source_path = repo_root / relative
        if not source_path.is_file() or sha256(source_path) != expected:
            raise DoctorError(f"source fingerprint mismatch: {relative}")
        if (repo_root / ".git").exists():
            try:
                committed = subprocess.check_output(["git", "-C", str(repo_root), "show", f"{executable}:{relative}"])
            except (OSError, subprocess.CalledProcessError) as error:
                raise DoctorError(f"source is absent from pinned executable revision: {relative}") from error
            if hashlib.sha256(committed).hexdigest() != expected:
                raise DoctorError(f"pinned commit does not match source fingerprint: {relative}")

    categories = document.get("categories")
    if not isinstance(categories, list):
        raise DoctorError("categories must be an array")
    by_name: dict[str, dict[str, Any]] = {}
    for item in categories:
        if not isinstance(item, dict) or not isinstance(item.get("name"), str) or item["name"] in by_name:
            raise DoctorError("malformed or duplicate Doctor category")
        if item.get("status") not in ALLOWED or not isinstance(item.get("evidence"), str) or not item["evidence"].strip():
            raise DoctorError(f"category {item.get('name')} needs an allowed status and evidence explanation")
        by_name[item["name"]] = item
    if REQUIRED_CATEGORIES - set(by_name):
        raise DoctorError(f"missing required categories: {', '.join(sorted(REQUIRED_CATEGORIES - set(by_name)))}")

    evidence = document.get("evidence")
    if not isinstance(evidence, dict):
        raise DoctorError("evidence section is missing")
    graph = evidence.get("graph")
    if not isinstance(graph, dict) or graph.get("expected_sha256") != EXPECTED_GRAPH_SHA256 or graph.get("modified") is not False or graph.get("pbf_rescanned") is not False:
        raise DoctorError("graph identity or read-only boundary is missing")
    if graph.get("status") not in ALLOWED or by_name["graph_identity"]["status"] != graph.get("status"):
        raise DoctorError("graph identity status is inconsistent")
    if graph.get("status") == "VERIFIED":
        if graph.get("actual_sha256") != EXPECTED_GRAPH_SHA256 or not isinstance(graph.get("observed_at_utc"), str):
            raise DoctorError("verified graph identity needs a matching current observation")
    elif graph.get("actual_sha256") is not None:
        raise DoctorError("an unverified graph must not contain an actual SHA claim")

    integrity = evidence.get("graph_integrity")
    if not isinstance(integrity, dict) or integrity.get("status") not in ALLOWED or by_name["graph_integrity"]["status"] != integrity.get("status"):
        raise DoctorError("graph integrity status is inconsistent")
    if integrity.get("status") == "VERIFIED" and (integrity.get("method") != "sqlite_read_only_quick_check_1" or integrity.get("result") != "ok"):
        raise DoctorError("graph integrity requires a current passing read-only SQLite quick_check")

    corpus = evidence.get("real_graph_corpus")
    if not isinstance(corpus, dict) or corpus.get("expected_cases") != 10 or corpus.get("status") not in ALLOWED:
        raise DoctorError("ten-case corpus evidence is missing")
    count_fields = ("executed", "pass", "fail", "blocked", "not_run")
    counts = [corpus.get(key) for key in count_fields]
    if any(not isinstance(value, int) or isinstance(value, bool) or value < 0 for value in counts):
        raise DoctorError("corpus case counts must be nonnegative integers")
    executed, passed, failed, blocked, not_run = counts
    if passed + failed != executed or executed + blocked + not_run != 10:
        raise DoctorError("corpus case counts do not reconcile")
    if corpus.get("complete") is not (executed == 10 and not_run == 0 and blocked == 0):
        raise DoctorError("corpus completeness does not match case counts")
    indices = corpus.get("case_indices_executed")
    if not isinstance(indices, list) or len(indices) != executed or any(not isinstance(index, int) or isinstance(index, bool) or not 0 <= index < 10 for index in indices):
        raise DoctorError("executed corpus case indices are malformed")
    if by_name["real_graph_execution"]["status"] != corpus["status"]:
        raise DoctorError("corpus category status is inconsistent")
    if corpus["status"] == "VERIFIED" and (executed, passed, failed, blocked, not_run) != (10, 10, 0, 0, 0):
        raise DoctorError("VERIFIED requires all ten current cases to pass")

    coverage = evidence.get("stored_record_coverage")
    if not isinstance(coverage, dict) or coverage.get("scope") != "STORED_RECORDS_ONLY":
        raise DoctorError("coverage scope must remain stored records only")
    if coverage.get("stops") != {"total": 503, "snappable_within_25m": 436, "unsnappable": 67}:
        raise DoctorError("pinned stored stop counts changed")
    if coverage.get("places") != {"total": 37, "snappable_within_25m": 30, "unsnappable": 7}:
        raise DoctorError("pinned stored place counts changed")
    if coverage.get("source_sha256") != EXPECTED_COVERAGE_SHA256 or coverage.get("geographic_population_sidewalk_or_field_claims") != "NONE":
        raise DoctorError("stored coverage report identity or boundary is invalid")
    if sha256(repo_root / "docs/reports/batch032-cebu-coverage.json") != EXPECTED_COVERAGE_SHA256:
        raise DoctorError("pinned coverage input changed")

    reconciliation = evidence.get("evidence_reconciliation")
    if not isinstance(reconciliation, dict) or reconciliation.get("vehicle_only_restrictions") != {"total": 648, "stored": 619, "omitted": 29}:
        raise DoctorError("vehicle-only restriction denominator must reconcile 648 = 619 + 29")
    if reconciliation.get("measured_graph_quality") != {"long_edges": 26, "boundary_nodes": 944, "crossing_tagged_nodes": 1510}:
        raise DoctorError("measured graph-quality diagnostics are missing")
    if reconciliation.get("unmeasured_legacy_defaults_are_zero_facts") is not False:
        raise DoctorError("unmeasured defaults cannot be converted into zero-valued facts")
    if reconciliation.get("provenance_sha256") != EXPECTED_PROVENANCE_SHA256 or sha256(repo_root / "docs/reports/batch041-evidence-provenance.json") != EXPECTED_PROVENANCE_SHA256:
        raise DoctorError("pinned provenance source changed")
    transfers = evidence.get("transfers")
    if not isinstance(transfers, dict) or transfers.get("unverified_count") != 2 or transfers.get("physically_verified") is not False:
        raise DoctorError("both transfer locations must remain physically unverified")

    tests = document.get("tests")
    if not isinstance(tests, dict):
        raise DoctorError("machine-readable test results are required")
    for name, row in tests.items():
        if not isinstance(row, dict) or row.get("status") not in ALLOWED:
            raise DoctorError(f"test {name} has an invalid status")
        command = row.get("command")
        if not isinstance(command, list) or not command or not all(isinstance(arg, str) for arg in command):
            raise DoctorError(f"test {name} must record its exact command")
        inputs = row.get("input_files")
        if not isinstance(inputs, list):
            raise DoctorError(f"test {name} must fingerprint input files")
        for item in inputs:
            if not isinstance(item, dict):
                raise DoctorError(f"test {name} has a malformed input")
            relative = safe_relative(item.get("path"), f"test {name} input path")
            digest = item.get("sha256")
            if digest == "MISSING":
                if row["status"] == "VERIFIED":
                    raise DoctorError(f"verified test input is missing: {relative}")
                continue
            require_digest(digest, f"test {name} input sha256")
            source_path = repo_root / relative
            if row["status"] == "VERIFIED" and (not source_path.is_file() or sha256(source_path) != digest):
                raise DoctorError(f"verified test input changed: {relative}")
        if not isinstance(row.get("fingerprint_environment"), dict) or not isinstance(row.get("tool_versions"), dict) or not isinstance(row.get("cwd"), str):
            raise DoctorError(f"test {name} is missing environment, tool, or cwd fingerprint inputs")
        if row.get("command_fingerprint_sha256") != test_fingerprint(row):
            raise DoctorError(f"test {name} command fingerprint does not match its inputs")
        if row["status"] == "VERIFIED":
            if row.get("executed") is not True or row.get("exit_code") != 0 or not isinstance(row.get("elapsed_milliseconds"), (int, float)):
                raise DoctorError(f"test {name} cannot be VERIFIED without a passing execution and runtime")
            output = row.get("output_path")
            output_hash = require_digest(row.get("output_sha256"), f"tests.{name}.output_sha256")
            if not isinstance(output, str) or not Path(output).is_file() or sha256(Path(output)) != output_hash:
                raise DoctorError(f"test {name} output evidence is missing or changed")
        elif row.get("output_sha256") is not None:
            output = row.get("output_path")
            output_hash = require_digest(row["output_sha256"], f"tests.{name}.output_sha256")
            if not isinstance(output, str) or not Path(output).is_file() or sha256(Path(output)) != output_hash:
                raise DoctorError(f"test {name} partial output evidence is missing or changed")

    gates = document.get("release_gates")
    expected_gates = {
        "live_graph_integration": "BLOCKED", "postgresql_staging": "BLOCKED",
        "field_safety": "BLOCKED", "private_beta": "BLOCKED", "production_activation": "NO",
    }
    if not isinstance(gates, dict) or any(gates.get(key) != value for key, value in expected_gates.items()):
        raise DoctorError("live graph, PostgreSQL, field, beta and production gates must remain honest")
    if by_name["private_beta"]["status"] not in {"BLOCKED_RESOURCE", "BLOCKED_DEPENDENCY"} or by_name["production_deployment"]["status"] not in {"NOT_RUN", "BLOCKED_DEPENDENCY"}:
        raise DoctorError("private beta and production deployment cannot be promoted")

    return {"format": "biyahe-doctor-v44-validation-v1", "status": "PASS_STATIC_DOCTOR_CONTRACT",
            "categories": len(by_name), "source_revision": executable, "fingerprinted_sources": len(fingerprints),
            "tests": len(tests), "graph_cases_executed": executed, "private_beta": "BLOCKED", "production_activation": "NO"}


def main(argv: list[str] | None = None) -> int:
    root = Path(__file__).resolve().parents[2]
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--doctor", type=Path, default=root / "docs/reports/batch044-doctor-v44.json")
    parser.add_argument("--repo-root", type=Path, default=root)
    args = parser.parse_args(argv)
    try:
        document = json.loads(args.doctor.read_text(encoding="utf-8"))
        print(json.dumps(validate_doctor(document, args.repo_root.resolve()), sort_keys=True))
        return 0
    except (OSError, json.JSONDecodeError, DoctorError) as error:
        print(f"DOCTOR_V44_VALIDATION_ERROR={error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
