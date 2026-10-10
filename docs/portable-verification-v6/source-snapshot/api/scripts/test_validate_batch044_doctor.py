#!/usr/bin/env python3
"""Contract fixtures for Doctor V44 evidence, boundaries, and release gates."""

from __future__ import annotations

import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
VALIDATOR_PATH = Path(__file__).with_name("validate-batch044-doctor.py")
SPEC = importlib.util.spec_from_file_location("batch044_doctor_validator", VALIDATOR_PATH)
assert SPEC is not None and SPEC.loader is not None
VALIDATOR = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(VALIDATOR)
REFRESH_PATH = Path(__file__).with_name("refresh-batch044-doctor.py")
REFRESH_SPEC = importlib.util.spec_from_file_location("batch044_doctor_refresh", REFRESH_PATH)
assert REFRESH_SPEC is not None and REFRESH_SPEC.loader is not None
REFRESH = importlib.util.module_from_spec(REFRESH_SPEC)
REFRESH_SPEC.loader.exec_module(REFRESH)


def valid_report(root: Path) -> dict[str, object]:
    (root / "docs/reports").mkdir(parents=True, exist_ok=True)
    for relative in ("docs/reports/batch032-cebu-coverage.json", "docs/reports/batch041-evidence-provenance.json"):
        (root / relative).write_bytes((ROOT / relative).read_bytes())
    source = root / "fixture.py"
    source.write_text("value = 44\n", encoding="utf-8")
    categories = [{"name": name, "status": "NOT_RUN", "evidence": "No external execution was performed."}
                  for name in sorted(VALIDATOR.REQUIRED_CATEGORIES)]
    next(row for row in categories if row["name"] == "private_beta")["status"] = "BLOCKED_DEPENDENCY"
    next(row for row in categories if row["name"] == "postgresql_staging")["status"] = "BLOCKED_DEPENDENCY"
    next(row for row in categories if row["name"] == "field_verification")["status"] = "BLOCKED_DEPENDENCY"
    evidence = {
        "graph": {"expected_sha256": VALIDATOR.EXPECTED_GRAPH_SHA256, "actual_sha256": None,
                  "status": "NOT_RUN", "modified": False, "pbf_rescanned": False},
        "graph_integrity": {"status": "NOT_RUN", "method": None, "result": None},
        "real_graph_corpus": {"expected_cases": 10, "executed": 0, "pass": 0, "fail": 0, "blocked": 0,
                              "not_run": 10, "complete": False, "case_indices_executed": [], "status": "NOT_RUN"},
        "stored_record_coverage": {
            "scope": "STORED_RECORDS_ONLY",
            "stops": {"total": 503, "snappable_within_25m": 436, "unsnappable": 67},
            "places": {"total": 37, "snappable_within_25m": 30, "unsnappable": 7},
            "source_sha256": VALIDATOR.EXPECTED_COVERAGE_SHA256,
            "geographic_population_sidewalk_or_field_claims": "NONE",
        },
        "evidence_reconciliation": {
            "vehicle_only_restrictions": {"total": 648, "stored": 619, "omitted": 29},
            "measured_graph_quality": {"long_edges": 26, "boundary_nodes": 944, "crossing_tagged_nodes": 1510},
            "unmeasured_legacy_defaults_are_zero_facts": False,
            "provenance_sha256": VALIDATOR.EXPECTED_PROVENANCE_SHA256,
        },
        "transfers": {"unverified_count": 2, "physically_verified": False},
    }
    return {
        "format": "biyahe-doctor-v44-evidence-v1", "batch": 44,
        "source_revision": {"executable": "a" * 40, "tested": "a" * 40},
        "source_fingerprints": {"fixture.py": VALIDATOR.sha256(source)},
        "categories": categories, "evidence": evidence, "tests": {},
        "release_gates": {"live_graph_integration": "BLOCKED", "postgresql_staging": "BLOCKED",
                          "field_safety": "BLOCKED", "private_beta": "BLOCKED", "production_activation": "NO"},
    }


