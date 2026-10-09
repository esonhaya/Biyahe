#!/usr/bin/env bash
set -euo pipefail

kit_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root=""
while (($#)); do
  case "$1" in
    --repo-root)
      if (($# < 2)); then
        printf '%s\n' 'ERROR: --repo-root requires a checkout path.' >&2
        exit 2
      fi
      repo_root="$2"
      shift 2
      ;;
    --help)
      printf 'Usage: %s [--repo-root CHECKOUT]\n' "$0"
      exit 0
      ;;
    *)
      printf 'ERROR: unknown option: %s\n' "$1" >&2
      exit 2
      ;;
  esac
done

for tool in sha256sum python3 git; do
  if ! command -v "$tool" >/dev/null 2>&1; then
    printf 'ERROR: required verification tool is missing: %s\n' "$tool" >&2
    exit 127
  fi
done

if [[ -z "$repo_root" ]]; then
  repo_root="$(cd "$kit_dir/../.." && pwd)"
fi
repo_root="$(cd "$repo_root" && pwd)"

cd "$kit_dir"
python3 - "$kit_dir" <<'PY'
import sys
from pathlib import Path, PurePosixPath

root = Path(sys.argv[1]).resolve()
sum_file = root / "SHA256SUMS"
if not sum_file.is_file():
    raise SystemExit("ERROR: SHA256SUMS is missing")
listed = set()
for number, line in enumerate(sum_file.read_text().splitlines(), 1):
    if len(line) < 67 or line[64:66] != "  ":
        raise SystemExit(f"ERROR: malformed SHA256SUMS line {number}")
    digest, name = line[:64], line[66:]
    path = PurePosixPath(name)
    if len(digest) != 64 or any(ch not in "0123456789abcdef" for ch in digest):
        raise SystemExit(f"ERROR: malformed digest on SHA256SUMS line {number}")
    if path.is_absolute() or ".." in path.parts or name in listed:
        raise SystemExit(f"ERROR: unsafe or duplicate checksum path on line {number}")
    listed.add(name)
    if not (root / Path(*path.parts)).is_file():
        raise SystemExit(f"ERROR: package file is missing: {name}")
actual = {path.relative_to(root).as_posix() for path in root.rglob("*") if path.is_file() and path.name != "SHA256SUMS"}
if listed != actual:
    missing = sorted(actual - listed)
    extra = sorted(listed - actual)
    raise SystemExit(f"ERROR: checksum inventory differs; unlisted={missing}, absent={extra}")
PY

sha256sum -c SHA256SUMS
python3 -m json.tool manifest.json >/dev/null
python3 - "$kit_dir/manifest.json" "$kit_dir" "$repo_root" <<'PY'
import hashlib
import json
import subprocess
import sys
from pathlib import Path, PurePosixPath

manifest_path, kit_path, repo_path = map(Path, sys.argv[1:])
manifest = json.loads(manifest_path.read_text())
kit = kit_path.resolve()
repo = repo_path.resolve()
expected = manifest.get("repository_revision")
if not isinstance(expected, str) or len(expected) != 40:
    raise SystemExit("ERROR: manifest repository_revision must be a full commit SHA")
actual = subprocess.check_output(["git", "-C", str(repo), "rev-parse", "HEAD"], text=True).strip()
if actual != expected:
    raise SystemExit(f"ERROR: checkout revision mismatch; expected {expected}, got {actual}")

included = manifest.get("included_files")
required = manifest.get("required_checkout_paths")
if not isinstance(included, list) or not isinstance(required, list):
    raise SystemExit("ERROR: manifest file inventories are missing")
for relative in included:
    path = PurePosixPath(relative)
    if path.is_absolute() or ".." in path.parts:
        raise SystemExit(f"ERROR: unsafe included file path: {relative}")
    source = (kit / Path(*path.parts)).resolve()
    if not source.is_file() or kit not in source.parents:
        raise SystemExit(f"ERROR: package source copy is missing or unsafe: {relative}")
for relative in required:
    path = PurePosixPath(relative)
    if path.is_absolute() or ".." in path.parts:
        raise SystemExit(f"ERROR: unsafe checkout path: {relative}")
    checkout_file = (repo / Path(*path.parts)).resolve()
    package_file = (kit / "source-snapshot" / Path(*path.parts)).resolve()
    if not checkout_file.is_file() or not package_file.is_file():
        raise SystemExit(f"ERROR: required checkout or package file is missing: {relative}")
    if hashlib.sha256(checkout_file.read_bytes()).digest() != hashlib.sha256(package_file.read_bytes()).digest():
        raise SystemExit(f"ERROR: package copy does not match pinned checkout: {relative}")

if manifest.get("artifacts_included") is not False:
    raise SystemExit("ERROR: this kit must not silently claim large source artifacts are included")
print(f"PACKAGE_FILES={len(included)}")
print(f"CHECKOUT_REVISION={actual}")
PY

printf 'PORTABLE_KIT_STATIC_VERIFICATION=PASS\n'
printf 'GRAPH_REPLAY=NOT_RUN\nPOSTGRESQL_STAGING=NOT_RUN\nFIELD_VERIFICATION=NOT_RUN\n'
