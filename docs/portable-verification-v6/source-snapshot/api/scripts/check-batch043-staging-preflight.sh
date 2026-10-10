#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat <<'EOF'
Usage: check-batch043-staging-preflight.sh --preflight|--dry-run

Checks an already provisioned external Linux staging host without opening a
database connection, installing packages, starting services, or changing files.
--dry-run performs the same checks and prints the documented command order.
EOF
}

mode="${1:-}"
if [[ "$mode" == "--help" || "$mode" == "-h" ]]; then
    usage
    exit 0
fi
if [[ "$mode" != "--preflight" && "$mode" != "--dry-run" ]]; then
    usage >&2
    exit 2
fi

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
failures=0

check_tool() {
    if command -v "$1" >/dev/null 2>&1; then
        printf 'TOOL_%s=PASS\n' "$(printf '%s' "$1" | tr '[:lower:]-' '[:upper:]_')"
    else
        printf 'TOOL_%s=BLOCKED_DEPENDENCY\n' "$(printf '%s' "$1" | tr '[:lower:]-' '[:upper:]_')"
        failures=$((failures + 1))
    fi
}

require_environment() {
    local name="$1"
    if [[ -n "${!name:-}" ]]; then
        printf 'ENV_%s=PRESENT_VALUE_REDACTED\n' "$name"
    else
        printf 'ENV_%s=MISSING\n' "$name"
        failures=$((failures + 1))
    fi
}

if [[ "$(id -u)" == "0" ]]; then
    printf 'ACCOUNT=BLOCKED_ROOT\n'
    failures=$((failures + 1))
else
    printf 'ACCOUNT=PASS_NON_ROOT\n'
fi

for tool in php composer node npm git curl psql pg_isready pg_dump pg_restore sha256sum; do
    check_tool "$tool"
done

for relative in api/artisan api/composer.lock web/package-lock.json \
    api/database/data/cebu-central-network-2026-10-05.json \
    api/database/data/cebu-network-expansion-2026-10-05.json; do
    if [[ -f "$repo_root/$relative" ]]; then
        printf 'CHECKOUT_%s=PASS\n' "$relative"
    else
        printf 'CHECKOUT_%s=BLOCKED_DEPENDENCY\n' "$relative"
        failures=$((failures + 1))
    fi
done

if command -v php >/dev/null 2>&1; then
    if php -r 'exit(PHP_VERSION_ID >= 80300 && extension_loaded("pdo_pgsql") && extension_loaded("pgsql") && extension_loaded("mbstring") && extension_loaded("xml") && extension_loaded("ctype") && extension_loaded("iconv") && extension_loaded("intl") ? 0 : 1);'; then
        printf 'PHP_RUNTIME=PASS_PHP_83_AND_REQUIRED_EXTENSIONS\n'
    else
        printf 'PHP_RUNTIME=BLOCKED_DEPENDENCY_PHP_83_OR_REQUIRED_EXTENSION\n'
        failures=$((failures + 1))
    fi
fi

if command -v node >/dev/null 2>&1; then
    if node -e 'const [major, minor] = process.versions.node.split(".").map(Number); process.exit(major > 20 || (major === 20 && minor >= 9) ? 0 : 1);'; then
        printf 'NODE_RUNTIME=PASS_NODE_20_9_OR_NEWER\n'
    else
        printf 'NODE_RUNTIME=BLOCKED_DEPENDENCY_NODE_20_9_OR_NEWER\n'
        failures=$((failures + 1))
    fi
fi

for name in APP_ENV APP_DEBUG APP_KEY DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME; do
    require_environment "$name"
done
if [[ "${APP_ENV:-}" != "staging" || "${APP_DEBUG:-}" != "false" || "${DB_CONNECTION:-}" != "pgsql" ]]; then
    printf 'APPLICATION_CONFIGURATION=BLOCKED_DEPENDENCY_EXPECTED_STAGING_DEBUG_FALSE_PGSQL\n'
    failures=$((failures + 1))
else
    printf 'APPLICATION_CONFIGURATION=PASS_STAGING_DEBUG_FALSE_PGSQL\n'
fi
if [[ "${BIYAHE_OFFLINE_WALKING_PREVIEW:-false}" == "true" ]]; then
    printf 'DEVELOPMENT_WALKING_PREVIEW=BLOCKED_UNSAFE_ENABLED\n'
    failures=$((failures + 1))
else
    printf 'DEVELOPMENT_WALKING_PREVIEW=PASS_DISABLED\n'
fi
if [[ "${DB_PORT:-}" =~ ^[0-9]{1,5}$ ]] && (( 10#${DB_PORT} >= 1 && 10#${DB_PORT} <= 65535 )); then
    printf 'DB_PORT=PASS_VALUE_REDACTED\n'
else
    printf 'DB_PORT=BLOCKED_INVALID_OR_MISSING\n'
    failures=$((failures + 1))
fi

printf 'DATABASE_CONNECTION_ATTEMPTED=NO\n'
printf 'SERVICE_START_ATTEMPTED=NO\n'
printf 'FILES_CHANGED=NO\n'
if ((failures > 0)); then
    printf 'STAGING_PREFLIGHT=BLOCKED_DEPENDENCY failures=%s\n' "$failures"
    exit 1
fi
printf 'STAGING_PREFLIGHT=PASS\n'

if [[ "$mode" == "--dry-run" ]]; then
    cat <<'EOF'
DRY_RUN_ACTION_ORDER:
1. Record the release and approved dataset checksums.
2. Take and verify a PostgreSQL custom-format backup; inspect it with pg_restore --list.
3. Check migration status, then apply reviewed forward migrations with php artisan migrate --force --no-interaction.
4. Validate each reviewed JSON dataset, then run its importer once with --dry-run.
5. Import only reviewed datasets after an operator approves the staging change.
6. Check GET /up, /api/v1/health, and /api/v1/ready.
7. In web/, run npm ci, npm run typecheck, npm run lint, and npm run build.
8. Run the external browser plan at 320, 360, and 390 CSS pixels.
DRY_RUN_NO_DATABASE_OR_FILE_CHANGES=YES
EOF
fi
