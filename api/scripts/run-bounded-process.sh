#!/usr/bin/env bash
set -euo pipefail
umask 077

usage() {
    cat <<'EOF'
Usage: run-bounded-process.sh --operation NAME --disk-path DIR --cancel-pid-file PATH --cancel-match TEXT --log PATH -- COMMAND [ARG...]

Run one command in a private process group and measure its full descendant RSS.
On repeated resource-budget breaches, terminate only the named descendant and
wait for the command to unwind. A bounded SIGTERM of the private process group
is the final fallback. Swap is recorded but is not a standalone gate.

Budgets: BIYAHE_RESOURCE_MIN_MEMAVAILABLE_KB (default 393216),
BIYAHE_RESOURCE_MIN_DISKFREE_KB (default 1048576),
BIYAHE_RESOURCE_MAX_PROCESS_TREE_RSS_KB (default 262144),
BIYAHE_RESOURCE_BREACH_SAMPLES (default 2),
BIYAHE_RESOURCE_POLL_SECONDS (default 1),
BIYAHE_RESOURCE_CANCEL_GRACE_SECONDS (default 30).
EOF
}

operation=
disk_path=
cancel_pid_file=
cancel_match=
log_path=
while (($#)); do
    case "$1" in
        --operation) (($# >= 2)) || { usage >&2; exit 2; }; operation="$2"; shift 2 ;;
        --disk-path) (($# >= 2)) || { usage >&2; exit 2; }; disk_path="$2"; shift 2 ;;
        --cancel-pid-file) (($# >= 2)) || { usage >&2; exit 2; }; cancel_pid_file="$2"; shift 2 ;;
        --cancel-match) (($# >= 2)) || { usage >&2; exit 2; }; cancel_match="$2"; shift 2 ;;
        --log) (($# >= 2)) || { usage >&2; exit 2; }; log_path="$2"; shift 2 ;;
        --) shift; break ;;
        -h|--help) usage; exit 0 ;;
        *) printf 'Unknown option: %s\n' "$1" >&2; usage >&2; exit 2 ;;
    esac
done

if [[ -z "$operation" || ! "$operation" =~ ^[a-z0-9-]{1,40}$ || -z "$disk_path" \
    || -z "$cancel_pid_file" || -z "$cancel_match" || -z "$log_path" || $# -eq 0 ]]; then
    usage >&2
    exit 2
fi
[[ -d "$disk_path" && -w "$disk_path" ]] || { printf 'Disk path must be a writable directory.\n' >&2; exit 2; }
[[ ! -e "$cancel_pid_file" ]] || { printf 'Refusing to overwrite cancellation PID file.\n' >&2; exit 2; }

min_mem_kb="$(printenv BIYAHE_RESOURCE_MIN_MEMAVAILABLE_KB 2>/dev/null || true)"
min_disk_kb="$(printenv BIYAHE_RESOURCE_MIN_DISKFREE_KB 2>/dev/null || true)"
max_rss_kb="$(printenv BIYAHE_RESOURCE_MAX_PROCESS_TREE_RSS_KB 2>/dev/null || true)"
breach_samples="$(printenv BIYAHE_RESOURCE_BREACH_SAMPLES 2>/dev/null || true)"
poll_seconds="$(printenv BIYAHE_RESOURCE_POLL_SECONDS 2>/dev/null || true)"
cancel_grace_seconds="$(printenv BIYAHE_RESOURCE_CANCEL_GRACE_SECONDS 2>/dev/null || true)"
max_duration_seconds="$(printenv BIYAHE_RESOURCE_MAX_DURATION_SECONDS 2>/dev/null || true)"
meminfo_path="$(printenv BIYAHE_RESOURCE_MEMINFO_PATH 2>/dev/null || true)"
[[ -n "$min_mem_kb" ]] || min_mem_kb=393216
[[ -n "$min_disk_kb" ]] || min_disk_kb=1048576
[[ -n "$max_rss_kb" ]] || max_rss_kb=262144
[[ -n "$breach_samples" ]] || breach_samples=2
[[ -n "$poll_seconds" ]] || poll_seconds=1
[[ -n "$cancel_grace_seconds" ]] || cancel_grace_seconds=30
[[ -n "$max_duration_seconds" ]] || max_duration_seconds=0
[[ -n "$meminfo_path" ]] || meminfo_path=/proc/meminfo

for threshold in "$min_mem_kb" "$min_disk_kb" "$max_rss_kb" "$breach_samples" "$poll_seconds" "$cancel_grace_seconds" "$max_duration_seconds"; do
    [[ "$threshold" =~ ^[0-9]+$ ]] || { printf 'Resource budgets must be nonnegative integers.\n' >&2; exit 2; }
done
if ((min_mem_kb < 262144 || min_disk_kb < 524288 || max_rss_kb < 65536 \
    || breach_samples < 1 || breach_samples > 10 || poll_seconds < 1 || poll_seconds > 30 \
    || cancel_grace_seconds < 1 || cancel_grace_seconds > 120 || max_duration_seconds > 86400)); then
    printf 'Resource budgets are below hard safety floors or outside supported bounds.\n' >&2
    exit 2
fi
command -v setsid >/dev/null || { printf 'setsid is required for isolated process monitoring.\n' >&2; exit 2; }
command -v ps >/dev/null || { printf 'ps is required for process RSS monitoring.\n' >&2; exit 2; }

memavailable_kb() { awk '$1 == "MemAvailable:" { print $2; exit }' "$meminfo_path"; }
swapfree_kb() { awk '$1 == "SwapFree:" { print $2; exit }' "$meminfo_path"; }
meminfo_value_kb() { awk -v key="$1:" '$1 == key { print $2; exit }' "$meminfo_path"; }
diskfree_kb() { df -Pk "$disk_path" | awk 'NR == 2 { print $4; exit }'; }
file_size_bytes() {
    local path="$1" bytes
    if [[ ! -f "$path" && "$path" == *.building && -f "${path%.building}" ]]; then
        path="${path%.building}"
    fi
    [[ -f "$path" ]] || { printf '0\n'; return; }
    bytes="$(stat -c '%s' "$path" 2>/dev/null || true)"
    [[ "$bytes" =~ ^[0-9]+$ ]] && printf '%s\n' "$bytes" || printf 'unknown\n'
}
progress_sample() {
    local path="$1"
    [[ -n "$path" ]] || { printf 'not_configured\n'; return; }
    [[ -r "$path" ]] || { printf 'unavailable\n'; return; }
    tail -n 1 "$path" 2>/dev/null | tr '\n' ' '
}
process_running() {
    local state
    kill -0 "$1" 2>/dev/null || return 1
    state="$(awk '{print $3; exit}' "/proc/$1/stat" 2>/dev/null || true)"
    [[ "$state" != Z && -n "$state" ]]
}
process_tree_rss_kb() {
    local root_pid="$1"
    ps -e -o pid=,ppid=,rss= | awk -v root="$root_pid" '
        NF >= 3 && $1 ~ /^[0-9]+$/ && $2 ~ /^[0-9]+$/ && $3 ~ /^[0-9]+$/ {
            pid[++count] = $1
            parent[$1] = $2
            rss[$1] = $3
            if ($1 == root) present = 1
        }
        END {
            if (!present) exit 1
            found[root] = 1
            for (pass = 0; pass <= count; pass++) {
                changed = 0
                for (i = 1; i <= count; i++) {
                    if (found[parent[pid[i]]] && !found[pid[i]]) {
                        found[pid[i]] = 1
                        changed = 1
                    }
                }
                if (!changed) break
            }
            if (!found[root]) exit 1
            total = 0
            for (i = 1; i <= count; i++) if (found[pid[i]]) total += rss[pid[i]]
            print total
        }
    '
}
process_tree_members() {
    local root_pid="$1"
    ps -e -o pid=,ppid=,rss= | awk -v root="$root_pid" '
        NF >= 3 && $1 ~ /^[0-9]+$/ && $2 ~ /^[0-9]+$/ && $3 ~ /^[0-9]+$/ {
            pid[++count] = $1
            parent[$1] = $2
            rss[$1] = $3
            if ($1 == root) present = 1
        }
        END {
            if (!present) exit 1
            found[root] = 1
            for (pass = 0; pass <= count; pass++) {
                changed = 0
                for (i = 1; i <= count; i++) {
                    if (found[parent[pid[i]]] && !found[pid[i]]) {
                        found[pid[i]] = 1
                        changed = 1
                    }
                }
                if (!changed) break
            }
            for (i = 1; i <= count; i++) {
                if (found[pid[i]]) printf "%s:%sKiB ", pid[i], rss[pid[i]]
            }
        }
    '
}
is_descendant() {
    local root_pid="$1" target_pid="$2"
    ps -e -o pid=,ppid= | awk -v root="$root_pid" -v target="$target_pid" '
        NF >= 2 && $1 ~ /^[0-9]+$/ && $2 ~ /^[0-9]+$/ { parent[$1] = $2; seen[$1] = 1 }
        END {
            current = target
            for (steps = 0; steps <= 1000; steps++) {
                if (current == root) exit 0
                if (!seen[current] || current == parent[current]) exit 1
                current = parent[current]
            }
            exit 1
        }
    '
}

[[ ! -e "$log_path" ]] || { printf 'Refusing to overwrite existing log: %s\n' "$log_path" >&2; exit 2; }
mkdir -p "$(dirname "$log_path")"
record() {
    local line
    line="$(date -u +%Y-%m-%dT%H:%M:%SZ) $*"
    printf '%s\n' "$line" | tee -a "$log_path"
}

initial_mem="$(memavailable_kb)"
initial_swap="$(swapfree_kb)"
initial_cached="$(meminfo_value_kb Cached)"
initial_reclaimable="$(meminfo_value_kb SReclaimable)"
initial_disk="$(diskfree_kb)"
[[ -n "$initial_swap" ]] || initial_swap=unknown
[[ -n "$initial_cached" ]] || initial_cached=unknown
[[ -n "$initial_reclaimable" ]] || initial_reclaimable=unknown
if [[ ! "$initial_mem" =~ ^[0-9]+$ || ! "$initial_disk" =~ ^[0-9]+$ ]]; then
    record "operation=$operation blocked; resource metrics unavailable; command_not_started=yes"
    exit 4
fi
watch_file="$(printenv BIYAHE_RESOURCE_WATCH_FILE 2>/dev/null || true)"
progress_file="$(printenv BIYAHE_RESOURCE_PROGRESS_FILE 2>/dev/null || true)"
watch_db_bytes=not_configured
watch_journal_bytes=not_configured
watch_wal_bytes=not_configured
initial_watch_bytes=0
if [[ -n "$watch_file" ]]; then
    watch_db_bytes="$(file_size_bytes "$watch_file")"
    watch_journal_bytes="$(file_size_bytes "$watch_file-journal")"
    watch_wal_bytes="$(file_size_bytes "$watch_file-wal")"
    for bytes in "$watch_db_bytes" "$watch_journal_bytes" "$watch_wal_bytes"; do
        if [[ "$bytes" =~ ^[0-9]+$ ]]; then initial_watch_bytes=$((initial_watch_bytes + bytes)); else initial_watch_bytes=unknown; break; fi
    done
fi
record "operation=$operation preflight_memavailable_kb=$initial_mem cached_kb=$initial_cached sreclaimable_kb=$initial_reclaimable swapfree_kb=$initial_swap diskfree_kb=$initial_disk sqlite_bytes=$watch_db_bytes sqlite_journal_bytes=$watch_journal_bytes sqlite_wal_bytes=$watch_wal_bytes producer_consumer_progress=$(progress_sample "$progress_file") budgets_min_memavailable_kb=$min_mem_kb min_diskfree_kb=$min_disk_kb max_process_tree_rss_kb=$max_rss_kb"
if ((initial_mem < min_mem_kb || initial_disk < min_disk_kb)); then
    record 'operation=blocked; resource preflight failed; command_not_started=yes'
    exit 4
fi

[[ ! -e "$log_path.runner" ]] || { record 'operation=blocked; runner PID marker already exists'; exit 2; }
worker_output="$(mktemp "$(dirname "$log_path")/.resource-worker.XXXXXX")"
runner_pid=
cleanup() {
    local status=$?
    trap - EXIT INT TERM HUP
    if [[ -n "$runner_pid" ]] && process_running "$runner_pid"; then
        local producer_pid
        producer_pid="$(head -n 1 "$cancel_pid_file" 2>/dev/null || true)"
        if [[ "$producer_pid" =~ ^[0-9]+$ ]] && ((producer_pid > 1)) && is_descendant "$runner_pid" "$producer_pid"; then
            kill -TERM "$producer_pid" 2>/dev/null || true
        fi
        kill -TERM -- "-$runner_pid" 2>/dev/null || true
    fi
    rm -f -- "$log_path.runner"
    [[ -z "$worker_output" ]] || rm -f -- "$worker_output"
    exit "$status"
}
trap cleanup EXIT INT TERM HUP

record "operation=started runner_command=$*"
setsid --wait "$@" >"$worker_output" 2>&1 &
runner_pid=$!
printf '%s\n' "$runner_pid" > "$log_path.runner"
record "runner_pid=$runner_pid session_id=$runner_pid"

breach_count=0
stop_reason=
peak_rss_kb=0
min_observed_mem_kb="$initial_mem"
min_observed_disk_kb="$initial_disk"
previous_watch_bytes="$initial_watch_bytes"
if [[ ! "$previous_watch_bytes" =~ ^[0-9]+$ ]]; then previous_watch_bytes=0; fi
elapsed=0
while process_running "$runner_pid"; do
    sleep "$poll_seconds"
    elapsed=$((elapsed + poll_seconds))
    process_running "$runner_pid" || break
    mem_now="$(memavailable_kb)"
    cached_now="$(meminfo_value_kb Cached)"
    reclaimable_now="$(meminfo_value_kb SReclaimable)"
    swap_now="$(swapfree_kb)"
    disk_now="$(diskfree_kb)"
    if [[ -n "$watch_file" ]]; then
        watch_db_bytes="$(file_size_bytes "$watch_file")"
        watch_journal_bytes="$(file_size_bytes "$watch_file-journal")"
        watch_wal_bytes="$(file_size_bytes "$watch_file-wal")"
        watched_now=0
        for bytes in "$watch_db_bytes" "$watch_journal_bytes" "$watch_wal_bytes"; do
            if [[ "$bytes" =~ ^[0-9]+$ ]]; then watched_now=$((watched_now + bytes)); else watched_now=unknown; break; fi
        done
    else
        watch_db_bytes=not_configured
        watch_journal_bytes=not_configured
        watch_wal_bytes=not_configured
        watched_now=not_configured
    fi
    progress_now="$(progress_sample "$progress_file")"
    rss_now="$(process_tree_rss_kb "$runner_pid" 2>/dev/null || true)"
    [[ "$rss_now" =~ ^[0-9]+$ ]] || rss_now=unknown
    [[ -n "$mem_now" ]] || mem_now=unknown
    [[ -n "$disk_now" ]] || disk_now=unknown
    [[ -n "$cached_now" ]] || cached_now=unknown
    [[ -n "$reclaimable_now" ]] || reclaimable_now=unknown
    [[ -n "$swap_now" ]] || swap_now=unknown
    watched_growth=unknown
    if [[ "$watched_now" =~ ^[0-9]+$ ]]; then
        watched_growth=$((watched_now - previous_watch_bytes))
        previous_watch_bytes="$watched_now"
    fi
    if [[ "$mem_now" =~ ^[0-9]+$ ]] && ((mem_now < min_observed_mem_kb)); then min_observed_mem_kb="$mem_now"; fi
    if [[ "$disk_now" =~ ^[0-9]+$ ]] && ((disk_now < min_observed_disk_kb)); then min_observed_disk_kb="$disk_now"; fi
    if [[ "$rss_now" =~ ^[0-9]+$ ]] && ((rss_now > peak_rss_kb)); then peak_rss_kb="$rss_now"; fi
    record "sample_elapsed_seconds=$elapsed process_tree_rss_kb=$rss_now peak_rss_kb=$peak_rss_kb memavailable_kb=$mem_now min_memavailable_kb=$min_observed_mem_kb cached_kb=$cached_now sreclaimable_kb=$reclaimable_now swapfree_kb=$swap_now diskfree_kb=$disk_now min_diskfree_kb=$min_observed_disk_kb sqlite_bytes=$watch_db_bytes sqlite_journal_bytes=$watch_journal_bytes sqlite_wal_bytes=$watch_wal_bytes sqlite_growth_bytes=$watched_growth producer_consumer_progress=$progress_now"

    breach_reason=
    if [[ ! "$mem_now" =~ ^[0-9]+$ || ! "$disk_now" =~ ^[0-9]+$ ]]; then
        breach_reason='resource_metric_unavailable'
    elif [[ "$rss_now" == unknown ]]; then
        if process_running "$runner_pid"; then
            breach_reason='process_tree_rss_unavailable'
        else
            break
        fi
    elif ((rss_now > max_rss_kb)); then
        breach_reason="process_tree_rss_"$rss_now"_over_"$max_rss_kb
    elif ((mem_now < min_mem_kb)); then
        breach_reason="memavailable_"$mem_now"_below_"$min_mem_kb
    elif ((disk_now < min_disk_kb)); then
        breach_reason="diskfree_"$disk_now"_below_"$min_disk_kb
    fi
    if [[ -n "$breach_reason" ]]; then
        breach_count=$((breach_count + 1))
        members="$(process_tree_members "$runner_pid" 2>/dev/null || true)"
        [[ -z "$members" ]] || record "resource_process_tree=$members"
        record "resource_breach_sample=$breach_count required_samples=$breach_samples reason=$breach_reason"
    else
        breach_count=0
    fi
    if ((breach_count >= breach_samples)); then
        stop_reason="$breach_reason"
        break
    fi
    if ((max_duration_seconds > 0 && elapsed >= max_duration_seconds)); then
        stop_reason="operation_timeout_"$max_duration_seconds"_seconds"
        break
    fi
done

if [[ -n "$stop_reason" ]]; then
    record "resource_cancel=required reason=$stop_reason"
    producer_pid="$(head -n 1 "$cancel_pid_file" 2>/dev/null || true)"
    if [[ "$producer_pid" =~ ^[0-9]+$ ]] && ((producer_pid > 1)) && is_descendant "$runner_pid" "$producer_pid"; then
        producer_command="$(ps -p "$producer_pid" -o args= 2>/dev/null || true)"
        case "$producer_command" in
            *"$cancel_match"*)
                kill -TERM "$producer_pid" 2>/dev/null || true
                record "producer_stop=SIGTERM pid=$producer_pid command_match=yes"
                ;;
            *)
                record "producer_stop=blocked pid=$producer_pid command_match=no"
                ;;
        esac
    else
        record 'producer_stop=blocked; cancellation PID is absent or is not a worker descendant'
    fi

    waited=0
    while ((waited < cancel_grace_seconds)) && process_running "$runner_pid"; do
        sleep 1
        waited=$((waited + 1))
    done
    if process_running "$runner_pid"; then
        record "resource_cancel=fallback_SIGTERM_process_group grace_seconds=$cancel_grace_seconds"
        kill -TERM -- "-$runner_pid" 2>/dev/null || true
    fi
    worker_status=0
    wait "$runner_pid" || worker_status=$?
    if [[ -s "$worker_output" ]]; then
        while IFS= read -r line; do record "worker: $line"; done < <(tail -n 30 "$worker_output")
    fi
    record "operation=stopped_for_resource_budget worker_exit=$worker_status peak_rss_kb=$peak_rss_kb min_memavailable_kb=$min_observed_mem_kb min_diskfree_kb=$min_observed_disk_kb"
    exit 6
fi

worker_status=0
wait "$runner_pid" || worker_status=$?
if [[ -s "$worker_output" ]]; then
    while IFS= read -r line; do record "worker: $line"; done < <(tail -n 30 "$worker_output")
fi
record "operation=complete worker_exit=$worker_status peak_rss_kb=$peak_rss_kb min_memavailable_kb=$min_observed_mem_kb min_diskfree_kb=$min_observed_disk_kb"
exit "$worker_status"
