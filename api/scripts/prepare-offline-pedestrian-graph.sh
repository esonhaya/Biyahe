#!/usr/bin/env bash
set -euo pipefail
umask 077

if (($# != 7)); then
    printf 'Usage: prepare-offline-pedestrian-graph.sh PBF OUTPUT SOURCE_URL SOURCE_SHA256 SNAPSHOT_TIMESTAMP OSMIUM_VERSION CANCEL_PID_FILE\n' >&2
    exit 2
fi

source_path="$1"
output_path="$2"
source_url="$3"
source_sha256="${4,,}"
source_timestamp="$5"
osmium_version="$6"
cancel_pid_file="$7"
api_dir="$(cd "$(dirname "$0")/.." && pwd)"
source "$(dirname "$0")/osmium-runtime.sh"
source_dir="$(cd "$(dirname "$source_path")" && pwd)"
output_dir="$(cd "$(dirname "$output_path")" && pwd)"
source_path="$source_dir/$(basename "$source_path")"
output_path="$output_dir/$(basename "$output_path")"
fifo_path="$output_dir/.biyahe-offline-osm-$$.fifo"
consumer_log="$output_dir/.biyahe-offline-consumer-$$.log"
producer_log="$output_dir/.biyahe-offline-producer-$$.log"
required_ids_path="$output_path.required-node-ids"
consumer_pid=
producer_pid=

[[ -r "$source_path" ]] || { printf 'Verified source PBF is not readable.\n' >&2; exit 2; }
[[ -d "$output_dir" && -w "$output_dir" ]] || { printf 'Output directory must exist and be writable.\n' >&2; exit 2; }
[[ ! -e "$output_path" && ! -e "$output_path.building" && ! -e "$required_ids_path" && ! -e "$fifo_path" && ! -e "$cancel_pid_file" ]] || {
    printf 'Refusing to overwrite an existing graph, checkpoint, FIFO, or cancellation marker.\n' >&2
    exit 2
}
[[ "$(basename "$source_path")" == "$(basename "$source_url")" ]] || { printf 'Source URL filename must match the local PBF.\n' >&2; exit 2; }
[[ "$source_sha256" =~ ^[[:xdigit:]]{64}$ ]] || { printf 'Expected SHA-256 is malformed.\n' >&2; exit 2; }
[[ -n "$source_timestamp" && -n "$osmium_version" && ${#osmium_version} -le 80 ]] || { printf 'Snapshot timestamp and bounded Osmium version are required.\n' >&2; exit 2; }
command -v php >/dev/null && command -v osmium >/dev/null && command -v sha256sum >/dev/null && command -v mkfifo >/dev/null || {
    printf 'PHP, Osmium, sha256sum, and FIFO support are required.\n' >&2
    exit 2
}
version_output=""
if ! version_output="$(run_osmium --version 2>&1)"; then
    printf 'Osmium cannot run in this Termux environment: %s\n' "$version_output" >&2
    exit 4
fi
reported_version="$(printf '%s\n' "$version_output" | sed -nE 's/.*osmium version ([0-9]+\.[0-9]+(\.[0-9]+)?).*/\1/p' | head -n 1)"
[[ "$reported_version" == "$osmium_version" ]] || {
    printf 'Declared Osmium version does not match the installed producer (%s).\n' "${reported_version:-unavailable}" >&2
    exit 4
}
actual_sha256="$(sha256sum "$source_path" | awk '{print tolower($1)}')"
[[ "$actual_sha256" == "$source_sha256" ]] || { printf 'Local source PBF SHA-256 mismatch.\n' >&2; exit 2; }

cleanup() {
    local status=$?
    trap - EXIT INT TERM HUP
    if [[ -n "$producer_pid" ]] && kill -0 "$producer_pid" 2>/dev/null; then kill -TERM "$producer_pid" 2>/dev/null || true; fi
    if [[ -n "$consumer_pid" ]] && kill -0 "$consumer_pid" 2>/dev/null; then kill -TERM "$consumer_pid" 2>/dev/null || true; fi
    rm -f -- "$fifo_path" "$cancel_pid_file" "$consumer_log" "$producer_log"
    exit "$status"
}
trap cleanup EXIT INT TERM HUP

mkfifo "$fifo_path"
php_memory_limit="$(printenv BIYAHE_OSM_PHP_MEMORY_LIMIT 2>/dev/null || true)"
[[ -n "$php_memory_limit" ]] || php_memory_limit=128M
[[ "$php_memory_limit" =~ ^[0-9]+[KMG]?$ ]] || { printf 'PHP memory limit must be a numeric K/M/G limit.\n' >&2; exit 2; }
(
    cd "$api_dir"
    php -d memory_limit="$php_memory_limit" artisan biyahe:pedestrian:stage-offline - \
        --output="$output_path" --source-path="$source_path" --source-url="$source_url" \
        --source-sha256="$source_sha256" --source-timestamp="$source_timestamp" \
        --osmium-version="$osmium_version" --stop-after-source --await-producer-exit
) < "$fifo_path" >"$consumer_log" 2>&1 &
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
if ((producer_status != 0)); then
    printf 'Producer failed; the source checkpoint remains unpublished and requires a fresh verified stream.\n' >&2
    exit "$producer_status"
fi

# The first complete pass indexes way references and only in-scope coordinates.
# Confirm its clean producer exit before exporting the bounded node-ID request.
(
    cd "$api_dir"
    php -d memory_limit="$php_memory_limit" artisan biyahe:pedestrian:stage-offline - \
        --output="$output_path" --source-path="$source_path" --source-url="$source_url" \
        --source-sha256="$source_sha256" --source-timestamp="$source_timestamp" \
        --osmium-version="$osmium_version" --resume --stop-after-source \
        --producer-exit-confirmed --export-required-node-ids="$required_ids_path"
)

# Resolve only node IDs referenced by candidate ways. Osmium scans the verified
# PBF again and returns exactly the requested nodes; the stager checks every ID.
(
    cd "$api_dir"
    php -d memory_limit="$php_memory_limit" artisan biyahe:pedestrian:stage-offline - \
        --output="$output_path" --source-path="$source_path" --source-url="$source_url" \
        --source-sha256="$source_sha256" --source-timestamp="$source_timestamp" \
        --osmium-version="$osmium_version" --resume --resolve-required-nodes \
        --stop-after-source --await-producer-exit
) < "$fifo_path" >"$consumer_log" 2>&1 &
consumer_pid=$!

sleep 1
if ! kill -0 "$consumer_pid" 2>/dev/null; then
    consumer_status=0
    wait "$consumer_pid" || consumer_status=$?
    cat "$consumer_log" >&2
    exit "$consumer_status"
fi

run_osmium getid --no-progress --id-file "$required_ids_path" --output-format=osm --output=- "$source_path" >"$fifo_path" 2>"$producer_log" &
producer_pid=$!
printf '%s\n' "$producer_pid" > "$cancel_pid_file"

consumer_status=0
producer_status=0
wait "$consumer_pid" || consumer_status=$?
wait "$producer_pid" || producer_status=$?
if [[ -s "$consumer_log" ]]; then cat "$consumer_log"; fi
if [[ -s "$producer_log" ]]; then cat "$producer_log" >&2; fi
if ((consumer_status != 0)); then exit "$consumer_status"; fi
if ((producer_status != 0)); then
    printf 'Reference producer failed; the offline graph remains unpublished.\n' >&2
    exit "$producer_status"
fi

if [[ "${BIYAHE_OSM_STOP_AFTER_SOURCE:-0}" == 1 ]]; then
    (
        cd "$api_dir"
        php -d memory_limit="$php_memory_limit" artisan biyahe:pedestrian:stage-offline - \
            --output="$output_path" --source-path="$source_path" --source-url="$source_url" \
            --source-sha256="$source_sha256" --source-timestamp="$source_timestamp" \
            --osmium-version="$osmium_version" --resume --stop-after-source --producer-exit-confirmed
    )
    exit $?
fi

(
    cd "$api_dir"
    php -d memory_limit="$php_memory_limit" artisan biyahe:pedestrian:stage-offline - \
        --output="$output_path" --source-path="$source_path" --source-url="$source_url" \
        --source-sha256="$source_sha256" --source-timestamp="$source_timestamp" \
        --osmium-version="$osmium_version" --resume --producer-exit-confirmed
)
