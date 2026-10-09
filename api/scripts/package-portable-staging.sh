#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat <<'EOF'
Usage: package-portable-staging.sh --destination NEW_DIRECTORY --graph FILE --source FILE --backup FILE --bundle FILE --core-commit SHA

Create a no-clobber, checksum-verified portable staging package. The source
PBF is included because the importer revalidates its checksum. No PostgreSQL
data directory, credentials, or production configuration is packaged.
EOF
}

destination=
graph=
source_pbf=
backup=
bundle=
core_commit=
while (($#)); do
    case "$1" in
        --destination) (($# >= 2)) || { usage >&2; exit 2; }; destination="$2"; shift 2 ;;
        --graph) (($# >= 2)) || { usage >&2; exit 2; }; graph="$2"; shift 2 ;;
        --source) (($# >= 2)) || { usage >&2; exit 2; }; source_pbf="$2"; shift 2 ;;
        --backup) (($# >= 2)) || { usage >&2; exit 2; }; backup="$2"; shift 2 ;;
        --bundle) (($# >= 2)) || { usage >&2; exit 2; }; bundle="$2"; shift 2 ;;
        --core-commit) (($# >= 2)) || { usage >&2; exit 2; }; core_commit="$2"; shift 2 ;;
        --help|-h) usage; exit 0 ;;
        *) printf 'Unknown option: %s\n' "$1" >&2; usage >&2; exit 2 ;;
    esac
done
[[ -n "$destination" && -n "$graph" && -n "$source_pbf" && -n "$backup" && -n "$bundle" ]] || { usage >&2; exit 2; }
[[ "$core_commit" =~ ^[a-f0-9]{40}$ ]] || { printf 'Core commit must be a 40-character lowercase Git SHA-1.\n' >&2; exit 2; }
for path in "$graph" "$source_pbf" "$backup" "$bundle"; do
    [[ -f "$path" && -r "$path" ]] || { printf 'Required package input is not a readable file: %s\n' "$path" >&2; exit 2; }
done
command -v python3 >/dev/null 2>&1 || { printf 'Python 3 is required.\n' >&2; exit 2; }
command -v git >/dev/null 2>&1 || { printf 'Git is required to verify the repository bundle.\n' >&2; exit 2; }

repo_dir="$(realpath "$(dirname "$(realpath "$0")")/../..")"
python3 - "$repo_dir" "$destination" "$graph" "$source_pbf" "$backup" "$bundle" "$core_commit" <<'PY'
import datetime
import hashlib
import json
import os
import pathlib
import shutil
import subprocess
import sys

repo = pathlib.Path(sys.argv[1]).resolve()
destination = pathlib.Path(sys.argv[2]).expanduser().absolute()
building = destination.with_name(destination.name + ".building")
graph = pathlib.Path(sys.argv[3]).resolve(strict=True)
source = pathlib.Path(sys.argv[4]).resolve(strict=True)
backup = pathlib.Path(sys.argv[5]).resolve(strict=True)
bundle = pathlib.Path(sys.argv[6]).resolve(strict=True)
core_commit = sys.argv[7]
restriction_audit_path = pathlib.Path(str(graph) + ".restriction-audit.json").resolve(strict=True)
snapshot = "2026-10-07T01:00:21Z"
expected = {
    "source": ("central_visayas-20261007.osm.pbf", "787cac20ecc586b754b1a464643a040566d486457c6a37bc0b11ce683bc66d4d"),
    "graph": ("cebu-walk-central-visayas-20261007-v3.sqlite", "29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8"),
    "backup": ("biyahe-batch024-20261008.dump", "e55af400fde88d0a3434b194e0625e204eb0a2726374eabb8286c152b11a4a92"),
}
if os.path.lexists(destination):
    raise SystemExit(f"PACKAGE_ABORT refusing to overwrite {destination}")
if os.path.lexists(building):
    raise SystemExit(f"PACKAGE_ABORT refusing to overwrite interrupted build directory {building}")

def sha256(path):
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()

actual_inputs = {"source": source, "graph": graph, "backup": backup}
for name, path in actual_inputs.items():
    if sha256(path) != expected[name][1]:
        raise SystemExit(f"PACKAGE_ABORT source hash mismatch: {name}")

head = subprocess.run(["git", "rev-parse", "HEAD"], cwd=repo, check=True, stdout=subprocess.PIPE, text=True).stdout.strip()
if head != core_commit:
    raise SystemExit("PACKAGE_ABORT repository HEAD does not match the declared core commit")
coverage = json.loads((repo / "docs/reports/batch032-cebu-coverage.json").read_text())
restriction_audit = json.loads(restriction_audit_path.read_text())
if coverage.get("source", {}).get("graph_source_sha256") != expected["source"][1]:
    raise SystemExit("PACKAGE_ABORT journey evidence is bound to another source")
if coverage.get("graph_validation", {}).get("artifact_sha256") != expected["graph"][1]:
    raise SystemExit("PACKAGE_ABORT journey evidence is bound to another graph")
if coverage.get("source", {}).get("graph_fingerprint") != "a0d9f1cb8289cf1f9bde3905d1ad8a57124ee41120153b4efc1f5b31e4c03ecd":
    raise SystemExit("PACKAGE_ABORT journey evidence has an unexpected graph fingerprint")
if len(coverage.get("journey_corpus", {}).get("cases", [])) != 10:
    raise SystemExit("PACKAGE_ABORT journey corpus does not contain the expected ten cases")
route_counts = {"ROUTE_FOUND": 0, "ROUTE_UNAVAILABLE": 0}
for case in coverage["journey_corpus"]["cases"]:
    if case.get("status") != "EVALUATED":
        raise SystemExit("PACKAGE_ABORT journey corpus contains a non-evaluated case")
    route_status = case.get("route", {}).get("status")
    if route_status not in route_counts:
        raise SystemExit("PACKAGE_ABORT journey corpus contains an unsupported route status")
    route_counts[route_status] += 1
if route_counts != {"ROUTE_FOUND": 9, "ROUTE_UNAVAILABLE": 1}:
    raise SystemExit("PACKAGE_ABORT journey outcomes differ from the verified Batch032 baseline")
restriction_counts = coverage.get("graph_validation", {}).get("restriction_counts", {})
if ((coverage.get("snap_policy", {}).get("maximum_distance_meters") != 25)
    or (coverage.get("coverage", {}).get("stops", {}).get("total") != 503)
    or (coverage.get("coverage", {}).get("stops", {}).get("with_usable_coordinates") != 503)
    or (coverage.get("coverage", {}).get("stops", {}).get("within_snap_policy") != 436)
    or (coverage.get("coverage", {}).get("stops", {}).get("connected_to_largest_component") != 433)
    or (coverage.get("coverage", {}).get("stops", {}).get("snapped_to_smaller_components") != 3)
    or (coverage.get("coverage", {}).get("stops", {}).get("snap_reason_counts", {}).get("snap_unavailable") != 67)
    or (coverage.get("coverage", {}).get("stops", {}).get("unreachable_or_unsnappable") != 70)
    or (coverage.get("coverage", {}).get("places", {}).get("total") != 37)
    or (coverage.get("coverage", {}).get("places", {}).get("within_snap_policy") != 30)
    or (coverage.get("coverage", {}).get("places", {}).get("snap_reason_counts", {}).get("snap_unavailable") != 7)
    or (coverage.get("coverage", {}).get("places", {}).get("unreachable_or_unsnappable") != 8)
    or (coverage.get("coverage", {}).get("transfers", {}).get("total") != 2)
    or (coverage.get("coverage", {}).get("transfers", {}).get("graph_route_connected") != 2)
    or (coverage.get("coverage", {}).get("transfers", {}).get("records_marked_verified") != 0)
    or (restriction_counts.get("total") != 619)
    or (restriction_counts.get("vehicle_only") != 619)
    or (restriction_counts.get("pedestrian_applicable") != 0)
    or (restriction_counts.get("ambiguous") != 0)
    or (restriction_counts.get("unsupported_applicable") != 0)):
    raise SystemExit("PACKAGE_ABORT Batch032 coverage or stored restriction counts differ from the verified baseline")
source_scan = restriction_audit.get("source_scan", {})
source_restrictions = restriction_audit.get("source_classification_counts", {})
if (restriction_audit.get("format") != "biyahe-pedestrian-restriction-audit-v1"
    or restriction_audit.get("source", {}).get("sha256") != expected["source"][1]
    or restriction_audit.get("source", {}).get("url") != "https://download.openstreetmap.fr/extracts/asia/philippines/central_visayas.osm.pbf"
    or restriction_audit.get("graph", {}).get("content_fingerprint") != "a0d9f1cb8289cf1f9bde3905d1ad8a57124ee41120153b4efc1f5b31e4c03ecd"
    or restriction_audit.get("graph", {}).get("nodes") != 76685
    or restriction_audit.get("graph", {}).get("edges") != 78225
    or source_scan.get("format") != "biyahe-osm-restriction-source-scan-v1"
    or source_scan.get("complete") is not True
    or source_scan.get("producer_exit_code") != 0
    or source_scan.get("source_sha256") != expected["source"][1]
    or source_scan.get("source_snapshot") != snapshot
    or source_scan.get("matching_relation_count") != 648
    or source_scan.get("stored_relation_count") != 619
    or source_scan.get("omitted_relation_count") != 29
    or len(source_scan.get("omitted_relations", [])) != 29
    or source_restrictions.get("total_turn_restrictions") != 648
    or source_restrictions.get("vehicle_only") != 648
    or source_restrictions.get("pedestrian_applicable") != 0
    or source_restrictions.get("ambiguous") != 0
    or source_restrictions.get("unsupported_applicable") != 0
    or source_restrictions.get("conditional") != 33
    or source_restrictions.get("via_node") != 449
    or source_restrictions.get("via_way") != 166
    or source_restrictions.get("malformed_or_complex_members") != 4
    or source_scan.get("pedestrian_specific_tag_counts", {}).get("restriction:foot") != 0
    or source_scan.get("pedestrian_specific_tag_counts", {}).get("restriction:pedestrian") != 0
    or source_scan.get("pedestrian_specific_tag_counts", {}).get("restriction:foot:conditional") != 0
    or source_scan.get("pedestrian_specific_tag_counts", {}).get("restriction:pedestrian:conditional") != 0):
    raise SystemExit("PACKAGE_ABORT complete source restriction evidence is missing, mismatched, or incomplete")
source_files = {
    "docs/README.md": repo / "docs/portable-staging-v1/README.md",
    "config/portable-staging.env.example": repo / "docs/portable-staging-v1/portable-staging.env.example",
    "scripts/run-bounded-process.sh": repo / "api/scripts/run-bounded-process.sh",
    "scripts/package-portable-staging.sh": repo / "api/scripts/package-portable-staging.sh",
    "scripts/verify-portable-staging-package.sh": repo / "api/scripts/verify-portable-staging-package.sh",
    "evidence/batch032-cebu-coverage.json": repo / "docs/reports/batch032-cebu-coverage.json",
    "evidence/batch030-cebu-restriction-audit.json": restriction_audit_path,
    "evidence/batch034-doctor-v34.json": repo / "docs/reports/batch034-doctor-v34-report-final.json",
    "evidence/batch035-doctor-v35.json": repo / "docs/reports/batch035-doctor-v35.json",
    "source/BiyaheDoctorV35.php": repo / "api/app/Console/Commands/BiyaheDoctorV35.php",
    "source/OfflinePedestrianGraphReadinessV35.php": repo / "api/app/Services/Routing/OfflinePedestrianGraphReadinessV35.php",
    "source/BiyaheDoctorV36.php": repo / "api/app/Console/Commands/BiyaheDoctorV36.php",
    "source/OfflinePedestrianGraphReadinessV36.php": repo / "api/app/Services/Routing/OfflinePedestrianGraphReadinessV36.php",
}
for relative, path in source_files.items():
    if not path.is_file():
        raise SystemExit(f"PACKAGE_ABORT required repository file is missing: {path}")
included_paths = [str(path.relative_to(repo)) for path in source_files.values()]
package_source_status = subprocess.run(
    ["git", "status", "--porcelain", "--", *included_paths],
    cwd=repo, check=True, stdout=subprocess.PIPE, text=True,
).stdout
if package_source_status.strip():
    raise SystemExit("PACKAGE_ABORT one or more package source files differ from the declared committed revision")

subprocess.run(["git", "bundle", "verify", str(bundle)], cwd=repo, check=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
heads = subprocess.run(["git", "bundle", "list-heads", str(bundle)], cwd=repo, check=True, stdout=subprocess.PIPE, text=True).stdout.splitlines()
if not any(line.split()[0] == core_commit for line in heads if len(line.split()) >= 2):
    raise SystemExit("PACKAGE_ABORT repository bundle does not contain the declared core commit")

destination.parent.mkdir(parents=True, exist_ok=True)
required_bytes = sum(path.stat().st_size for path in actual_inputs.values()) + bundle.stat().st_size + sum(path.stat().st_size for path in source_files.values())
reserve_bytes = 64 * 1024 * 1024
free_bytes = shutil.disk_usage(destination.parent).free
if free_bytes < required_bytes + reserve_bytes:
    raise SystemExit(f"PACKAGE_ABORT insufficient destination space: need {required_bytes + reserve_bytes} bytes including reserve, have {free_bytes}")
building.mkdir()

files = {}
def copy_verified(source_path, relative, expected_hash=None):
    target = building / relative
    target.parent.mkdir(parents=True, exist_ok=True)
    temporary = target.with_name(target.name + ".building")
    if target.exists() or temporary.exists():
        raise SystemExit(f"PACKAGE_ABORT destination file exists: {relative}")
    available = shutil.disk_usage(target.parent).free
    if available < source_path.stat().st_size + 8 * 1024 * 1024:
        raise SystemExit(f"PACKAGE_ABORT destination free space fell below per-copy reserve: {relative}")
    shutil.copyfile(source_path, temporary)
    digest = sha256(temporary)
    if expected_hash is not None and digest != expected_hash:
        temporary.unlink()
        raise SystemExit(f"PACKAGE_ABORT copied file hash mismatch: {relative}")
    temporary.replace(target)
    target.chmod(0o755 if source_path.stat().st_mode & 0o111 else 0o644)
    if sha256(target) != digest:
        raise SystemExit(f"PACKAGE_ABORT readback hash mismatch: {relative}")
    files[relative] = digest
    return target

for name, (filename, digest) in expected.items():
    copy_verified(actual_inputs[name], "assets/" + filename, digest)
copy_verified(bundle, "repository/biyahe-core.bundle")
for relative, path in source_files.items():
    copy_verified(path, relative)

manifest = {
    "format": "biyahe-portable-pedestrian-staging-v1",
    "package_version": 1,
    "created_utc": datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z"),
    "repository": {
        "core_commit": core_commit,
        "bundle": "repository/biyahe-core.bundle",
        "checkout_instructions": "git clone repository/biyahe-core.bundle biyahe && git -C biyahe checkout " + core_commit,
        "uncommitted_changes_included": False,
    },
    "source_snapshot": snapshot,
    "artifacts": {
        "source": {"filename": "assets/" + expected["source"][0], "sha256": expected["source"][1], "snapshot": snapshot, "url": "https://download.openstreetmap.fr/extracts/asia/philippines/central_visayas.osm.pbf", "required_for_staging_validation": True},
        "graph": {"filename": "assets/" + expected["graph"][0], "sha256": expected["graph"][1], "fingerprint": "a0d9f1cb8289cf1f9bde3905d1ad8a57124ee41120153b4efc1f5b31e4c03ecd", "schema": "biyahe-pedestrian-sqlite-v3", "nodes": 76685, "edges": 78225, "license": "ODbL-1.0", "attribution": "© OpenStreetMap contributors"},
        "backup": {"filename": "assets/" + expected["backup"][0], "sha256": expected["backup"][1], "format": "PostgreSQL custom archive", "expected_stops": 503, "expected_places": 37, "expected_transfers": 2},
    },
    "journey_corpus": {"filename": "evidence/batch032-cebu-coverage.json", "sha256": files["evidence/batch032-cebu-coverage.json"], "case_count": 10, "routes_found": 9, "routes_unavailable": 1, "snap_radius_meters": 25},
    "coverage_summary": {
        "snap_radius_meters": 25,
        "stops": {"total": 503, "with_usable_coordinates": 503, "snappable": 436, "largest_component": 433, "smaller_components": 3, "snap_unavailable": 67, "unreachable_or_unsnappable": 70},
        "places": {"total": 37, "snappable": 30, "snap_unavailable": 7, "unreachable_or_unsnappable": 8},
        "transfers": {"total": 2, "graph_connected": 2, "verified": 0},
    },
    "restriction_inventory": {
        "audit_filename": "evidence/batch030-cebu-restriction-audit.json",
        "audit_sha256": files["evidence/batch030-cebu-restriction-audit.json"],
        "source_scan_complete": True,
        "producer_exit_code": 0,
        "source_relations": 648,
        "stored_relations": 619,
        "omitted_relations": 29,
        "vehicle_only": 648,
        "pedestrian_applicable": 0,
        "pedestrian_exempt": 0,
        "ambiguous": 0,
        "unsupported_applicable": 0,
        "conditional": 33,
        "via_node": 449,
        "via_way": 166,
        "malformed_or_complex": 4,
        "unresolved_member_references": 75,
    },
    "requirements": {
        "php": "8.3 or newer",
        "postgresql": "18.x",
        "postgis": "3.6.x",
        "php_extensions": ["pdo_pgsql", "pdo_sqlite", "sqlite3"],
        "tools": ["Python 3", "Git", "PostgreSQL client/server tools", "setsid", "ss", "sha256sum"],
        "minimum_memavailable_kib": 524288,
        "minimum_swapfree_kib": 131072,
        "minimum_diskfree_kib": 1048576,
        "database_directory": "internal host storage only; never SD or production PGDATA",
    },
    "policy": {
        "dataset_stays_inactive": True,
        "production_activation": "NO",
        "transfer_locations_verified": 0,
        "field_safety_verified": False,
        "conditional_or_vehicle_restriction_limitations": "The 648 source relations are bound to this snapshot; generic conditional vehicle rules are not dynamically evaluated for pedestrian routing, and no pedestrian-specific restriction tag was observed in the source scan.",
    },
    "files": files,
}
manifest_path = building / "manifest.json"
manifest_path.write_text(json.dumps(manifest, indent=2) + "\n")
if json.loads(manifest_path.read_text()) != manifest:
    raise SystemExit("PACKAGE_ABORT manifest readback mismatch")
subprocess.run(["bash", str(repo / "api/scripts/verify-portable-staging-package.sh"), str(building)], check=True)
building.rename(destination)
print(json.dumps({"package": str(destination), "files": len(files), "source_sha256": expected["source"][1], "graph_sha256": expected["graph"][1], "backup_sha256": expected["backup"][1], "core_commit": core_commit}, indent=2))
PY
bash "$(dirname "$(realpath "$0")")/verify-portable-staging-package.sh" "$destination"
