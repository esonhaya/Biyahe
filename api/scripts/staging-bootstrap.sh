#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../deploy/staging" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
COMPOSE_FILE="$SCRIPT_DIR/compose.yaml"
ENV_FILE="${BIYAHE_STAGING_ENV_FILE:-$SCRIPT_DIR/.env}"
MODE="${1:---preflight}"

usage() {
    cat <<'EOF'
Usage: staging-bootstrap.sh MODE [ARGUMENTS]
  --preflight                         Check host/config; no services or DB.
  --dry-run                           Print the proposed order; no changes.
  --start --confirm-staging-start     Start isolated Compose services.
  --inspect-db                        Read-only DB/PostGIS inspection.
  --initialize-db --confirm-database-initialization
                                      Create PostGIS extension and app role.
  --apply-migrations --confirm-staging-migrations
                                      Apply reviewed forward migrations.
  --dry-run-import DATASET            Validate and dry-run one pinned dataset.
  --import DATASET --confirm-dataset SHA256
                                      Validate, dry-run, then explicitly import.
  --restore-backup --confirm-staging-restore SHA256
                                      Restore only the checksum-pinned staging backup.
  --shutdown --confirm-staging-shutdown
                                      Stop services; preserve the DB volume.
Use only on an isolated external Linux staging host. No mode targets production.
EOF
}

verify_backup() {
    backup_file="$(dotenv BIYAHE_STAGING_BACKUP_FILE)"
    backup_sha="$(dotenv BIYAHE_STAGING_BACKUP_SHA256)"
    [[ -n "$backup_file" && "$backup_sha" =~ ^[a-f0-9]{64}$ && -f "$backup_file" && ! -L "$backup_file" ]] || { echo 'FAIL=Set a regular staging backup file and its lowercase SHA256.' >&2; return 1; }
    [[ "$(sha256sum "$backup_file" | cut -d ' ' -f 1)" == "$backup_sha" ]] || { echo 'FAIL=Staging backup checksum mismatch.' >&2; return 1; }
    command -v pg_restore >/dev/null 2>&1 && pg_restore --list "$backup_file" >/dev/null || { echo 'FAIL=Staging backup is not a readable PostgreSQL custom-format dump.' >&2; return 1; }
}

if [[ "$MODE" == --help || "$MODE" == -h ]]; then usage; exit 0; fi
if [[ -n "${TERMUX_VERSION:-}" || "${PREFIX:-}" == *"/com.termux/"* ]]; then
    echo 'BLOCKED_DEPENDENCY=This deployment script is only for an external Linux host.' >&2
    exit 3
fi
if [[ "$(uname -s)" != Linux ]]; then echo 'BLOCKED_DEPENDENCY=Linux host required.' >&2; exit 3; fi
host_arch="$(uname -m)"
if [[ "$host_arch" != x86_64 && "$host_arch" != amd64 ]]; then
    echo "BLOCKED_DEPENDENCY=The pinned PostGIS staging image currently requires x86_64; host architecture is $host_arch." >&2
    exit 3
fi
if [[ ! -f "$ENV_FILE" ]]; then echo "BLOCKED_DEPENDENCY=Missing staging env file: $ENV_FILE" >&2; exit 3; fi
for tool in python3 stat; do
    command -v "$tool" >/dev/null 2>&1 || { echo "BLOCKED_DEPENDENCY=Missing host tool: $tool" >&2; exit 3; }
done

# Parse the restricted KEY=value file without evaluating shell code.
dotenv() {
    python3 - "$ENV_FILE" "$1" <<'PY'
import re, sys
from pathlib import Path
path, requested = Path(sys.argv[1]), sys.argv[2]
found = None
for number, raw in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
    line = raw.strip()
    if not line or line.startswith("#"):
        continue
    match = re.fullmatch(r"([A-Z][A-Z0-9_]*)=(.*)", line)
    if not match:
        raise SystemExit(f"Invalid .env syntax on line {number}; expected KEY=value")
    key, value = match.groups()
    if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
        value = value[1:-1]
    if "\n" in value or "\r" in value:
        raise SystemExit(f"Multiline .env value is not supported: {key}")
    if key == requested:
        found = value
if found is None:
    raise SystemExit(f"Missing required staging variable: {requested}")
print(found)
PY
}

APP_ENV_VALUE="$(dotenv APP_ENV)"
APP_DEBUG_VALUE="$(dotenv APP_DEBUG)"
DB_CONNECTION_VALUE="$(dotenv DB_CONNECTION)"
WALKING_VALUE="$(dotenv BIYAHE_OFFLINE_WALKING_PREVIEW)"
if [[ "$APP_ENV_VALUE" != staging || "$APP_DEBUG_VALUE" != false || "$DB_CONNECTION_VALUE" != pgsql || "$WALKING_VALUE" == true ]]; then
    echo 'FAIL=Expected staging environment, debug off, PostgreSQL, and walking preview disabled.' >&2
    exit 1
fi

