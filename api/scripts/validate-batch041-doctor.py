#!/usr/bin/env python3
"""Validate Doctor V41 status vocabulary and evidence bindings."""

from __future__ import annotations

import argparse
import json
from pathlib import Path
import sys

from evidence_provenance import ProvenanceError, validate_registry_file

ALLOWED = {"VERIFIED", "IMPLEMENTED_UNTESTED", "BLOCKED", "FAILED", "UNRESOLVED"}
REQUIRED_CATEGORIES = {
    "evidence_reconciliation", "coverage_diagnostics", "graph_identity",
    "offline_adapter_contract", "ten_case_corpus", "journey_preview",
    "ride_mode", "saved_journeys", "map_and_mobile", "validation_ledger",
    "portable_verification", "postgresql_staging", "transfer_field_evidence",
}
REQUIRED_GATES = {
    "local_walking_adapter", "live_api_integration", "postgresql_staging",
    "route_parity", "field_safety", "private_beta", "production_activation",
}


class DoctorError(ValueError):
    pass


def validate_doctor(document: object, repo_root: Path, registry_path: Path) -> dict:
    if not isinstance(document, dict) or document.get("format") != "biyahe-doctor-v41-evidence-v1":
        raise DoctorError("unsupported Doctor V41 format")
    if document.get("batch") != 41 or not isinstance(document.get("assessed_revision"), str):
        raise DoctorError("Doctor V41 revision or batch identity is missing")
    identity = document.get("source_and_graph")
    if not isinstance(identity, dict):
        raise DoctorError("Doctor V41 source and graph identity is missing")
    registry = validate_registry_file(registry_path, repo_root)
    if (identity.get("source_pbf_sha256") != registry["source_pbf_sha256"]
        or identity.get("graph_sha256") != registry["graph_sha256"]
        or identity.get("graph_fingerprint") != registry["graph_content_fingerprint"]
        or identity.get("provenance_registry_sha256") != registry["registry_sha256"]):
        raise DoctorError("Doctor V41 source, graph, or registry fingerprint mismatch")

    categories = document.get("categories")
    if not isinstance(categories, list):
        raise DoctorError("Doctor V41 categories must be an array")
    category_map = {}
    for row in categories:
        if not isinstance(row, dict) or not isinstance(row.get("name"), str) or row["name"] in category_map:
            raise DoctorError("Doctor V41 contains a malformed or duplicate category")
        for key in ("implementation", "validation"):
            if row.get(key) not in ALLOWED:
                raise DoctorError(f"Doctor V41 category {row['name']} has invalid {key} status")
        category_map[row["name"]] = row
    missing = REQUIRED_CATEGORIES - set(category_map)
    if missing:
        raise DoctorError(f"Doctor V41 categories missing: {', '.join(sorted(missing))}")

    gates = document.get("release_gates")
    if not isinstance(gates, dict) or not REQUIRED_GATES.issubset(gates):
        raise DoctorError("Doctor V41 release gates are incomplete")
    if not str(gates.get("postgresql_staging", "")).startswith("BLOCKED") \
        or not str(gates.get("field_safety", "")).startswith("BLOCKED"):
        raise DoctorError("PostgreSQL staging and field safety cannot be promoted by this report")
    if gates.get("private_beta") != "BLOCKED" or gates.get("production_activation") != "NO":
        raise DoctorError("private beta and production activation must remain blocked")
    if document.get("execution_evidence", {}).get("real_graph_replay") != "BLOCKED_NOT_RUN":
        raise DoctorError("a static evidence report cannot claim real graph replay")

    return {
        "format": "biyahe-doctor-v41-validation-v1",
        "status": "PASS_STATIC_DOCTOR_CONTRACT",
        "categories": len(categories),
        "registry_sha256": registry["registry_sha256"],
        "production_activation": "NO",
        "private_beta": "BLOCKED",
        "real_graph_replay": "BLOCKED_NOT_RUN",
    }


def main(argv=None) -> int:
    root = Path(__file__).resolve().parents[2]
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--doctor", type=Path, default=root / "docs/reports/batch041-doctor-v41.json")
    parser.add_argument("--registry", type=Path, default=root / "docs/reports/batch041-evidence-provenance.json")
    args = parser.parse_args(argv)
    try:
        document = json.loads(args.doctor.read_text(encoding="utf-8"))
        result = validate_doctor(document, root, args.registry)
        print(json.dumps(result, sort_keys=True))
        return 0
    except (OSError, json.JSONDecodeError, ProvenanceError, DoctorError) as error:
        print(f"DOCTOR_V41_VALIDATION=FAIL: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
