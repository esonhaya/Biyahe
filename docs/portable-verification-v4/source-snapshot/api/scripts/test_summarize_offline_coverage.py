"""Synthetic-only unit fixtures for the stored-evidence coverage summarizer."""

import importlib.util
from pathlib import Path
import tempfile
import unittest

SCRIPT = Path(__file__).with_name("summarize-offline-coverage.py")
SPEC = importlib.util.spec_from_file_location("coverage_summary", SCRIPT)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)
COMPARE_SPEC = importlib.util.spec_from_file_location("offline_corpus_compare", Path(__file__).with_name("compare-offline-corpus.py"))
COMPARE = importlib.util.module_from_spec(COMPARE_SPEC)
COMPARE_SPEC.loader.exec_module(COMPARE)


def synthetic_document():
    snapped = {"status": "SNAPPED", "component_id": 7, "node_id": 70, "snap_distance_meters": 2.0,
               "largest_component": True, "field_access_verified": False}
    miss = {"status": "ROUTE_UNAVAILABLE", "reason_code": "snap_unavailable"}
    route_cases = []
    for index in range(10):
        found = index == 0
        route_cases.append({
            "case_id": f"synthetic_case_{index}",
            "source_records": {"origin": {"type": "stop", "id": index + 1, "trust_status": "UNVERIFIED_RECORD"},
                               "destination": {"type": "stop", "id": index + 2, "trust_status": "UNVERIFIED_RECORD"}},
            "route": {"status": "ROUTE_FOUND" if found else "ROUTE_UNAVAILABLE",
                      "reason_code": None if found else "component_disconnected",
                      "geometry": {"type": "LineString", "coordinates": [[123.8, 10.3], [123.81, 10.31]]} if found else None,
                      "node_ids": [1, 2] if found else [],
                      "origin_snap": {"status": "SNAPPED", "node_id": 1, "node_longitude": 123.8, "node_latitude": 10.3, "snap_distance_meters": 0.5} if found else None,
                      "destination_snap": {"status": "SNAPPED", "node_id": 2, "node_longitude": 123.81, "node_latitude": 10.31, "snap_distance_meters": 0.5} if found else None,
                      "edges": [{"way_id": 1, "segment_index": 0, "length_meters": 12}] if found else [],
                      "distance_meters": 12 if found else None},
        })
    return {
        "format": "biyahe-cebu-offline-coverage-v1", "complete": True,
        "source": {"graph_source_sha256": MODULE.EXPECTED_SOURCE_SHA256,
                   "transport_backup_sha256": MODULE.EXPECTED_BACKUP_SHA256, "graph_fingerprint": MODULE.EXPECTED_GRAPH_FINGERPRINT},
        "snap_policy": {"maximum_distance_meters": 25},
        "graph_validation": {"artifact_sha256": MODULE.EXPECTED_GRAPH_SHA256, "graph_fingerprint": MODULE.EXPECTED_GRAPH_FINGERPRINT,
                             "nodes": 76685, "edges": 78225, "restriction_relations": 619,
                             "routing_ready": True, "restriction_inventory_complete": True,
                             "restriction_counts": {"total": 619, "pedestrian_applicable": 0, "ambiguous": 0, "unsupported_applicable": 0},
                             "routing_capabilities": {"routing_ready": True}},
        "coverage": {
            "stops": {"records": [
                {"id": 1, "coordinates": {"lat": 10.3, "lng": 123.8}, "snap": snapped, "city_id": 4, "trust_status": "UNVERIFIED_RECORD", "verification_state": "UNVERIFIED", "coordinate_status": "STORED_COORDINATES", "source": {"source_type": "COMMUNITY_OPEN_DATA"}},
                {"id": 2, "coordinates": {"lat": 91, "lng": 123.8}, "snap": miss, "city_id": 4, "trust_status": "DRAFT_DEMO", "verification_state": "DRAFT", "coordinate_status": "STORED_COORDINATES", "source": {"source_type": "DEMO_FIXTURE"}},
            ]},
            "places": {"records": [{"id": 8, "coordinates": {"lat": 10.31, "lng": 123.81}, "snap": snapped, "city_id": 5, "trust_status": "UNVERIFIED_RECORD", "verification_state": "UNVERIFIED", "coordinate_status": "STORED_COORDINATES", "source": {"source_type": "COMMUNITY_OPEN_DATA"}}]},
            "transfers": {"records": [{"id": 3, "trust_status": "UNVERIFIED_RECORD", "graph_route_status": "ROUTE_FOUND"}]},
        },
        "journey_corpus": {"cases": route_cases},
        "quality_summary": {"long_edges_over_250m": 0, "boundary_nodes_within_0_0005_degrees": 0, "crossing_tagged_nodes": 0},
        "quality_evidence": {"source_sha256": MODULE.EXPECTED_SOURCE_SHA256,
                             "graph_fingerprint": MODULE.EXPECTED_GRAPH_FINGERPRINT,
                             "counts": {"long_edges_over_250m": 26, "boundary_nodes": 944, "crossing_tagged_nodes": 1510}},
    }


