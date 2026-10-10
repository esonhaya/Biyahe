from __future__ import annotations

import importlib.util
from pathlib import Path
import tempfile
import unittest
import shutil

SCRIPT = Path(__file__).with_name("validate-staging-config.py")
SPEC = importlib.util.spec_from_file_location("batch044_staging_config", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
CONFIG = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(CONFIG)


class StagingConfigurationTests(unittest.TestCase):
    def test_checked_in_staging_stack_satisfies_safety_contract(self):
        checks = CONFIG.validate()
        self.assertIn("loopback-only-gateway", checks)
        self.assertIn("walking-preview-disabled", checks)
        self.assertIn("no-startup-migrations", checks)
        self.assertIn("secret-files-copied-to-private-runtime-tmpfs", checks)
        self.assertIn("database-bootstrap-uses-tested-sql-renderer", checks)
        self.assertIn("web-build-arguments-are-nested", checks)
        self.assertIn("staging-host-architecture-is-checked", checks)

    def test_staging_contract_rejects_public_api_port_and_enabled_walking_preview(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            shutil.copytree(CONFIG.ROOT / "deploy", root / "deploy")
            (root / "api/config").mkdir(parents=True)
            (root / "api/scripts").mkdir(parents=True)
            shutil.copyfile(CONFIG.ROOT / "api/config/cors.php", root / "api/config/cors.php")
            shutil.copyfile(CONFIG.ROOT / "api/scripts/staging-bootstrap.sh", root / "api/scripts/staging-bootstrap.sh")
            shutil.copyfile(CONFIG.ROOT / "api/scripts/staging-db-init-sql.py", root / "api/scripts/staging-db-init-sql.py")
            (root / "web").mkdir()
            shutil.copyfile(CONFIG.ROOT / "web/playwright.config.ts", root / "web/playwright.config.ts")
            shutil.copyfile(CONFIG.ROOT / ".dockerignore", root / ".dockerignore")
            compose = root / "deploy/staging/compose.yaml"
            compose.write_text(compose.read_text().replace('"127.0.0.1:${STAGING_HTTP_PORT:-8080}:8080"', '"0.0.0.0:8080:8080"'), encoding="utf-8")
            environment = root / "deploy/staging/.env.example"
            environment.write_text(environment.read_text().replace("BIYAHE_OFFLINE_WALKING_PREVIEW=false", "BIYAHE_OFFLINE_WALKING_PREVIEW=true"), encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "loopback-only-gateway,walking-preview-disabled"):
                CONFIG.validate(root)


if __name__ == "__main__":
    unittest.main()
