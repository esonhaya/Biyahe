#!/usr/bin/env python3
"""Compare a stored-corpus replay against the pinned Batch032 Cebu evidence."""

import argparse
import hashlib
import json
import math
import os
from pathlib import Path
import sys

EXPECTED_REPORT_SHA256 = "6caf101177904bee6fda1b12cab9c5cda36ddaf7ecc08b0d9778c0517123c81b"
EXPECTED_GRAPH_SHA256 = "29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8"
EXPECTED_SOURCE_SHA256 = "787cac20ecc586b754b1a464643a040566d486457c6a37bc0b11ce683bc66d4d"
EXPECTED_GRAPH_FINGERPRINT = "a0d9f1cb8289cf1f9bde3905d1ad8a57124ee41120153b4efc1f5b31e4c03ecd"
MAX_REPORT_BYTES = 5_000_000


class CompareError(ValueError):
    pass


def read_pinned(path):
    data = path.read_bytes()
    if len(data) > MAX_REPORT_BYTES:
        raise CompareError(f"{path} exceeds the 5 MB report limit")
    return data, json.loads(data)


def compare(expected, actual, expected_sha, actual_sha):
    if not isinstance(expected, dict) or not isinstance(actual, dict):
        raise CompareError("expected and actual replay reports must be JSON objects")
    if expected.get("format") != "biyahe-cebu-offline-coverage-v1" or expected.get("complete") is not True:
        raise CompareError("pinned source coverage report is incomplete or unsupported")
    if actual.get("format") != "biyahe-offline-walking-corpus-v1":
        raise CompareError("actual replay report format is unsupported")
    for key, pinned in (("source_sha256", EXPECTED_SOURCE_SHA256), ("graph_sha256", EXPECTED_GRAPH_SHA256),
                        ("graph_fingerprint", EXPECTED_GRAPH_FINGERPRINT)):
        if (expected.get("source", {}).get("graph_source_sha256") if key == "source_sha256" else
            expected.get("graph_validation", {}).get("artifact_sha256") if key == "graph_sha256" else
            expected.get("graph_validation", {}).get("graph_fingerprint")) != pinned:
            raise CompareError(f"expected evidence {key} differs from the pinned identity")
        if actual.get(key) != pinned:
            raise CompareError(f"actual replay {key} differs from the pinned identity")
    if actual.get("graph_format") != "biyahe-pedestrian-sqlite-v3" or actual.get("graph_schema_version") != 3:
        raise CompareError("actual replay graph schema is unsupported")
    if actual.get("status") != "PASS":
        raise CompareError("actual replay does not report a complete PASS")
    expected_cases = expected.get("journey_corpus", {}).get("cases")
    actual_cases = actual.get("cases")
    if not isinstance(expected_cases, list) or len(expected_cases) != 10 or not isinstance(actual_cases, list) or len(actual_cases) != 10:
        raise CompareError("expected and actual reports must each contain ten indexed cases")
    if len({row.get("case_key") for row in actual_cases if isinstance(row, dict)}) != 10:
        raise CompareError("actual case keys are missing or duplicated")
    results = []
    for index, (expected_case, actual_case) in enumerate(zip(expected_cases, actual_cases)):
        if not isinstance(expected_case, dict) or not isinstance(actual_case, dict):
            raise CompareError(f"expected or actual corpus case is not an object at {index}")
        if actual_case.get("case_index") != index:
            raise CompareError(f"actual corpus case order/index mismatch at {index}")
        expected_key = f"{index:02d}:{expected_case.get('case_id')}"
        if actual_case.get("case_key") != expected_key or actual_case.get("case_id") != expected_case.get("case_id"):
            raise CompareError(f"actual corpus case identity mismatch at {index}")
        expected_route = expected_case.get("route") or {}
        if not isinstance(expected_route, dict):
            raise CompareError(f"expected route evidence is malformed at {index}")
        found = expected_route.get("status") == "ROUTE_FOUND"
        status_match = actual_case.get("actual_status") == expected_route.get("status")
        reason_match = actual_case.get("actual_reason_code") == expected_route.get("reason_code")
        expected_distance = expected_route.get("distance_meters")
        actual_distance = actual_case.get("distance_meters")
        valid_distances = all(isinstance(value, (int, float)) and not isinstance(value, bool) and math.isfinite(value)
                              for value in (expected_distance, actual_distance))
        distance_match = not found or (valid_distances and abs(actual_distance - expected_distance) <= 0.02)
        component_match = actual_case.get("component_id") == expected_route.get("component_id")
        geometry_match = not found or actual_case.get("geometry_matches_stored_route") is True
        direction_match = not found or actual_case.get("geometry_direction_matches") is True
        edges_match = not found or actual_case.get("edge_identity_matches") is True
        continuous = not found or actual_case.get("geometry_continuous") is True
        expected_origin = expected_route.get("origin_snap", {}).get("node_id")
        expected_destination = expected_route.get("destination_snap", {}).get("node_id")
        snap_match = not found or (actual_case.get("origin_snap_node_id") == expected_origin
                                   and actual_case.get("destination_snap_node_id") == expected_destination)
        matches = all((status_match, reason_match, distance_match, component_match, geometry_match, direction_match, edges_match, continuous, snap_match))
        results.append({
            "case_index": index,
            "case_key": actual_case.get("case_key"),
            "case_id": expected_case.get("case_id"),
            "source_endpoint_ids": [
                (expected_case.get("source_records", {}).get("origin") or {}).get("id"),
                (expected_case.get("source_records", {}).get("destination") or {}).get("id"),
            ],
            "expected_status": expected_route.get("status"),
            "actual_status": actual_case.get("actual_status"),
            "expected_reason_code": expected_route.get("reason_code"),
            "actual_reason_code": actual_case.get("actual_reason_code"),
            "status_match": status_match,
            "reason_match": reason_match,
            "distance_match_within_0_02_meters": distance_match,
            "component_match": component_match,
            "geometry_match": geometry_match,
            "direction_match": direction_match,
            "edge_identity_match": edges_match,
            "geometry_continuous": continuous,
            "snapped_endpoint_match": snap_match,
            "match": matches,
        })
    return {
        "format": "biyahe-offline-corpus-comparison-v1",
        "status": "PASS" if all(row["match"] for row in results) else "FAIL",
        "source_sha256": EXPECTED_SOURCE_SHA256,
        "graph_sha256": EXPECTED_GRAPH_SHA256,
        "graph_fingerprint": EXPECTED_GRAPH_FINGERPRINT,
        "expected_report_sha256": expected_sha,
        "actual_report_sha256": actual_sha,
        "cases_total": len(results),
        "matches": sum(row["match"] for row in results),
        "mismatches": len(results) - sum(row["match"] for row in results),
        "external_host_execution": False,
        "field_safety_verified": False,
        "cases": results,
    }