for variable in APP_KEY_SECRET_FILE DB_PASSWORD_SECRET_FILE DB_ADMIN_PASSWORD_SECRET_FILE; do
    secret="$(dotenv "$variable")"
    secret_path="$SCRIPT_DIR/$secret"
    [[ "$secret" = /* ]] && secret_path="$secret"
    if [[ ! -f "$secret_path" || -L "$secret_path" || ! -s "$secret_path" ]]; then
        echo "BLOCKED_DEPENDENCY=Secret file for $variable is missing, symlinked, or empty." >&2
        exit 3
    fi
    mode_bits="$(stat -c '%a' "$secret_path")"
    if (( (8#$mode_bits & 077) != 0 )); then
        echo "FAIL=Secret file for $variable must not be group/world accessible." >&2
        exit 1
    fi
done

allowed_hosts="$(dotenv STAGING_ALLOWED_HOSTS)"
if [[ ! "$allowed_hosts" =~ ^[A-Za-z0-9._-]+([[:space:]]+[A-Za-z0-9._-]+)*$ ]]; then
    echo 'FAIL=STAGING_ALLOWED_HOSTS must be a space-separated host allowlist.' >&2
    exit 1
fi
app_url="$(dotenv APP_URL)"
python3 - "$app_url" "$allowed_hosts" <<'PY'
from urllib.parse import urlsplit
import sys
value, allowed_text = sys.argv[1:]
try:
    parsed = urlsplit(value)
    valid = parsed.scheme in {"http", "https"} and parsed.hostname is not None and not parsed.username and not parsed.password and parsed.path in {"", "/"} and not parsed.query and not parsed.fragment
    if valid:
        valid = parsed.hostname.lower().rstrip(".") in {item.lower() for item in allowed_text.split()}
except ValueError:
    valid = False
if not valid:
    raise SystemExit("FAIL=APP_URL must be an HTTP(S) origin on the staging host allowlist.")
PY
browser_api_url="$(dotenv NEXT_PUBLIC_API_URL)"
if [[ "$browser_api_url" != /api/v1 || "$browser_api_url" == *://* ]]; then
    echo 'FAIL=NEXT_PUBLIC_API_URL must remain the same-origin /api/v1 path.' >&2
    exit 1
fi
for target in "$(dotenv APP_URL)" "$(dotenv CORS_ALLOWED_ORIGINS)"; do
    if [[ "${target,,}" == *biyahe.ph* ]]; then
        echo 'FAIL=Production Biyahe domains are not valid staging targets.' >&2
        exit 1
    fi
done

for tool in docker python3 sha256sum; do
    command -v "$tool" >/dev/null 2>&1 || { echo "BLOCKED_DEPENDENCY=Missing tool: $tool" >&2; exit 3; }
done
docker compose version >/dev/null 2>&1 || { echo 'BLOCKED_DEPENDENCY=Docker Compose v2 is required.' >&2; exit 3; }

cd "$SCRIPT_DIR"
compose=(docker compose --project-directory "$SCRIPT_DIR" --env-file "$ENV_FILE" -f "$COMPOSE_FILE")
"${compose[@]}" config --quiet
printf 'STAGING_CONFIG=PASS\nDATABASE_CONNECTION_ATTEMPTED=NO\nSERVICE_START_ATTEMPTED=NO\n'

case "$MODE" in
    --preflight)
        echo 'STAGING_PREFLIGHT=PASS'
        ;;
    --dry-run)
        cat <<'EOF'
DRY_RUN_ORDER:
1. Start isolated services with --start --confirm-staging-start.
2. Create PostGIS and the application role with --initialize-db --confirm-database-initialization.
3. Apply reviewed migrations with --apply-migrations --confirm-staging-migrations.
4. Validate and dry-run each pinned dataset; import only with its SHA256 confirmation.
5. Run API smoke, frontend, and browser checks against the staging origin.
DATABASE_MUTATION=NO
SERVICES_STARTED=NO
EOF
        ;;
    --start)
        [[ "${2:-}" == --confirm-staging-start ]] || { echo 'FAIL=Pass --confirm-staging-start to initialize/start staging services.' >&2; exit 2; }
        "${compose[@]}" up -d --build
        echo 'STAGING_SERVICES=STARTED'
        ;;
    --inspect-db)
        admin_user="$(dotenv DB_ADMIN_USERNAME)"
        database="$(dotenv DB_DATABASE)"
        app_user="$(dotenv DB_USERNAME)"
        [[ "$database" =~ ^[A-Za-z_][A-Za-z0-9_]*$ && "$app_user" =~ ^[A-Za-z_][A-Za-z0-9_]*$ && "$admin_user" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || { echo 'FAIL=Database and role names must use simple SQL identifiers.' >&2; exit 1; }
        "${compose[@]}" exec -T db psql -U "$admin_user" -d "$database" -X -v ON_ERROR_STOP=1 -Atc \
            "select json_build_object('database', current_database(), 'postgis', postgis_version(), 'app_role_present', exists(select 1 from pg_roles where rolname = '$app_user'), 'table_estimates', coalesce((select json_agg(json_build_object('table', schemaname||'.'||relname, 'estimated_rows', n_live_tup) order by schemaname, relname) from pg_stat_user_tables), '[]'::json))::text;"
        echo 'DATABASE_INSPECTION=READ_ONLY_PASS'
        ;;
    --initialize-db)
        [[ "${2:-}" == --confirm-database-initialization ]] || { echo 'FAIL=Explicit database initialization confirmation required.' >&2; exit 2; }
        database="$(dotenv DB_DATABASE)"
        app_user="$(dotenv DB_USERNAME)"
        admin_user="$(dotenv DB_ADMIN_USERNAME)"
        [[ "$database" =~ ^[A-Za-z_][A-Za-z0-9_]*$ && "$app_user" =~ ^[A-Za-z_][A-Za-z0-9_]*$ && "$admin_user" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || { echo 'FAIL=Database and role names must use simple SQL identifiers.' >&2; exit 1; }
        secret="$(dotenv DB_PASSWORD_SECRET_FILE)"
        secret_path="$SCRIPT_DIR/$secret"
        [[ "$secret" = /* ]] && secret_path="$secret"
        sql_file="$(mktemp "$SCRIPT_DIR/.initialize-db.XXXXXX.sql")"
        chmod 600 "$sql_file"
        trap 'rm -f "$sql_file"' EXIT
        python3 "$REPO_ROOT/api/scripts/staging-db-init-sql.py" \
            --database "$database" --role "$app_user" --password-file "$secret_path" > "$sql_file"
        "${compose[@]}" exec -T db psql -U "$admin_user" -d "$database" -X -v ON_ERROR_STOP=1 < "$sql_file"
        rm -f "$sql_file"
        trap - EXIT
        echo 'DATABASE_INITIALIZATION=PASS'
        ;;
    --apply-migrations)
        [[ "${2:-}" == --confirm-staging-migrations ]] || { echo 'FAIL=Explicit staging migration confirmation required.' >&2; exit 2; }
        verify_backup || exit 1
        "${compose[@]}" exec -T api php artisan migrate --force --no-interaction
        echo 'MIGRATIONS=APPLIED'
        ;;
    --dry-run-import|--import)
        dataset="${2:-}"
        case "$dataset" in
            cebu-central-network-2026-10-05.json) expected=edb91e22f3a55b46f20a1faf3823d4bda0d2e48eb5d9ee4b06b1c724e3e9e6fe ;;
            cebu-network-expansion-2026-10-05.json) expected=0b6ce82304689b6472d2835b2ef4b3da7b397560db846ba1f7cf1aa4866920d7 ;;
            *) echo 'FAIL=Choose one of the two pinned reviewed datasets.' >&2; exit 2 ;;
        esac
        actual="$(sha256sum "$REPO_ROOT/api/database/data/$dataset" | cut -d ' ' -f 1)"
        [[ "$actual" == "$expected" ]] || { echo 'FAIL=Dataset fingerprint differs from reviewed input.' >&2; exit 1; }
        "${compose[@]}" exec -T api php artisan biyahe:data:validate "$dataset"
        "${compose[@]}" exec -T api php artisan biyahe:data:import "$dataset" --dry-run
        if [[ "$MODE" == --import ]]; then
            [[ "${3:-}" == --confirm-dataset && "${4:-}" == "$expected" ]] || { echo 'FAIL=Pass --confirm-dataset followed by the pinned SHA256.' >&2; exit 2; }
            verify_backup || exit 1
            "${compose[@]}" exec -T api php artisan biyahe:data:import "$dataset"
            echo 'DATASET_IMPORT=APPLIED_WITH_APPROVED_SHA256'
        else
            echo 'DATASET_IMPORT=DRY_RUN_ONLY'
        fi
        ;;
    --restore-backup)
        verify_backup || exit 1
        [[ "${2:-}" == --confirm-staging-restore && "${3:-}" == "$backup_sha" ]] || { echo 'FAIL=Pass --confirm-staging-restore followed by the configured backup SHA256.' >&2; exit 2; }
        admin_user="$(dotenv DB_ADMIN_USERNAME)"
        database="$(dotenv DB_DATABASE)"
        [[ "$database" =~ ^[A-Za-z_][A-Za-z0-9_]*$ && "$admin_user" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || { echo 'FAIL=Database and role names must use simple SQL identifiers.' >&2; exit 1; }
        "${compose[@]}" stop api web
        "${compose[@]}" exec -T db pg_restore --single-transaction --exit-on-error --clean --if-exists --no-owner --no-privileges -U "$admin_user" -d "$database" < "$backup_file"
        echo 'STAGING_BACKUP_RESTORED=PASS API_AND_WEB=STOPPED REVIEW_BEFORE_RESTART=YES'
        ;;
    --shutdown)
        [[ "${2:-}" == --confirm-staging-shutdown ]] || { echo 'FAIL=Pass --confirm-staging-shutdown.' >&2; exit 2; }
        "${compose[@]}" down
        echo 'STAGING_SERVICES=STOPPED_DATABASE_VOLUME_PRESERVED'
        ;;
    *) usage >&2; exit 2 ;;
esac
