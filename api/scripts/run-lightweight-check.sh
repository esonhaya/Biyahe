#!/usr/bin/env bash
set -euo pipefail
set -o noclobber
umask 077

usage() {
    cat <<'EOF'
Usage:
  run-lightweight-check.sh OPERATION EVIDENCE_DIR
  run-lightweight-check.sh bundle EVIDENCE_DIR OUTPUT.bundle EXPECTED_FULL_HEAD

Only enumerated pure Node fixtures and the bounded Git bundle workflow are
accepted. All workers still run through run-bounded-process.sh. The lightweight
profile retains the 524288 KiB MemAvailable floor and applies a separate
32768 KiB SwapFree reserve, 131072 KiB process-tree RSS cap, first-breach
cancellation, three one-second preflight samples, and a 60-second timeout.
This profile is never used for builds, browsers, databases, graph work, or
arbitrary commands. Heavy commands keep the existing 131072 KiB SwapFree floor.
EOF
}

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
guard="$repo_root/api/scripts/run-bounded-process.sh"
bundle_helper="$repo_root/api/scripts/create-batch047-bundle.sh"
operation="${1:-}"
evidence_dir="${2:-}"
[[ -n "$operation" && -n "$evidence_dir" ]] || { usage >&2; exit 2; }
shift 2

declare -a command_argv=()
declare -a fingerprint_inputs=()
command_cwd="$repo_root/web"
disk_path="$HOME"

