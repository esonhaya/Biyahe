#!/usr/bin/env python3
"""Fail-closed regression fixtures for the Doctor V42 evidence contract."""

import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = Path(__file__).with_name("validate-batch042-doctor.py")
SPEC = importlib.util.spec_from_file_location("batch042_doctor_validator", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
VALIDATOR = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(VALIDATOR)
REFRESH_SCRIPT = Path(__file__).with_name("refresh-batch042-doctor.py")
REFRESH_SPEC = importlib.util.spec_from_file_location("batch042_doctor_refresh", REFRESH_SCRIPT)
assert REFRESH_SPEC is not None and REFRESH_SPEC.loader is not None
REFRESH = importlib.util.module_from_spec(REFRESH_SPEC)
REFRESH_SPEC.loader.exec_module(REFRESH)
DOCTOR = json.loads((ROOT / "docs/reports/batch042-doctor-v42.json").read_text(encoding="utf-8"))


class DoctorV42Tests(unittest.TestCase):
    def test_current_doctor_contract_validates(self):
        result = VALIDATOR.validate_doctor(copy.deepcopy(DOCTOR), ROOT)
        self.assertEqual("PASS_STATIC_DOCTOR_CONTRACT", result["status"])
        self.assertEqual("NO", result["production_activation"])

    def test_unrecognized_status_fails_closed(self):
        document = copy.deepcopy(DOCTOR)
        document["categories"][0]["status"] = "PASS"
        with self.assertRaisesRegex(VALIDATOR.DoctorError, "invalid status"):
            VALIDATOR.validate_doctor(document, ROOT)

    def test_wrong_graph_identity_cannot_be_verified(self):
        document = copy.deepcopy(DOCTOR)
        document["evidence"]["graph"]["status"] = "VERIFIED"
        document["evidence"]["graph"]["actual_sha256"] = "0" * 64
        with self.assertRaisesRegex(VALIDATOR.DoctorError, "different or absent SHA256"):
            VALIDATOR.validate_doctor(document, ROOT)

    def test_graph_identity_requires_a_current_read_only_hash_observation(self):
        document = copy.deepcopy(DOCTOR)
        graph = document["evidence"]["graph"]
        graph["status"] = "VERIFIED"
        graph["actual_sha256"] = VALIDATOR.EXPECTED_GRAPH_SHA256
        graph["observations"] = [{
            "method": "read_only_sha256", "path": "$HOME/.cache/graph.sqlite",
            "sha256": VALIDATOR.EXPECTED_GRAPH_SHA256, "observed_at_utc": "2026-10-10T00:00:00Z",
        }]
        self.assertEqual("PASS_STATIC_DOCTOR_CONTRACT", VALIDATOR.validate_doctor(document, ROOT)["status"])
        graph["observations"] = []
        with self.assertRaisesRegex(VALIDATOR.DoctorError, "current read-only hash observation"):
            VALIDATOR.validate_doctor(document, ROOT)

    def test_current_pass_requires_current_execution_and_evidence(self):
        document = copy.deepcopy(DOCTOR)
        document["tests"]["provenance_fixtures"] = {
            "status": "VERIFIED", "executed": False,
            "command_fingerprint_sha256": "a" * 64,
            "output_path": "/missing/evidence.json", "output_sha256": "b" * 64,
        }
        with self.assertRaisesRegex(VALIDATOR.DoctorError, "without a current execution"):
            VALIDATOR.validate_doctor(document, ROOT)

    def test_changed_source_fingerprint_invalidates_doctor(self):
        document = copy.deepcopy(DOCTOR)
        first = next(iter(document["source_fingerprints"]))
        document["source_fingerprints"][first] = "0" * 64
        with self.assertRaisesRegex(VALIDATOR.DoctorError, "fingerprint mismatch"):
            VALIDATOR.validate_doctor(document, ROOT)

    def test_changed_command_fingerprint_invalidates_current_pass(self):
        document = copy.deepcopy(DOCTOR)
        document["source_fingerprints"]["api/scripts/test_validate_batch042_doctor.py"] = VALIDATOR.sha256(Path(__file__).resolve())
        output_path = ROOT / "docs/reports/batch042-doctor-v42.json"
        command = ["python3", "api/scripts/test_evidence_provenance.py"]
        cwd = str(ROOT)
        environment = {"PYTHONDONTWRITEBYTECODE": "1"}
        tools = {"python3": "fixture-python"}
        document["tests"]["provenance_fixtures"] = {
            "status": "VERIFIED", "executed": True,
            "command_fingerprint_sha256": "0" * 64,
            "command": command, "cwd": cwd, "input_files": [],
            "fingerprint_environment": environment, "tool_versions": tools,
            "output_path": str(output_path),
            "output_sha256": hashlib.sha256(output_path.read_bytes()).hexdigest(),
        }
        with self.assertRaisesRegex(VALIDATOR.DoctorError, "command fingerprint does not match"):
            VALIDATOR.validate_doctor(document, ROOT)

    def test_provenance_registry_hash_is_bound_to_source(self):
        document = copy.deepcopy(DOCTOR)
        document["evidence"]["reconciliation"]["provenance_registry_sha256"] = "0" * 64
        with self.assertRaisesRegex(VALIDATOR.DoctorError, "provenance registry changed"):
            VALIDATOR.validate_doctor(document, ROOT)

    def test_old_graph_fingerprint_is_not_represented_as_current(self):
        document = copy.deepcopy(DOCTOR)
        document["evidence"]["graph"]["fingerprint_status"] = "VERIFIED"
        with self.assertRaisesRegex(VALIDATOR.DoctorError, "historical expectation"):
            VALIDATOR.validate_doctor(document, ROOT)

    def test_historical_coverage_cannot_claim_geographic_coverage(self):
        document = copy.deepcopy(DOCTOR)
        document["evidence"]["stored_record_coverage"]["geographic_population_sidewalk_field_claims"] = "YES"
        with self.assertRaisesRegex(VALIDATOR.DoctorError, "cannot claim geographic"):
            VALIDATOR.validate_doctor(document, ROOT)

    def test_legacy_defaults_remain_unmeasured(self):
        document = copy.deepcopy(DOCTOR)
        document["evidence"]["reconciliation"]["graph_quality"]["unmeasured_legacy_defaults_are_zero_facts"] = True
        with self.assertRaisesRegex(VALIDATOR.DoctorError, "cannot be reported as measured"):
            VALIDATOR.validate_doctor(document, ROOT)

    def test_release_gates_cannot_be_promoted(self):
        document = copy.deepcopy(DOCTOR)
        document["release_gates"]["private_beta"] = "VERIFIED"
        with self.assertRaisesRegex(VALIDATOR.DoctorError, "must keep production"):
            VALIDATOR.validate_doctor(document, ROOT)

    def test_doctor_builder_invalidates_changed_inputs_and_evidence(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / "source.json"
            output = root / "evidence.txt"
            source.write_text("source-v1", encoding="utf-8")
            output.write_text("PASS", encoding="utf-8")
            row = {
                "status": "PASS",
                "input_files": [{"path": "source.json", "sha256": REFRESH.sha256(source)}],
                "output_path": str(output),
                "output_sha256": REFRESH.sha256(output),
            }
            current = REFRESH.make_test("fixture", row, root=root)
            self.assertEqual("VERIFIED", current["status"])
            missing_output_hash = dict(row)
            missing_output_hash["output_sha256"] = None
            missing_evidence = REFRESH.make_test("fixture", missing_output_hash, root=root)
            self.assertEqual("NOT_RUN", missing_evidence["status"])
            self.assertIn("missing_evidence_output_hash", missing_evidence["evidence_invalidated"])
            source.write_text("source-v2", encoding="utf-8")
            stale_source = REFRESH.make_test("fixture", row, root=root)
            self.assertEqual("NOT_RUN", stale_source["status"])
            self.assertEqual("VERIFIED", stale_source["attempted_status"])
            self.assertIn("changed_input:source.json", stale_source["evidence_invalidated"])
            source.write_text("source-v1", encoding="utf-8")
            output.write_text("FAIL", encoding="utf-8")
            stale_output = REFRESH.make_test("fixture", row, root=root)
            self.assertEqual("NOT_RUN", stale_output["status"])
            self.assertIsNone(stale_output["output_sha256"])
            self.assertEqual(row["output_sha256"], stale_output["attempted_output_sha256"])
            self.assertIn("changed_evidence_output", stale_output["evidence_invalidated"])
            report = root / "docs/reports/batch042-doctor-v42.json"
            report.parent.mkdir(parents=True)
            report.write_text("current report", encoding="utf-8")
            self_referential = dict(row, status="PASS",
                input_files=[{"path": "docs/reports/batch042-doctor-v42.json", "sha256": REFRESH.sha256(report)}],
                output_path=str(output), output_sha256=REFRESH.sha256(output))
            circular = REFRESH.make_test("doctor-self-check", self_referential, root=root)
            self.assertEqual("NOT_RUN", circular["status"])
            self.assertIn("self_referential_doctor_report_input", circular["evidence_invalidated"])

    def test_completed_child_remains_verified_when_parent_group_is_resource_blocked(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source_paths = ["web/scripts/place-search-harness.mjs", "web/src/lib/latestPlaceSearch.ts"]
            input_files = []
            for relative in source_paths:
                source = root / relative
                source.parent.mkdir(parents=True, exist_ok=True)
                source.write_text(f"fixture:{relative}", encoding="utf-8")
                input_files.append({"path": relative, "sha256": REFRESH.sha256(source)})
            output = root / "group.stdout.txt"
            output.write_text(
                "2026-10-10T03:51:02Z worker: CHILD_COMMAND=['node', 'scripts/place-search-harness.mjs'] EXIT=0\n",
                encoding="utf-8",
            )
            row = {
                "id": "search-and-can-i-ride", "status": "BLOCKED_RESOURCE", "exit_code": 6,
                "command": ["python3", "-c", "run child harnesses"], "cwd": str(root),
                "input_fingerprint_sha256": "a" * 64,
                "input_files": input_files,
                "fingerprint_environment": {"NODE_OPTIONS": ""},
                "tool_versions": {"node": "fixture-node", "python3": "fixture-python", "php": "MISSING",
                                   "bash": "fixture-bash", "npm": "MISSING", "composer": "MISSING",
                                   "python_runtime": "3.14"},
                "output_path": str(output), "output_sha256": REFRESH.sha256(output),
                "resource_guard": "BLOCKED", "resource_log": None,
                "started_at": "2026-10-10T03:50:55Z", "ended_at": "2026-10-10T03:51:02Z",
            }
            parent, child = REFRESH.find_child([{"suites": [row]}], "search-and-can-i-ride", "place-search-harness")
            self.assertEqual(row["status"], parent["status"])
            self.assertEqual(["node", "scripts/place-search-harness.mjs"], child["argv"])
            self.assertEqual(0, child["exit_code"])
            self.assertEqual("PASS", child["status"])
            evidence = REFRESH.make_test("place_search", parent, child, root=root)
            self.assertEqual("VERIFIED", evidence["status"])
            self.assertEqual("BLOCKED_RESOURCE", evidence["parent_status"])
            self.assertEqual("BLOCKED", evidence["resource_guard"])
            self.assertEqual("node", evidence["child_command"][0])
            self.assertEqual(sorted(source_paths), [item["path"] for item in evidence["input_files"]])
            self.assertEqual(64, len(evidence["command_fingerprint_sha256"]))

            earlier_output = root / "earlier.stdout.txt"
            earlier_output.write_text(
                "2026-10-10T02:30:39Z worker: CHILD_COMMAND=['node', 'scripts/validation-ledger-harness.mjs'] EXIT=0\n",
                encoding="utf-8",
            )
            ledger_paths = ["web/scripts/validation-ledger-harness.mjs", "web/scripts/validation-ledger.mjs"]
            ledger_inputs = []
            for relative in ledger_paths:
                source = root / relative
                source.parent.mkdir(parents=True, exist_ok=True)
                source.write_text(f"fixture:{relative}", encoding="utf-8")
                ledger_inputs.append({"path": relative, "sha256": REFRESH.sha256(source)})
            earlier = dict(row, status="FAIL", exit_code=1, input_files=ledger_inputs, output_path=str(earlier_output),
                           output_sha256=REFRESH.sha256(earlier_output))
            latest_parent, earlier_child = REFRESH.find_child(
                [{"executable_source_revision": "1" * 40, "suites": [earlier]},
                 {"executable_source_revision": "2" * 40, "suites": [row]}],
                "search-and-can-i-ride", "validation-ledger-harness",
            )
            self.assertIsNotNone(earlier_child)
            self.assertEqual("PASS", earlier_child["status"])
            self.assertEqual("1" * 40, latest_parent["_run_source_revision"])
            ledger_evidence = REFRESH.make_test("validation_ledger", latest_parent, earlier_child, root=root)
            self.assertEqual("VERIFIED", ledger_evidence["status"])
            self.assertEqual("FAIL", ledger_evidence["parent_status"])

            can_i_ride_output = root / "can-i-ride.stdout.txt"
            can_i_ride_output.write_text(
                "2026-10-10T03:51:02Z worker: CHILD_COMMAND=['node', 'scripts/can-i-ride-harness.mjs'] EXIT=0\n",
                encoding="utf-8",
            )
            can_i_ride_inputs = []
            for relative in ["web/scripts/can-i-ride-harness.mjs", "web/src/lib/canIRidePresentation.ts"]:
                source = root / relative
                source.parent.mkdir(parents=True, exist_ok=True)
                source.write_text(f"fixture:{relative}", encoding="utf-8")
                can_i_ride_inputs.append({"path": relative, "sha256": REFRESH.sha256(source)})
            can_i_ride_row = dict(row, input_files=can_i_ride_inputs, output_path=str(can_i_ride_output),
                                  output_sha256=REFRESH.sha256(can_i_ride_output))
            can_i_ride_parent, can_i_ride_child = REFRESH.find_child(
                [{"suites": [can_i_ride_row]}], "search-and-can-i-ride", "can-i-ride-harness")
            can_i_ride_evidence = REFRESH.make_test("can_i_ride", can_i_ride_parent, can_i_ride_child, root=root)
            self.assertEqual("NOT_RUN", can_i_ride_evidence["status"])
            self.assertEqual("PASS", can_i_ride_evidence["execution_observed_status"])
            self.assertIn("missing_child_input:web/src/app/page.tsx", can_i_ride_evidence["evidence_invalidated"])

            output.write_text("tampered\n", encoding="utf-8")
            parent, child = REFRESH.find_child([{"suites": [row]}], "search-and-can-i-ride", "place-search-harness")
            self.assertIsNone(child)
            self.assertEqual("BLOCKED_RESOURCE", REFRESH.make_test("place_search", parent, child, root=root)["status"])
            output.write_text(
                "2026-10-10T03:51:02Z worker: CHILD_COMMAND=['node', 'scripts/place-search-harness.mjs'] EXIT=1\n",
                encoding="utf-8",
            )
            forged = dict(row, output_sha256=REFRESH.sha256(output), child_command_results=[{
                "argv": ["node", "scripts/place-search-harness.mjs"], "status": "PASS", "exit_code": 0,
            }])
            parent, child = REFRESH.find_child([{"suites": [forged]}], "search-and-can-i-ride", "place-search-harness")
            self.assertEqual("FAIL", child["status"])
            conflicting_evidence = REFRESH.make_test("place_search", parent, child, root=root)
            self.assertEqual("NOT_RUN", conflicting_evidence["status"])
            self.assertIn("conflicting_child_result_record", conflicting_evidence["evidence_invalidated"])


if __name__ == "__main__":
    unittest.main(verbosity=2)
