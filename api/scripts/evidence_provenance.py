"""Validate the Batch041 metric registry against its immutable evidence files."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path
from typing import Any

EXPECTED_REGISTRY_SHA256 = "882fd1ae2c7ab8f2e1edab87c9122911f62c4568513bbe6c8875263958dc1bb6"
REGISTRY_FORMAT = "biyahe-evidence-provenance-registry-v1"
PINNED_SOURCE_SHA256 = "787cac20ecc586b754b1a464643a040566d486457c6a37bc0b11ce683bc66d4d"
PINNED_GRAPH_SHA256 = "29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8"
PINNED_GRAPH_FINGERPRINT = "a0d9f1cb8289cf1f9bde3905d1ad8a57124ee41120153b4efc1f5b31e4c03ecd"


class ProvenanceError(ValueError):
    """Evidence identities, definitions, or claims do not agree."""


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def get_pointer(document: Any, pointer: str) -> Any:
    current = document
    for component in pointer.split("."):
        if not isinstance(current, dict) or component not in current:
            raise ProvenanceError(f"missing evidence field: {pointer}")
        current = current[component]
    return current


def _identity_checks(source: dict[str, Any], document: dict[str, Any], scope: dict[str, Any]) -> None:
    source_id = source["id"]
    checks: dict[str, Any] = {
        "batch031_source_restriction_scan": {
            "source_sha256": scope["source_pbf_sha256"],
            "source_snapshot": scope["source_snapshot"],
            "complete": True,
            "producer_exit_code": 0,
        },
        "batch031_restriction_audit": {
            "source.sha256": scope["source_pbf_sha256"],
            "source.snapshot": "2026-10-07T01:00:21+00:00",
            "graph.content_fingerprint": scope["graph_content_fingerprint"],
            "graph.schema_version": 3,
        },
        "batch031_graph_quality_review": {
            "source.sha256": scope["source_pbf_sha256"],
            "source.snapshot": "2026-10-07T01:00:21+00:00",
            "graph.sha256": scope["graph_sha256"],
            "graph.content_fingerprint": scope["graph_content_fingerprint"],
        },
        "batch032_coverage_report": {
            "source.graph_source_sha256": scope["source_pbf_sha256"],
            "source.graph_fingerprint": scope["graph_content_fingerprint"],
            "source.transport_backup_sha256": "e55af400fde88d0a3434b194e0625e204eb0a2726374eabb8286c152b11a4a92",
            "graph_validation.artifact_sha256": scope["graph_sha256"],
            "graph_validation.graph_fingerprint": scope["graph_content_fingerprint"],
            "complete": True,
        },
        "batch032_doctor_v32": {
            "source_sha256": scope["source_pbf_sha256"],
            "graph_sha256": scope["graph_sha256"],
            "graph_fingerprint": scope["graph_content_fingerprint"],
        },
        "batch040_coverage_derivative": {
            "source.coverage_report_sha256": "6caf101177904bee6fda1b12cab9c5cda36ddaf7ecc08b0d9778c0517123c81b",
            "mode": "CARRIED_FORWARD_FROM_PINNED_EVIDENCE",
            "recomputed_from_graph": False,
        },
    }
    expected = checks.get(source_id)
    if expected is None:
        raise ProvenanceError(f"unsupported evidence source id: {source_id}")
    for pointer, value in expected.items():
        if get_pointer(document, pointer) != value:
            raise ProvenanceError(f"source identity mismatch for {source_id}.{pointer}")


def validate_registry_document(registry: Any, repo_root: Path, registry_sha256: str | None = None) -> dict[str, Any]:
    if not isinstance(registry, dict) or registry.get("format") != REGISTRY_FORMAT or registry.get("registry_version") != 1:
        raise ProvenanceError("unsupported or incomplete provenance registry")
    scope = registry.get("scope")
    if not isinstance(scope, dict):
        raise ProvenanceError("registry scope is missing")
    expected_scope = {
        "source_snapshot": "2026-10-07T01:00:21Z",
        "source_pbf_sha256": PINNED_SOURCE_SHA256,
        "graph_sha256": PINNED_GRAPH_SHA256,
        "graph_content_fingerprint": PINNED_GRAPH_FINGERPRINT,
    }
    if any(scope.get(key) != expected for key, expected in expected_scope.items()):
        raise ProvenanceError("registry source or graph identity differs from the pinned snapshot")

    source_rows = registry.get("sources")
    claims = registry.get("metric_claims")
    if not isinstance(source_rows, list) or not source_rows or not isinstance(claims, list) or not claims:
        raise ProvenanceError("registry sources and metric claims are required")
    source_map: dict[str, tuple[dict[str, Any], dict[str, Any]]] = {}
    source_results = []
    for source in source_rows:
        if not isinstance(source, dict):
            raise ProvenanceError("registry source must be an object")
        for key in ("id", "path", "sha256", "format", "repository_commit", "producer", "method_limit"):
            if not isinstance(source.get(key), str) or not source[key].strip():
                raise ProvenanceError(f"registry source requires {key}")
        source_id = source["id"]
        if source_id in source_map:
            raise ProvenanceError(f"duplicate evidence source id: {source_id}")
        if len(source["sha256"]) != 64 or any(char not in "0123456789abcdef" for char in source["sha256"]):
            raise ProvenanceError(f"invalid source SHA-256 for {source_id}")
        relative = Path(source["path"])
        if relative.is_absolute() or ".." in relative.parts:
            raise ProvenanceError(f"unsafe evidence source path for {source_id}")
        path = (repo_root / relative).resolve()
        if repo_root.resolve() not in path.parents:
            raise ProvenanceError(f"evidence source escapes repository root: {source_id}")
        try:
            raw = path.read_bytes()
            document = json.loads(raw)
        except FileNotFoundError as error:
            raise ProvenanceError(f"evidence source is missing: {source_id}") from error
        except (OSError, json.JSONDecodeError) as error:
            raise ProvenanceError(f"evidence source is unreadable or invalid JSON: {source_id}") from error
        actual_sha = sha256_bytes(raw)
        if actual_sha != source["sha256"]:
            raise ProvenanceError(f"evidence source SHA-256 mismatch: {source_id}")
        if not isinstance(document, dict) or document.get("format") != source["format"]:
            raise ProvenanceError(f"evidence source format mismatch: {source_id}")
        _identity_checks(source, document, scope)
        source_map[source_id] = (source, document)
        source_results.append({"id": source_id, "path": source["path"], "sha256": actual_sha, "status": "VERIFIED"})

    claim_ids: set[str] = set()
    metric_definitions: dict[str, tuple[str, str]] = {}
    claim_results = []
    for claim in claims:
        if not isinstance(claim, dict):
            raise ProvenanceError("registry metric claim must be an object")
        required = ("id", "value", "unit", "source_id", "source_pointer", "definition", "filters", "scope", "status", "interpretation")
        for key in required:
            if key not in claim or claim[key] is None or (isinstance(claim[key], str) and not claim[key].strip()):
                raise ProvenanceError(f"metric claim requires {key}")
        claim_id = claim["id"]
        if not isinstance(claim_id, str) or claim_id in claim_ids:
            raise ProvenanceError(f"duplicate or invalid metric id: {claim_id}")
        claim_ids.add(claim_id)
        definition = (claim["definition"], claim["unit"])
        if claim_id in metric_definitions and metric_definitions[claim_id] != definition:
            raise ProvenanceError(f"conflicting definition for metric id: {claim_id}")
        metric_definitions[claim_id] = definition
        if claim["source_id"] not in source_map:
            raise ProvenanceError(f"unknown source id in metric claim: {claim_id}")
        source, document = source_map[claim["source_id"]]
        actual = get_pointer(document, claim["source_pointer"])
        if actual != claim["value"]:
            raise ProvenanceError(f"metric value mismatch for {claim_id}: registry={claim['value']!r}, source={actual!r}")
        claim_results.append({"id": claim_id, "source_id": source["id"], "value": actual, "status": claim["status"]})

    reconciliation = registry.get("reconciliation")
    if not isinstance(reconciliation, list):
        raise ProvenanceError("registry reconciliation is missing")
    for row in reconciliation:
        if not isinstance(row, dict) or not isinstance(row.get("metric_ids"), list) or not row["metric_ids"]:
            raise ProvenanceError("reconciliation entries require metric_ids")
        missing = [metric_id for metric_id in row["metric_ids"] if metric_id not in claim_ids]
        if missing:
            raise ProvenanceError(f"reconciliation references unknown metrics: {', '.join(missing)}")
        if row.get("status") not in {"RESOLVED_DIFFERENT_DENOMINATORS", "RESOLVED_ZERO_FIELDS_ARE_UNMEASURED_DEFAULTS", "UNRESOLVED"}:
            raise ProvenanceError(f"unknown reconciliation status: {row.get('status')}")

    unresolved = registry.get("unresolved_conflicts")
    if not isinstance(unresolved, list):
        raise ProvenanceError("unresolved_conflicts must be an array")
    claim_values = {row["id"]: row["value"] for row in claims}
    restriction_ids = ("restrictions.source_relations", "restrictions.source_vehicle_only_classifications",
                       "restrictions.graph_stored_relations", "restrictions.source_only_omitted_relations")
    if all(metric_id in claim_values for metric_id in restriction_ids):
        if claim_values["restrictions.source_relations"] != claim_values["restrictions.source_vehicle_only_classifications"]:
            raise ProvenanceError("source restriction count and classification totals do not reconcile")
        if claim_values["restrictions.source_relations"] != (claim_values["restrictions.graph_stored_relations"]
                                                               + claim_values["restrictions.source_only_omitted_relations"]):
            raise ProvenanceError("stored and omitted restriction counts do not reconcile to the source inventory")
    claim_statuses = {row["id"]: row["status"] for row in claims}
    for metric_id, status in claim_statuses.items():
        if status == "UNMEASURED_DEFAULT_NOT_A_COUNT" and metric_id not in {
            "quality.legacy_summary.long_edges_over_250m", "quality.legacy_summary.boundary_nodes", "quality.legacy_summary.crossing_nodes"
        }:
            raise ProvenanceError(f"unmeasured default status is not permitted for {metric_id}")
    digest = registry_sha256 or ""
    if EXPECTED_REGISTRY_SHA256 != "TO_BE_PINNED" and digest != EXPECTED_REGISTRY_SHA256:
        raise ProvenanceError("provenance registry SHA-256 differs from the code-pinned version")
    return {
        "format": "biyahe-evidence-provenance-validation-v1",
        "status": "PASS",
        "registry_sha256": digest,
        "source_snapshot": scope["source_snapshot"],
        "source_pbf_sha256": scope["source_pbf_sha256"],
        "graph_sha256": scope["graph_sha256"],
        "graph_content_fingerprint": scope["graph_content_fingerprint"],
        "sources_verified": len(source_results),
        "metric_claims_verified": len(claim_results),
        "reconciliations": [{"id": row["id"], "status": row["status"]} for row in reconciliation],
        "unresolved_conflicts": unresolved,
        "source_results": source_results,
        "claim_results": claim_results,
        "field_safety_verified": False,
    }


def validate_registry_file(registry_path: Path, repo_root: Path) -> dict[str, Any]:
    try:
        raw = registry_path.read_bytes()
        registry = json.loads(raw)
    except (OSError, json.JSONDecodeError) as error:
        raise ProvenanceError(f"registry is unreadable or invalid JSON: {registry_path}") from error
    digest = sha256_bytes(raw)
    if EXPECTED_REGISTRY_SHA256 != "TO_BE_PINNED" and digest != EXPECTED_REGISTRY_SHA256:
        raise ProvenanceError("provenance registry SHA-256 differs from the code-pinned version")
    return validate_registry_document(registry, repo_root, digest)
