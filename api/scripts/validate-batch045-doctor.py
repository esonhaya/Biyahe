#!/usr/bin/env python3
"""Fail-closed structural and fingerprint checks for Doctor V45."""

from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys
from typing import Any

ALLOWED = {
    "VERIFIED", "IMPLEMENTED_UNTESTED", "BLOCKED_RESOURCE",
    "BLOCKED_DEPENDENCY", "NOT_RUN", "FAILED",
}
REQUIRED_CATEGORIES = {
    "external_host_availability", "staging_activation", "postgis", "importer",
    "real_cebu_corpus", "live_api", "frontend_build", "browser_checks",
    "request_lifecycle", "saved_journeys", "ride_mode", "evidence_presentation",
    "failure_recovery", "portable_verification", "field_safety", "private_beta",
    "production_activation", "graph_identity", "graph_integrity",
}
EXPECTED_GRAPH_SHA256 = "29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8"


class DoctorError(ValueError):
    pass


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def fail(message: str) -> None:
    raise DoctorError(message)


def validate(report_path: Path, repo_root: Path) -> dict[str, Any]:
    try:
        document = json.loads(report_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as error:
        raise DoctorError(f"could not read Doctor V45 JSON: {error}") from error

    if not isinstance(document, dict) or document.get("format") != "biyahe-doctor-v45-evidence-v1" or document.get("batch") != 45:
        fail("unsupported Doctor V45 report")

    revision = document.get("source_revision")
    if not isinstance(revision, dict):
        fail("source revision pin is missing")
    executable = revision.get("executable")
    if not isinstance(executable, str) or re.fullmatch(r"[0-9a-f]{40}", executable) is None:
        fail("executable source revision must be a full Git commit")
    try:
        subprocess.run(["git", "-C", str(repo_root), "cat-file", "-e", f"{executable}^{{commit}}"],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    except (OSError, subprocess.CalledProcessError) as error:
        raise DoctorError("pinned executable revision is absent from Git") from error

    fingerprints = document.get("source_fingerprints")
    if not isinstance(fingerprints, dict) or not fingerprints:
        fail("executable source fingerprints are missing")
    for relative, expected in fingerprints.items():
        if not isinstance(relative, str) or relative.startswith("/") or ".." in Path(relative).parts:
            fail("source fingerprint path is not repository-relative")
        if not isinstance(expected, str) or re.fullmatch(r"[0-9a-f]{64}", expected) is None:
            fail(f"invalid source fingerprint: {relative}")
        path = repo_root / relative
        if not path.is_file() or sha256(path) != expected:
            fail(f"current source fingerprint mismatch: {relative}")
        try:
            committed = subprocess.check_output(["git", "-C", str(repo_root), "show", f"{executable}:{relative}"])
        except (OSError, subprocess.CalledProcessError) as error:
            raise DoctorError(f"source file is absent from pinned revision: {relative}") from error
        if hashlib.sha256(committed).hexdigest() != expected:
            fail(f"pinned revision does not match source fingerprint: {relative}")

    tools = document.get("verification_tools")
    if not isinstance(tools, dict) or not tools:
        fail("verification-tool fingerprints are missing")
    for relative, expected in tools.items():
        if not isinstance(relative, str) or relative.startswith("/") or ".." in Path(relative).parts:
            fail("verification-tool path is not repository-relative")
        path = repo_root / relative
        if not isinstance(expected, str) or not path.is_file() or sha256(path) != expected:
            fail(f"verification-tool fingerprint mismatch: {relative}")

    categories = document.get("categories")
    if not isinstance(categories, list):
        fail("categories must be an array")
    by_name: dict[str, dict[str, Any]] = {}
    for item in categories:
        if not isinstance(item, dict) or not isinstance(item.get("name"), str) or item["name"] in by_name:
            fail("malformed or duplicate category")
        if item.get("status") not in ALLOWED or not isinstance(item.get("evidence"), str) or not item["evidence"].strip():
            fail(f"category {item.get('name')} has no supported status or evidence")
        by_name[item["name"]] = item
    if set(by_name) != REQUIRED_CATEGORIES:
        fail("required Doctor categories differ from the Batch 045 contract")

    evidence = document.get("evidence")
    if not isinstance(evidence, dict):
        fail("evidence object is missing")
    graph = evidence.get("graph")
    if not isinstance(graph, dict) or graph.get("expected_sha256") != EXPECTED_GRAPH_SHA256:
        fail("expected graph identity is missing")
    if graph.get("actual_sha256") is not None or graph.get("accessed") is not False or graph.get("modified") is not False or graph.get("pbf_rescanned") is not False:
        fail("unrun graph execution must not claim an observed or modified graph")
    if by_name["graph_identity"]["status"] != "NOT_RUN" or by_name["graph_integrity"]["status"] != "NOT_RUN":
        fail("unrun graph identity/integrity cannot be promoted")

    corpus = evidence.get("real_cebu_corpus")
    if not isinstance(corpus, dict) or corpus.get("expected_cases") != 10:
        fail("ten-case corpus contract is missing")
    counts = [corpus.get(key) for key in ("executed", "pass", "fail", "blocked", "not_run")]
    if any(not isinstance(value, int) or isinstance(value, bool) or value < 0 for value in counts):
        fail("corpus counts must be nonnegative integers")
    executed, passed, failed, blocked, not_run = counts
    if passed + failed != executed or executed + blocked + not_run != 10:
        fail("corpus counts do not reconcile")
    if executed != 0 or passed != 0 or failed != 0 or blocked != 0 or not_run != 10:
        fail("Batch 045 must not claim current routes without a graph run")

    resources = evidence.get("resource_preflight")
    if not isinstance(resources, dict) or resources.get("sample_count") != 5:
        fail("five-sample resource evidence is missing")
    samples = resources.get("samples")
    if not isinstance(samples, list) or len(samples) != 5:
        fail("five spaced resource samples are required")
    if any(not isinstance(row, dict)
           or not isinstance(row.get("memavailable_kib"), int)
           or not isinstance(row.get("swapfree_kib"), int) for row in samples):
        fail("resource samples must contain integer memory and swap observations")
    minimum_memory = min(row["memavailable_kib"] for row in samples)
    minimum_swap = min(row["swapfree_kib"] for row in samples)
    if minimum_memory != resources.get("minimum_memavailable_kib") or minimum_swap != resources.get("minimum_swapfree_kib"):
        fail("resource minima do not match the recorded samples")
    if minimum_memory < 524288 or minimum_swap < 131072:
        if resources.get("status") != "BLOCKED_RESOURCE":
            fail("a breached resource floor must block execution")
    if resources.get("owned_worker_started") is not False or resources.get("cancellation") != "NOT_NEEDED_COMMAND_NOT_STARTED":
        fail("blocked preflight must not claim a worker or cancellation")

    harness = evidence.get("api_failure_harness")
    if not isinstance(harness, dict) or harness.get("status") != "BLOCKED_RESOURCE" or harness.get("command_started") is not False or harness.get("exit_code") != 4:
        fail("API harness preflight outcome is inconsistent")

    gates = document.get("release_gates")
    expected_gates = {
        "live_production_graph": "BLOCKED", "postgresql_staging": "BLOCKED",
        "field_safety": "BLOCKED", "private_beta": "BLOCKED",
        "production_activation": "NO",
    }
    if gates != expected_gates:
        fail("release gates must remain blocked with production activation NO")

    reports = evidence.get("historical_untracked_reports")
    if not isinstance(reports, list) or len(reports) != 7:
        fail("the seven historical reports must be represented")
    for item in reports:
        if not isinstance(item, dict) or item.get("preserved") is not True:
            fail("historical report preservation evidence is malformed")
        path = repo_root / str(item.get("path", ""))
        if not path.is_file() or sha256(path) != item.get("sha256"):
            fail(f"historical report changed: {item.get('path')}")

    static = evidence.get("static_validation")
    if not isinstance(static, dict) or static.get("status") != "PASS":
        fail("static-check evidence is missing")
    evidence_rows = static.get("evidence")
    if not isinstance(evidence_rows, dict):
        fail("static-check evidence fingerprints are missing")
    for row in evidence_rows.values():
        if not isinstance(row, dict) or not isinstance(row.get("path"), str):
            fail("malformed static evidence fingerprint")
        path_value = row["path"]
        path = Path(path_value).expanduser()
        if not path.is_absolute():
            path = repo_root / path
        if not path.is_file() or sha256(path) != row.get("sha256"):
            fail(f"static evidence file changed or is missing: {path_value}")

    return {"status": "PASS", "categories": len(by_name), "corpus_cases_executed": executed,
            "historical_reports": len(reports), "source_revision": executable}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repo-root", type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument("--report", type=Path)
    arguments = parser.parse_args()
    root = arguments.repo_root.resolve()
    report = arguments.report or root / "docs/reports/batch045-doctor-v45.json"
    try:
        result = validate(report.resolve(), root)
    except DoctorError as error:
        print(f"DOCTOR_V45_CONTRACT=FAIL reason={error}", file=sys.stderr)
        return 1
    print("DOCTOR_V45_CONTRACT=PASS " + " ".join(f"{key}={value}" for key, value in result.items() if key != "status"))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
