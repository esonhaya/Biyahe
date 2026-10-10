from __future__ import annotations

import importlib.util
from pathlib import Path
import tempfile
import unittest

SCRIPT = Path(__file__).with_name("staging-db-init-sql.py")
SPEC = importlib.util.spec_from_file_location("batch044_staging_db_init_sql", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
SQL = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(SQL)


class StagingDatabaseInitializationSqlTests(unittest.TestCase):
    def test_sql_uses_database_identifier_and_idempotent_role_creation(self):
        statement = SQL.initialization_sql("biyahe_staging", "biyahe_app", "safe-password")
        self.assertIn('GRANT CONNECT ON DATABASE "biyahe_staging" TO "biyahe_app";', statement)
        self.assertIn("CREATE EXTENSION IF NOT EXISTS postgis;", statement)
        self.assertIn("IF NOT EXISTS (SELECT 1 FROM pg_roles", statement)
        self.assertIn('GRANT USAGE, CREATE ON SCHEMA public TO "biyahe_app";', statement)

    def test_password_literal_is_escaped_and_line_ending_is_trimmed(self):
        with tempfile.TemporaryDirectory() as temporary:
            path = Path(temporary) / "password"
            path.write_text("pa'ssword\n", encoding="utf-8")
            password = path.read_text(encoding="utf-8").rstrip("\r\n")
            statement = SQL.initialization_sql("biyahe_staging", "biyahe_app", password)
        self.assertIn("'pa''ssword'", statement)
        self.assertNotIn("pa'ssword\n", statement)

    def test_database_and_role_names_reject_sql_fragments(self):
        for database, role in (("db; DROP DATABASE x", "biyahe_app"), ("biyahe_staging", "role\"; --")):
            with self.subTest(database=database, role=role), self.assertRaises(ValueError):
                SQL.initialization_sql(database, role, "secret")

    def test_empty_password_is_rejected(self):
        with self.assertRaisesRegex(ValueError, "password is empty"):
            SQL.initialization_sql("biyahe_staging", "biyahe_app", "")
        with self.assertRaisesRegex(ValueError, "NUL byte"):
            SQL.initialization_sql("biyahe_staging", "biyahe_app", "a\x00b")


if __name__ == "__main__":
    unittest.main()
