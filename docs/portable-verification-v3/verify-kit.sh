#!/usr/bin/env bash
set -euo pipefail
kit_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root=""
while (($#)); do
  case "$1" in
    --repo-root) (($# >= 2)) || { printf '%s\n' 'ERROR: --repo-root requires a path.' >&2; exit 2; }; repo_root="$2"; shift 2 ;;
    --help) printf 'Usage: %s [--repo-root CHECKOUT]\n' "$0"; exit 0 ;;
    *) printf 'ERROR: unknown option: %s\n' "$1" >&2; exit 2 ;;
  esac
done
for tool in sha256sum python3 git; do
  command -v "$tool" >/dev/null 2>&1 || { printf 'ERROR: missing required tool: %s\n' "$tool" >&2; exit 127; }
done
if [[ -z "$repo_root" ]]; then repo_root="$(cd "$kit_dir/../.." && pwd)"; fi
repo_root="$(cd "$repo_root" && pwd)"
cd "$kit_dir"
python3 - "$kit_dir" <<'PY'
import json, sys
from pathlib import Path, PurePosixPath
root=Path(sys.argv[1]).resolve()
manifest=json.loads((root/'manifest.json').read_text())
listed=set()
for n,line in enumerate((root/'SHA256SUMS').read_text().splitlines(),1):
    if len(line)<67 or line[64:66]!='  ': raise SystemExit(f'ERROR: malformed checksum line {n}')
    digest,name=line[:64],line[66:]
    p=PurePosixPath(name)
    if len(digest)!=64 or any(c not in '0123456789abcdef' for c in digest) or p.is_absolute() or '..' in p.parts or name in listed:
        raise SystemExit(f'ERROR: unsafe checksum entry {n}')
    listed.add(name)
    target=root.joinpath(*p.parts)
    if target.is_symlink() or not target.is_file(): raise SystemExit(f'ERROR: missing or symlinked package file: {name}')
actual={p.relative_to(root).as_posix() for p in root.rglob('*') if p.is_file() and p.name!='SHA256SUMS'}
if actual!=listed: raise SystemExit(f'ERROR: checksum inventory mismatch unlisted={sorted(actual-listed)} missing={sorted(listed-actual)}')
for relative in manifest.get('included_files',[]):
    p=PurePosixPath(relative)
    if p.is_absolute() or '..' in p.parts or not root.joinpath(*p.parts).is_file(): raise SystemExit(f'ERROR: missing or unsafe included path {relative}')
for relative in manifest.get('required_checkout_paths',[]):
    p=PurePosixPath(relative)
    if p.is_absolute() or '..' in p.parts: raise SystemExit(f'ERROR: unsafe checkout path {relative}')
PY
sha256sum -c SHA256SUMS
python3 -m json.tool manifest.json >/dev/null
python3 - "$kit_dir" "$repo_root" <<'PY'
import hashlib,json,subprocess,sys
from pathlib import Path,PurePosixPath
kit,repo=map(lambda s:Path(s).resolve(),sys.argv[1:])
m=json.loads((kit/'manifest.json').read_text())
actual=subprocess.check_output(['git','-C',str(repo),'rev-parse','HEAD'],text=True).strip()
if actual!=m.get('repository_revision'): raise SystemExit(f'ERROR: expected source revision {m.get("repository_revision")}, got {actual}')
for relative in m['required_checkout_paths']:
    p=PurePosixPath(relative)
    checkout=repo.joinpath(*p.parts)
    packaged=kit/'source-snapshot'
    for part in p.parts: packaged=packaged/part
    if not checkout.is_file() or not packaged.is_file(): raise SystemExit(f'ERROR: missing checkout or snapshot file {relative}')
    if hashlib.sha256(checkout.read_bytes()).digest()!=hashlib.sha256(packaged.read_bytes()).digest(): raise SystemExit(f'ERROR: source snapshot differs from pinned checkout: {relative}')
if m.get('large_artifacts_included') is not False: raise SystemExit('ERROR: large source artifacts must not be bundled')
PY
export PYTHONDONTWRITEBYTECODE=1
smoke_output="$(python3 source-snapshot/api/scripts/offline-evidence-smoke.py)"
printf '%s\n' "$smoke_output" | python3 -c 'import json,sys; r=json.load(sys.stdin); valid=(r.get("status")=="PASS_STATIC_EVIDENCE_ONLY" and r.get("execution_boundaries",{}).get("sqlite_opened") is False and r.get("stored_corpus",{}).get("current_replay_executed") is False); print("NO_DATABASE_SMOKE=PASS_STATIC_EVIDENCE_ONLY" if valid else "ERROR: no-database smoke output is incomplete or claims execution"); sys.exit(0 if valid else 1)'
printf 'PORTABLE_KIT_STATIC_VERIFICATION=PASS\nREAL_GRAPH_REPLAY=NOT_RUN\nPOSTGRESQL_STAGING=NOT_RUN\nEXTERNAL_HOST_EXECUTION=NOT_RUN\nFIELD_VERIFICATION=NOT_RUN\n'
