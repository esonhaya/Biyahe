#!/usr/bin/env python3
"""Contract fixtures for Batch043 Doctor status, source, and case accounting."""

from __future__ import annotations

import hashlib
import importlib.util
import json
from pathlib import Path
import shutil
import tempfile
import unittest

SCRIPT = Path(__file__).with_name("validate-batch043-doctor.py")
SPEC = importlib.util.spec_from_file_location("batch043_doctor_validator", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
VALIDATOR = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(VALIDATOR)


def digest(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def valid_document(root: Path) -> dict:
    source = root / "source.py"
    source.write_text("# pinned fixture\n", encoding="utf-8")
    coverage = root / "docs/reports/batch032-cebu-coverage.json"
    provenance = root / "docs/reports/batch041-evidence-provenance.json"
    coverage.parent.mkdir(parents=True)
    shutil.copyfile(SCRIPT.parents[2] / "docs/reports/batch032-cebu-coverage.json", coverage)
    shutil.copyfile(SCRIPT.parents[2] / "docs/reports/batch041-evidence-provenance.json", provenance)
    blocked = {"graph_identity", "graph_integrity", "real_cebu_corpus"}
    categories = [
        {"name": name, "status": "BLOCKED_RESOURCE" if name in blocked else "IMPLEMENTED_UNTESTED",
         "evidence": "Synthetic validator contract fixture."}
        for name in sorted(VALIDATOR.REQUIRED_CATEGORIES)
    ]
    samples = []
    for index in range(5):
        samples.append({
            "timestamp_utc": f"2026-10-10T10:00:{index * 5:02d}+00:00",
            "MemAvailable_KiB": 600000,
            "SwapFree_KiB": 100000,
            "postgres_rss_kib": 0,
            "owned_worker_rss_kib": 0,
        })
    return {
        "format": "biyahe-doctor-v43-evidence-v1",
        "batch": 43,
        "source_revision": {"executable": "a" * 40, "tested": "a" * 40},
        "source_fingerprints": {"source.py": digest(source)},
        "categories": categories,
        "evidence": {
            "graph": {
                "expected_sha256": VALIDATOR.EXPECTED_GRAPH_SHA256,
                "expected_fingerprint": VALIDATOR.EXPECTED_GRAPH_FINGERPRINT,
                "status": "BLOCKED_RESOURCE",
                "actual_sha256": None,
                "graph_modified": False,
                "pbf_rescanned": False,
            },
            "graph_integrity": {"status": "BLOCKED_RESOURCE", "method": None, "result": None},
            "real_cebu_corpus": {
                "status": "BLOCKED_RESOURCE", "cases_expected_total": 10,
                "cases_executed": 0, "cases_pass": 0, "cases_fail": 0,
                "cases_blocked": 1, "cases_not_selected": 9,
                "case_indices_attempted": [0], "complete_corpus": False,
            },
            "stored_record_coverage": {
                "scope": "STORED_RECORDS_ONLY",
                "stops": {"total": 503, "snappable_within_25m": 436, "unsnappable": 67},
                "places": {"total": 37, "snappable_within_25m": 30, "unsnappable": 7},
                "geographic_population_sidewalk_field_claims": "NONE",
                "source_report_sha256": VALIDATOR.EXPECTED_COVERAGE_SHA256,
            },
            "reconciliation": {
                "vehicle_only_restrictions": {"total": 648, "stored": 619, "omitted": 29},
                "measured_graph_quality": {"long_edges": 26, "boundary_nodes": 944, "crossing_tagged_nodes": 1510},
                "unmeasured_legacy_defaults_are_zero_facts": False,
                "provenance_registry_sha256": VALIDATOR.EXPECTED_PROVENANCE_SHA256,
            },
            "transfer_verification": {"unverified_count": 2, "field_verified": False},
            "resource_preflight": {
                "preflight_status": "BLOCKED_RESOURCE",
                "memavailable_floor_kib": 524288,
                "swapfree_floor_kib": 131072,
                "breach_samples": 1,
                "process_tree_rss_cap_kib": 262144,
                "minimum_memavailable_kib": 600000,
                "minimum_swapfree_kib": 100000,
                "samples": samples,
            },
        },
        "tests": {},
        "release_gates": {
            "live_production_graph_integration": "BLOCKED",
            "postgresql_staging": "BLOCKED",
            "field_safety": "BLOCKED",
            "private_beta": "BLOCKED",
            "production_activation": "NO",
        },
    }


class DoctorV43Tests(unittest.TestCase):
    def test_zero_current_cases_and_resource_block_are_valid(self):
        with tempfile.TemporaryDirectory() as temporary:
            document = valid_document(Path(temporary))
            result = VALIDATOR.validate_doctor(document, Path(temporary))
        self.assertEqual("PASS_STATIC_DOCTOR_CONTRACT", result["status"])
        self.assertEqual(0, result["corpus_cases_executed"])
        self.assertFalse(result["corpus_completeness"])

    def test_unrecognized_status_fails_closed(self):
        with tempfile.TemporaryDirectory() as temporary:
            document = valid_document(Path(temporary))
            document["categories"][0]["status"] = "PASS"
            with self.assertRaisesRegex(VALIDATOR.DoctorError, "invalid status"):
                VALIDATOR.validate_doctor(document, Path(temporary))

    def test_partial_case_evidence_cannot_be_promoted_to_complete(self):
        with tempfile.TemporaryDirectory() as temporary:
            document = valid_document(Path(temporary))
            document["evidence"]["real_cebu_corpus"].update(
                status="VERIFIED", cases_executed=1, cases_pass=1, cases_blocked=0,
                cases_not_selected=9, complete_corpus=True)
            next(row for row in document["categories"] if row["name"] == "real_cebu_corpus")["status"] = "VERIFIED"
            with self.assertRaisesRegex(VALIDATOR.DoctorError, "ten current matching cases"):
                VALIDATOR.validate_doctor(document, Path(temporary))

    def test_resource_samples_must_be_spaced(self):
        with tempfile.TemporaryDirectory() as temporary:
            document = valid_document(Path(temporary))
            document["evidence"]["resource_preflight"]["samples"][1]["timestamp_utc"] = "2026-10-10T10:00:01+00:00"
            with self.assertRaisesRegex(VALIDATOR.DoctorError, "spaced at least five seconds"):
                VALIDATOR.validate_doctor(document, Path(temporary))

    def test_changed_source_fingerprint_invalidates_report(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            document = valid_document(root)
            document["source_fingerprints"]["source.py"] = "0" * 64
            with self.assertRaisesRegex(VALIDATOR.DoctorError, "source fingerprint mismatch"):
                VALIDATOR.validate_doctor(document, root)

    def test_release_gates_cannot_be_promoted(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            document = valid_document(root)
            document["release_gates"]["private_beta"] = "VERIFIED"
            with self.assertRaisesRegex(VALIDATOR.DoctorError, "must remain blocked"):
                VALIDATOR.validate_doctor(document, root)

    def test_verified_test_requires_hash_bound_output(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            document = valid_document(root)
            output = root / "test-output.txt"
            output.write_text("PASS\n", encoding="utf-8")
            row = {
                "status": "VERIFIED", "executed": True, "exit_code": 0,
                "command": ["python3", "fixture.py"], "cwd": str(root),
                "input_files": [], "fingerprint_environment": {}, "tool_versions": {},
                "elapsed_milliseconds": 5.0, "output_path": str(output), "output_sha256": digest(output),
            }
            payload = {"files": [], "command": row["command"], "cwd": row["cwd"],
                       "environment": {}, "tools": {}}
            row["command_fingerprint_sha256"] = hashlib.sha256(
                json.dumps(payload, sort_keys=True, separators=(",", ":")).encode()).hexdigest()
            document["tests"]["fixture"] = row
            self.assertEqual("PASS_STATIC_DOCTOR_CONTRACT", VALIDATOR.validate_doctor(document, root)["status"])
            row["output_sha256"] = "0" * 64
            with self.assertRaisesRegex(VALIDATOR.DoctorError, "output evidence is missing or changed"):
                VALIDATOR.validate_doctor(document, root)


if __name__ == "__main__":
    unittest.main(verbosity=2)
