from __future__ import annotations

import importlib.util
from pathlib import Path
import tempfile
import unittest

SCRIPT = Path(__file__).with_name("staging-api-smoke.py")
SPEC = importlib.util.spec_from_file_location("batch044_staging_smoke", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
SMOKE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(SMOKE)


class StagingSmokeContractTests(unittest.TestCase):
    def test_smoke_never_fabricates_valid_journey_inputs(self):
        def request(url, method="GET", timeout=10.0):
            return 200, {"data": [], "meta": {}}, "a" * 64

        smoke = SMOKE.Smoke("http://127.0.0.1:8080", requester=request)
        results = smoke.run("Cebu", None, None, None)
        by_name = {row["name"]: row for row in results}
        self.assertEqual("NOT_RUN", by_name["direct-journey-contract"]["status"])
        self.assertEqual("NOT_RUN", by_name["transfer-journey-contract"]["status"])
        self.assertEqual("NOT_RUN", by_name["can-i-ride-contract"]["status"])

    def test_sensitive_fields_are_redacted_recursively(self):
        self.assertEqual({"email": "[REDACTED]", "place": {"lat": "[REDACTED]", "name": "Public Place"}},
                         SMOKE.redact({"email": "person@example.test", "place": {"lat": 10, "name": "Public Place"}}))

    def test_non_json_response_evidence_never_stores_response_text(self):
        from unittest.mock import patch

        class Response:
            status = 502

            def __enter__(self):
                return self

            def __exit__(self, *args):
                return False

            def read(self, limit):
                return b"private-token=should-never-be-recorded"

        with patch.object(SMOKE, "build_opener", return_value=type("Opener", (), {"open": lambda self, *args, **kwargs: Response()})()):
            status, body, digest = SMOKE.request_json("http://127.0.0.1/health")
        self.assertEqual(502, status)
        self.assertNotIn("private-token", repr(body))
        self.assertEqual("non_json", body["body_format"])
        self.assertEqual(64, len(digest))

    def test_evidence_output_is_atomic_and_no_clobber(self):
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / "smoke.json"
            SMOKE.atomic_no_clobber(output, {"status": "PASS"})
            original = output.read_bytes()
            with self.assertRaises(FileExistsError):
                SMOKE.atomic_no_clobber(output, {"status": "FAIL"})
            self.assertEqual(original, output.read_bytes())

    def test_credentials_and_non_http_urls_are_rejected(self):
        for url in ("ftp://host", "https://user:password@example.test", "https://host/path?q=secret", "https://api.biyahe.ph"):
            with self.subTest(url=url), self.assertRaises(ValueError):
                SMOKE.Smoke(url)

    def test_non_loopback_smoke_requires_explicit_staging_confirmation(self):
        with self.assertRaisesRegex(ValueError, "confirm-isolated-staging"):
            SMOKE.Smoke("https://stage.example.test")
        self.assertEqual("https://stage.example.test", SMOKE.Smoke("https://stage.example.test", allow_external=True).origin)


if __name__ == "__main__":
    unittest.main()
