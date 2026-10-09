#!/usr/bin/env python3
"""Synthetic fail-closed tests for the Doctor V41 status contract."""

import copy
import importlib.util
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = Path(__file__).with_name("validate-batch041-doctor.py")
SPEC = importlib.util.spec_from_file_location("batch041_doctor_validator", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
VALIDATOR = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(VALIDATOR)
DOCTOR = json.loads((ROOT / "docs/reports/batch041-doctor-v41.json").read_text())
REGISTRY = ROOT / "docs/reports/batch041-evidence-provenance.json"


class DoctorV41Tests(unittest.TestCase):
    def test_current_static_doctor_contract_validates(self):
        result = VALIDATOR.validate_doctor(copy.deepcopy(DOCTOR), ROOT, REGISTRY)
        self.assertEqual("PASS_STATIC_DOCTOR_CONTRACT", result["status"])
        self.assertEqual("BLOCKED", result["private_beta"])

    def test_wrong_graph_identity_is_rejected(self):
        document = copy.deepcopy(DOCTOR)
        document["source_and_graph"]["graph_sha256"] = "0" * 64
        with self.assertRaisesRegex(VALIDATOR.DoctorError, "fingerprint mismatch"):
            VALIDATOR.validate_doctor(document, ROOT, REGISTRY)

    def test_unknown_category_status_is_rejected(self):
        document = copy.deepcopy(DOCTOR)
        document["categories"][0]["validation"] = "PASS"
        with self.assertRaisesRegex(VALIDATOR.DoctorError, "invalid validation status"):
            VALIDATOR.validate_doctor(document, ROOT, REGISTRY)

    def test_private_beta_or_activation_cannot_be_promoted(self):
        document = copy.deepcopy(DOCTOR)
        document["release_gates"]["private_beta"] = "VERIFIED"
        with self.assertRaisesRegex(VALIDATOR.DoctorError, "must remain blocked"):
            VALIDATOR.validate_doctor(document, ROOT, REGISTRY)


if __name__ == "__main__":
    unittest.main()
