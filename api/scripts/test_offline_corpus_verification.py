#!/usr/bin/env python3
"""Low-resource contract tests for guarded, bounded Cebu corpus execution."""

from __future__ import annotations

import importlib.util
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).with_name("run-offline-corpus-verification.py")
SPEC = importlib.util.spec_from_file_location("offline_corpus_verification", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
VERIFIER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(VERIFIER)


def valid_guard_environment() -> dict[str, str]:
    return {
        "BIYAHE_RESOURCE_GUARD_PREFLIGHT": "PASS",
        "BIYAHE_RESOURCE_GUARD_PREFLIGHT_SAMPLES": "5",
        "BIYAHE_RESOURCE_GUARD_PREFLIGHT_SPACING_SECONDS": "5",
        "BIYAHE_RESOURCE_GUARD_MIN_MEMAVAILABLE_KB": "600000",
        "BIYAHE_RESOURCE_GUARD_MIN_SWAPFREE_KB": "140000",
        "BIYAHE_RESOURCE_GUARD_CONFIGURED_MIN_MEMAVAILABLE_KB": "524288",
        "BIYAHE_RESOURCE_GUARD_CONFIGURED_MIN_SWAPFREE_KB": "131072",
        "BIYAHE_RESOURCE_GUARD_BREACH_SAMPLES": "1",
        "BIYAHE_RESOURCE_GUARD_MAX_PROCESS_TREE_RSS_KB": "262144",
    }


class OfflineCorpusVerificationTests(unittest.TestCase):
    def test_case_selection_is_sorted_and_rejects_duplicates_or_malformed_values(self):
        self.assertIsNone(VERIFIER.parse_case_indices(None))
        self.assertEqual([1, 4], VERIFIER.parse_case_indices("4,1"))
        for value in ("", "1,1", "10", "-1", "１"):
            with self.subTest(value=value), self.assertRaises(ValueError):
                VERIFIER.parse_case_indices(value)

    def test_preflight_requires_five_samples_fixed_floors_and_first_breach(self):
        with patch.dict(os.environ, valid_guard_environment(), clear=True):
            self.assertEqual((600000, 140000), VERIFIER.require_guard_preflight())
        for variable, value in (
            ("BIYAHE_RESOURCE_GUARD_PREFLIGHT_SAMPLES", "4"),
            ("BIYAHE_RESOURCE_GUARD_MIN_SWAPFREE_KB", "130000"),
            ("BIYAHE_RESOURCE_GUARD_BREACH_SAMPLES", "2"),
        ):
            environment = valid_guard_environment()
            environment[variable] = value
            with self.subTest(variable=variable), patch.dict(os.environ, environment, clear=True):
                with self.assertRaises(RuntimeError):
                    VERIFIER.require_guard_preflight()

    def test_resource_gate_precedes_graph_identity_access(self):
        graph_path = Path("/unread/graph.sqlite")
        original_is_file = Path.is_file
        graph_metadata_reads = []

        def observe_is_file(path):
            if path == graph_path:
                graph_metadata_reads.append(path)
            return original_is_file(path)

        with patch.dict(os.environ, {}, clear=True), patch.object(VERIFIER, "sha256_file") as hash_graph, \
                patch.object(Path, "is_file", observe_is_file):
            result = VERIFIER.main([
                "--graph", str(graph_path),
                "--actual-output", "/unread/actual.json",
                "--comparison-output", "/unread/comparison.json",
            ])
        self.assertEqual(4, result)
        hash_graph.assert_not_called()
        self.assertEqual([], graph_metadata_reads, "graph metadata must remain untouched until guarded preflight passes")

    def test_no_clobber_output_is_rejected_before_graph_access(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            actual = root / "actual.json"
            actual.write_text("existing", encoding="utf-8")
            comparison = root / "comparison.json"
            graph = root / "missing.sqlite"
            with patch.dict(os.environ, valid_guard_environment(), clear=True), patch.object(VERIFIER, "sha256_file") as hash_graph:
                result = VERIFIER.main([
                    "--graph", str(graph),
                    "--actual-output", str(actual),
                    "--comparison-output", str(comparison),
                ])
            self.assertEqual(2, result)
            self.assertEqual("existing", actual.read_text(encoding="utf-8"))
            hash_graph.assert_not_called()


if __name__ == "__main__":
    unittest.main(verbosity=2)
