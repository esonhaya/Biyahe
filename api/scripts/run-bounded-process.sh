#!/usr/bin/env bash
set -euo pipefail
umask 077

usage() {
    cat <<'EOF'
Usage: run-bounded-process.sh --operation NAME --disk-path DIR --cancel-pid-file PATH --cancel-match TEXT --log PATH -- COMMAND [ARG...]

Run one command in a private session/process group and measure its descendant
RSS. Require sustained preflight headroom, then terminate only the verified
owned worker group on a resource-budget breach. The parent shell is kept out
of that session. Swap is recorded and can be enabled as a hard gate.

Budgets: BIYAHE_RESOURCE_MIN_MEMAVAILABLE_KB (default 393216),
BIYAHE_RESOURCE_MIN_DISKFREE_KB (default 1048576),
BIYAHE_RESOURCE_MIN_SWAPFREE_KB (default 0 / disabled),
BIYAHE_RESOURCE_MAX_PROCESS_TREE_RSS_KB (default 262144),
BIYAHE_RESOURCE_BREACH_SAMPLES (default 2),
BIYAHE_RESOURCE_POLL_SECONDS (default 1),
BIYAHE_RESOURCE_CANCEL_GRACE_SECONDS (default 30),
BIYAHE_RESOURCE_PREFLIGHT_SAMPLES (default 3),
BIYAHE_RESOURCE_PREFLIGHT_INTERVAL_SECONDS (default 5).
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
min_swap_kb="$(printenv BIYAHE_RESOURCE_MIN_SWAPFREE_KB 2>/dev/null || true)"
preflight_samples="$(printenv BIYAHE_RESOURCE_PREFLIGHT_SAMPLES 2>/dev/null || true)"
preflight_interval_seconds="$(printenv BIYAHE_RESOURCE_PREFLIGHT_INTERVAL_SECONDS 2>/dev/null || true)"
meminfo_path="$(printenv BIYAHE_RESOURCE_MEMINFO_PATH 2>/dev/null || true)"
[[ -n "$min_mem_kb" ]] || min_mem_kb=393216
[[ -n "$min_disk_kb" ]] || min_disk_kb=1048576
[[ -n "$max_rss_kb" ]] || max_rss_kb=262144
[[ -n "$breach_samples" ]] || breach_samples=2
[[ -n "$poll_seconds" ]] || poll_seconds=1
[[ -n "$cancel_grace_seconds" ]] || cancel_grace_seconds=30
[[ -n "$max_duration_seconds" ]] || max_duration_seconds=0
[[ -n "$min_swap_kb" ]] || min_swap_kb=0
[[ -n "$preflight_samples" ]] || preflight_samples=3
[[ -n "$preflight_interval_seconds" ]] || preflight_interval_seconds=5
[[ -n "$meminfo_path" ]] || meminfo_path=/proc/meminfo

for threshold in "$min_mem_kb" "$min_disk_kb" "$max_rss_kb" "$breach_samples" "$poll_seconds" "$cancel_grace_seconds" "$max_duration_seconds" "$min_swap_kb" "$preflight_samples" "$preflight_interval_seconds"; do
    [[ "$threshold" =~ ^[0-9]+$ ]] || { printf 'Resource budgets must be nonnegative integers.\n' >&2; exit 2; }
done
if ((min_mem_kb < 262144 || min_disk_kb < 524288 || max_rss_kb < 65536 \
    || (min_swap_kb > 0 && min_swap_kb < 16384) \
    || breach_samples < 1 || breach_samples > 10 || poll_seconds < 1 || poll_seconds > 30 \
    || cancel_grace_seconds < 1 || cancel_grace_seconds > 120 || max_duration_seconds > 86400 \
    || preflight_samples < 1 || preflight_samples > 10 \
    || preflight_interval_seconds < 1 || preflight_interval_seconds > 60)); then
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
proc_identity() {
    local pid="$1" stat_line rest
    local -a fields
    IFS= read -r stat_line < "/proc/$pid/stat" 2>/dev/null || return 1
    rest="${stat_line##*) }"
    read -r -a fields <<< "$rest"
    ((${#fields[@]} >= 20)) || return 1
    # After comm: state, ppid, pgrp, session, ..., starttime.
    printf '%s %s %s %s\n' "$pid" "${fields[2]}" "${fields[3]}" "${fields[19]}"
}
process_group_members() {
    local pgid="$1" sid="$2"
    ps -A -o pid=,ppid=,pgid=,sid=,rss=,stat= 2>/dev/null | awk -v pgid="$pgid" -v sid="$sid" '
        NF >= 6 && $1 ~ /^[0-9]+$/ && $3 == pgid {
            if ($4 != sid) unsafe = 1
            print $1, $2, $3, $4, $5, $6
        }
        END { if (unsafe) exit 1 }
    '
}
process_group_active_members() {
    local members
    members="$(process_group_members "$1" "$2")" || return 1
    printf '%s\n' "$members" | awk 'NF >= 6 && $6 !~ /^Z/ { print }'
}
worker_group_is_owned() {
    [[ "$worker_pid" =~ ^[0-9]+$ && "$worker_pgid" =~ ^[0-9]+$ && "$worker_sid" =~ ^[0-9]+$ \
        && "$worker_starttime" =~ ^[0-9]+$ && "$parent_pgid" =~ ^[0-9]+$ && "$parent_sid" =~ ^[0-9]+$ ]] || return 1
    ((worker_pid == worker_pgid && worker_sid == worker_pgid \
        && worker_pgid != parent_pgid && worker_sid != parent_sid)) || return 1
    local current current_pid current_pgid current_sid current_start
    current="$(proc_identity "$worker_pid" 2>/dev/null || true)"
    if [[ -n "$current" ]]; then
        read -r current_pid current_pgid current_sid current_start <<< "$current"
        [[ "$current_pid" == "$worker_pid" && "$current_pgid" == "$worker_pgid" \
            && "$current_sid" == "$worker_sid" && "$current_start" == "$worker_starttime" ]] || return 1
    fi
    process_group_members "$worker_pgid" "$worker_sid" >/dev/null
}
send_worker_group_signal() {
    local signal="$1" members
    worker_group_is_owned || return 1
    members="$(process_group_active_members "$worker_pgid" "$worker_sid")" || return 1
    [[ -n "$members" ]] || return 0
    kill -s "$signal" -- "-$worker_pgid"
}
process_group_inventory() {
    local rows pid ppid pgid sid rss uid comm vmswap output=''
    rows="$(ps -A -o pid=,ppid=,pgid=,sid=,rss=,uid=,comm= 2>/dev/null)" || { printf 'unavailable'; return; }
    while read -r pid ppid pgid sid rss uid comm _; do
        [[ "$pid" =~ ^[0-9]+$ ]] || continue
        vmswap="$(awk '$1 == "VmSwap:" { print $2; exit }' "/proc/$pid/status" 2>/dev/null || true)"
        [[ "$vmswap" =~ ^[0-9]+$ ]] || vmswap=unknown
        output+="${output:+;}pid=$pid,ppid=$ppid,pgid=$pgid,sid=$sid,uid=$uid,rss_kb=$rss,vmswap_kb=$vmswap,comm=${comm:-unknown}"
    done <<< "$rows"
    printf '%s' "${output:-none}"
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
postgres_process_rss_kb() {
    local process_list
    # Android truncates comm for the postmaster and decorates worker titles;
    # match executable/argv text in one ps pass instead of relying on pgrep -x.
    process_list="$(ps -A -o pid=,rss=,args= 2>/dev/null)" || { printf 'unknown\n'; return; }
    printf '%s\n' "$process_list" | awk '
        NF >= 3 {
            rss = $2
            $1 = $2 = ""
            sub(/^[[:space:]]+/, "")
            if ($0 ~ /(^|[^[:alnum:]_])postgres([[:space:]]|$)/ || $0 ~ /^postgres:/) total += rss
        }
        END { print total + 0 }
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

watch_file="$(printenv BIYAHE_RESOURCE_WATCH_FILE 2>/dev/null || true)"
progress_file="$(printenv BIYAHE_RESOURCE_PROGRESS_FILE 2>/dev/null || true)"
watch_db_bytes=not_configured
watch_journal_bytes=not_configured
watch_wal_bytes=not_configured
record "operation=$operation resource_preflight=starting samples=$preflight_samples interval_seconds=$preflight_interval_seconds min_memavailable_kb=$min_mem_kb min_swapfree_kb=$min_swap_kb min_diskfree_kb=$min_disk_kb"
preflight_block_reason=
initial_mem=unknown
initial_swap=unknown
initial_cached=unknown
initial_reclaimable=unknown
initial_disk=unknown
minimum_preflight_mem=unknown
minimum_preflight_swap=unknown
minimum_preflight_disk=unknown
for ((sample = 1; sample <= preflight_samples; sample++)); do
    sample_mem="$(memavailable_kb)"
    sample_swap="$(swapfree_kb)"
    sample_cached="$(meminfo_value_kb Cached)"
    sample_reclaimable="$(meminfo_value_kb SReclaimable)"
    sample_swapcached="$(meminfo_value_kb SwapCached)"
    [[ -n "$sample_swapcached" ]] || sample_swapcached=unknown
    sample_disk="$(diskfree_kb)"
    sample_postgres_rss="$(postgres_process_rss_kb)"
    sample_processes="$(process_group_inventory)"
    if ((sample == 1)); then
        initial_mem="$sample_mem"; initial_swap="$sample_swap"
        initial_cached="$sample_cached"; initial_reclaimable="$sample_reclaimable"
        initial_disk="$sample_disk"
        minimum_preflight_mem="$sample_mem"; minimum_preflight_swap="$sample_swap"
        minimum_preflight_disk="$sample_disk"
    else
        if [[ "$sample_mem" =~ ^[0-9]+$ ]] && { [[ ! "$minimum_preflight_mem" =~ ^[0-9]+$ ]] || ((sample_mem < minimum_preflight_mem)); }; then minimum_preflight_mem="$sample_mem"; fi
        if [[ "$sample_swap" =~ ^[0-9]+$ ]] && { [[ ! "$minimum_preflight_swap" =~ ^[0-9]+$ ]] || ((sample_swap < minimum_preflight_swap)); }; then minimum_preflight_swap="$sample_swap"; fi
        if [[ "$sample_disk" =~ ^[0-9]+$ ]] && { [[ ! "$minimum_preflight_disk" =~ ^[0-9]+$ ]] || ((sample_disk < minimum_preflight_disk)); }; then minimum_preflight_disk="$sample_disk"; fi
    fi
    record "resource_preflight_sample=$sample/$preflight_samples memavailable_kb=$sample_mem cached_kb=$sample_cached sreclaimable_kb=$sample_reclaimable swapcached_kb=$sample_swapcached swapfree_kb=$sample_swap postgres_rss_kb=$sample_postgres_rss diskfree_kb=$sample_disk process_inventory=$sample_processes"
    if [[ ! "$sample_mem" =~ ^[0-9]+$ || ! "$sample_swap" =~ ^[0-9]+$ || ! "$sample_disk" =~ ^[0-9]+$ ]]; then
        preflight_block_reason='resource_metric_unavailable'
    elif ((sample_mem < min_mem_kb)); then
        preflight_block_reason="memavailable_${sample_mem}_below_${min_mem_kb}"
    elif ((min_swap_kb > 0 && sample_swap < min_swap_kb)); then
        preflight_block_reason="swapfree_${sample_swap}_below_${min_swap_kb}"
    elif ((sample_disk < min_disk_kb)); then
        preflight_block_reason="diskfree_${sample_disk}_below_${min_disk_kb}"
    fi
    if ((sample < preflight_samples)); then sleep "$preflight_interval_seconds"; fi
done
record "operation=$operation preflight_summary=min_memavailable_kb=$minimum_preflight_mem min_swapfree_kb=$minimum_preflight_swap min_diskfree_kb=$minimum_preflight_disk"
if [[ -n "$preflight_block_reason" ]]; then
    record "operation=blocked; sustained resource preflight failed reason=$preflight_block_reason command_not_started=yes"
    exit 4
fi
initial_watch_bytes=0
if [[ -n "$watch_file" ]]; then
    watch_db_bytes="$(file_size_bytes "$watch_file")"
    watch_journal_bytes="$(file_size_bytes "$watch_file-journal")"
    watch_wal_bytes="$(file_size_bytes "$watch_file-wal")"
    for bytes in "$watch_db_bytes" "$watch_journal_bytes" "$watch_wal_bytes"; do
        if [[ "$bytes" =~ ^[0-9]+$ ]]; then initial_watch_bytes=$((initial_watch_bytes + bytes)); else initial_watch_bytes=unknown; break; fi
    done
fi
record "operation=$operation preflight=PASS preflight_memavailable_kb=$initial_mem cached_kb=$initial_cached sreclaimable_kb=$initial_reclaimable swapfree_kb=$initial_swap diskfree_kb=$initial_disk postgres_rss_kb=$(postgres_process_rss_kb) sqlite_bytes=$watch_db_bytes sqlite_journal_bytes=$watch_journal_bytes sqlite_wal_bytes=$watch_wal_bytes producer_consumer_progress=$(progress_sample "$progress_file") budgets_min_memavailable_kb=$min_mem_kb min_swapfree_kb=$min_swap_kb min_diskfree_kb=$min_disk_kb max_process_tree_rss_kb=$max_rss_kb"
export BIYAHE_RESOURCE_GUARD_PREFLIGHT=PASS
export BIYAHE_RESOURCE_GUARD_PREFLIGHT_SAMPLES="$preflight_samples"
export BIYAHE_RESOURCE_GUARD_PREFLIGHT_SPACING_SECONDS="$preflight_interval_seconds"
export BIYAHE_RESOURCE_GUARD_MIN_MEMAVAILABLE_KB="$minimum_preflight_mem"
export BIYAHE_RESOURCE_GUARD_MIN_SWAPFREE_KB="$minimum_preflight_swap"
export BIYAHE_RESOURCE_GUARD_CONFIGURED_MIN_MEMAVAILABLE_KB="$min_mem_kb"
export BIYAHE_RESOURCE_GUARD_CONFIGURED_MIN_SWAPFREE_KB="$min_swap_kb"
export BIYAHE_RESOURCE_GUARD_BREACH_SAMPLES="$breach_samples"
export BIYAHE_RESOURCE_GUARD_MAX_PROCESS_TREE_RSS_KB="$max_rss_kb"

[[ ! -e "$log_path.runner" ]] || { record 'operation=blocked; runner PID marker already exists'; exit 2; }
worker_output="$log_path.worker.log"
worker_identity_file="$log_path.worker.identity"
worker_release_file="$log_path.worker.release"
worker_abort_file="$log_path.worker.abort"
for path in "$worker_output" "$worker_identity_file" "$worker_release_file" "$worker_abort_file"; do
    [[ ! -e "$path" ]] || { record "operation=blocked; refusing to overwrite worker artifact path=$path"; exit 2; }
done
: > "$worker_output"
runner_pid=
worker_pid=
worker_pgid=
worker_sid=
worker_starttime=
parent_pgid=
parent_sid=
terminate_owned_worker_group() {
    local reason="$1" waited=0 members escalation=NO
    if ! worker_group_is_owned; then
        record "worker_group_cancel=BLOCKED reason=$reason ownership_check=failed"
        return 1
    fi
    members="$(process_group_active_members "$worker_pgid" "$worker_sid")" || {
        record "worker_group_cancel=BLOCKED reason=$reason process_inventory=unavailable"
        return 1
    }
    if [[ -z "$members" ]]; then
        record "worker_group_cancel=NOT_NEEDED reason=$reason pgid=$worker_pgid"
        return 0
    fi
    if ! send_worker_group_signal TERM; then
        record "worker_group_cancel=BLOCKED reason=$reason signal=TERM ownership_check=failed"
        return 1
    fi
    record "worker_group_stop=SIGTERM reason=$reason pgid=$worker_pgid sid=$worker_sid"
    while ((waited < cancel_grace_seconds)); do
        members="$(process_group_active_members "$worker_pgid" "$worker_sid" 2>/dev/null || true)"
        [[ -n "$members" ]] || break
        sleep 1
        waited=$((waited + 1))
    done
    members="$(process_group_active_members "$worker_pgid" "$worker_sid" 2>/dev/null || true)"
    if [[ -n "$members" ]]; then
        if ! send_worker_group_signal KILL; then
            record "worker_group_cancel=BLOCKED reason=$reason escalation=SIGKILL ownership_check=failed"
            return 1
        fi
        escalation=YES
        record "worker_group_stop=SIGKILL reason=$reason pgid=$worker_pgid sid=$worker_sid grace_seconds=$waited"
        waited=0
        while ((waited < 5)); do
            members="$(process_group_active_members "$worker_pgid" "$worker_sid" 2>/dev/null || true)"
            [[ -n "$members" ]] || break
            sleep 1
            waited=$((waited + 1))
        done
        members="$(process_group_active_members "$worker_pgid" "$worker_sid" 2>/dev/null || true)"
        if [[ -n "$members" ]]; then
            record "worker_group_cancel=INCOMPLETE reason=$reason escalation=$escalation members=${members//$'\n'/,}"
            return 1
        fi
    fi
    record "worker_group_cancel=COMPLETE reason=$reason escalation=$escalation"
    return 0
}
cleanup_owned_worker() {
    if worker_group_is_owned; then
        members="$(process_group_active_members "$worker_pgid" "$worker_sid" 2>/dev/null || true)"
        if [[ -n "$members" ]]; then
            terminate_owned_worker_group 'guard_cleanup' || true
        fi
    elif [[ -n "$runner_pid" && -n "$worker_abort_file" && ! -e "$worker_release_file" ]]; then
        : > "$worker_abort_file"
        record 'worker_bootstrap=ABORTED before command release; group ownership could not be verified'
    fi
}
cleanup() {
    local status=$?
    trap - EXIT INT TERM HUP
    cleanup_owned_worker
    if [[ -n "$runner_pid" ]]; then
        wait "$runner_pid" 2>/dev/null || true
    fi
    rm -f -- "$log_path.runner" "$worker_identity_file" "$worker_release_file" "$worker_abort_file"
    exit "$status"
}
trap cleanup EXIT INT TERM HUP

parent_identity="$(proc_identity "$$" 2>/dev/null || true)"
read -r _ parent_pgid parent_sid _ <<< "$parent_identity"
worker_entry='identity_file=$1; release_file=$2; abort_file=$3; shift 3
stat_line="$(cat /proc/$$/stat)" || exit 125
rest="${stat_line##*) }"
read -r -a fields <<< "$rest"
((${#fields[@]} >= 20)) || exit 125
worker_pgid="${fields[2]}"; worker_sid="${fields[3]}"; worker_start="${fields[19]}"
tmp="$identity_file.tmp.$$"
printf "%s %s %s %s\\n" "$$" "$worker_pgid" "$worker_sid" "$worker_start" > "$tmp" || exit 125
mv "$tmp" "$identity_file" || exit 125
while [[ ! -e "$release_file" ]]; do
    [[ ! -e "$abort_file" ]] || exit 125
    sleep 0.1
done
exec "$@"'
record "operation=started runner_command=$* worker_log=$worker_output"
setsid --wait bash -c "$worker_entry" biyahe-worker "$worker_identity_file" "$worker_release_file" "$worker_abort_file" "$@" >"$worker_output" 2>&1 &
runner_pid=$!
printf '%s\n' "$runner_pid" > "$log_path.runner"
identity_wait=0
while [[ ! -s "$worker_identity_file" ]] && ((identity_wait < 50)) && process_running "$runner_pid"; do
    sleep 0.1
    identity_wait=$((identity_wait + 1))
done
if [[ -s "$worker_identity_file" ]]; then
    read -r worker_pid worker_pgid worker_sid worker_starttime < "$worker_identity_file"
fi
if ! worker_group_is_owned; then
    record "operation=blocked; worker process group isolation verification failed runner_pid=$runner_pid worker_pid=${worker_pid:-unknown} parent_pgid=${parent_pgid:-unknown} parent_sid=${parent_sid:-unknown} command_not_released=yes"
    : > "$worker_abort_file"
    wait "$runner_pid" || true
    exit 5
fi
: > "$worker_release_file"
record "worker_group=verified pid=$worker_pid pgid=$worker_pgid sid=$worker_sid parent_pgid=$parent_pgid parent_sid=$parent_sid starttime=$worker_starttime"
record "runner_pid=$runner_pid worker_pid=$worker_pid session_id=$worker_sid"

breach_count=0
stop_reason=
peak_rss_kb=0
peak_postgres_rss_kb=0
min_observed_mem_kb="$initial_mem"
min_observed_disk_kb="$initial_disk"
min_observed_swap_kb="$initial_swap"
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
    postgres_rss_now="$(postgres_process_rss_kb)"
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
    if [[ "$postgres_rss_now" =~ ^[0-9]+$ ]] && ((postgres_rss_now > peak_postgres_rss_kb)); then peak_postgres_rss_kb="$postgres_rss_now"; fi
    if [[ "$swap_now" =~ ^[0-9]+$ ]] && { [[ ! "$min_observed_swap_kb" =~ ^[0-9]+$ ]] || ((swap_now < min_observed_swap_kb)); }; then min_observed_swap_kb="$swap_now"; fi
    record "sample_elapsed_seconds=$elapsed process_tree_rss_kb=$rss_now peak_rss_kb=$peak_rss_kb postgres_rss_kb=$postgres_rss_now peak_postgres_rss_kb=$peak_postgres_rss_kb memavailable_kb=$mem_now min_memavailable_kb=$min_observed_mem_kb cached_kb=$cached_now sreclaimable_kb=$reclaimable_now swapfree_kb=$swap_now min_swapfree_kb=$min_observed_swap_kb diskfree_kb=$disk_now min_diskfree_kb=$min_observed_disk_kb sqlite_bytes=$watch_db_bytes sqlite_journal_bytes=$watch_journal_bytes sqlite_wal_bytes=$watch_wal_bytes sqlite_growth_bytes=$watched_growth producer_consumer_progress=$progress_now"

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
    elif ((min_swap_kb > 0)) && { [[ ! "$swap_now" =~ ^[0-9]+$ ]] || ((swap_now < min_swap_kb)); }; then
        breach_reason="swapfree_"${swap_now:-unknown}"_below_"$min_swap_kb
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
                producer_identity_before="$(proc_identity "$producer_pid" 2>/dev/null || true)"
                producer_identity_after="$(proc_identity "$producer_pid" 2>/dev/null || true)"
                if [[ -n "$producer_identity_before" && "$producer_identity_before" == "$producer_identity_after" ]] \
                    && is_descendant "$runner_pid" "$producer_pid"; then
                    kill -TERM "$producer_pid" 2>/dev/null || true
                    record "producer_stop=SIGTERM pid=$producer_pid command_match=yes owned_descendant=yes"
                else
                    record "producer_stop=BLOCKED pid=$producer_pid reason=identity_changed_or_not_owned"
                fi
                ;;
            *)
                record "producer_stop=blocked pid=$producer_pid command_match=no"
                ;;
        esac
    else
        record 'producer_stop=blocked; cancellation PID is absent or is not a worker descendant'
    fi
    terminate_owned_worker_group 'resource_budget' || true
    worker_status=0
    wait "$runner_pid" || worker_status=$?
    worker_signal=NONE
    case "$worker_status" in
        143) worker_signal=SIGTERM ;;
        137) worker_signal=SIGKILL ;;
        130) worker_signal=SIGINT ;;
    esac
    if [[ -s "$worker_output" ]]; then
        while IFS= read -r line; do record "worker: $line"; done < <(tail -n 30 "$worker_output")
    fi
    record "operation=stopped_for_resource_budget worker_exit=$worker_status worker_exit_signal=$worker_signal worker_log=$worker_output peak_rss_kb=$peak_rss_kb peak_postgres_rss_kb=$peak_postgres_rss_kb min_memavailable_kb=$min_observed_mem_kb min_swapfree_kb=$min_observed_swap_kb min_diskfree_kb=$min_observed_disk_kb"
    exit 6
fi

worker_status=0
wait "$runner_pid" || worker_status=$?
if [[ -s "$worker_output" ]]; then
    while IFS= read -r line; do record "worker: $line"; done < <(tail -n 30 "$worker_output")
fi
record "operation=complete worker_exit=$worker_status worker_log=$worker_output peak_rss_kb=$peak_rss_kb peak_postgres_rss_kb=$peak_postgres_rss_kb min_memavailable_kb=$min_observed_mem_kb min_swapfree_kb=$min_observed_swap_kb min_diskfree_kb=$min_observed_disk_kb"
if process_group_active_members "$worker_pgid" "$worker_sid" 2>/dev/null | grep -q .; then
    record 'operation=complete worker_left_owned_children=YES; canceling only the verified worker group'
    terminate_owned_worker_group 'worker_exit_cleanup' || true
fi
exit "$worker_status"
