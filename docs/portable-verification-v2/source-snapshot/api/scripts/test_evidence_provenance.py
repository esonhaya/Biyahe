"""Lightweight integrity tests for Batch041 metric provenance."""

import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT_DIR = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location("evidence_provenance", SCRIPT_DIR / "evidence_provenance.py")
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class EvidenceProvenanceTests(unittest.TestCase):
    def setUp(self):
        self.registry = json.loads((ROOT / "docs/reports/batch041-evidence-provenance.json").read_text())

    def test_pinned_sources_and_metric_denominators_validate(self):
        registry_bytes = (ROOT / "docs/reports/batch041-evidence-provenance.json").read_bytes()
        digest = hashlib.sha256(registry_bytes).hexdigest()
        result = MODULE.validate_registry_document(self.registry, ROOT, digest)
        self.assertEqual(result["status"], "PASS")
        self.assertEqual(result["sources_verified"], 6)
        self.assertEqual(result["metric_claims_verified"], 13)
        claims = {row["id"]: row["value"] for row in result["claim_results"]}
        self.assertEqual(claims["restrictions.source_relations"], 648)
        self.assertEqual(claims["restrictions.graph_stored_relations"], 619)
        self.assertEqual(claims["restrictions.source_pedestrian_applicable"], 0)
        self.assertEqual(claims["quality.graph_evidence.long_edges_over_250m"], 26)

    def test_source_hash_mismatch_fails_closed(self):
        registry = copy.deepcopy(self.registry)
        registry["sources"][0]["sha256"] = "0" * 64
        with self.assertRaisesRegex(MODULE.ProvenanceError, "SHA-256 mismatch"):
            MODULE.validate_registry_document(registry, ROOT)

    def test_missing_claim_definition_fails_closed(self):
        registry = copy.deepcopy(self.registry)
        del registry["metric_claims"][0]["definition"]
        with self.assertRaisesRegex(MODULE.ProvenanceError, "requires definition"):
            MODULE.validate_registry_document(registry, ROOT)

    def test_duplicate_metric_identity_is_rejected(self):
        registry = copy.deepcopy(self.registry)
        conflicting = copy.deepcopy(registry["metric_claims"][0])
        conflicting["value"] = 619
        conflicting["definition"] = "A different denominator hidden behind the same metric ID."
        registry["metric_claims"].append(conflicting)
        with self.assertRaisesRegex(MODULE.ProvenanceError, "duplicate or invalid metric id"):
            MODULE.validate_registry_document(registry, ROOT)

    def test_source_value_change_is_not_silently_relabelled(self):
        registry = copy.deepcopy(self.registry)
        registry["metric_claims"][0]["value"] = 619
        with self.assertRaisesRegex(MODULE.ProvenanceError, "metric value mismatch"):
            MODULE.validate_registry_document(registry, ROOT)

    def test_registry_hash_mismatch_fails_closed(self):
        with self.assertRaisesRegex(MODULE.ProvenanceError, "registry SHA-256"):
            MODULE.validate_registry_document(self.registry, ROOT, "0" * 64)


if __name__ == "__main__":
    unittest.main()
