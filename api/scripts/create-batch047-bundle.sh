#!/usr/bin/env bash
set -euo pipefail
umask 077

repo="${1:?repository path is required}"
output="${2:?final bundle path is required}"
expected_head="${3:?expected full HEAD is required}"
archive_root="$(realpath -m -- "$HOME/storage/external-1/Termux-Archive/Biyahe/Batch047-Recovery")"
output="$(realpath -m -- "$output")"
if [[ ! "$expected_head" =~ ^[0-9a-f]{40}$ || "$output" != "$archive_root"/*/*.bundle ]]; then
    printf 'BUNDLE_STATUS=FAIL reason=invalid_head_or_output_path\n' >&2
    exit 2
fi

final_dir="$(dirname "$output")"
bundle_name="$(basename "$output")"
staging="$(dirname "$final_dir")/.$(basename "$final_dir").building"
staged_bundle="$staging/$bundle_name"
staged_sha="$staging/$bundle_name.sha256"
final_bundle="$final_dir/$bundle_name"

[[ ! -e "$final_dir" ]] || { printf 'Refusing existing final directory: %s\n' "$final_dir" >&2; exit 2; }
[[ ! -e "$staging" ]] || { printf 'Refusing existing staging directory: %s\n' "$staging" >&2; exit 2; }
disk_free_kb="$(df -Pk "$archive_root" | awk 'NR == 2 { print $4; exit }')"
[[ "$disk_free_kb" =~ ^[0-9]+$ && "$disk_free_kb" -ge 131072 ]] || {
    printf 'BUNDLE_STATUS=BLOCKED_RESOURCE reason=archive_disk_free_kb_%s_below_131072\n' "$disk_free_kb" >&2
    exit 3
}

# Git emits one bounded pack-objects child; file-size, process-tree RSS, wall
# time and device memory are also controlled by the outer owned-group guard.
ulimit -f 131072
mkdir -- "$staging"
head="$(git -C "$repo" rev-parse HEAD)"
[[ "$head" == "$expected_head" ]] || { printf 'BUNDLE_STATUS=FAIL expected=%s actual=%s\n' "$expected_head" "$head" >&2; exit 1; }

printf 'BUNDLE_CREATE_BEGIN head=%s\n' "$head"
git -C "$repo" bundle create "$staged_bundle" --all
[[ -s "$staged_bundle" ]] || { printf 'BUNDLE_STATUS=FAIL reason=empty_bundle\n' >&2; exit 1; }
bundle_bytes="$(stat -c '%s' "$staged_bundle")"
((bundle_bytes <= 67108864)) || { printf 'BUNDLE_STATUS=BLOCKED_RESOURCE reason=bundle_size_cap bytes=%s\n' "$bundle_bytes" >&2; exit 3; }
git -C "$repo" bundle verify "$staged_bundle"
git -C "$repo" bundle list-heads "$staged_bundle" > "$staging/heads.txt"
awk -v expected="$expected_head" '$1 == expected && $2 == "refs/heads/main" { found=1 } END { exit !found }' "$staging/heads.txt"
expected_sha="$(sha256sum "$staged_bundle" | awk '{print $1}')"
printf '%s  %s\n' "$expected_sha" "$bundle_name" > "$staged_sha"
sync -f "$staged_bundle"
sync -f "$staged_sha"
(cd "$staging" && sha256sum -c "$bundle_name.sha256")

# mkdir atomically reserves the new unique destination. The following rename
# atomically replaces only this empty directory, never a pre-existing path.
mkdir -- "$final_dir"
mv -T -- "$staging" "$final_dir"

(cd "$final_dir" && sha256sum -c "$bundle_name.sha256")
git -C "$repo" bundle verify "$final_bundle"
git -C "$repo" bundle list-heads "$final_bundle" | awk -v expected="$expected_head" \
    '$1 == expected && $2 == "refs/heads/main" { found=1 } END { exit !found }'
actual_sha="$(sha256sum "$final_bundle" | awk '{print $1}')"
[[ "$actual_sha" == "$expected_sha" ]] || { printf 'BUNDLE_STATUS=FAIL reason=post_publish_hash_mismatch\n' >&2; exit 1; }
printf 'BUNDLE_STATUS=PASS\nBUNDLE=%s\nHEAD=%s\nBYTES=%s\nSHA256=%s\n' \
    "$final_bundle" "$expected_head" "$bundle_bytes" "$actual_sha"
