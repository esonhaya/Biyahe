#!/usr/bin/env bash
set -euo pipefail

usage() {
    printf 'Usage: verify-portable-staging-package.sh PACKAGE_DIRECTORY [--report NEW_JSON_PATH]\n'
}

if (($# != 1 && $# != 3)) || { (($# == 3)) && [[ "$2" != --report ]]; }; then
    usage >&2
    exit 2
fi
report_path=
if (($# == 3)); then report_path="$3"; fi
command -v python3 >/dev/null 2>&1 || { printf 'Python 3 is required to verify the package manifest.\n' >&2; exit 2; }
command -v git >/dev/null 2>&1 || { printf 'Git is required to verify the bundled repository commit.\n' >&2; exit 2; }
package_dir="$(realpath -e "$1")"
[[ -d "$package_dir" ]] || { printf 'Package path is not a directory.\n' >&2; exit 2; }

python3 - "$package_dir" "$report_path" <<'PY'
import hashlib
import json
import pathlib
import re
import subprocess
import sys
import tempfile

root = pathlib.Path(sys.argv[1]).resolve(strict=True)
manifest_path = root / "manifest.json"
if manifest_path.is_symlink() or not manifest_path.is_file():
    raise SystemExit("PACKAGE_INVALID missing manifest.json")
try:
    manifest = json.loads(manifest_path.read_text())
except (OSError, json.JSONDecodeError) as exc:
    raise SystemExit(f"PACKAGE_INVALID manifest JSON: {exc}")
if not isinstance(manifest, dict):
    raise SystemExit("PACKAGE_INVALID manifest root must be an object")
if manifest.get("format") != "biyahe-portable-pedestrian-staging-v1" or manifest.get("package_version") != 1:
    raise SystemExit("PACKAGE_INVALID unsupported manifest format or version")
if any(not isinstance(manifest.get(key), dict) for key in ("artifacts", "repository", "requirements", "policy", "journey_corpus", "restriction_inventory")):
    raise SystemExit("PACKAGE_INVALID artifact, repository, requirement, policy, and corpus metadata must be objects")
if manifest.get("repository", {}).get("uncommitted_changes_included") is not False:
    raise SystemExit("PACKAGE_INVALID package must declare a committed-code-only repository bundle")
requirements = manifest.get("requirements", {})
if (requirements.get("postgresql") != "18.x"
    or requirements.get("postgis") != "3.6.x"
    or requirements.get("minimum_memavailable_kib") != 524288
    or requirements.get("minimum_swapfree_kib") != 131072
    or requirements.get("minimum_diskfree_kib") != 1048576):
    raise SystemExit("PACKAGE_INVALID runtime versions or resource floors differ from the portable contract")
policy = manifest.get("policy", {})
if (policy.get("dataset_stays_inactive") is not True
    or policy.get("production_activation") != "NO"
    or policy.get("transfer_locations_verified") != 0
    or policy.get("field_safety_verified") is not False):
    raise SystemExit("PACKAGE_INVALID staging or release policy is not fail-closed")

expected = {
    "source": "787cac20ecc586b754b1a464643a040566d486457c6a37bc0b11ce683bc66d4d",
    "graph": "29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8",
    "backup": "e55af400fde88d0a3434b194e0625e204eb0a2726374eabb8286c152b11a4a92",
}
for name, digest in expected.items():
    artifact = manifest.get("artifacts", {}).get(name, {})
    if artifact.get("sha256") != digest or not re.fullmatch(r"[a-f0-9]{64}", str(artifact.get("sha256", ""))):
        raise SystemExit(f"PACKAGE_INVALID {name} identity differs from the verified Batch036 baseline")
expected_names = {
    "source": "assets/central_visayas-20261007.osm.pbf",
    "graph": "assets/cebu-walk-central-visayas-20261007-v3.sqlite",
    "backup": "assets/biyahe-batch024-20261008.dump",
}
files = manifest.get("files")
if not isinstance(files, dict) or not files:
    raise SystemExit("PACKAGE_INVALID file checksum inventory is empty")
if "manifest.json" in files:
    raise SystemExit("PACKAGE_INVALID manifest.json must not recursively appear in its own checksum inventory")
for name, filename in expected_names.items():
    artifact = manifest.get("artifacts", {}).get(name, {})
    if artifact.get("filename") != filename:
        raise SystemExit(f"PACKAGE_INVALID {name} filename differs from the versioned package contract")
    if files.get(filename) != expected[name]:
        raise SystemExit(f"PACKAGE_INVALID {name} file entry is not bound to its expected SHA-256")
if (manifest["artifacts"]["source"].get("snapshot") != "2026-10-07T01:00:21Z"
    or manifest["artifacts"]["source"].get("url") != "https://download.openstreetmap.fr/extracts/asia/philippines/central_visayas.osm.pbf"
    or manifest["artifacts"]["graph"].get("schema") != "biyahe-pedestrian-sqlite-v3"
    or manifest["artifacts"]["graph"].get("license") != "ODbL-1.0"
    or manifest["artifacts"]["graph"].get("attribution") != "© OpenStreetMap contributors"):
    raise SystemExit("PACKAGE_INVALID source snapshot, graph schema, license, or attribution differs from the verified contract")

verified = 0
for relative, digest in sorted(files.items()):
    path = pathlib.PurePosixPath(relative)
    if (not isinstance(relative, str) or not relative or path.is_absolute()
        or ".." in path.parts or "." in path.parts or path.as_posix() != relative
        or not re.fullmatch(r"[a-f0-9]{64}", str(digest))):
        raise SystemExit(f"PACKAGE_INVALID unsafe or malformed file entry: {relative}")
    candidate_path = root
    for part in path.parts:
        candidate_path = candidate_path / part
        if candidate_path.is_symlink():
            raise SystemExit(f"PACKAGE_INVALID symlink is not allowed: {relative}")
    candidate = candidate_path.resolve(strict=False)
    if root not in candidate.parents or not candidate.is_file():
        raise SystemExit(f"PACKAGE_INVALID missing or out-of-package file: {relative}")
    digest_builder = hashlib.sha256()
    with candidate.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest_builder.update(block)
    actual = digest_builder.hexdigest()
    if actual != digest:
        raise SystemExit(f"PACKAGE_INVALID checksum mismatch: {relative}")
    verified += 1

required = {
    "assets/cebu-walk-central-visayas-20261007-v3.sqlite",
    "assets/central_visayas-20261007.osm.pbf",
    "assets/biyahe-batch024-20261008.dump",
    "evidence/batch032-cebu-coverage.json",
    "evidence/batch030-cebu-restriction-audit.json",
    "evidence/batch034-doctor-v34.json",
    "evidence/batch035-doctor-v35.json",
    "scripts/run-bounded-process.sh",
    "scripts/package-portable-staging.sh",
    "scripts/verify-portable-staging-package.sh",
    "repository/biyahe-core.bundle",
    "source/BiyaheDoctorV36.php",
    "source/OfflinePedestrianGraphReadinessV36.php",
    "source/BiyaheDoctorV35.php",
    "source/OfflinePedestrianGraphReadinessV35.php",
    "docs/README.md",
    "config/portable-staging.env.example",
}
missing = sorted(required.difference(files))
if missing:
    raise SystemExit("PACKAGE_INVALID required files absent from manifest: " + ", ".join(missing))
actual_files = {
    path.relative_to(root).as_posix()
    for path in root.rglob("*")
    if path.is_file() and path != manifest_path
}
symlinks = [path.relative_to(root).as_posix() for path in root.rglob("*") if path.is_symlink()]
if symlinks:
    raise SystemExit("PACKAGE_INVALID package contains symlinks: " + ", ".join(sorted(symlinks)))
unlisted = sorted(actual_files.difference(files))
if unlisted:
    raise SystemExit("PACKAGE_INVALID files are present but not covered by the checksum inventory: " + ", ".join(unlisted))
commit = manifest.get("repository", {}).get("core_commit", "")
bundle_relative = manifest.get("repository", {}).get("bundle", "")
if not isinstance(commit, str) or not re.fullmatch(r"[a-f0-9]{40}", commit):
    raise SystemExit("PACKAGE_INVALID repository core commit is malformed")
if bundle_relative != "repository/biyahe-core.bundle" or bundle_relative not in files:
    raise SystemExit("PACKAGE_INVALID repository bundle path is unsupported or unchecked")
bundle_path = root / bundle_relative
try:
    with tempfile.TemporaryDirectory(prefix="biyahe-portable-bundle-check-") as temporary:
        empty_repository = pathlib.Path(temporary) / "repository.git"
        subprocess.run(["git", "init", "--bare", str(empty_repository)], check=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
        subprocess.run(["git", "-C", str(empty_repository), "bundle", "verify", str(bundle_path)], check=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    heads = subprocess.run(["git", "bundle", "list-heads", str(bundle_path)], check=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True).stdout.splitlines()
except (OSError, subprocess.CalledProcessError) as exc:
    raise SystemExit(f"PACKAGE_INVALID repository bundle verification failed: {exc}")
if not any(len(line.split()) >= 2 and line.split()[0] == commit for line in heads):
    raise SystemExit("PACKAGE_INVALID repository bundle does not contain the declared core commit")
if manifest.get("source_snapshot") != "2026-10-07T01:00:21Z":
    raise SystemExit("PACKAGE_INVALID source snapshot differs from the verified source manifest")
if manifest.get("artifacts", {}).get("graph", {}).get("nodes") != 76685 or manifest.get("artifacts", {}).get("graph", {}).get("edges") != 78225:
    raise SystemExit("PACKAGE_INVALID graph row counts differ from the verified Batch030 artifact")
if manifest.get("artifacts", {}).get("graph", {}).get("fingerprint") != "a0d9f1cb8289cf1f9bde3905d1ad8a57124ee41120153b4efc1f5b31e4c03ecd":
    raise SystemExit("PACKAGE_INVALID graph fingerprint differs from the verified Batch032 evidence")
journey = manifest.get("journey_corpus", {})
if (journey.get("case_count") != 10
    or journey.get("routes_found") != 9
    or journey.get("routes_unavailable") != 1
    or journey.get("snap_radius_meters") != 25
    or journey.get("filename") not in files
    or journey.get("sha256") != files.get(journey.get("filename"))):
    raise SystemExit("PACKAGE_INVALID real journey corpus case count differs from Batch032 evidence")
expected_coverage_summary = {
    "snap_radius_meters": 25,
    "stops": {"total": 503, "with_usable_coordinates": 503, "snappable": 436, "largest_component": 433, "smaller_components": 3, "snap_unavailable": 67, "unreachable_or_unsnappable": 70},
    "places": {"total": 37, "snappable": 30, "snap_unavailable": 7, "unreachable_or_unsnappable": 8},
    "transfers": {"total": 2, "graph_connected": 2, "verified": 0},
}
if manifest.get("coverage_summary") != expected_coverage_summary:
    raise SystemExit("PACKAGE_INVALID coverage summary differs from the verified Batch032 baseline")
try:
    coverage = json.loads((root / journey["filename"]).read_text())
except (OSError, json.JSONDecodeError) as exc:
    raise SystemExit(f"PACKAGE_INVALID Batch032 coverage JSON: {exc}")
if not isinstance(coverage, dict):
    raise SystemExit("PACKAGE_INVALID Batch032 coverage root must be an object")
coverage_cases = coverage.get("journey_corpus", {}).get("cases", [])
coverage_routes = {"ROUTE_FOUND": 0, "ROUTE_UNAVAILABLE": 0}
for case in coverage_cases:
    if not isinstance(case, dict) or case.get("status") != "EVALUATED":
        raise SystemExit("PACKAGE_INVALID journey evidence contains a non-evaluated case")
    status = case.get("route", {}).get("status")
    if status not in coverage_routes:
        raise SystemExit("PACKAGE_INVALID journey evidence contains an unknown route status")
    coverage_routes[status] += 1
if (coverage.get("format") != "biyahe-cebu-offline-coverage-v1"
    or coverage.get("source", {}).get("graph_source_sha256") != expected["source"]
    or coverage.get("source", {}).get("transport_backup_sha256") != expected["backup"]
    or coverage.get("source", {}).get("graph_fingerprint") != manifest["artifacts"]["graph"].get("fingerprint")
    or coverage.get("snap_policy", {}).get("maximum_distance_meters") != 25
    or coverage_routes != {"ROUTE_FOUND": 9, "ROUTE_UNAVAILABLE": 1}
    or coverage.get("graph_validation", {}).get("artifact_sha256") != expected["graph"]
    or coverage.get("graph_validation", {}).get("nodes") != 76685
    or coverage.get("graph_validation", {}).get("edges") != 78225
    or coverage.get("graph_validation", {}).get("restriction_counts", {}).get("total") != 619
    or coverage.get("coverage", {}).get("stops", {}).get("total") != 503
    or coverage.get("coverage", {}).get("stops", {}).get("within_snap_policy") != 436
    or coverage.get("coverage", {}).get("places", {}).get("total") != 37
    or coverage.get("coverage", {}).get("places", {}).get("within_snap_policy") != 30
    or coverage.get("coverage", {}).get("transfers", {}).get("total") != 2
    or coverage.get("coverage", {}).get("transfers", {}).get("records_marked_verified") != 0):
    raise SystemExit("PACKAGE_INVALID Batch032 coverage evidence does not match the package baseline")
restriction_summary = manifest.get("restriction_inventory", {})
restriction_file = "evidence/batch030-cebu-restriction-audit.json"
if (restriction_summary.get("audit_filename") != restriction_file
    or restriction_summary.get("audit_sha256") != files.get(restriction_file)
    or restriction_summary.get("source_scan_complete") is not True
    or restriction_summary.get("producer_exit_code") != 0
    or restriction_summary.get("source_relations") != 648
    or restriction_summary.get("stored_relations") != 619
    or restriction_summary.get("omitted_relations") != 29
    or restriction_summary.get("vehicle_only") != 648
    or restriction_summary.get("pedestrian_applicable") != 0
    or restriction_summary.get("pedestrian_exempt") != 0
    or restriction_summary.get("ambiguous") != 0
    or restriction_summary.get("unsupported_applicable") != 0
    or restriction_summary.get("conditional") != 33
    or restriction_summary.get("via_node") != 449
    or restriction_summary.get("via_way") != 166
    or restriction_summary.get("malformed_or_complex") != 4
    or restriction_summary.get("unresolved_member_references") != 75):
    raise SystemExit("PACKAGE_INVALID source restriction inventory summary differs from the verified Batch030 evidence")
try:
    restriction_audit = json.loads((root / restriction_file).read_text())
except (OSError, json.JSONDecodeError) as exc:
    raise SystemExit(f"PACKAGE_INVALID restriction audit JSON: {exc}")
if not isinstance(restriction_audit, dict):
    raise SystemExit("PACKAGE_INVALID restriction audit root must be an object")
source_scan = restriction_audit.get("source_scan", {})
source_counts = restriction_audit.get("source_classification_counts", {})
stored_inventory = restriction_audit.get("stored_inventory", {})
if (not isinstance(source_scan, dict) or not isinstance(source_counts, dict)
    or not isinstance(stored_inventory, dict)
    or not isinstance(source_scan.get("omitted_relations"), list)):
    raise SystemExit("PACKAGE_INVALID restriction audit subdocuments have unsupported shapes")
stored_counts = stored_inventory.get("counts", {})
pedestrian_tags = source_scan.get("pedestrian_specific_tag_counts", {})
if not isinstance(stored_counts, dict) or not isinstance(pedestrian_tags, dict):
    raise SystemExit("PACKAGE_INVALID restriction audit count shapes are unsupported")
if (restriction_audit.get("format") != "biyahe-pedestrian-restriction-audit-v1"
    or restriction_audit.get("source", {}).get("sha256") != expected["source"]
    or restriction_audit.get("source", {}).get("url") != "https://download.openstreetmap.fr/extracts/asia/philippines/central_visayas.osm.pbf"
    or restriction_audit.get("source", {}).get("snapshot") not in ("2026-10-07T01:00:21Z", "2026-10-07T01:00:21+00:00")
    or restriction_audit.get("graph", {}).get("content_fingerprint") != "a0d9f1cb8289cf1f9bde3905d1ad8a57124ee41120153b4efc1f5b31e4c03ecd"
    or source_scan.get("complete") is not True
    or source_scan.get("producer_exit_code") != 0
    or source_scan.get("source_sha256") != expected["source"]
    or source_scan.get("source_snapshot") != "2026-10-07T01:00:21Z"
    or source_scan.get("matching_relation_count") != 648
    or source_scan.get("stored_relation_count") != 619
    or source_scan.get("omitted_relation_count") != 29
    or len(source_scan.get("omitted_relations", [])) != 29
    or source_counts.get("vehicle_only") != 648
    or source_counts.get("pedestrian_applicable") != 0
    or source_counts.get("ambiguous") != 0
    or source_counts.get("unsupported_applicable") != 0
    or source_counts.get("conditional") != 33
    or stored_counts.get("total") != 619
    or stored_counts.get("vehicle_only") != 619
    or stored_counts.get("unsupported_applicable") != 0
    or any(pedestrian_tags.get(key) != 0 for key in ("restriction:foot", "restriction:pedestrian", "restriction:foot:conditional", "restriction:pedestrian:conditional"))):
    raise SystemExit("PACKAGE_INVALID complete source/stored restriction audit failed identity or count checks")

report = {
    "format": "biyahe-portable-package-verification-v1",
    "status": "PASS",
    "package_version": manifest["package_version"],
    "package_directory": root.name,
    "files_verified": verified,
    "manifest_sha256": hashlib.sha256(manifest_path.read_bytes()).hexdigest(),
    "repository_core_commit": commit,
    "artifact_hashes": {name: artifact["sha256"] for name, artifact in manifest["artifacts"].items()},
    "restriction_inventory": restriction_summary,
    "real_staging_executed": False,
    "production_activation": "NO",
}
if len(sys.argv) > 2 and sys.argv[2]:
    target = pathlib.Path(sys.argv[2]).expanduser()
    if target.exists() or target.with_name(target.name + ".building").exists() or not target.parent.is_dir():
        raise SystemExit("PACKAGE_INVALID report path must be new under an existing directory")
    building = target.with_name(target.name + ".building")
    with building.open("x") as stream:
        stream.write(json.dumps(report, indent=2) + "\n")
        stream.flush()
    building.replace(target)
print(f"PACKAGE_VALID=YES version={manifest['package_version']} files_verified={verified}")
print("SOURCE_SHA256=" + expected["source"])
print("GRAPH_SHA256=" + expected["graph"])
print("BACKUP_SHA256=" + expected["backup"])
print("REAL_STAGING_EXECUTED=NO")
if len(sys.argv) > 2 and sys.argv[2]:
    print("PACKAGE_VERIFICATION_REPORT=" + str(target))
PY
