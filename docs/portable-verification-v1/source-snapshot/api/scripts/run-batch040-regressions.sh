#!/usr/bin/env bash
set -u

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
web_root="$repo_root/web"
failures=0
checks=0

run_check() {
    local name="$1"
    shift
    checks=$((checks + 1))
    printf 'CHECK_START=%s\n' "$name"
    "$@"
    local result=$?
    printf 'CHECK_RESULT=%s:%s\n' "$name" "$([[ "$result" -eq 0 ]] && printf PASS || printf FAIL)"
    if [[ "$result" -ne 0 ]]; then failures=$((failures + 1)); fi
}

cd "$repo_root" || exit 2
run_check commute_cards node --experimental-strip-types web/scripts/commute-card-harness.mjs
run_check offline_preview node --experimental-strip-types web/scripts/offline-walking-preview-harness.mjs
run_check ride_mode node --experimental-strip-types web/scripts/ride-mode-harness.mjs
run_check validation_ledger node web/scripts/validation-ledger-harness.mjs
run_check ux_contract node web/scripts/ux-contract-harness.mjs
run_check coverage_diagnostics python3 api/scripts/test_summarize_offline_coverage.py
run_check pinned_coverage_report python3 api/scripts/summarize-offline-coverage.py --output-dir="$HOME/.cache/biyahe/batch040/coverage-diagnostics"
run_check offline_adapter_phpunit bash -c 'cd "$1" && php artisan test --filter=OfflineJourneyWalkingPreviewTest' _ "$repo_root/api"
run_check offline_graph_router_phpunit bash -c 'cd "$1" && php artisan test --filter=OfflinePedestrianGraphRouterTest' _ "$repo_root/api"
run_check owned_process_guard_phpunit bash -c 'cd "$1" && php artisan test --filter=BoundedProcessGuardTest' _ "$repo_root/api"
run_check real_cebu_corpus bash -c 'cd "$1" && php artisan biyahe:pedestrian:verify-offline-corpus --output="$2"' _ "$repo_root/api" "$HOME/.cache/biyahe/batch040/offline-corpus.json"

printf 'CHECKS_TOTAL=%s\n' "$checks"
printf 'CHECKS_FAILED=%s\n' "$failures"
if [[ "$failures" -gt 0 ]]; then exit 1; fi
printf 'BATCH040_REGRESSIONS=PASS\n'
