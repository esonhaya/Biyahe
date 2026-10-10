"""Static, no-database smoke verifier tests."""

import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = Path(__file__).with_name("offline-evidence-smoke.py")
SPEC = importlib.util.spec_from_file_location("offline_evidence_smoke", SCRIPT)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class OfflineEvidenceSmokeTests(unittest.TestCase):
    def test_pinned_inputs_pass_without_claiming_graph_or_route_execution(self):
        report = MODULE.build_smoke_report(
            ROOT / "docs/reports/batch032-cebu-coverage.json",
            ROOT / "docs/reports/batch041-evidence-provenance.json",
            ROOT,
        )
        self.assertEqual(report["status"], "PASS_STATIC_EVIDENCE_ONLY")
        self.assertEqual(report["coverage"]["stops_snappable"], 436)
        self.assertEqual(report["stored_corpus"]["cases"], 10)
        self.assertFalse(report["stored_corpus"]["current_replay_executed"])
        self.assertFalse(report["execution_boundaries"]["sqlite_opened"])
        self.assertFalse(report["execution_boundaries"]["private_beta_ready"])

    def test_tampered_coverage_fails_before_readiness_is_emitted(self):
        import json
        import tempfile

        original = json.loads((ROOT / "docs/reports/batch032-cebu-coverage.json").read_text())
        original["complete"] = False
        with tempfile.TemporaryDirectory() as directory:
            coverage = Path(directory) / "coverage.json"
            coverage.write_text(json.dumps(original))
            with self.assertRaisesRegex(MODULE.SmokeError, "SHA-256 mismatch"):
                MODULE.build_smoke_report(coverage, ROOT / "docs/reports/batch041-evidence-provenance.json", ROOT)


if __name__ == "__main__":
    unittest.main()
