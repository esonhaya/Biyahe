#!/usr/bin/env python3
"""Fail-closed regression fixtures for the Doctor V42 evidence contract."""

import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = Path(__file__).with_name("validate-batch042-doctor.py")
SPEC = importlib.util.spec_from_file_location("batch042_doctor_validator", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
VALIDATOR = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(VALIDATOR)
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
        document["source_fingerprints"]["api/scripts/test_validate_batch042_doctor.py"] = VALIDATOR.sha256(SCRIPT)
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


if __name__ == "__main__":
    unittest.main(verbosity=2)
