#!/usr/bin/env python3
"""Low-dependency safety checks for the checked-in staging deployment."""

from __future__ import annotations

from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parents[2]


def require(condition: bool, label: str) -> None:
    if not condition:
        raise ValueError(label)


def validate(root: Path = ROOT) -> list[str]:
    staging = root / "deploy/staging"
    compose = (staging / "compose.yaml").read_text(encoding="utf-8")
    environment = (staging / ".env.example").read_text(encoding="utf-8")
    gateway = (staging / "nginx.conf.template").read_text(encoding="utf-8")
    api_docker = (staging / "api.Dockerfile").read_text(encoding="utf-8")
    web_docker = (staging / "web.Dockerfile").read_text(encoding="utf-8")
    entrypoint = (staging / "api-entrypoint.sh").read_text(encoding="utf-8")
    cors = (root / "api/config/cors.php").read_text(encoding="utf-8")
    bootstrap = (root / "api/scripts/staging-bootstrap.sh").read_text(encoding="utf-8")
    db_init_sql = (root / "api/scripts/staging-db-init-sql.py").read_text(encoding="utf-8")
    browser_config = (root / "web/playwright.config.ts").read_text(encoding="utf-8")
    checks = [
        ("pinned-runtime-tags", "php:8.3.35-apache-bookworm" in api_docker and "node:24.21.0-bookworm-slim" in web_docker and "postgis/postgis:16-3.5" in compose and "nginx:1.31.6-alpine3.24" in compose),
        ("composer-runtime-extensions", all(f" {extension}" in api_docker for extension in ("dom", "intl", "mbstring", "pdo_pgsql", "pgsql", "xml", "xmlreader", "xmlwriter", "zip", "opcache"))),
        ("no-database-host-port", not re.search(r"(?ms)^  db:\n(?:(?!^  [a-z]).)*?^\s+ports:", compose)),
        ("loopback-only-gateway", '"127.0.0.1:${STAGING_HTTP_PORT:-8080}:8080"' in compose and compose.count("\n    ports:") == 1),
        ("persistent-database-volume", "postgres-data:/var/lib/postgresql/data" in compose and "postgres-data:" in compose),
        ("bounded-services", compose.count("mem_limit:") == 4 and compose.count("pids_limit:") == 4 and compose.count("cpus:") == 4),
        ("secret-file-mounts", all(key in compose and key in environment for key in ("APP_KEY_SECRET_FILE", "DB_PASSWORD_SECRET_FILE", "DB_ADMIN_PASSWORD_SECRET_FILE"))),
        ("walking-preview-disabled", "BIYAHE_OFFLINE_WALKING_PREVIEW=false" in environment and "must remain disabled" in entrypoint),
        ("secret-files-copied-to-private-runtime-tmpfs", "/run/biyahe-secrets:rw,noexec,nosuid,size=1m" in compose and "install -o www-data -g www-data -m 0400" in entrypoint and "gosu www-data" in entrypoint and "USER www-data" not in api_docker and 'secret contents are empty' in entrypoint),
        ("cors-does-not-allow-credentials", "supports_credentials' => false" in cors),
        ("host-allowlist-and-deny-default", "server_name _;\n    return 444;" in gateway and "server_name ${STAGING_ALLOWED_HOSTS};" in gateway),
        ("browser-checks-loopback-only", "loopbackHost" in browser_config and "Browser checks require a loopback staging origin" in browser_config),
        ("same-origin-browser-api", "NEXT_PUBLIC_API_URL=/api/v1" in environment),
        ("same-origin-api-build-argument", "NEXT_PUBLIC_API_URL: ${NEXT_PUBLIC_API_URL:-/api/v1}" in compose),
        ("web-build-arguments-are-nested", "      args:\n        NEXT_PUBLIC_API_URL:" in compose and "\n        BIYAHE_API_INTERNAL_URL:" in compose),
        ("gateway-health-host-is-allowlisted", "Host: localhost" in compose and "STAGING_ALLOWED_HOSTS=localhost 127.0.0.1" in environment),
        ("no-wildcard-host-allowlist", not re.search(r"(?m)^STAGING_ALLOWED_HOSTS=.*\*", environment)),
        ("internal-network", "internal: true" in compose),
        ("no-credential-values-in-template", not re.search(r"(?im)^(?:APP_KEY|DB_PASSWORD|POSTGRES_PASSWORD)=\S+", environment)),
        ("staging-debug-and-database-values", "APP_ENV=staging" in environment and "APP_DEBUG=false" in environment and "DB_CONNECTION=pgsql" in environment),
        ("secrets-excluded-from-build-context", "**/.env" in (root / ".dockerignore").read_text(encoding="utf-8") and "**/secrets" in (root / ".dockerignore").read_text(encoding="utf-8")),
        ("no-startup-migrations", "artisan migrate" not in api_docker and "artisan migrate" not in entrypoint),
        ("preflight-default-is-read-only", 'MODE="${1:---preflight}"' in bootstrap and "--preflight)" in bootstrap and "DATABASE_CONNECTION_ATTEMPTED=NO" in bootstrap),
        ("staging-host-architecture-is-checked", 'host_arch="$(uname -m)"' in bootstrap and "pinned PostGIS staging image currently requires x86_64" in bootstrap),
        ("mutations-require-explicit-confirmations", all(flag in bootstrap for flag in ("--confirm-staging-start", "--confirm-database-initialization", "--confirm-staging-migrations", "--confirm-dataset", "--confirm-staging-restore"))),
        ("database-bootstrap-uses-tested-sql-renderer", "staging-db-init-sql.py" in bootstrap and "GRANT CONNECT ON DATABASE {database_identifier}" in db_init_sql),
        ("restore-requires-pinned-atomic-backup", "--single-transaction" in bootstrap and "--exit-on-error" in bootstrap and "pg_restore --list" in bootstrap and "STAGING_BACKUP_SHA256" in bootstrap),
        ("no-volume-deletion-or-production-domain", "down -v" not in bootstrap and "biyahe.ph" in bootstrap),
        ("importer-hashes-pinned", all((ROOT / "api/database/data" / name).is_file() for name in (
            "cebu-central-network-2026-10-05.json", "cebu-network-expansion-2026-10-05.json"))),
    ]
    failures = [label for label, passed in checks if not passed]
    if failures:
        raise ValueError(",".join(failures))
    return [label for label, _ in checks]


def main() -> int:
    try:
        for check in validate():
            print(f"{check}=PASS")
        print("STAGING_CONFIG_VALIDATION=PASS")
        return 0
    except (OSError, ValueError) as error:
        print(f"STAGING_CONFIG_VALIDATION=FAIL:{error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
