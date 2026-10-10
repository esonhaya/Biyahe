#!/usr/bin/env python3
"""Compare a stored-corpus replay against the pinned Batch032 Cebu evidence."""

import argparse
import ctypes
import errno
import hashlib
import json
import math
import os
from pathlib import Path
import re
import sys

EXPECTED_REPORT_SHA256 = "6caf101177904bee6fda1b12cab9c5cda36ddaf7ecc08b0d9778c0517123c81b"
EXPECTED_GRAPH_SHA256 = "29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8"
EXPECTED_SOURCE_SHA256 = "787cac20ecc586b754b1a464643a040566d486457c6a37bc0b11ce683bc66d4d"
EXPECTED_GRAPH_FINGERPRINT = "a0d9f1cb8289cf1f9bde3905d1ad8a57124ee41120153b4efc1f5b31e4c03ecd"
MAX_REPORT_BYTES = 5_000_000


class CompareError(ValueError):
    pass


def publish_no_clobber(source: Path, destination: Path) -> None:
    """Atomically publish a same-filesystem file without replacing a peer."""
    if hasattr(os, "link"):
        os.link(source, destination)
        return
    libc = ctypes.CDLL(None, use_errno=True)
    if hasattr(libc, "renameat2"):
        renameat2 = libc.renameat2
        renameat2.argtypes = (ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint)
        renameat2.restype = ctypes.c_int
        if renameat2(-100, os.fsencode(source), -100, os.fsencode(destination), 1) == 0:
            return
        error_number = ctypes.get_errno()
        if error_number not in {errno.ENOSYS, errno.EINVAL, getattr(errno, "EOPNOTSUPP", errno.ENOSYS)}:
            raise OSError(error_number, os.strerror(error_number), str(destination))
    link = libc.link
    link.argtypes = (ctypes.c_char_p, ctypes.c_char_p)
    link.restype = ctypes.c_int
    if link(os.fsencode(source), os.fsencode(destination)) != 0:
        error_number = ctypes.get_errno()
        raise OSError(error_number, os.strerror(error_number), str(destination))


def read_pinned(path):
    data = path.read_bytes()
    if len(data) > MAX_REPORT_BYTES:
        raise CompareError(f"{path} exceeds the 5 MB report limit")
    return data, json.loads(data)


def finite_number(value):
    return isinstance(value, (int, float)) and not isinstance(value, bool) and math.isfinite(value)


