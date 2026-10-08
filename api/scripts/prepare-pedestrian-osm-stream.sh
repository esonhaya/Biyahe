#!/usr/bin/env bash
set -euo pipefail
umask 077

if (($# < 7 || $# > 8)); then
    printf 'Usage: prepare-pedestrian-osm-stream.sh PBF OUTPUT SOURCE_URL SOURCE_MD5 SOURCE_SHA256 OSMIUM_VERSION CANCEL_PID_FILE [BOUNDS]\n' >&2
    exit 2
fi

source_path="$1"
output_path="$2"
source_url="$3"
source_md5="$4"
source_sha256="$5"
osmium_version="$6"
cancel_pid_file="$7"
bounds=
if (($# == 8)); then
    bounds="$8"
fi
api_dir="$(cd "$(dirname "$0")/.." && pwd)"
source "$(dirname "$0")/osmium-runtime.sh"
output_dir="$(dirname "$output_path")"
fifo_path="$output_dir/.biyahe-osm-xml-$$.fifo"
consumer_log="$output_dir/.biyahe-osm-consumer-$$.log"
producer_log="$output_dir/.biyahe-osm-producer-$$.log"
consumer_pid=
producer_pid=

[[ -r "$source_path" ]] || { printf 'Verified PBF source is not readable.\n' >&2; exit 2; }
[[ -d "$output_dir" && -w "$output_dir" ]] || { printf 'Output directory must be writable.\n' >&2; exit 2; }
[[ ! -e "$output_path" && ! -e "$fifo_path" && ! -e "$cancel_pid_file" ]] || {
    printf 'Refusing to overwrite a prepared output, stream FIFO, or cancellation PID file.\n' >&2
    exit 2
}
source_url_file="$(basename "$source_url")"
if [[ "$(basename "$source_path")" != "$source_url_file" ]]; then
    printf 'Source URL filename does not match the verified local PBF.\n' >&2
    exit 2
fi
if [[ ! "$source_md5" =~ ^[[:xdigit:]]{32}$ || ! "$source_sha256" =~ ^[[:xdigit:]]{64}$ ]]; then
    printf 'Expected source hashes are malformed.\n' >&2
    exit 2
fi
if ! command -v php >/dev/null || ! command -v osmium >/dev/null || ! command -v mkfifo >/dev/null; then
    printf 'PHP, Osmium, and FIFO support are required for the streaming worker.\n' >&2
    exit 2
fi

cleanup() {
    local status=$?
    trap - EXIT INT TERM HUP
    if [[ -n "$producer_pid" ]] && kill -0 "$producer_pid" 2>/dev/null; then
        kill -TERM "$producer_pid" 2>/dev/null || true
    fi
    if [[ -n "$consumer_pid" ]] && kill -0 "$consumer_pid" 2>/dev/null; then
        kill -TERM "$consumer_pid" 2>/dev/null || true
    fi
    [[ -z "$fifo_path" ]] || rm -f -- "$fifo_path"
    rm -f -- "$cancel_pid_file" "$consumer_log" "$producer_log"
    exit "$status"
}
trap cleanup EXIT INT TERM HUP

mkfifo "$fifo_path"
php_memory_limit="$(printenv BIYAHE_OSM_PHP_MEMORY_LIMIT 2>/dev/null || true)"
[[ -n "$php_memory_limit" ]] || php_memory_limit=256M
if [[ ! "$php_memory_limit" =~ ^(-1|[0-9]+[KMG]?)$ ]]; then
    printf 'PHP memory limit must be -1 or a numeric K/M/G limit.\n' >&2
    exit 2
fi
if [[ -n "$bounds" ]]; then
    (
        cd "$api_dir"
        php -d memory_limit="$php_memory_limit" artisan biyahe:pedestrian:prepare-osm-stream - \
            --output="$output_path" --source-path="$source_path" --source-url="$source_url" \
            --source-md5="$source_md5" --source-sha256="$source_sha256" \
            --osmium-version="$osmium_version" --bounds="$bounds"
    ) < "$fifo_path" >"$consumer_log" 2>&1 &
else
    (
        cd "$api_dir"
        php -d memory_limit="$php_memory_limit" artisan biyahe:pedestrian:prepare-osm-stream - \
            --output="$output_path" --source-path="$source_path" --source-url="$source_url" \
            --source-md5="$source_md5" --source-sha256="$source_sha256" \
            --osmium-version="$osmium_version"
    ) < "$fifo_path" >"$consumer_log" 2>&1 &
fi
consumer_pid=$!

sleep 1
if ! kill -0 "$consumer_pid" 2>/dev/null; then
    consumer_status=0
    wait "$consumer_pid" || consumer_status=$?
    cat "$consumer_log" >&2
    exit "$consumer_status"
fi

run_osmium cat --no-progress --output-format=osm --output=- "$source_path" >"$fifo_path" 2>"$producer_log" &
producer_pid=$!
printf '%s\n' "$producer_pid" > "$cancel_pid_file"

consumer_status=0
producer_status=0
wait "$consumer_pid" || consumer_status=$?
wait "$producer_pid" || producer_status=$?
if [[ -s "$consumer_log" ]]; then cat "$consumer_log"; fi
if [[ -s "$producer_log" ]]; then cat "$producer_log" >&2; fi
if ((consumer_status != 0)); then exit "$consumer_status"; fi
if ((producer_status != 0)); then exit "$producer_status"; fi
