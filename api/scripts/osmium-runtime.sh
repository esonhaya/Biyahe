#!/usr/bin/env bash

# Termux tools can inherit an unrelated LD_LIBRARY_PATH from their parent app.
# Keep Osmium on the package-managed runtime when PREFIX is available.
run_osmium() {
    local osmium_binary
    osmium_binary="$(command -v osmium)" || return 127

    if [[ -n "${PREFIX:-}" && -d "$PREFIX/lib" ]]; then
        LD_LIBRARY_PATH="$PREFIX/lib" "$osmium_binary" "$@"
    else
        "$osmium_binary" "$@"
    fi
}