def write_new(path, document):
    if path.exists() or path.with_name(path.name + ".building").exists():
        raise CompareError("output path or .building path already exists")
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(path.name + ".building")
    owns_temporary = False
    try:
        with temporary.open("x", encoding="utf-8") as handle:
            owns_temporary = True
            handle.write(json.dumps(document, sort_keys=True, ensure_ascii=False, indent=2) + "\n")
            handle.flush()
            os.fsync(handle.fileno())
        # A same-directory hard link publishes atomically and fails if another
        # process created the destination after the initial check.
        os.link(temporary, path)
        temporary.unlink()
        owns_temporary = False
    except Exception:
        if owns_temporary:
            temporary.unlink(missing_ok=True)
        raise


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--expected", type=Path, default=Path(__file__).resolve().parents[2] / "docs/reports/batch032-cebu-coverage.json")
    parser.add_argument("--actual", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--expected-sha256", default=EXPECTED_REPORT_SHA256)
    args = parser.parse_args(argv)
    try:
        expected_bytes, expected = read_pinned(args.expected)
        actual_bytes, actual = read_pinned(args.actual)
        expected_sha = hashlib.sha256(expected_bytes).hexdigest()
        actual_sha = hashlib.sha256(actual_bytes).hexdigest()
        if expected_sha != EXPECTED_REPORT_SHA256 or expected_sha != args.expected_sha256:
            raise CompareError("expected coverage report SHA-256 does not match the pinned evidence")
        if not isinstance(actual, dict):
            raise CompareError("actual replay report must be a JSON object")
        result = compare(expected, actual, expected_sha, actual_sha)
        write_new(args.output, result)
        print(f"CORPUS_COMPARISON={result['status']}")
        print(f"MATCHES={result['matches']}/{result['cases_total']}")
        print(f"REPORT={args.output}")
        return 0 if result["status"] == "PASS" else 1
    except (OSError, json.JSONDecodeError, CompareError) as error:
        print(f"CORPUS_COMPARISON_ERROR={error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
