#!/usr/bin/env python3
"""Render idempotent SQL for explicit staging database initialization."""

from __future__ import annotations

import argparse
from pathlib import Path
import re
import sys


IDENTIFIER = re.compile(r"[A-Za-z_][A-Za-z0-9_]*\Z", re.ASCII)


def sql_literal(value: str) -> str:
    return "'" + value.replace("'", "''") + "'"


def sql_identifier(value: str) -> str:
    if IDENTIFIER.fullmatch(value) is None:
        raise ValueError("database and role names must use simple SQL identifiers")
    return '"' + value.replace('"', '""') + '"'


def initialization_sql(database: str, role: str, password: str) -> str:
    database_identifier = sql_identifier(database)
    role_identifier = sql_identifier(role)
    if not password or "\x00" in password:
        raise ValueError("application database password is empty or contains a NUL byte")
    role_literal = sql_literal(role)
    password_literal = sql_literal(password)
    return (
        "CREATE EXTENSION IF NOT EXISTS postgis;\n"
        "DO $bootstrap$ BEGIN\n"
        f" IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = {role_literal}) THEN\n"
        f"  EXECUTE format('CREATE ROLE %I LOGIN PASSWORD %L', {role_literal}, {password_literal});\n"
        " END IF;\n"
        "END $bootstrap$;\n"
        f"GRANT CONNECT ON DATABASE {database_identifier} TO {role_identifier};\n"
        f"GRANT USAGE, CREATE ON SCHEMA public TO {role_identifier};\n"
    )


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--database", required=True)
    parser.add_argument("--role", required=True)
    parser.add_argument("--password-file", required=True, type=Path)
    args = parser.parse_args(argv)
    try:
        password = args.password_file.read_text(encoding="utf-8").rstrip("\r\n")
        sys.stdout.write(initialization_sql(args.database, args.role, password))
        return 0
    except (OSError, UnicodeError, ValueError) as error:
        print(f"STAGING_DB_INIT_SQL=FAIL:{type(error).__name__}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