case "$operation" in
    api-failure)
        (($# == 0)) || { usage >&2; exit 2; }
        command_argv=(node --experimental-strip-types scripts/api-failure-harness.mjs)
        fingerprint_inputs=(web/package.json web/scripts/api-failure-harness.mjs web/src/lib/api.ts web/src/lib/fetchWithTimeout.ts)
        ;;
    commute-cards)
        (($# == 0)) || { usage >&2; exit 2; }
        command_argv=(node --experimental-strip-types scripts/commute-card-harness.mjs)
        fingerprint_inputs=(web/package.json web/scripts/commute-card-harness.mjs web/src/lib/commuteInstructions.ts web/src/lib/journeyMapPresentation.ts web/src/lib/journeyExplanation.ts web/src/lib/savedJourneys.ts)
        ;;
    ride-mode)
        (($# == 0)) || { usage >&2; exit 2; }
        command_argv=(node --experimental-strip-types scripts/ride-mode-harness.mjs)
        fingerprint_inputs=(web/package.json web/scripts/ride-mode-harness.mjs web/src/lib/commuteInstructions.ts web/src/lib/rideMode.ts)
        ;;
    request-gate)
        (($# == 0)) || { usage >&2; exit 2; }
        command_argv=(node --experimental-strip-types scripts/request-gate-harness.mjs)
        fingerprint_inputs=(web/package.json web/scripts/request-gate-harness.mjs web/src/lib/requestGate.ts)
        ;;
    place-search)
        (($# == 0)) || { usage >&2; exit 2; }
        command_argv=(node --experimental-strip-types scripts/place-search-harness.mjs)
        fingerprint_inputs=(web/package.json web/scripts/place-search-harness.mjs web/src/lib/placeSearch.ts web/src/lib/requestGate.ts)
        ;;
    search-keyboard)
        (($# == 0)) || { usage >&2; exit 2; }
        command_argv=(node --experimental-strip-types scripts/search-keyboard-harness.mjs)
        fingerprint_inputs=(web/package.json web/scripts/search-keyboard-harness.mjs web/src/lib/searchKeyboard.ts)
        ;;
    can-i-ride)
        (($# == 0)) || { usage >&2; exit 2; }
        command_argv=(node --experimental-strip-types scripts/can-i-ride-harness.mjs)
        fingerprint_inputs=(web/package.json web/scripts/can-i-ride-harness.mjs web/src/lib/api.ts web/src/lib/fetchWithTimeout.ts)
        ;;
    bundle)
        (($# == 2)) || { usage >&2; exit 2; }
        output="$1"
        expected_head="$2"
        command_argv=(bash "$bundle_helper" "$repo_root" "$output" "$expected_head")
        command_cwd="$repo_root"
        disk_path="$HOME/storage/external-1/Termux-Archive/Biyahe/Batch047-Recovery"
        fingerprint_inputs=(api/scripts/run-lightweight-check.sh api/scripts/create-batch047-bundle.sh api/scripts/run-bounded-process.sh)
        ;;
    *)
        printf 'Unknown or non-allowlisted lightweight operation: %s\n' "$operation" >&2
        usage >&2
        exit 2
        ;;
esac

if [[ "${command_argv[0]}" == node ]] && ! command -v node >/dev/null 2>&1; then
    printf 'STATUS=BLOCKED_DEPENDENCY\nDEPENDENCY=node\nCOMMAND_STARTED=NO\n'
    exit 127
fi
[[ -x "$guard" && -f "$guard" ]] || { printf 'Resource guard is missing.\n' >&2; exit 2; }

evidence_dir="$(realpath -m -- "$evidence_dir")"
mkdir -p -- "$evidence_dir"
log="$evidence_dir/$operation.guard.log"
manifest="$evidence_dir/$operation.inputs.txt"
pid_file="$evidence_dir/$operation.cancel.pid"
for path in "$log" "$manifest" "$pid_file"; do
    [[ ! -e "$path" ]] || { printf 'Refusing to overwrite evidence path: %s\n' "$path" >&2; exit 2; }
done

command_label=""
printf -v command_label '%q ' "${command_argv[@]}"
{
    printf 'START_TIME_UTC=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    printf 'OPERATION=%s\n' "$operation"
    printf 'COMMAND=%s\n' "${command_label% }"
    printf 'WORKING_DIRECTORY=%s\n' "$command_cwd"
    printf 'EVIDENCE_LOG=%s\n' "$log"
    printf 'NODE_EXECUTABLE=%s\n' "$(command -v node || printf 'NOT_INSTALLED')"
    printf 'INPUT_SHA256_BEGIN\n'
    for input in "${fingerprint_inputs[@]}"; do
        [[ -f "$repo_root/$input" ]] || { printf 'Missing fingerprint input: %s\n' "$input" >&2; exit 2; }
    done
    (cd "$repo_root" && sha256sum -- "${fingerprint_inputs[@]}")
    printf 'INPUT_SHA256_END\n'
} > "$manifest"

start_epoch="$(date +%s)"
set +e
(
    cd "$command_cwd"
    export BIYAHE_RESOURCE_MIN_MEMAVAILABLE_KB=524288
    export BIYAHE_RESOURCE_MIN_SWAPFREE_KB=32768
    export BIYAHE_RESOURCE_MIN_DISKFREE_KB=1048576
    export BIYAHE_RESOURCE_MAX_PROCESS_TREE_RSS_KB=131072
    export BIYAHE_RESOURCE_BREACH_SAMPLES=1
    export BIYAHE_RESOURCE_POLL_SECONDS=1
    export BIYAHE_RESOURCE_CANCEL_GRACE_SECONDS=2
    export BIYAHE_RESOURCE_MAX_DURATION_SECONDS=60
    export BIYAHE_RESOURCE_PREFLIGHT_SAMPLES=3
    export BIYAHE_RESOURCE_PREFLIGHT_INTERVAL_SECONDS=1
    exec "$guard" \
        --operation "light-$operation" \
        --disk-path "$disk_path" \
        --cancel-pid-file "$pid_file" \
        --cancel-match "${command_label% }" \
        --log "$log" \
        -- "${command_argv[@]}"
)
worker_exit=$?
set -e
end_epoch="$(date +%s)"
elapsed_seconds=$((end_epoch - start_epoch))

status=FAIL
case "$worker_exit" in
    0) status=PASS ;;
    4|6) status=BLOCKED_RESOURCE ;;
    5)
        if [[ -f "$log" ]] && grep -q 'worker process group isolation verification failed' "$log"; then
            status=BLOCKED_RESOURCE
        fi
        ;;
    2)
        if [[ -f "$log" ]] && ! grep -q 'operation=complete worker_exit=2' "$log"; then
            status=BLOCKED_DEPENDENCY
        fi
        ;;
    3)
        if [[ -f "$log" ]] && grep -q 'BUNDLE_STATUS=BLOCKED_RESOURCE' "$log"; then
            status=BLOCKED_RESOURCE
        fi
        ;;
    127) status=BLOCKED_DEPENDENCY ;;
esac
printf 'END_TIME_UTC=%s\nELAPSED_WALL_SECONDS=%s\nEXIT_CODE=%s\nSTATUS=%s\n' \
    "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$elapsed_seconds" "$worker_exit" "$status" >> "$manifest"
printf 'STATUS=%s\nEXIT_CODE=%s\nELAPSED_WALL_SECONDS=%s\nLOG=%s\nINPUT_FINGERPRINTS=%s\n' \
    "$status" "$worker_exit" "$elapsed_seconds" "$log" "$manifest"
exit "$worker_exit"