def validate_path_evidence(actual_case, expected_route, found):
    geometry = actual_case.get("geometry")
    nodes = actual_case.get("node_ids")
    edges = actual_case.get("edges")
    if not found:
        return geometry is None and nodes == [] and edges == [] and actual_case.get("failure_has_no_path_geometry") is True
    if not isinstance(geometry, dict) or geometry.get("type") != "LineString":
        return False
    coordinates = geometry.get("coordinates")
    if not isinstance(coordinates, list) or not 2 <= len(coordinates) <= 2048:
        return False
    if not isinstance(nodes, list) or len(nodes) != len(coordinates) or not isinstance(edges, list) or len(edges) != len(coordinates) - 1:
        return False
    if any(not isinstance(node, int) or isinstance(node, bool) or node <= 0 for node in nodes) or len(set(nodes)) != len(nodes):
        return False
    normalized_coordinates = []
    for point in coordinates:
        if not isinstance(point, list) or len(point) != 2 or not all(finite_number(value) for value in point):
            return False
        longitude, latitude = point
        if not -180 <= longitude <= 180 or not -90 <= latitude <= 90:
            return False
        normalized_coordinates.append(point)
    if any(left == right for left, right in zip(normalized_coordinates, normalized_coordinates[1:])):
        return False

    expected_origin = expected_route.get("origin_snap") or {}
    expected_destination = expected_route.get("destination_snap") or {}
    origin_distance = actual_case.get("origin_snap_distance_meters")
    destination_distance = actual_case.get("destination_snap_distance_meters")
    if not finite_number(origin_distance) or not finite_number(destination_distance) or not (0 <= origin_distance <= 25 and 0 <= destination_distance <= 25):
        return False
    if (nodes[0] != actual_case.get("origin_snap_node_id") or nodes[-1] != actual_case.get("destination_snap_node_id")
        or nodes[0] != expected_origin.get("node_id") or nodes[-1] != expected_destination.get("node_id")
        or abs(origin_distance - expected_origin.get("snap_distance_meters", -1000)) > 0.001
        or abs(destination_distance - expected_destination.get("snap_distance_meters", -1000)) > 0.001):
        return False
    origin_coordinate = actual_case.get("origin_snap_coordinate")
    destination_coordinate = actual_case.get("destination_snap_coordinate")
    if (origin_coordinate != [expected_origin.get("node_longitude"), expected_origin.get("node_latitude")]
        or destination_coordinate != [expected_destination.get("node_longitude"), expected_destination.get("node_latitude")]
        or normalized_coordinates[0] != origin_coordinate or normalized_coordinates[-1] != destination_coordinate):
        return False

    edge_lengths = 0.0
    normalized_edges = []
    for index, edge in enumerate(edges):
        if not isinstance(edge, dict):
            return False
        if (edge.get("from_node_id") != nodes[index] or edge.get("to_node_id") != nodes[index + 1]
            or not isinstance(edge.get("way_id"), int) or isinstance(edge.get("way_id"), bool) or edge["way_id"] <= 0
            or not isinstance(edge.get("segment_index"), int) or isinstance(edge.get("segment_index"), bool) or edge["segment_index"] < 0
            or not finite_number(edge.get("length_meters")) or edge["length_meters"] <= 0):
            return False
        edge_lengths += edge["length_meters"]
        normalized_edges.append({key: edge[key] for key in ("way_id", "segment_index", "length_meters")})
    distance = actual_case.get("distance_meters")
    expected_distance = expected_route.get("distance_meters")
    return (finite_number(distance) and distance > 0 and finite_number(expected_distance)
            and abs(edge_lengths - distance) <= 0.05
            and abs(distance - expected_distance) <= 0.02
            and normalized_edges == expected_route.get("edges")
            and nodes == expected_route.get("node_ids")
            and geometry == expected_route.get("geometry"))


def normalize_case_indices(indices):
    if indices is None:
        return list(range(10))
    if not isinstance(indices, list) or not indices:
        raise CompareError("case selection must contain one or more zero-based indices")
    if any(not isinstance(index, int) or isinstance(index, bool) or not 0 <= index < 10 for index in indices):
        raise CompareError("case selection indices must be integers from 0 through 9")
    if len(set(indices)) != len(indices):
        raise CompareError("case selection indices must not be repeated")
    return sorted(indices)


def parse_case_indices(value):
    if value is None:
        return None
    parts = value.split(",")
    if any(re.fullmatch(r"(?:0|[1-9][0-9]*)", part, flags=re.ASCII) is None for part in parts):
        raise CompareError("--case-indices must be comma-separated zero-based indices from 0 through 9")
    return normalize_case_indices([int(part) for part in parts])