class DoctorV44ContractTests(unittest.TestCase):
    def test_not_run_graph_and_historical_evidence_are_accepted(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            result = VALIDATOR.validate_doctor(valid_report(root), root)
            self.assertEqual("PASS_STATIC_DOCTOR_CONTRACT", result["status"])
            self.assertEqual(0, result["graph_cases_executed"])

    def test_historical_nine_routes_cannot_be_claimed_as_a_current_replay(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            report = valid_report(root)
            report["evidence"]["real_graph_corpus"].update({
                "executed": 10, "pass": 9, "fail": 1, "blocked": 0, "not_run": 0,
                "complete": True, "case_indices_executed": list(range(10)), "status": "VERIFIED",
            })
            for row in report["categories"]:
                if row["name"] == "real_graph_execution":
                    row["status"] = "VERIFIED"
            with self.assertRaisesRegex(VALIDATOR.DoctorError, "all ten current cases to pass"):
                VALIDATOR.validate_doctor(report, root)

    def test_nonverified_graph_cannot_contain_actual_hash(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            report = valid_report(root)
            report["evidence"]["graph"]["actual_sha256"] = VALIDATOR.EXPECTED_GRAPH_SHA256
            with self.assertRaisesRegex(VALIDATOR.DoctorError, "must not contain an actual SHA"):
                VALIDATOR.validate_doctor(report, root)

    def test_verified_test_requires_matching_output_and_command_fingerprint(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            report = valid_report(root)
            output = root / "suite.stdout.txt"
            output.write_text("SUITE=PASS\n", encoding="utf-8")
            test_row = {
                "status": "VERIFIED", "executed": True, "exit_code": 0, "elapsed_milliseconds": 3.5,
                "command": ["python3", "-m", "unittest"], "cwd": str(root),
                "input_files": [{"path": "fixture.py", "sha256": report["source_fingerprints"]["fixture.py"]}],
                "fingerprint_environment": {}, "tool_versions": {"python": "fixture"},
                "output_path": str(output), "output_sha256": VALIDATOR.sha256(output),
            }
            test_row["command_fingerprint_sha256"] = VALIDATOR.test_fingerprint(test_row)
            report["tests"] = {"fixture": test_row}
            self.assertEqual(1, VALIDATOR.validate_doctor(report, root)["tests"])
            test_row["output_sha256"] = "0" * 64
            with self.assertRaisesRegex(VALIDATOR.DoctorError, "output evidence is missing or changed"):
                VALIDATOR.validate_doctor(report, root)

    def test_verified_test_rejects_a_missing_input_fingerprint(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            report = valid_report(root)
            output = root / "suite.stdout.txt"
            output.write_text("SUITE=PASS\n", encoding="utf-8")
            test_row = {
                "status": "VERIFIED", "executed": True, "exit_code": 0, "elapsed_milliseconds": 1.0,
                "command": ["python3", "check.py"], "cwd": str(root),
                "input_files": [{"path": "missing.py", "sha256": "MISSING"}],
                "fingerprint_environment": {}, "tool_versions": {"python3": "fixture"},
                "output_path": str(output), "output_sha256": VALIDATOR.sha256(output),
            }
            test_row["command_fingerprint_sha256"] = VALIDATOR.test_fingerprint(test_row)
            report["tests"] = {"fixture": test_row}
            with self.assertRaisesRegex(VALIDATOR.DoctorError, "verified test input is missing"):
                VALIDATOR.validate_doctor(report, root)

    def test_report_writer_never_overwrites(self):
        with tempfile.TemporaryDirectory() as temporary:
            path = Path(temporary) / "doctor.json"
            REFRESH.write_new(path, {"format": "first"})
            self.assertEqual({"format": "first"}, json.loads(path.read_text(encoding="utf-8")))
            with self.assertRaisesRegex(ValueError, "refusing to overwrite"):
                REFRESH.write_new(path, {"format": "ignored"})
            self.assertEqual({"format": "first"}, json.loads(path.read_text(encoding="utf-8")))


if __name__ == "__main__":
    unittest.main(verbosity=2)
