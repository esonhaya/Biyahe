#!/data/data/com.termux/files/usr/bin/sh
set -eu

usage() {
    printf 'Usage: %s VERIFIED_BACKUP EXPECTED_BACKUP_SHA256 NEW_JSON_OUTPUT\n' "$0" >&2
    printf 'Requires PGHOST=127.0.0.1, PGPORT, and an isolated staging/test PGDATABASE.\n' >&2
    exit 2
}

[ "$#" -eq 3 ] || usage
backup_path=$1
expected_backup_sha256=$(printf '%s' "$2" | tr '[:upper:]' '[:lower:]')
output_path=$3

case "$expected_backup_sha256" in
    *[!a-f0-9]*|'') usage ;;
esac
[ "${#expected_backup_sha256}" -eq 64 ] || usage
[ -f "$backup_path" ] && [ -r "$backup_path" ] || { printf 'Verified backup is not readable.\n' >&2; exit 1; }
[ "${PGHOST:-}" = '127.0.0.1' ] || { printf 'Export is restricted to a loopback PostgreSQL host.\n' >&2; exit 1; }
[ -n "${PGPORT:-}" ] && case "$PGPORT" in *[!0-9]*) exit 1 ;; esac
[ -n "${PGDATABASE:-}" ] || { printf 'PGDATABASE must name an isolated staging/test database.\n' >&2; exit 1; }
printf '%s' "$PGDATABASE" | grep -Eiq '(^|[_-])(staging|test|testing|sandbox|scratch)([_-]|$)' || {
    printf 'Refusing a database without an isolated staging/test name.\n' >&2
    exit 1
}
if printf '%s' "$PGDATABASE" | grep -Eiq '(^|[_-])(prod|production)([_-]|$)'; then
    printf 'Refusing a production-named database.\n' >&2
    exit 1
fi
command -v psql >/dev/null 2>&1 || { printf 'psql is required.\n' >&2; exit 1; }
command -v sha256sum >/dev/null 2>&1 || { printf 'sha256sum is required.\n' >&2; exit 1; }
command -v python3 >/dev/null 2>&1 || { printf 'python3 is required for output validation.\n' >&2; exit 1; }

actual_backup_sha256=$(sha256sum "$backup_path" | awk '{print tolower($1)}')
[ "$actual_backup_sha256" = "$expected_backup_sha256" ] || {
    printf 'Backup SHA-256 does not match the expected identity.\n' >&2
    exit 1
}
output_directory=$(dirname "$output_path")
[ -d "$output_directory" ] && [ -w "$output_directory" ] || { printf 'Output directory must already exist and be writable.\n' >&2; exit 1; }
building_path="$output_path.building"
[ ! -e "$output_path" ] && [ ! -e "$building_path" ] || { printf 'Output or its build path already exists.\n' >&2; exit 1; }

umask 077
if ! (set -C; : > "$building_path") 2>/dev/null; then
    printf 'Could not reserve output build file.\n' >&2
    exit 1
fi
trap 'rm -f "$building_path"' EXIT HUP INT TERM

query=$(cat <<'SQL'
SELECT jsonb_build_object(
    'backup_sha256', :'backup_sha256',
    'database_snapshot', 'restored isolated database ' || current_database(),
    'stops', COALESCE((
        SELECT jsonb_agg(jsonb_build_object(
            'id', stops.id,
            'lat', ST_Y(stops.location::geometry),
            'lng', ST_X(stops.location::geometry),
            'verification_state', stops.verification_state,
            'boarding_verification_state', stops.boarding_verification_state,
            'is_active', stops.is_active,
            'is_demo', stops.is_demo,
            'is_boarding_point', stops.is_boarding_point,
            'provenance_source_id', stops.provenance_source_id,
            'source_type', sources.source_type,
            'city_id', stops.city_id
        ) ORDER BY stops.id)
        FROM stops
        LEFT JOIN provenance_sources AS sources ON sources.id=stops.provenance_source_id
    ), '[]'::jsonb),
    'places', COALESCE((
        SELECT jsonb_agg(jsonb_build_object(
            'id', places.id,
            'lat', ST_Y(places.location::geometry),
            'lng', ST_X(places.location::geometry),
            'verification_state', places.verification_state,
            'is_active', places.is_active,
            'is_demo', places.is_demo,
            'provenance_source_id', places.provenance_source_id,
            'source_type', sources.source_type,
            'category', places.category,
            'city_id', places.city_id
        ) ORDER BY places.id)
        FROM places
        LEFT JOIN provenance_sources AS sources ON sources.id=places.provenance_source_id
    ), '[]'::jsonb),
    'transfers', COALESCE((
        SELECT jsonb_agg(jsonb_build_object(
            'id', transfers.id,
            'from_stop_id', transfers.from_stop_id,
            'to_stop_id', transfers.to_stop_id,
            'verification_state', transfers.verification_state,
            'is_active', transfers.is_active,
            'is_demo', transfers.is_demo,
            'provenance_source_id', transfers.provenance_source_id,
            'source_type', sources.source_type,
            'relationship_type', transfers.relationship_type,
            'distance_meters', transfers.distance_meters
        ) ORDER BY transfers.id)
        FROM transfer_relationships AS transfers
        LEFT JOIN provenance_sources AS sources ON sources.id=transfers.provenance_source_id
    ), '[]'::jsonb)
)::text;
SQL
)

psql -X -qAt -v ON_ERROR_STOP=1 -v "backup_sha256=$expected_backup_sha256" -c "$query" > "$building_path"
python3 - "$building_path" "$expected_backup_sha256" <<'PY'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as stream:
    document = json.load(stream)
if document.get("backup_sha256", "").lower() != sys.argv[2]:
    raise SystemExit("Export provenance does not match the verified backup.")
for field in ("stops", "places", "transfers"):
    if not isinstance(document.get(field), list):
        raise SystemExit(f"Export field {field} must be an array.")
PY

mv "$building_path" "$output_path"
trap - EXIT HUP INT TERM
printf 'Coverage points exported from isolated database %s.\n' "$PGDATABASE"
printf 'output: %s\n' "$output_path"
