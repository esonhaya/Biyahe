#!/usr/bin/env bash
set -euo pipefail

snapshot="${1:-${BIYAHE_OSM_SNAPSHOT:-}}"
if [[ ! "$snapshot" =~ ^[0-9]{8}$ ]]; then
    printf 'Usage: BIYAHE_OSM_SNAPSHOT=YYYYMMDD bash %s [YYYYMMDD]\n' "$0" >&2
    exit 2
fi

api_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
data_dir="${BIYAHE_PEDESTRIAN_DATA_DIR:-${XDG_CACHE_HOME:-$HOME/.cache}/biyahe/pedestrian}"

db_connection="${BIYAHE_DB_CONNECTION:-${DB_CONNECTION:-pgsql}}"
db_host="${BIYAHE_DB_HOST:-${DB_HOST:-127.0.0.1}}"
db_port="${BIYAHE_DB_PORT:-${DB_PORT:-5432}}"
db_database="${BIYAHE_DB_DATABASE:-}"
db_username="${BIYAHE_DB_USERNAME:-${DB_USERNAME:-$(id -un)}}"
db_password="${BIYAHE_DB_PASSWORD:-${DB_PASSWORD:-}}"
if [[ "$db_connection" != "pgsql" ]]; then
    printf 'The pedestrian graph requires PostgreSQL/PostGIS; refusing DB_CONNECTION=%s.\n' "$db_connection" >&2
    exit 1
fi
if [[ -z "$db_database" ]]; then
    printf 'Set BIYAHE_DB_DATABASE explicitly to a disposable test or staging database; refusing implicit database selection.\n' >&2
    exit 1
fi
db_identity="${db_database,,}"
protected_database_pattern='prod|production'
staging_database_pattern='(^|[^a-z0-9])(test|testing|stage|staging|batch[0-9]+)([^a-z0-9]|$)'
if [[ "$db_identity" == "biyahe" || "$db_identity" =~ $protected_database_pattern ]]; then
    printf 'Refusing protected database target %s; this updater runs migrations and activates a graph, so use a disposable staging database.\n' "$db_database" >&2
    exit 1
fi
if [[ ! "$db_identity" =~ $staging_database_pattern ]]; then
    printf 'Refusing database target %s; its name must identify a disposable test or staging database.\n' "$db_database" >&2
    exit 1
fi

mkdir -p "$data_dir"
data_dir="$(cd "$data_dir" && pwd)"

artisan() {
    DB_CONNECTION="$db_connection" \
    DB_URL='' \
    DB_HOST="$db_host" \
    DB_PORT="$db_port" \
    DB_DATABASE="$db_database" \
    DB_USERNAME="$db_username" \
    DB_PASSWORD="$db_password" \
    php -d memory_limit="${BIYAHE_PHP_MEMORY_LIMIT:-512M}" artisan "$@"
}

run_osmium() {
    if [[ -n "${PREFIX:-}" && -d "$PREFIX/lib" ]]; then
        LD_LIBRARY_PATH="$PREFIX/lib${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}" command osmium "$@"
    else
        command osmium "$@"
    fi
}

source_url="${BIYAHE_OSM_SOURCE_URL:-https://download.openstreetmap.fr/extracts/asia/philippines/central_visayas.osm.pbf}"
source_file="${BIYAHE_OSM_SOURCE_FILE:-central_visayas.osm.pbf}"
pbf_path="$data_dir/$source_file"
checksum_path="$data_dir/${source_file}.md5"
checksum_url="${BIYAHE_OSM_CHECKSUM_URL:-${source_url}.md5}"
source_url_filename="$(basename "${source_url%%\?*}")"
if [[ "$source_url_filename" != "$source_file" ]]; then
    printf 'Source URL filename %s does not match configured local filename %s.\n' "$source_url_filename" "$source_file" >&2
    exit 1
fi

source_headers="$(curl --fail --location --silent --show-error --head "$source_url")"
source_http_last_modified="$(printf '%s\n' "$source_headers" \
    | awk 'tolower($1) == "last-modified:" { sub(/^[^:]*:[[:space:]]*/, ""); gsub(/\r/, ""); value = $0 } END { print value }')"
source_size="$(printf '%s\n' "$source_headers" \
    | awk 'tolower($1) == "content-length:" { gsub(/\r/, ""); value = $2 } END { print value }')"
if [[ ! "$source_size" =~ ^[0-9]+$ ]] || ((source_size < 1)); then
    printf 'The source server did not provide a usable nonzero file size.\n' >&2
    exit 1
fi
printf 'HTTP Last-Modified=%s; OSM snapshot time will come from the PBF stream header.\n' "${source_http_last_modified:-unavailable}"