class CoverageSummaryTests(unittest.TestCase):
    def test_synthetic_evidence_is_summarized_with_conflicts_and_limits(self):
        result = MODULE.build_summary(synthetic_document(), "a" * 64)
        self.assertEqual(result["mode"], "CARRIED_FORWARD_FROM_PINNED_EVIDENCE")
        self.assertFalse(result["recomputed_from_graph"])
        self.assertEqual(result["stops"]["snappable_within_pinned_policy"], 1)
        self.assertEqual(result["stops"]["with_usable_coordinates"], 1)
        self.assertEqual(result["stops"]["invalid_or_missing_coordinate_ids"][0]["id"], 2)
        self.assertEqual(result["stops"]["unsnappable_ids_and_reasons"][0]["id"], 2)
        self.assertEqual(result["transfers"]["unverified_transfer_ids"], [3])
        self.assertEqual(result["journey_corpus"]["cases_total"], 10)
        self.assertEqual(len(result["graph_quality"]["summary_discrepancies"]), 3)
        self.assertEqual(result["routing_capability"]["count_reconciliation"]["status"], "BLOCKED_PROVENANCE_REGISTRY_NOT_VALIDATED")
        self.assertEqual(result["stops"]["measurement_scope"], "STORED_TRANSIT_RECORDS_ONLY")
        self.assertFalse(result["stops"]["geographic_sidewalk_population_and_field_coverage_measured"])
        self.assertFalse(result["evidence_boundaries"]["graph_connectivity_proves_physical_transfer_safety"])

    def test_duplicate_point_ids_are_rejected(self):
        records = synthetic_document()["coverage"]["stops"]["records"]
        with self.assertRaisesRegex(MODULE.EvidenceError, "duplicate record id"):
            MODULE.summarize_points([records[0], dict(records[0])], 25)

    def test_snapped_record_with_invalid_coordinates_is_rejected(self):
        records = synthetic_document()["coverage"]["stops"]["records"]
        invalid = dict(records[0], id=9, coordinates={"lat": 91, "lng": 123.8})
        with self.assertRaisesRegex(MODULE.EvidenceError, "SNAPPED with invalid coordinates"):
            MODULE.summarize_points([invalid], 25)

    def test_snap_distance_cannot_exceed_pinned_radius(self):
        records = synthetic_document()["coverage"]["stops"]["records"]
        invalid = dict(records[0], id=9, snap={**records[0]["snap"], "snap_distance_meters": 25.1})
        with self.assertRaisesRegex(MODULE.EvidenceError, "exceeds the pinned snap radius"):
            MODULE.summarize_points([invalid], 25)

    def test_missing_coverage_section_has_clear_error(self):
        document = synthetic_document()
        del document["coverage"]["places"]
        with self.assertRaisesRegex(MODULE.EvidenceError, "coverage.places.records is missing"):
            MODULE.build_summary(document, "a" * 64)

    def test_repeated_corpus_descriptive_id_is_disambiguated_by_ordered_key(self):
        corpus = synthetic_document()["journey_corpus"]
        corpus["cases"][1]["case_id"] = corpus["cases"][0]["case_id"]
        result = MODULE.summarize_corpus(corpus)
        self.assertEqual(result["repeated_descriptive_case_ids"]["synthetic_case_0"], [0, 1])
        self.assertEqual(result["cases"][0]["case_key"], "00:synthetic_case_0")
        self.assertEqual(result["cases"][1]["case_key"], "01:synthetic_case_0")

    def test_source_mismatch_fails_closed(self):
        document = synthetic_document()
        document["source"]["graph_source_sha256"] = "0" * 64
        with self.assertRaisesRegex(MODULE.EvidenceError, "source PBF identity"):
            MODULE.build_summary(document, "a" * 64)

    def test_incomplete_corpus_is_rejected(self):
        document = synthetic_document()
        document["journey_corpus"]["cases"].pop()
        with self.assertRaisesRegex(MODULE.EvidenceError, "exactly ten"):
            MODULE.build_summary(document, "a" * 64)

    def test_synthetic_coordinate_grid_flags_only_low_snap_record_cells(self):
        records = [
            {"id": 1, "coordinates": {"lat": 10.301, "lng": 123.801}, "snap": {"status": "ROUTE_UNAVAILABLE"}},
            {"id": 2, "coordinates": {"lat": 10.302, "lng": 123.802}, "snap": {"status": "ROUTE_UNAVAILABLE"}},
            {"id": 3, "coordinates": {"lat": 10.303, "lng": 123.803}, "snap": {"status": "ROUTE_UNAVAILABLE"}},
            {"id": 4, "coordinates": {"lat": 10.304, "lng": 123.804}, "snap": {"status": "SNAPPED"}},
            {"id": 5, "coordinates": {"lat": 100, "lng": 123.8}, "snap": {"status": "SNAPPED"}},
        ]
        result = MODULE.coordinate_grid_coverage(records)
        self.assertEqual(len(result["cells_with_records"]), 1)
        self.assertEqual(result["low_snap_candidate_cells"][0]["record_ids"], [1, 2, 3, 4])
        self.assertEqual(result["low_snap_candidate_cells"][0]["snappable_fraction"], 0.25)
        self.assertIn("population coverage", result["interpretation"])

    def test_synthetic_corpus_comparator_requires_pinned_identity_and_parity(self):
        expected = synthetic_document()
        actual = {
            "format": "biyahe-offline-walking-corpus-v1",
            "status": "PASS",
            "source_sha256": MODULE.EXPECTED_SOURCE_SHA256,
            "graph_sha256": MODULE.EXPECTED_GRAPH_SHA256,
            "graph_fingerprint": MODULE.EXPECTED_GRAPH_FINGERPRINT,
            "graph_format": "biyahe-pedestrian-sqlite-v3",
            "graph_schema_version": 3,
            "cases_total": 10,
            "maximum_snap_distance_meters": 25,
            "routes_found": 1,
            "routes_unavailable": 9,
            "cases": [],
        }
        for index, expected_case in enumerate(expected["journey_corpus"]["cases"]):
            route = expected_case["route"]
            found = route["status"] == "ROUTE_FOUND"
            origin_snap = route.get("origin_snap") or {}
            destination_snap = route.get("destination_snap") or {}
            edges = [dict(edge, from_node_id=1, to_node_id=2) for edge in route.get("edges", [])] if found else []
            actual["cases"].append({
                "case_index": index, "case_id": expected_case["case_id"], "case_key": f"{index:02d}:{expected_case['case_id']}",
                "actual_status": route["status"], "actual_reason_code": route["reason_code"],
                "distance_meters": route["distance_meters"], "component_id": route.get("component_id"),
                "geometry_matches_stored_route": True,
                "geometry_direction_matches": True, "edge_identity_matches": True,
                "geometry_continuous": True, "origin_snap_node_id": origin_snap.get("node_id"),
                "destination_snap_node_id": destination_snap.get("node_id"),
                "origin_snap_distance_meters": origin_snap.get("snap_distance_meters"),
                "destination_snap_distance_meters": destination_snap.get("snap_distance_meters"),
                "origin_snap_coordinate": [origin_snap["node_longitude"], origin_snap["node_latitude"]] if found else None,
                "destination_snap_coordinate": [destination_snap["node_longitude"], destination_snap["node_latitude"]] if found else None,
                "geometry": route["geometry"], "node_ids": route["node_ids"], "edges": edges,
                "failure_has_no_path_geometry": not found,
            })
        result = COMPARE.compare(expected, actual, "a" * 64, "b" * 64)
        self.assertEqual(result["status"], "PASS")
        self.assertEqual(result["matches"], 10)
        actual["status"] = "FAIL"
        with self.assertRaisesRegex(COMPARE.CompareError, "complete PASS"):
            COMPARE.compare(expected, actual, "a" * 64, "b" * 64)
        actual["status"] = "PASS"
        actual["graph_sha256"] = "0" * 64
        with self.assertRaisesRegex(COMPARE.CompareError, "graph_sha256"):
            COMPARE.compare(expected, actual, "a" * 64, "b" * 64)
        actual["graph_sha256"] = MODULE.EXPECTED_GRAPH_SHA256
        actual["cases"][0]["case_id"] = "wrong_case"
        with self.assertRaisesRegex(COMPARE.CompareError, "case identity"):
            COMPARE.compare(expected, actual, "a" * 64, "b" * 64)
        actual["cases"][0] = None
        with self.assertRaisesRegex(COMPARE.CompareError, "case is not an object"):
            COMPARE.compare(expected, actual, "a" * 64, "b" * 64)

    def test_comparator_publication_preserves_existing_outputs(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / "comparison.json"
            target.write_text("existing output", encoding="utf-8")
            with self.assertRaisesRegex(COMPARE.CompareError, "already exists"):
                COMPARE.write_new(target, {"status": "PASS"})
            self.assertEqual(target.read_text(encoding="utf-8"), "existing output")

            race_target = Path(directory) / "race.json"
            original_publish = COMPARE.publish_no_clobber

            def create_competing_destination(source, destination):
                Path(destination).write_text("concurrent output", encoding="utf-8")
                raise FileExistsError(destination)

            COMPARE.publish_no_clobber = create_competing_destination
            try:
                with self.assertRaises(FileExistsError):
                    COMPARE.write_new(race_target, {"status": "PASS"})
            finally:
                COMPARE.publish_no_clobber = original_publish
            self.assertEqual(race_target.read_text(encoding="utf-8"), "concurrent output")
            self.assertFalse(race_target.with_name(race_target.name + ".building").exists())


if __name__ == "__main__":
    unittest.main()