def compare(expected, actual, expected_sha, actual_sha, selected_case_indices=None):
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
    expected_cases = expected.get("journey_corpus", {}).get("cases")
    actual_cases = actual.get("cases")
    case_indices = normalize_case_indices(selected_case_indices)
    complete_corpus = case_indices == list(range(10))
    if not isinstance(expected_cases, list) or len(expected_cases) != 10 or not isinstance(actual_cases, list):
        raise CompareError("expected report must contain ten indexed cases and actual cases must be a list")
    expected_status = "PASS" if complete_corpus else "PARTIAL"
    if actual.get("status") != expected_status:
        raise CompareError(f"actual replay status must be {expected_status} for the selected case scope")
    if actual.get("cases_total") != len(case_indices) or len(actual_cases) != len(case_indices):
        raise CompareError("actual case count does not match the selected case scope")
    if actual.get("maximum_snap_distance_meters") != 25:
        raise CompareError("actual replay case count or snap policy is missing or unsupported")
    if actual.get("case_indices", case_indices) != case_indices:
        raise CompareError("actual replay case indices differ from the requested selection")
    if actual.get("cases_expected_total", 10) != 10 or actual.get("complete_corpus", complete_corpus) is not complete_corpus:
        raise CompareError("actual replay completeness metadata differs from the selected scope")
    results = []
    for index, actual_case in zip(case_indices, actual_cases):
        expected_case = expected_cases[index]
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
        valid_distances = all(finite_number(value) for value in (expected_distance, actual_distance))
        distance_match = not found or (valid_distances and abs(actual_distance - expected_distance) <= 0.02)
        component_match = actual_case.get("component_id") == expected_route.get("component_id")
        path_evidence_valid = validate_path_evidence(actual_case, expected_route, found)
        geometry_match = not found or (path_evidence_valid and actual_case.get("geometry_matches_stored_route") is True)
        direction_match = not found or (path_evidence_valid and actual_case.get("geometry_direction_matches") is True)
        edges_match = not found or (path_evidence_valid and actual_case.get("edge_identity_matches") is True)
        continuous = path_evidence_valid and (not found or actual_case.get("geometry_continuous") is True)
        expected_origin = (expected_route.get("origin_snap") or {}).get("node_id")
        expected_destination = (expected_route.get("destination_snap") or {}).get("node_id")
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
    actual_case_keys = [row.get("case_key") for row in actual_cases]
    if (any(not isinstance(key, str) or not key for key in actual_case_keys)
        or len(set(actual_case_keys)) != len(case_indices)):
        raise CompareError("actual case keys are missing or duplicated")
    actual_found = sum(row.get("actual_status") == "ROUTE_FOUND" for row in actual_cases)
    actual_unavailable = sum(row.get("actual_status") == "ROUTE_UNAVAILABLE" for row in actual_cases)
    if actual.get("routes_found") != actual_found or actual.get("routes_unavailable") != actual_unavailable:
        raise CompareError("actual route counts do not match the per-case results")
    return {
        "format": "biyahe-offline-corpus-comparison-v1",
        "status": "PASS" if all(row["match"] for row in results) else "FAIL",
        "corpus_completeness": "COMPLETE" if complete_corpus else "PARTIAL",
        "complete_corpus": complete_corpus,
        "case_indices": case_indices,
        "cases_expected_total": 10,
        "source_sha256": EXPECTED_SOURCE_SHA256,
        "graph_sha256": EXPECTED_GRAPH_SHA256,
        "graph_fingerprint": EXPECTED_GRAPH_FINGERPRINT,
        "expected_report_sha256": expected_sha,
        "actual_report_sha256": actual_sha,
        "cases_total": len(results),
        "matches": sum(row["match"] for row in results),
        "mismatches": len(results) - sum(row["match"] for row in results),
        "geometry_contract_checked": True,
        "directed_edge_continuity_checked": True,
        "endpoint_connector_geometry_rejected": True,
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
        publish_no_clobber(temporary, path)
        temporary.unlink(missing_ok=True)
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
    parser.add_argument("--case-indices", help="optional comma-separated zero-based indices, for example 0,3")
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
        result = compare(expected, actual, expected_sha, actual_sha, parse_case_indices(args.case_indices))
        write_new(args.output, result)
        print(f"CORPUS_COMPARISON={result['status']}")
        print(f"MATCHES={result['matches']}/{result['cases_total']}")
        print(f"CORPUS_COMPLETENESS={result['corpus_completeness']}")
        print(f"REPORT={args.output}")
        return 0 if result["status"] == "PASS" else 1
    except (OSError, json.JSONDecodeError, CompareError) as error:
        print(f"CORPUS_COMPARISON_ERROR={error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
