#!/usr/bin/env python3
"""No-database smoke check for pinned offline evidence and metric provenance."""

from __future__ import annotations

import argparse
import importlib.util
import json
from pathlib import Path
import sys

from evidence_provenance import ProvenanceError, validate_registry_file

SCRIPT_DIR = Path(__file__).resolve().parent
REPO_ROOT = SCRIPT_DIR.parents[1]
SUMMARY_SCRIPT = SCRIPT_DIR / "summarize-offline-coverage.py"
SPEC = importlib.util.spec_from_file_location("coverage_summary_smoke", SUMMARY_SCRIPT)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("coverage summarizer could not be loaded")
SUMMARY = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(SUMMARY)


class SmokeError(ValueError):
    pass


def build_smoke_report(coverage_path: Path, registry_path: Path, repo_root: Path) -> dict:
    provenance = validate_registry_file(registry_path, repo_root)
    raw = coverage_path.read_bytes()
    if len(raw) > SUMMARY.MAX_INPUT_BYTES:
        raise SmokeError("pinned coverage evidence exceeds the bounded JSON size")
    digest = SUMMARY.sha256_bytes(raw)
    if digest != SUMMARY.EXPECTED_REPORT_SHA256:
        raise SmokeError("pinned coverage report SHA-256 mismatch")
    try:
        coverage = json.loads(raw)
    except json.JSONDecodeError as error:
        raise SmokeError("pinned coverage report is invalid JSON") from error
    if not isinstance(coverage, dict) or coverage.get("complete") is not True:
        raise SmokeError("pinned coverage report is incomplete")
    summary = SUMMARY.build_summary(coverage, digest, provenance)
    stops, places = summary["stops"], summary["places"]
    transfers, corpus = summary["transfers"], summary["journey_corpus"]
    if (stops["total"] != 503 or places["total"] != 37
        or stops["snappable_within_pinned_policy"] != 436
        or places["snappable_within_pinned_policy"] != 30
        or transfers["total"] != 2 or transfers["graph_route_connected"] != 2
        or len(transfers["unverified_transfer_ids"]) != 2
        or corpus["cases_total"] != 10 or corpus["routes_found"] != 9
        or corpus["routes_unavailable"] != 1 or len(corpus["component_disconnected_cases"]) != 1):
        raise SmokeError("pinned stored evidence does not match the reviewed coverage and corpus baseline")
    return {
        "format": "biyahe-offline-evidence-smoke-v1",
        "status": "PASS_STATIC_EVIDENCE_ONLY",
        "evidence_files": {
            "coverage_report_sha256": digest,
            "provenance_registry_sha256": provenance["registry_sha256"],
            "source_pbf_sha256": SUMMARY.EXPECTED_SOURCE_SHA256,
            "graph_sha256": SUMMARY.EXPECTED_GRAPH_SHA256,
            "graph_fingerprint": SUMMARY.EXPECTED_GRAPH_FINGERPRINT,
        },
        "coverage": {
            "snap_radius_meters": 25,
            "stops_total": stops["total"],
            "stops_snappable": stops["snappable_within_pinned_policy"],
            "places_total": places["total"],
            "places_snappable": places["snappable_within_pinned_policy"],
            "measurement_scope": "STORED_TRANSIT_RECORDS_ONLY",
        },
        "transfers": {
            "total": transfers["total"],
            "graph_connected": transfers["graph_route_connected"],
            "unverified": len(transfers["unverified_transfer_ids"]),
            "field_verification": "NOT_ESTABLISHED_BY_GRAPH_CONNECTIVITY",
        },
        "stored_corpus": {
            "cases": corpus["cases_total"],
            "routes_found_in_historical_evidence": corpus["routes_found"],
            "routes_unavailable_in_historical_evidence": corpus["routes_unavailable"],
            "current_replay_executed": False,
        },
        "restriction_reconciliation": summary["routing_capability"]["count_reconciliation"],
        "quality_reconciliation": summary["graph_quality"]["quality_reconciliation_status"],
        "execution_boundaries": {
            "sqlite_opened": False,
            "pbf_opened": False,
            "postgresql_contacted": False,
            "route_replay_executed": False,
            "field_safety_verified": False,
            "private_beta_ready": False,
            "production_activation": False,
            "external_host_execution": False,
        },
        "meaning": "PASS validates pinned report files and their declared identities only; it does not validate the graph file, execute routing, stage PostgreSQL, or establish commuter safety.",
    }


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--coverage", type=Path, default=REPO_ROOT / "docs/reports/batch032-cebu-coverage.json")
    parser.add_argument("--registry", type=Path, default=REPO_ROOT / "docs/reports/batch041-evidence-provenance.json")
    args = parser.parse_args(argv)
    try:
        report = build_smoke_report(args.coverage, args.registry, REPO_ROOT)
        print(json.dumps(report, sort_keys=True, ensure_ascii=False, indent=2))
        return 0
    except (OSError, json.JSONDecodeError, ProvenanceError, SUMMARY.EvidenceError, SmokeError) as error:
        print(f"OFFLINE_EVIDENCE_SMOKE=FAIL: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
