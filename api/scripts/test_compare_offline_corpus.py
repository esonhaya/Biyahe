#!/usr/bin/env python3
"""Synthetic comparator-contract tests derived from the pinned corpus schema.

These tests exercise report validation only. They do not execute the graph router
or reproduce a real Cebu route.
"""

from __future__ import annotations

import importlib.util
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = Path(__file__).with_name("compare-offline-corpus.py")
SPEC = importlib.util.spec_from_file_location("offline_corpus_comparator", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
COMPARATOR = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(COMPARATOR)
EXPECTED = json.loads((ROOT / "docs/reports/batch032-cebu-coverage.json").read_text())


def synthetic_replay() -> dict:
    """Create a schema fixture from stored expectations; this is not route evidence."""
    cases = []
    for index, expected_case in enumerate(EXPECTED["journey_corpus"]["cases"]):
        route = expected_case["route"]
        found = route["status"] == "ROUTE_FOUND"
        nodes = list(route["node_ids"]) if found else []
        edges = []
        if found:
            for edge_index, edge in enumerate(route["edges"]):
                edges.append({
                    **edge,
                    "from_node_id": nodes[edge_index],
                    "to_node_id": nodes[edge_index + 1],
                })
        origin = route.get("origin_snap") or {}
        destination = route.get("destination_snap") or {}
        cases.append({
            "case_index": index,
            "case_key": f"{index:02d}:{expected_case['case_id']}",
            "case_id": expected_case["case_id"],
            "actual_status": route["status"],
            "actual_reason_code": route.get("reason_code"),
            "component_id": route.get("component_id"),
            "distance_meters": route.get("distance_meters"),
            "geometry": route.get("geometry"),
            "node_ids": nodes,
            "edges": edges,
            "origin_snap_node_id": origin.get("node_id"),
            "destination_snap_node_id": destination.get("node_id"),
            "origin_snap_distance_meters": origin.get("snap_distance_meters"),
            "destination_snap_distance_meters": destination.get("snap_distance_meters"),
            "origin_snap_coordinate": [origin.get("node_longitude"), origin.get("node_latitude")] if found else None,
            "destination_snap_coordinate": [destination.get("node_longitude"), destination.get("node_latitude")] if found else None,
            "failure_has_no_path_geometry": not found,
            "geometry_matches_stored_route": True,
            "geometry_direction_matches": True,
            "edge_identity_matches": True,
            "geometry_continuous": True,
        })
    found_count = sum(case["actual_status"] == "ROUTE_FOUND" for case in cases)
    return {
        "format": "biyahe-offline-walking-corpus-v1",
        "status": "PASS",
        "source_sha256": COMPARATOR.EXPECTED_SOURCE_SHA256,
        "graph_sha256": COMPARATOR.EXPECTED_GRAPH_SHA256,
        "graph_fingerprint": COMPARATOR.EXPECTED_GRAPH_FINGERPRINT,
        "graph_format": "biyahe-pedestrian-sqlite-v3",
        "graph_schema_version": 3,
        "cases_total": len(cases),
        "maximum_snap_distance_meters": 25,
        "routes_found": found_count,
        "routes_unavailable": len(cases) - found_count,
        "cases": cases,
    }


def synthetic_subset(indices: list[int]) -> dict:
    replay = synthetic_replay()
    replay["cases"] = [replay["cases"][index] for index in indices]
    replay["cases_total"] = len(indices)
    replay["case_indices"] = indices
    replay["cases_expected_total"] = 10
    replay["complete_corpus"] = False
    replay["status"] = "PARTIAL"
    replay["routes_found"] = sum(case["actual_status"] == "ROUTE_FOUND" for case in replay["cases"])
    replay["routes_unavailable"] = len(indices) - replay["routes_found"]
    return replay


class OfflineCorpusComparatorTests(unittest.TestCase):
    def compare(self, actual: dict) -> dict:
        return COMPARATOR.compare(EXPECTED, actual, "a" * 64, "b" * 64)

    def test_synthetic_schema_fixture_matches_all_ten_stored_expectations(self):
        result = self.compare(synthetic_replay())
        self.assertEqual("PASS", result["status"])
        self.assertEqual(10, result["matches"])
        self.assertFalse(result["external_host_execution"])

    def test_rejects_wrong_source_identity(self):
        actual = synthetic_replay()
        actual["graph_sha256"] = "0" * 64
        with self.assertRaisesRegex(COMPARATOR.CompareError, "graph_sha256"):
            self.compare(actual)

    def test_rejects_discontinuous_or_reversed_edge(self):
        actual = synthetic_replay()
        first = next(case for case in actual["cases"] if case["actual_status"] == "ROUTE_FOUND")
        first["edges"][0]["from_node_id"], first["edges"][0]["to_node_id"] = (
            first["edges"][0]["to_node_id"], first["edges"][0]["from_node_id"]
        )
        result = self.compare(actual)
        self.assertEqual("FAIL", result["status"])
        self.assertFalse(result["cases"][first["case_index"]]["geometry_continuous"])

    def test_rejects_geometry_on_disconnected_case(self):
        actual = synthetic_replay()
        unavailable = next(case for case in actual["cases"] if case["actual_status"] == "ROUTE_UNAVAILABLE")
        unavailable["geometry"] = {"type": "LineString", "coordinates": [[123.9, 10.3], [123.91, 10.3]]}
        result = self.compare(actual)
        self.assertEqual("FAIL", result["status"])
        self.assertFalse(result["cases"][unavailable["case_index"]]["geometry_continuous"])

    def test_selected_case_match_is_passed_without_claiming_complete_corpus(self):
        actual = synthetic_subset([1, 4])
        result = COMPARATOR.compare(EXPECTED, actual, "a" * 64, "b" * 64, [1, 4])

        self.assertEqual("PASS", result["status"])
        self.assertEqual("PARTIAL", result["corpus_completeness"])
        self.assertFalse(result["complete_corpus"])
        self.assertEqual([1, 4], result["case_indices"])
        self.assertEqual(2, result["matches"])
        self.assertEqual(10, result["cases_expected_total"])

    def test_rejects_subset_report_that_is_misrepresented_as_a_full_pass(self):
        actual = synthetic_subset([4])
        actual["status"] = "PASS"

        with self.assertRaisesRegex(COMPARATOR.CompareError, "status must be PARTIAL"):
            COMPARATOR.compare(EXPECTED, actual, "a" * 64, "b" * 64, [4])

    def test_rejects_repeated_or_out_of_range_case_selection(self):
        for indices in ([2, 2], [10]):
            with self.subTest(indices=indices):
                with self.assertRaises(COMPARATOR.CompareError):
                    COMPARATOR.normalize_case_indices(list(indices))

    def test_case_selection_cli_parser_is_strict_and_does_not_treat_empty_as_full(self):
        self.assertIsNone(COMPARATOR.parse_case_indices(None))
        self.assertEqual([0, 3], COMPARATOR.parse_case_indices("3,0"))
        for value in ("", " ", "01", "1,", "1,1", "10", "-1", "１"):
            with self.subTest(value=value), self.assertRaises(COMPARATOR.CompareError):
                COMPARATOR.parse_case_indices(value)


if __name__ == "__main__":
    unittest.main()
