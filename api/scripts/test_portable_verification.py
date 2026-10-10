#!/usr/bin/env python3
"""Deterministic fixtures for portable runner fingerprints and recovery."""

from __future__ import annotations

import importlib.util
import json
from pathlib import Path
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = Path(__file__).with_name("run-portable-verification.py")
SPEC = importlib.util.spec_from_file_location("batch042_portable_runner", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
RUNNER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(RUNNER)
PACKAGER_SCRIPT = Path(__file__).with_name("package-batch041-verification-kit.py")
PACKAGER_SPEC = importlib.util.spec_from_file_location("batch042_verification_packager", PACKAGER_SCRIPT)
assert PACKAGER_SPEC is not None and PACKAGER_SPEC.loader is not None
PACKAGER = importlib.util.module_from_spec(PACKAGER_SPEC)
PACKAGER_SPEC.loader.exec_module(PACKAGER)


class PortableRunnerTests(unittest.TestCase):
    def test_child_command_results_accept_guard_worker_prefix(self):
        output = "\n".join([
            "2026-10-10T03:51:01Z worker: CHILD_COMMAND=['node', 'one.mjs'] EXIT=0",
            "2026-10-10T03:51:02Z worker: CHILD_COMMAND=['node', 'two.mjs'] EXIT=143",
            "  CHILD_COMMAND=['node', 'example.mjs'] EXIT=0 is illustrative source text",
        ])
        self.assertEqual([
            {"argv": ["node", "one.mjs"], "status": "PASS", "exit_code": 0},
            {"argv": ["node", "two.mjs"], "status": "FAIL", "exit_code": 143},
        ], RUNNER.parse_child_command_results(output))

    def test_source_command_cwd_and_environment_change_fingerprint(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / "source.txt"
            source.write_text("first", encoding="utf-8")
            base, files, tools = RUNNER.file_fingerprint(
                root, ["source.txt"], ["python3", "-c", "print('ok')"], root, {"MODE": "one"})
            self.assertEqual(files, [{"path": "source.txt", "sha256": RUNNER.sha256_file(source)}])
            self.assertIn("python_runtime", tools)
            command_change, _, _ = RUNNER.file_fingerprint(
                root, ["source.txt"], ["python3", "-c", "print('changed')"], root, {"MODE": "one"})
            environment_change, _, _ = RUNNER.file_fingerprint(
                root, ["source.txt"], ["python3", "-c", "print('ok')"], root, {"MODE": "two"})
            source.write_text("second", encoding="utf-8")
            source_change, _, _ = RUNNER.file_fingerprint(
                root, ["source.txt"], ["python3", "-c", "print('ok')"], root, {"MODE": "one"})
            self.assertEqual(len({base, command_change, environment_change, source_change}), 4)

    def test_no_clobber_output_is_not_executed_or_overwritten(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root / "input.txt").write_text("input", encoding="utf-8")
            run_dir = root / "run"
            run_dir.mkdir()
            output = run_dir / "suite.stdout.txt"
            output.write_text("previous evidence", encoding="utf-8")
            marker = root / "command-ran"
            spec = {"id": "suite", "argv": [sys.executable, "-c", f"from pathlib import Path; Path({str(marker)!r}).write_text('ran')"],
                    "cwd": root, "inputs": ["input.txt"]}
            results = RUNNER.run_specs(root, run_dir, [spec], root / "unused-guard.sh")
            self.assertEqual(results[0]["status"], "NOT_RUN")
            self.assertEqual(results[0]["failure"], "no_clobber_output_exists")
            self.assertEqual(output.read_text(encoding="utf-8"), "previous evidence")
            self.assertFalse(marker.exists())

    def test_abandoned_running_records_recover_as_not_run(self):
        with tempfile.TemporaryDirectory() as temporary:
            output_root = Path(temporary)
            run_dir = output_root / "interrupted"
            run_dir.mkdir()
            partial_output = run_dir / "partial.stdout.txt"
            partial_output.write_text("partial output", encoding="utf-8")
            status_path = run_dir / "run-status.json"
            status_path.write_text(json.dumps({
                "status": "RUNNING",
                "pid": 2_147_483_647,
                "active_suite": {"id": "suite", "status": "RUNNING", "output_path": str(partial_output)},
                "suites": [{"id": "old", "status": "RUNNING"}],
            }), encoding="utf-8")
            recovered = RUNNER.recover_stale_runs(output_root)
            self.assertEqual(recovered, [str(status_path)])
            status = json.loads(status_path.read_text(encoding="utf-8"))
            self.assertEqual(status["status"], "NOT_RUN")
            self.assertEqual(status["active_suite"]["status"], "NOT_RUN")
            self.assertEqual(status["suites"][0]["status"], "NOT_RUN")
            self.assertEqual(status["recovery"]["status"], "INTERRUPTED_RUN_RECOVERED")
            self.assertEqual(status["active_suite"]["partial_output_sha256"], RUNNER.sha256_file(partial_output))

    def test_aggregate_keeps_blocked_and_failed_distinct(self):
        self.assertEqual(RUNNER.aggregate([{"status": "PASS"}, {"status": "BLOCKED_RESOURCE"}]), "BLOCKED_RESOURCE")
        self.assertEqual(RUNNER.aggregate([{"status": "PASS"}, {"status": "BLOCKED_DEPENDENCY"}]), "BLOCKED_DEPENDENCY")
        self.assertEqual(RUNNER.aggregate([{"status": "PASS"}, {"status": "FAIL"}]), "FAIL")
        self.assertEqual(RUNNER.aggregate([{"status": "PASS"}, {"status": "NOT_RUN"}]), "NOT_RUN")

    def test_individual_suite_selection_is_mode_scoped(self):
        specs = RUNNER.command_specs(ROOT, "FRONTEND", ROOT / ".temporary-run-directory")
        self.assertEqual(["commute-card"], [spec["id"] for spec in RUNNER.select_specs(specs, "commute-card")])
        for suite_id in ("place-search", "search-keyboard", "validation-ledger", "can-i-ride", "ux-contract"):
            self.assertEqual([suite_id], [spec["id"] for spec in RUNNER.select_specs(specs, suite_id)])
        with self.assertRaisesRegex(ValueError, "not available in this mode"):
            RUNNER.select_specs(specs, "not-a-suite")

    def test_search_group_fingerprints_every_ux_harness_source(self):
        specs = RUNNER.command_specs(ROOT, "FRONTEND", ROOT / ".temporary-run-directory")
        group = next(spec for spec in specs if spec["id"] == "search-and-can-i-ride")
        required = {
            "web/src/app/page.tsx", "web/src/app/globals.css", "web/src/components/NearbyRoutes.tsx",
            "web/src/components/PersonalCommuteTools.tsx", "web/src/lib/nearbyLookup.ts",
            "web/src/lib/rideMode.ts", "web/public/sw.js", "web/public/manifest.webmanifest",
            "api/app/Http/Controllers/Api/DevelopmentOfflineWalkingPreviewController.php",
        }
        self.assertTrue(required.issubset(set(group["inputs"])), sorted(required - set(group["inputs"])))

    def test_packaged_doctor_evidence_is_relocated_and_hash_pinned(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            snapshot = root / "source-snapshot"
            report_path = snapshot / "docs/reports/batch042-doctor-v42.json"
            report_path.parent.mkdir(parents=True)
            output = root / "original-cache/test.stdout.txt"
            resource_log = root / "original-cache/test.resource.log"
            output.parent.mkdir()
            output.write_text("SUITE=PASS\n", encoding="utf-8")
            resource_log.write_text("min_memavailable_kb=900000 min_swapfree_kb=200000\n", encoding="utf-8")
            report = {"tests": {"preview_contract": {
                "status": "VERIFIED", "output_path": str(output),
                "output_sha256": PACKAGER.sha256(output), "resource_log": str(resource_log),
                "resource_log_sha256": PACKAGER.sha256(resource_log),
            }}}
            report_path.write_text(json.dumps(report), encoding="utf-8")
            bundled = PACKAGER.bundle_doctor_evidence(report_path, snapshot)
            packaged = json.loads(report_path.read_text(encoding="utf-8"))["tests"]["preview_contract"]
            self.assertEqual("evidence/preview_contract.stdout.txt", packaged["output_path"])
            self.assertEqual("evidence/preview_contract.resource.log", packaged["resource_log"])
            self.assertEqual(2, len(bundled))
            self.assertEqual(packaged["output_sha256"], PACKAGER.sha256(snapshot / packaged["output_path"]))
            self.assertEqual(packaged["resource_log_sha256"], PACKAGER.sha256(snapshot / packaged["resource_log"]))
            self.assertTrue(all(item["path"].startswith("source-snapshot/evidence/") for item in bundled))


if __name__ == "__main__":
    unittest.main(verbosity=2)
