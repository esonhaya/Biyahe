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


class PortableRunnerTests(unittest.TestCase):
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
        specs = [{"id": "preview-state"}, {"id": "commute-card"}]
        self.assertEqual(RUNNER.select_specs(specs, "commute-card"), [specs[1]])
        self.assertEqual(RUNNER.select_specs(specs, None), specs)
        with self.assertRaisesRegex(ValueError, "not available in this mode"):
            RUNNER.select_specs(specs, "adapter-phpunit")


if __name__ == "__main__":
    unittest.main(verbosity=2)
