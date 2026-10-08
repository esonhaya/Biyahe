#!/usr/bin/env bash
set -euo pipefail
umask 077

usage() {
    cat <<'EOF'
Usage: observe-postgres.sh [--start] [--duration-seconds N] [--interval-seconds N] [--log PATH]

Observe this repository's Termux PostgreSQL cluster without restarting it.
--start permits one normal pg_ctl start when the cluster is stopped and all
resource and stale-PID checks pass. The observer never restarts or stops it.

Defaults: observe for 300 seconds, probe every 15 seconds, write a new private
log under api/.runtime. Resource gates for --start can be configured with
BIYAHE_PG_MIN_MEMAVAILABLE_KB, BIYAHE_PG_MIN_SWAPFREE_KB, and
BIYAHE_PG_MIN_DISKFREE_KB.
EOF
}

start_requested=0
duration=300
interval=15
requested_log=
while (($#)); do
    case "$1" in
        --start) start_requested=1; shift ;;
        --duration-seconds)
            (($# >= 2)) || { usage >&2; exit 2; }
            duration="$2"; shift 2 ;;
        --interval-seconds)
            (($# >= 2)) || { usage >&2; exit 2; }
            interval="$2"; shift 2 ;;
        --log)
            (($# >= 2)) || { usage >&2; exit 2; }
            requested_log="$2"; shift 2 ;;
        --help|-h) usage; exit 0 ;;
        *) printf 'Unknown option: %s\n' "$1" >&2; usage >&2; exit 2 ;;
    esac
done

if [[ ! "$duration" =~ ^[0-9]+$ ]] || ((duration < 1 || duration > 3600)); then
    printf 'Duration must be 1..3600 seconds.\n' >&2; exit 2;
fi
if [[ ! "$interval" =~ ^[0-9]+$ ]] || ((interval < 5 || interval > 300)); then
    printf 'Interval must be 5..300 seconds.\n' >&2; exit 2;
fi

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
api_dir="$(cd "$script_dir/.." && pwd)"
runtime_dir="${BIYAHE_PG_RUNTIME_DIR:-$api_dir/.runtime}"
pgdata="${PGDATA:-$runtime_dir/postgres}"
socket_dir="${BIYAHE_PG_SOCKET_DIR:-$runtime_dir}"
port="${BIYAHE_PG_PORT:-55432}"
pg_ctl_bin="${PG_CTL:-pg_ctl}"
pg_isready_bin="${PG_ISREADY:-pg_isready}"
server_log="${BIYAHE_PG_SERVER_LOG:-$runtime_dir/postgres.log}"
min_mem_kb="${BIYAHE_PG_MIN_MEMAVAILABLE_KB:-870400}"
min_swap_kb="${BIYAHE_PG_MIN_SWAPFREE_KB:-262144}"
min_disk_kb="${BIYAHE_PG_MIN_DISKFREE_KB:-1048576}"
pid_file="$pgdata/postmaster.pid"

for threshold in "$min_mem_kb" "$min_swap_kb" "$min_disk_kb"; do
    [[ "$threshold" =~ ^[0-9]+$ ]] || { printf 'Resource thresholds must be nonnegative KiB integers.\n' >&2; exit 2; }
done
if [[ ! "$port" =~ ^[0-9]+$ ]] || ((port < 1 || port > 65535)); then
    printf 'Invalid PostgreSQL port.\n' >&2
    exit 2
fi
[[ -d "$pgdata" ]] || { printf 'PostgreSQL data directory does not exist: %s\n' "$pgdata" >&2; exit 2; }
command -v "$pg_ctl_bin" >/dev/null || { printf 'pg_ctl is unavailable.\n' >&2; exit 2; }
command -v "$pg_isready_bin" >/dev/null || { printf 'pg_isready is unavailable.\n' >&2; exit 2; }

memavailable_kb() { awk '$1 == "MemAvailable:" { print $2; exit }' /proc/meminfo; }
swapfree_kb() { awk '$1 == "SwapFree:" { print $2; exit }' /proc/meminfo; }
diskfree_kb() { df -Pk "$pgdata" | awk 'NR == 2 { print $4; exit }'; }
server_pid() {
    [[ -r "$pid_file" ]] || return 1
    IFS= read -r found_pid < "$pid_file" || true
    [[ "${found_pid:-}" =~ ^[0-9]+$ ]] || return 1
    printf '%s\n' "$found_pid"
}
server_running() { "$pg_ctl_bin" -D "$pgdata" status >/dev/null 2>&1; }
probe_readiness() {
    local probe_elapsed="$1"
    if "$pg_isready_bin" -h "$socket_dir" -p "$port" -t 2 >/dev/null 2>&1; then
        ready=yes
        last_successful_probe="$probe_elapsed"
    else
        ready=no
        if [[ -z "$first_failed_probe" ]]; then first_failed_probe="$probe_elapsed"; fi
    fi
}

if [[ -n "$requested_log" ]]; then
    observation_log="$requested_log"
else
    mkdir -p "$runtime_dir"
    observation_log="$runtime_dir/postgres-observation-$(date -u +%Y%m%dT%H%M%SZ)-$$.log"
fi
[[ ! -e "$observation_log" ]] || { printf 'Refusing to overwrite existing observation log: %s\n' "$observation_log" >&2; exit 2; }
mkdir -p "$(dirname "$observation_log")"

record() {
    local line
    line="$(date -u +%Y-%m-%dT%H:%M:%SZ) $*"
    printf '%s\n' "$line" | tee -a "$observation_log"
}

record "observer=started pid=$$ ppid=$PPID duration_seconds=$duration interval_seconds=$interval"
record "cluster=\"$pgdata\" socket=\"$socket_dir\" port=$port server_log=\"$server_log\""
record "thresholds_start_memavailable_kb=$min_mem_kb swapfree_kb=$min_swap_kb diskfree_kb=$min_disk_kb"
initial_mem="$(memavailable_kb)"
initial_swap="$(swapfree_kb)"
initial_disk="$(diskfree_kb)"
record "resources_initial memavailable_kb=${initial_mem:-unknown} swapfree_kb=${initial_swap:-unknown} diskfree_kb=${initial_disk:-unknown}"
server_log_bytes_before=0
[[ -f "$server_log" ]] && server_log_bytes_before="$(wc -c < "$server_log" | tr -d '[:space:]')"
record "server_log_bytes_before=${server_log_bytes_before:-unknown}"
record "observer_process=$(ps -p "$$" -o pid=,ppid=,pgid=,sid=,stat=,rss=,args= 2>/dev/null || true)"

if server_running; then
    record 'startup=already_running; no start command issued'
else
    stale_pid=
    stale_pid_absent=0
    stale_pid_unsafe=0
    if [[ -e "$pid_file" ]]; then
        stale_pid="$(head -n 1 "$pid_file" 2>/dev/null || true)"
        if [[ "${stale_pid:-}" =~ ^[0-9]+$ ]] && kill -0 "$stale_pid" 2>/dev/null; then
            record "pid_file=present process_exists=yes pid=$stale_pid"
            stale_pid_unsafe=1
        elif [[ "${stale_pid:-}" =~ ^[0-9]+$ ]] && [[ -e "/proc/$stale_pid" ]]; then
            record "pid_file=present process_identity_uncertain=yes pid=$stale_pid; file_left_untouched=yes"
            stale_pid_unsafe=1
        elif [[ "${stale_pid:-}" =~ ^[0-9]+$ ]] && ((stale_pid > 1)); then
            record "pid_file=stale pid=$stale_pid process_absent=yes; PostgreSQL may handle it during normal startup"
            stale_pid_absent=1
        else
            record "pid_file=present invalid_or_unreadable=yes first_line=${stale_pid:-unreadable}; file_left_untouched=yes"
            stale_pid_unsafe=1
        fi
    else
        record 'pid_file=absent'
    fi
    if ((start_requested == 0)); then
        record 'startup=not_requested; cluster is not running'
        exit 3
    fi
    if ((stale_pid_unsafe == 1)); then
        record "startup=blocked; PID file process is present or uncertain; no file changed"
        exit 4
    fi
    ((stale_pid_absent == 0)) || record 'stale_pid_action=left_to_postgresql_normal_startup; observer will not edit PID file'
    mem_now="$(memavailable_kb)"
    swap_now="$(swapfree_kb)"
    disk_now="$(diskfree_kb)"
    record "preflight memavailable_kb=${mem_now:-unknown} swapfree_kb=${swap_now:-unknown} diskfree_kb=${disk_now:-unknown}"
    [[ "$mem_now" =~ ^[0-9]+$ && "$swap_now" =~ ^[0-9]+$ && "$disk_now" =~ ^[0-9]+$ ]] || {
        record 'startup=blocked; resource metrics unavailable'; exit 4;
    }
    if ((mem_now < min_mem_kb || swap_now < min_swap_kb || disk_now < min_disk_kb)); then
        record 'startup=blocked; configured resource gate not met; no pg_ctl start issued'
        exit 4
    fi

    start_timeout="${BIYAHE_PG_START_TIMEOUT_SECONDS:-180}"
    if [[ ! "$start_timeout" =~ ^[0-9]+$ ]] || ((start_timeout < 30 || start_timeout > 600)); then
        record 'startup=blocked; start timeout must be 30..600 seconds'
        exit 2
    fi
    start_output="$(mktemp "$runtime_dir/.postgres-start.XXXXXX")"
    record "startup=one_normal_pg_ctl_attempt timeout_seconds=$start_timeout"
    start_status=0
    "$pg_ctl_bin" -D "$pgdata" -o "-p $port -k $socket_dir" -l "$server_log" -w -t "$start_timeout" start >"$start_output" 2>&1 &
    pg_ctl_pid=$!
    record "pg_ctl_pid=$pg_ctl_pid"
    startup_elapsed=0
    last_successful_probe=
    first_failed_probe=
    while kill -0 "$pg_ctl_pid" 2>/dev/null; do
        pg_ctl_state="$(awk '{print $3; exit}' "/proc/$pg_ctl_pid/stat" 2>/dev/null || true)"
        [[ "$pg_ctl_state" == Z || -z "$pg_ctl_state" ]] && break
        sleep 5
        startup_elapsed=$((startup_elapsed + 5))
        start_mem="$(memavailable_kb)"
        start_swap="$(swapfree_kb)"
        start_disk="$(diskfree_kb)"
        startup_server_pid="$(server_pid || true)"
        probe_readiness "$startup_elapsed"
        if [[ -n "$startup_server_pid" ]] && kill -0 "$startup_server_pid" 2>/dev/null; then
            startup_rss="$(awk '$1 == "VmRSS:" { print $2; exit }' "/proc/$startup_server_pid/status" 2>/dev/null || true)"
            startup_ppid="$(awk '$1 == "PPid:" { print $2; exit }' "/proc/$startup_server_pid/status" 2>/dev/null || true)"
        else
            startup_rss=unknown
            startup_ppid=unknown
        fi
        record "startup_probe_elapsed_seconds=$startup_elapsed pg_ctl_pid=$pg_ctl_pid postgres_pid=${startup_server_pid:-not-yet-created} postgres_ppid=$startup_ppid postgres_rss_kb=$startup_rss readiness=$ready memavailable_kb=${start_mem:-unknown} swapfree_kb=${start_swap:-unknown} diskfree_kb=${start_disk:-unknown}"
    done
    wait "$pg_ctl_pid" || start_status=$?
    if [[ -f "$start_output" ]]; then
        while IFS= read -r line; do record "pg_ctl: $line"; done < "$start_output"
        rm -f -- "$start_output"
    fi
    record "pg_ctl_start_exit=$start_status"
    if ! server_running; then
        if [[ -f "$server_log" ]]; then
            while IFS= read -r line; do record "server_log: $line"; done < <(tail -n 20 "$server_log")
        fi
        record 'startup=failed_or_not_ready; no retry issued'
        exit 5
    fi
    record 'startup=server_detected_after_single_attempt'
fi

ready_pid="$(server_pid || true)"
if [[ -z "$ready_pid" ]]; then
    record 'observation=blocked; pg_ctl reports running but postmaster.pid is unreadable'
    exit 5
fi
record "postgres_pid=$ready_pid"
last_successful_probe="${last_successful_probe:-}"
first_failed_probe="${first_failed_probe:-}"
probe_readiness 0
record "readiness_initial=$ready"
record "readiness_first_failed_elapsed_seconds=${first_failed_probe:-none} readiness_last_success_elapsed_seconds=${last_successful_probe:-none}"

elapsed=0
while ((elapsed < duration)); do
    sleep "$interval"
    elapsed=$((elapsed + interval))
    now_pid="$(server_pid || true)"
    mem_now="$(memavailable_kb)"
    swap_now="$(swapfree_kb)"
    disk_now="$(diskfree_kb)"
    if [[ -n "$now_pid" ]] && kill -0 "$now_pid" 2>/dev/null; then
        probe_readiness "$elapsed"
        proc_status="/proc/$now_pid/status"
        rss_kb="$(awk '$1 == "VmRSS:" { print $2; exit }' "$proc_status" 2>/dev/null || true)"
        ppid="$(awk '$1 == "PPid:" { print $2; exit }' "$proc_status" 2>/dev/null || true)"
        ps_row="$(ps -p "$now_pid" -o pid=,ppid=,pgid=,sid=,stat=,rss=,args= 2>/dev/null || true)"
        record "probe_elapsed_seconds=$elapsed pid=$now_pid ppid=${ppid:-unknown} rss_kb=${rss_kb:-unknown} readiness=$ready readiness_first_failed_elapsed_seconds=${first_failed_probe:-none} readiness_last_success_elapsed_seconds=${last_successful_probe:-none} memavailable_kb=${mem_now:-unknown} swapfree_kb=${swap_now:-unknown} diskfree_kb=${disk_now:-unknown}"
        [[ -z "$ps_row" ]] || record "process=$ps_row"
        if [[ "${mem_now:-}" =~ ^[0-9]+$ && "${swap_now:-}" =~ ^[0-9]+$ ]] && ((mem_now < 614400 || swap_now < 65536)); then
            record 'resource_alert=critical; observer stopping without signaling PostgreSQL; operator should assess and use normal pg_ctl stop if required'
            exit 6
        fi
    else
        record "probe_elapsed_seconds=$elapsed pid_file_pid=${now_pid:-missing} postgres_process=absent memavailable_kb=${mem_now:-unknown} swapfree_kb=${swap_now:-unknown} diskfree_kb=${disk_now:-unknown}"
        if [[ -f "$server_log" ]]; then
            current_log_bytes="$(wc -c < "$server_log" | tr -d '[:space:]')"
            if [[ "$current_log_bytes" =~ ^[0-9]+$ && "$server_log_bytes_before" =~ ^[0-9]+$ ]] && ((current_log_bytes > server_log_bytes_before)); then
                appended_log="$(tail -c +$((server_log_bytes_before + 1)) "$server_log" | tail -c 8192)"
            else
                appended_log=
            fi
            tail_lines="$(printf '%s\n' "$appended_log" | tail -n 20)"
            if [[ -z "${appended_log//[$'\n\r\t ']/}" ]]; then
                exit_cause='unknown-no-new-postgres-log-evidence'
            elif printf '%s\n' "$tail_lines" | grep -Eqi 'database system is shut down|shutdown complete'; then
                exit_cause='clean-shutdown-evidence'
            elif printf '%s\n' "$tail_lines" | grep -Eqi 'PANIC|segmentation fault|terminated by signal|server process .* was terminated'; then
                exit_cause='postgres-crash-or-signal-evidence-review-required'
            else
                exit_cause='unknown-no-postgres-exit-evidence'
            fi
            while IFS= read -r line; do record "server_log_tail: $line"; done <<< "$tail_lines"
        else
            exit_cause='unknown-server-log-missing'
        fi
        record "process_exit_classification=$exit_cause"
        exit 7
    fi
done

record "observation=complete elapsed_seconds=$elapsed exit_cause=process-remained-present-through-observation"
