#!/usr/bin/env bash
set -euo pipefail
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
mode="${1:-STATIC}"
if (($#)); then shift; fi
exec python3 "$script_dir/run-portable-verification.py" --mode "$mode" "$@"
