#!/usr/bin/env bash
set -euo pipefail

snapshot="${1:-${BIYAHE_OSM_SNAPSHOT:-}}"
if [[ ! "$snapshot" =~ ^[0-9]{8}$ ]]; then
    printf 'Usage: BIYAHE_OSM_SNAPSHOT=YYYYMMDD bash %s [YYYYMMDD]\n' "$0" >&2
    exit 2
fi

api_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
data_dir="${BIYAHE_PEDESTRIAN_DATA_DIR:-${XDG_CACHE_HOME:-$HOME/.cache}/biyahe/pedestrian}"
mkdir -p "$data_dir"

db_connection="${BIYAHE_DB_CONNECTION:-${DB_CONNECTION:-pgsql}}"
db_host="${BIYAHE_DB_HOST:-${DB_HOST:-127.0.0.1}}"
db_port="${BIYAHE_DB_PORT:-${DB_PORT:-5432}}"
db_database="${BIYAHE_DB_DATABASE:-${DB_DATABASE:-biyahe}}"
db_username="${BIYAHE_DB_USERNAME:-${DB_USERNAME:-$(id -un)}}"
db_password="${BIYAHE_DB_PASSWORD:-${DB_PASSWORD:-}}"
if [[ "$db_connection" != "pgsql" ]]; then
    printf 'The pedestrian graph requires PostgreSQL/PostGIS; refusing DB_CONNECTION=%s.\n' "$db_connection" >&2
    exit 1
fi

artisan() {
    DB_CONNECTION="$db_connection" \
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
bounds='123.860,10.282,123.935,10.381'

source_headers="$(curl --fail --location --silent --show-error --head "$source_url")"
source_timestamp="$(printf '%s\n' "$source_headers" \
    | awk 'tolower($1) == "last-modified:" { sub(/^[^:]*:[[:space:]]*/, ""); gsub(/\r/, ""); value = $0 } END { print value }')"
source_size="$(printf '%s\n' "$source_headers" \
    | awk 'tolower($1) == "content-length:" { gsub(/\r/, ""); value = $2 } END { print value }')"
if [[ -z "$source_timestamp" || ! "$source_size" =~ ^[0-9]+$ ]]; then
    printf 'The source server did not provide a usable snapshot timestamp and file size.\n' >&2
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

run_osmium tags-filter --overwrite "$pbf_path" \
    --output="$data_dir/central-visayas-${snapshot}-walk-source.osm.pbf" \
    'w/highway=*' 'n/barrier=*' 'n/highway=crossing'
run_osmium extract --overwrite --bbox="$bounds" --strategy=complete_ways \
    --output="$data_dir/cebu-${snapshot}-walk.pbf" "$data_dir/central-visayas-${snapshot}-walk-source.osm.pbf"
run_osmium fileinfo --extended --json "$data_dir/cebu-${snapshot}-walk.pbf" \
    > "$data_dir/cebu-${snapshot}-walk-fileinfo.json"
source_elements="$(php -r '$info = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo array_sum($info["data"]["count"] ?? []);' \
    < "$data_dir/cebu-${snapshot}-walk-fileinfo.json")"
run_osmium cat --overwrite --output-format='osm,add_metadata=false' \
    --output="$data_dir/cebu-${snapshot}-walk.osm" \
    "$data_dir/cebu-${snapshot}-walk.pbf"
osmium_version="$(run_osmium version | sed -n '1p' | sed -E 's/.* ([0-9]+\.[0-9]+\.[0-9]+).*/\1/')"
prepared_json="$data_dir/cebu-pedestrian-${snapshot}.json"

cd "$api_dir"
artisan migrate --force
artisan biyahe:pedestrian:prepare-osm-xml "$data_dir/cebu-${snapshot}-walk.osm" \
    --output="$prepared_json" \
    --source-url="$source_url" \
    --source-file="$source_file" \
    --source-md5="$source_md5" \
    --source-timestamp="$source_timestamp" \
    --source-elements="$source_elements" \
    --osmium-version="$osmium_version"
artisan biyahe:pedestrian:import "$prepared_json" --source-url="$source_url"
artisan biyahe:pedestrian:status
artisan biyahe:doctor