min_mem_kb="${BIYAHE_OSM_MIN_MEMAVAILABLE_KB:-870400}"
min_swap_kb="${BIYAHE_OSM_MIN_SWAPFREE_KB:-262144}"
min_disk_kb="${BIYAHE_OSM_MIN_DISKFREE_KB:-1048576}"
for threshold in "$min_mem_kb" "$min_swap_kb" "$min_disk_kb"; do
    if [[ ! "$threshold" =~ ^[0-9]+$ ]]; then
        printf 'OSM resource thresholds must be nonnegative KiB integers.\n' >&2
        exit 1
    fi
done
disk_free_kb="$(df -Pk "$data_dir" | awk 'NR == 2 { print $4; exit }')"
required_download_kb=$((min_disk_kb + (source_size + 1023) / 1024))
if [[ ! "$disk_free_kb" =~ ^[0-9]+$ ]] || ((disk_free_kb < required_download_kb)); then
    printf 'Insufficient disk for source download and reserve: free=%s KiB required=%s KiB.\n' "${disk_free_kb:-unknown}" "$required_download_kb" >&2
    exit 1
fi

local_size=0
if [[ -f "$pbf_path" ]]; then
    local_size="$(wc -c < "$pbf_path" | tr -d '[:space:]')"
fi
if (( local_size > source_size )); then
    printf 'Cached PBF is larger than the pinned upstream object; refusing to overwrite it.\n' >&2
    exit 1
fi
if (( local_size < source_size )); then
    curl --fail --location --silent --show-error --retry 3 --retry-delay 3 --continue-at - \
        --output "$pbf_path" "$source_url"
fi
if [[ "$(wc -c < "$pbf_path" | tr -d '[:space:]')" != "$source_size" ]]; then
    printf 'Downloaded PBF size does not match the upstream Content-Length.\n' >&2
    exit 1
fi
curl --fail --location --silent --show-error --retry 3 --retry-delay 3 \
    --output "$checksum_path" "$checksum_url"

source_md5="$(awk 'NR == 1 { print $1 }' "$checksum_path")"
if [[ ! "$source_md5" =~ ^[[:xdigit:]]{32}$ ]]; then
    printf 'OSM checksum sidecar is malformed: %s\n' "$checksum_path" >&2
    exit 1
fi
printf '%s  %s\n' "$source_md5" "$pbf_path" | md5sum --check --status -
source_sha256="$(sha256sum "$pbf_path" | awk '{ print $1 }')"
if [[ ! "$source_sha256" =~ ^[[:xdigit:]]{64}$ ]]; then
    printf 'Could not calculate a source SHA-256.\n' >&2
    exit 1
fi

available_mem_kb="$(awk '$1 == "MemAvailable:" { print $2; exit }' /proc/meminfo)"
free_swap_kb="$(awk '$1 == "SwapFree:" { print $2; exit }' /proc/meminfo)"
disk_free_kb="$(df -Pk "$data_dir" | awk 'NR == 2 { print $4; exit }')"
printf 'OSM stream preflight: MemAvailable=%s KiB SwapFree=%s KiB DiskFree=%s KiB\n' \
    "${available_mem_kb:-unknown}" "${free_swap_kb:-unknown}" "${disk_free_kb:-unknown}"
if [[ ! "$available_mem_kb" =~ ^[0-9]+$ || ! "$free_swap_kb" =~ ^[0-9]+$ || ! "$disk_free_kb" =~ ^[0-9]+$ ]] \
    || ((available_mem_kb < min_mem_kb || free_swap_kb < min_swap_kb || disk_free_kb < min_disk_kb)); then
    printf 'OSM stream blocked by configured resource gate; no migration or database import was run.\n' >&2
    exit 1
fi

osmium_version="$(run_osmium version | sed -n '1p' | sed -E 's/.* ([0-9]+\.[0-9]+\.[0-9]+).*/\1/')"
prepared_json="$data_dir/cebu-pedestrian-${snapshot}.json"

cd "$api_dir"
run_osmium cat --no-progress --output-format='osm' --output=- "$pbf_path" \
    | artisan biyahe:pedestrian:prepare-osm-stream - \
        --output="$prepared_json" \
        --source-path="$pbf_path" \
        --source-url="$source_url" \
        --source-md5="$source_md5" \
        --source-sha256="$source_sha256" \
        --osmium-version="$osmium_version"

artisan migrate --force
artisan biyahe:pedestrian:import "$prepared_json" --source-url="$source_url"
artisan biyahe:pedestrian:status
artisan biyahe:doctor
