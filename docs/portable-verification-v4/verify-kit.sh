#!/usr/bin/env bash
set -euo pipefail
kit_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
mode=STATIC
suite=""
repo_root=""
output_root="${HOME}/.cache/biyahe/batch042/portable-verification"
while (($#)); do
  case "$1" in
    STATIC|FRONTEND|ADAPTER|CORPUS|FULL) mode="$1"; shift ;;
    --mode) (($# >= 2)) || { printf '%s\n' 'ERROR: --mode requires a value.' >&2; exit 2; }; mode="$2"; shift 2 ;;
    --suite) (($# >= 2)) || { printf '%s\n' 'ERROR: --suite requires a value.' >&2; exit 2; }; suite="$2"; shift 2 ;;
    --repo-root) (($# >= 2)) || { printf '%s\n' 'ERROR: --repo-root requires a path.' >&2; exit 2; }; repo_root="$2"; shift 2 ;;
    --output-root) (($# >= 2)) || { printf '%s\n' 'ERROR: --output-root requires a path.' >&2; exit 2; }; output_root="$2"; shift 2 ;;
    --help) printf 'Usage: %s [STATIC|FRONTEND|ADAPTER|CORPUS|FULL] [--suite SUITE_ID] [--repo-root PINNED_CHECKOUT] [--output-root DIR]\n' "$0"; exit 0 ;;
    *) printf 'ERROR: unknown option: %s\n' "$1" >&2; exit 2 ;;
  esac
done
case "$mode" in STATIC|FRONTEND|ADAPTER|CORPUS|FULL) ;; *) printf 'ERROR: unsupported mode: %s\n' "$mode" >&2; exit 2 ;; esac
for tool in sha256sum python3; do
  command -v "$tool" >/dev/null 2>&1 || { printf 'ERROR: missing required tool: %s\n' "$tool" >&2; exit 127; }
done
cd "$kit_dir"
python3 - "$kit_dir" <<'PY'
import hashlib, json, sys
from pathlib import Path, PurePosixPath
root=Path(sys.argv[1]).resolve()
manifest=json.loads((root/'manifest.json').read_text())
if manifest.get('format') != 'biyahe-portable-offline-verification-kit-v4' or manifest.get('batch') != 42:
    raise SystemExit('ERROR: unsupported portable verification manifest')
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
for relative,digest in manifest.get('executable_source_fingerprints',{}).items():
    p=PurePosixPath(relative)
    if p.is_absolute() or '..' in p.parts or len(digest)!=64: raise SystemExit(f'ERROR: unsafe executable fingerprint {relative}')
    source=root/'source-snapshot'
    for part in p.parts: source=source/part
    if not source.is_file() or hashlib.sha256(source.read_bytes()).hexdigest()!=digest: raise SystemExit(f'ERROR: executable source fingerprint mismatch: {relative}')
if manifest.get('large_artifacts_included') is not False: raise SystemExit('ERROR: large source artifacts must not be bundled')
PY
sha256sum -c SHA256SUMS
python3 -m json.tool manifest.json >/dev/null
source_revision="$(python3 -c 'import json; print(json.load(open("manifest.json"))["executable_source_revision"])')"
if [[ -z "$repo_root" ]]; then
  repo_root="$kit_dir/source-snapshot"
else
  command -v git >/dev/null 2>&1 || { printf '%s\n' 'ERROR: git is required with --repo-root.' >&2; exit 127; }
  repo_root="$(cd "$repo_root" && pwd)"
  python3 - "$kit_dir" "$repo_root" <<'PY'
import hashlib,json,subprocess,sys
from pathlib import Path,PurePosixPath
kit,repo=map(lambda s:Path(s).resolve(),sys.argv[1:])
m=json.loads((kit/'manifest.json').read_text())
actual=subprocess.check_output(['git','-C',str(repo),'rev-parse','HEAD'],text=True).strip()
if actual!=m.get('repository_revision'): raise SystemExit(f'ERROR: expected source revision {m.get("repository_revision")}, got {actual}')
source=m.get('executable_source_revision')
if subprocess.run(['git','-C',str(repo),'merge-base','--is-ancestor',source,actual]).returncode: raise SystemExit('ERROR: executable source revision is not an ancestor of the checkout')
normalized={row['path']:row for row in m.get('normalized_checkout_files',[])}
for relative in m['required_checkout_paths']:
    p=PurePosixPath(relative)
    checkout=repo.joinpath(*p.parts)
    packaged=kit/'source-snapshot'
    for part in p.parts: packaged=packaged/part
    if not checkout.is_file() or not packaged.is_file(): raise SystemExit(f'ERROR: missing checkout or snapshot file {relative}')
    if relative in normalized:
        record=normalized[relative]
        committed=subprocess.check_output(['git','-C',str(repo),'show',f'{actual}:{relative}'])
        if hashlib.sha256(committed).hexdigest()!=record.get('checkout_sha256') or hashlib.sha256(checkout.read_bytes()).hexdigest()!=record.get('checkout_sha256'):
            raise SystemExit(f'ERROR: normalized checkout source differs from pinned Git content: {relative}')
        if hashlib.sha256(packaged.read_bytes()).hexdigest()!=record.get('snapshot_sha256'):
            raise SystemExit(f'ERROR: normalized package snapshot differs from manifest: {relative}')
        if record.get('transformation')!='doctor_evidence_path_relocation':
            raise SystemExit(f'ERROR: unsupported normalized checkout transformation: {relative}')
        original=json.loads(committed)
        bundled=json.loads(packaged.read_bytes())
        evidence={(row['test'],row['kind']):row for row in m.get('packaged_test_evidence',[])}
        expected_evidence=set()
        for test_name,test_row in original.get('tests',{}).items():
            for field,hash_field,kind in (('output_path','output_sha256','stdout.txt'),('resource_log','resource_log_sha256','resource.log')):
                if test_row.get(hash_field) is None: continue
                key=(test_name,kind)
                expected_evidence.add(key)
                artifact=evidence.get(key)
                if artifact is None or artifact.get('sha256')!=test_row[hash_field]:
                    raise SystemExit(f'ERROR: normalized Doctor evidence mapping is missing or inconsistent: {test_name}/{kind}')
                artifact_path=PurePosixPath(artifact['path'])
                if artifact_path.is_absolute() or '..' in artifact_path.parts or not artifact_path.parts or artifact_path.parts[0]!='source-snapshot':
                    raise SystemExit(f'ERROR: unsafe normalized Doctor evidence path: {artifact["path"]}')
                test_row[field]=PurePosixPath(*artifact_path.parts[1:]).as_posix()
        if set(evidence)!=expected_evidence or original!=bundled:
            raise SystemExit(f'ERROR: packaged Doctor differs beyond evidence path relocation: {relative}')
        continue
    if hashlib.sha256(checkout.read_bytes()).digest()!=hashlib.sha256(packaged.read_bytes()).digest(): raise SystemExit(f'ERROR: source snapshot differs from pinned checkout: {relative}')
for relative,expected in m.get('executable_source_fingerprints',{}).items():
    checkout=repo.joinpath(*PurePosixPath(relative).parts)
    committed=subprocess.check_output(['git','-C',str(repo),'show',f'{source}:{relative}'])
    if not checkout.is_file() or hashlib.sha256(checkout.read_bytes()).hexdigest()!=expected or hashlib.sha256(committed).hexdigest()!=expected:
        raise SystemExit(f'ERROR: executable source differs from its pinned revision: {relative}')
PY
fi
export PYTHONDONTWRITEBYTECODE=1
runner_args=(--mode "$mode" --repo-root "$repo_root" --output-root "$output_root" --source-revision "$source_revision")
if [[ -n "$suite" ]]; then runner_args+=(--suite "$suite"); fi
exec python3 "$repo_root/api/scripts/run-portable-verification.py" "${runner_args[@]}"
