#!/usr/bin/env python3
"""Summarize pinned Batch032 coverage evidence; never opens the graph or PBF."""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import os
from pathlib import Path
import shutil
import sys
from collections import Counter, defaultdict

from evidence_provenance import ProvenanceError, validate_registry_file

EXPECTED_REPORT_SHA256 = "6caf101177904bee6fda1b12cab9c5cda36ddaf7ecc08b0d9778c0517123c81b"
EXPECTED_GRAPH_SHA256 = "29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8"
EXPECTED_SOURCE_SHA256 = "787cac20ecc586b754b1a464643a040566d486457c6a37bc0b11ce683bc66d4d"
EXPECTED_BACKUP_SHA256 = "e55af400fde88d0a3434b194e0625e204eb0a2726374eabb8286c152b11a4a92"
EXPECTED_GRAPH_FINGERPRINT = "a0d9f1cb8289cf1f9bde3905d1ad8a57124ee41120153b4efc1f5b31e4c03ecd"
MAX_INPUT_BYTES = 5_000_000
COORDINATE_GRID_DEGREES = 0.01
LOW_SNAP_CELL_MINIMUM_RECORDS = 3


class EvidenceError(ValueError):
    pass


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def count_by(items, selector):
    counts = Counter()
    for item in items:
        counts[str(selector(item) or "UNKNOWN")] += 1
    return dict(sorted(counts.items()))


def records_for(document, key):
    coverage = document.get("coverage")
    section = coverage.get(key) if isinstance(coverage, dict) else None
    records = section.get("records") if isinstance(section, dict) else None
    if not isinstance(records, list):
        raise EvidenceError(f"coverage.{key}.records is missing")
    return records


def validate_record_ids(records, label):
    seen = set()
    for index, row in enumerate(records):
        if not isinstance(row, dict):
            raise EvidenceError(f"coverage {label} record {index} must be an object")
        record_id = row.get("id")
        if isinstance(record_id, bool) or not isinstance(record_id, int) or record_id <= 0:
            raise EvidenceError(f"coverage {label} record {index} requires a positive integer id")
        if record_id in seen:
            raise EvidenceError(f"coverage {label} contains duplicate record id {record_id}")
        seen.add(record_id)


def valid_coordinates(row):
    coordinates = row.get("coordinates")
    if not isinstance(coordinates, dict):
        return False
    latitude, longitude = coordinates.get("lat"), coordinates.get("lng")
    if any(isinstance(value, bool) or not isinstance(value, (int, float)) for value in (latitude, longitude)):
        return False
    return math.isfinite(latitude) and math.isfinite(longitude) and -90 <= latitude <= 90 and -180 <= longitude <= 180


def coordinate_grid_coverage(records):
    cells = defaultdict(lambda: {"total": 0, "snapped": 0, "unsnapped": 0, "record_ids": []})
    for row in records:
        if not valid_coordinates(row):
            continue
        coordinates = row["coordinates"]
        latitude_cell = math.floor(coordinates["lat"] / COORDINATE_GRID_DEGREES)
        longitude_cell = math.floor(coordinates["lng"] / COORDINATE_GRID_DEGREES)
        key = (latitude_cell, longitude_cell)
        entry = cells[key]
        entry["total"] += 1
        snapped = isinstance(row.get("snap"), dict) and row["snap"].get("status") == "SNAPPED"
        entry["snapped" if snapped else "unsnapped"] += 1
        entry["record_ids"].append(row.get("id"))

    results = []
    for (latitude_cell, longitude_cell), counts in sorted(cells.items()):
        fraction = round(counts["snapped"] / counts["total"], 6) if counts["total"] else 0
        results.append({
            "latitude_min_degrees": round(latitude_cell * COORDINATE_GRID_DEGREES, 2),
            "longitude_min_degrees": round(longitude_cell * COORDINATE_GRID_DEGREES, 2),
            **counts,
            "snappable_fraction": fraction,
            "low_snap_candidate": counts["total"] >= LOW_SNAP_CELL_MINIMUM_RECORDS and fraction < 0.5,
        })
    return {
        "cell_size_degrees": COORDINATE_GRID_DEGREES,
        "minimum_records_for_low_snap_candidate": LOW_SNAP_CELL_MINIMUM_RECORDS,
        "interpretation": "Stored transit records grouped by coordinate cell; empty cells, population coverage, pedestrian access, and geographic service coverage are not measured.",
        "cells_with_records": results,
        "low_snap_candidate_cells": [cell for cell in results if cell["low_snap_candidate"]],
    }


def summarize_points(records, snap_radius_meters):
    validate_record_ids(records, "points")
    coordinate_status_discrepancies = []
    snapped = []
    for row in records:
        actual_coordinate_status = "STORED_COORDINATES" if valid_coordinates(row) else "MISSING_OR_INVALID"
        if row.get("coordinate_status") != actual_coordinate_status:
            coordinate_status_discrepancies.append({
                "id": row["id"], "reported_status": row.get("coordinate_status"),
                "measured_status": actual_coordinate_status,
            })
        snap = row.get("snap")
        if not isinstance(snap, dict):
            raise EvidenceError(f"coverage point {row['id']} has no snap result object")
        status = snap.get("status")
        if status not in {"SNAPPED", "ROUTE_UNAVAILABLE", "UNAVAILABLE"}:
            raise EvidenceError(f"coverage point {row['id']} has unsupported snap status")
        if status == "SNAPPED":
            distance = snap.get("snap_distance_meters")
            node_id = snap.get("node_id")
            component_id = snap.get("component_id")
            if not valid_coordinates(row):
                raise EvidenceError(f"coverage point {row['id']} is marked SNAPPED with invalid coordinates")
            if (isinstance(distance, bool) or not isinstance(distance, (int, float)) or not math.isfinite(distance)
                or distance < 0 or distance > snap_radius_meters):
                raise EvidenceError(f"coverage point {row['id']} exceeds the pinned snap radius or has invalid snap distance")
            if (isinstance(node_id, bool) or not isinstance(node_id, int) or node_id <= 0
                or isinstance(component_id, bool) or not isinstance(component_id, int) or component_id <= 0):
                raise EvidenceError(f"coverage point {row['id']} has invalid snapped node or component identity")
            snapped.append(row)
        elif not isinstance(snap.get("reason_code"), str) or not snap["reason_code"].strip():
            raise EvidenceError(f"coverage point {row['id']} has unavailable snap status without a reason code")
    unsnapped = [row for row in records if row not in snapped]
    components = defaultdict(lambda: {"total": 0, "largest_component": 0})
    city = defaultdict(lambda: {"total": 0, "snapped": 0, "unsnapped": 0})
    for row in records:
        snap = row.get("snap") if isinstance(row.get("snap"), dict) else {}
        if snap.get("status") == "SNAPPED":
            component = str(snap.get("component_id", "UNKNOWN"))
            components[component]["total"] += 1
            components[component]["largest_component"] += int(bool(snap.get("largest_component")))
        city_id = str(row.get("city_id", "UNKNOWN"))
        city[city_id]["total"] += 1
        city[city_id]["snapped" if snap.get("status") == "SNAPPED" else "unsnapped"] += 1
    return {
        "measurement_scope": "STORED_TRANSIT_RECORDS_ONLY",
        "total": len(records),
        "with_usable_coordinates": sum(valid_coordinates(row) for row in records),
        "coordinate_status_discrepancies": coordinate_status_discrepancies,
        "invalid_or_missing_coordinate_ids": [
            {"id": row.get("id"), "reported_status": row.get("coordinate_status")}
            for row in records if not valid_coordinates(row)
        ],
        "snappable_within_pinned_policy": len(snapped),
        "unsnappable_ids_and_reasons": [
            {"id": row.get("id"), "reason_code": (row.get("snap") or {}).get("reason_code"),
             "coordinate_status": row.get("coordinate_status"), "trust_status": row.get("trust_status")}
            for row in unsnapped
        ],
        "unsnappable_reason_counts": count_by(unsnapped, lambda row: (row.get("snap") or {}).get("reason_code")),
        "walkable_component_distribution": dict(sorted(components.items(), key=lambda pair: pair[0])),
        "trust_status_counts": count_by(records, lambda row: row.get("trust_status")),
        "verification_state_counts": count_by(records, lambda row: row.get("verification_state")),
        "source_type_counts": count_by(records, lambda row: (row.get("source") or {}).get("source_type")),
        "administrative_city_id_coverage": {
            key: {**value, "snappable_fraction": round(value["snapped"] / value["total"], 6) if value["total"] else 0}
            for key, value in sorted(city.items())
        },
        "administrative_groups_below_half_snappable": [
            {"city_id": key, **value}
            for key, value in sorted(city.items())
            if value["total"] and value["snapped"] / value["total"] < 0.5
        ],
        "administrative_grouping_is_geographic_proof": False,
        "geographic_sidewalk_population_and_field_coverage_measured": False,
        "coordinate_grid_coverage": coordinate_grid_coverage(records),
    }


def quality_discrepancies(summary, evidence_counts, provenance_validation):
    comparisons = {
        "long_edges_over_250m": ("long_edges_over_250m", "quality.legacy_summary.long_edges_over_250m", "quality.graph_evidence.long_edges_over_250m"),
        "boundary_nodes_within_0_0005_degrees": ("boundary_nodes", "quality.legacy_summary.boundary_nodes", "quality.graph_evidence.boundary_nodes"),
        "crossing_tagged_nodes": ("crossing_tagged_nodes", "quality.legacy_summary.crossing_nodes", "quality.graph_evidence.crossing_tagged_nodes"),
    }
    claim_status = {row["id"]: row["status"] for row in (provenance_validation or {}).get("claim_results", [])}
    rows = []
    for summary_key, (detail_key, summary_claim, detailed_claim) in comparisons.items():
        summary_value = summary.get(summary_key)
        detail_value = evidence_counts.get(detail_key)
        if summary_value != detail_value:
            rows.append({
                "summary_field": summary_key,
                "summary_value": summary_value,
                "summary_interpretation": claim_status.get(summary_claim, "UNVERIFIED_AS_MEASUREMENT"),
                "detailed_evidence_field": detail_key,
                "detailed_evidence_value": detail_value,
                "detailed_evidence_status": claim_status.get(detailed_claim, "UNVERIFIED"),
            })
    return rows


def restriction_count_reconciliation(provenance_validation):
    if not isinstance(provenance_validation, dict) or provenance_validation.get("status") != "PASS":
        return {"status": "BLOCKED_PROVENANCE_REGISTRY_NOT_VALIDATED"}
    claims = {row["id"]: row["value"] for row in provenance_validation.get("claim_results", [])}
    required = ("restrictions.source_relations", "restrictions.source_vehicle_only_classifications",
                "restrictions.graph_stored_relations", "restrictions.source_only_omitted_relations")
    if any(metric_id not in claims for metric_id in required):
        return {"status": "BLOCKED_REQUIRED_METRIC_CLAIM_MISSING"}
    source_total = claims["restrictions.source_relations"]
    stored = claims["restrictions.graph_stored_relations"]
    omitted = claims["restrictions.source_only_omitted_relations"]
    return {
        "status": "RESOLVED_DIFFERENT_DENOMINATORS" if source_total == stored + omitted else "UNRESOLVED_ARITHMETIC_MISMATCH",
        "source_tag_scanned_relations": source_total,
        "source_vehicle_only_classifications": claims["restrictions.source_vehicle_only_classifications"],
        "graph_stored_relations": stored,
        "source_only_omitted_relations": omitted,
        "equation_holds": source_total == stored + omitted,
        "registry_sha256": provenance_validation.get("registry_sha256"),
    }


def summarize_corpus(corpus):
    cases = corpus.get("cases")
    if not isinstance(cases, list) or len(cases) != 10:
        raise EvidenceError("the pinned real Cebu corpus must contain exactly ten stored cases")
    compact = []
    case_id_indexes = defaultdict(list)
    for index, case in enumerate(cases):
        if not isinstance(case, dict):
            raise EvidenceError(f"corpus case {index} is not an object")
        case_id = case.get("case_id")
        if not isinstance(case_id, str) or not case_id.strip():
            raise EvidenceError(f"corpus case {index} has a missing case_id")
        case_id_indexes[case_id].append(index)
        route = case.get("route") or {}
        source = case.get("source_records") or {}
        origin, destination = source.get("origin") or {}, source.get("destination") or {}
        geometry = route.get("geometry") or {}
        coordinates = geometry.get("coordinates") or []
        edges = route.get("edges") or []
        node_ids = route.get("node_ids") or []
        status = route.get("status")
        found = status == "ROUTE_FOUND"
        if status not in {"ROUTE_FOUND", "ROUTE_UNAVAILABLE"}:
            raise EvidenceError(f"corpus case {index} has an unsupported route status")
        if found:
            origin_snap, destination_snap = route.get("origin_snap"), route.get("destination_snap")
            if (not isinstance(geometry, dict) or geometry.get("type") != "LineString"
                or not isinstance(coordinates, list) or len(coordinates) < 2
                or not isinstance(edges, list) or len(edges) != len(coordinates) - 1
                or not isinstance(node_ids, list) or len(node_ids) != len(coordinates)
                or any(isinstance(node_id, bool) or not isinstance(node_id, int) or node_id <= 0 for node_id in node_ids)
                or len(set(node_ids)) != len(node_ids)
                or not isinstance(origin_snap, dict) or not isinstance(destination_snap, dict)
                or origin_snap.get("status") != "SNAPPED" or destination_snap.get("status") != "SNAPPED"):
                raise EvidenceError(f"corpus case {index} has incomplete successful route geometry")
            for snap in (origin_snap, destination_snap):
                snap_distance = snap.get("snap_distance_meters")
                if (isinstance(snap_distance, bool) or not isinstance(snap_distance, (int, float))
                    or not math.isfinite(snap_distance) or snap_distance < 0 or snap_distance > 25):
                    raise EvidenceError(f"corpus case {index} snap exceeds the pinned 25 m policy")
            for point in coordinates:
                if (not isinstance(point, list) or len(point) != 2
                    or any(isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(value) for value in point)
                    or not -180 <= point[0] <= 180 or not -90 <= point[1] <= 90):
                    raise EvidenceError(f"corpus case {index} has invalid route coordinates")
            if any(left == right for left, right in zip(coordinates, coordinates[1:])):
                raise EvidenceError(f"corpus case {index} has repeated adjacent route coordinates")
            if (not node_ids or node_ids[0] != origin_snap.get("node_id") or node_ids[-1] != destination_snap.get("node_id")
                or coordinates[0] != [origin_snap.get("node_longitude"), origin_snap.get("node_latitude")]
                or coordinates[-1] != [destination_snap.get("node_longitude"), destination_snap.get("node_latitude")]):
                raise EvidenceError(f"corpus case {index} geometry endpoints do not match source graph snaps")
            distance = route.get("distance_meters")
            if isinstance(distance, bool) or not isinstance(distance, (int, float)) or not math.isfinite(distance) or distance <= 0:
                raise EvidenceError(f"corpus case {index} has invalid route distance")
            edge_distance = 0.0
            for edge in edges:
                if (not isinstance(edge, dict) or isinstance(edge.get("way_id"), bool)
                    or not isinstance(edge.get("way_id"), int) or edge["way_id"] <= 0
                    or isinstance(edge.get("segment_index"), bool) or not isinstance(edge.get("segment_index"), int)
                    or edge["segment_index"] < 0 or isinstance(edge.get("length_meters"), bool)
                    or not isinstance(edge.get("length_meters"), (int, float))
                    or not math.isfinite(edge["length_meters"]) or edge["length_meters"] <= 0):
                    raise EvidenceError(f"corpus case {index} has invalid source edge evidence")
                edge_distance += edge["length_meters"]
            if abs(edge_distance - distance) > 0.05:
                raise EvidenceError(f"corpus case {index} edge lengths do not match the reported distance")
        elif (route.get("geometry") is not None or edges != [] or node_ids != []
              or not isinstance(route.get("reason_code"), str) or not route["reason_code"]):
            raise EvidenceError(f"corpus case {index} unavailable route must retain a reason and no path geometry")
        compact.append({
            "case_index": index,
            "case_id": case.get("case_id"),
            "case_key": f"{index:02d}:{case.get('case_id')}",
            "origin_type_id": [origin.get("type"), origin.get("id")],
            "destination_type_id": [destination.get("type"), destination.get("id")],
            "origin_trust": origin.get("trust_status"),
            "destination_trust": destination.get("trust_status"),
            "status": route.get("status"),
            "reason_code": route.get("reason_code"),
            "component_id": route.get("component_id"),
            "distance_meters": route.get("distance_meters"),
            "geometry_points": len(coordinates) if isinstance(coordinates, list) else 0,
            "edge_count": len(edges) if isinstance(edges, list) else 0,
            "geometry_edge_count_continuous": True,
            "source_geometry_is_field_safety_evidence": False,
        })
    disconnected = [row for row in compact if row["reason_code"] == "component_disconnected"]
    missing_geometry = [row["case_index"] for row in compact if row["status"] == "ROUTE_FOUND" and row["geometry_points"] < 2]
    return {
        "cases_total": len(compact),
        "repeated_descriptive_case_ids": {
            case_id: indexes for case_id, indexes in sorted(case_id_indexes.items()) if len(indexes) > 1
        },
        "routes_found": sum(row["status"] == "ROUTE_FOUND" for row in compact),
        "routes_unavailable": sum(row["status"] == "ROUTE_UNAVAILABLE" for row in compact),
        "component_disconnected_cases": disconnected,
        "missing_geometry_case_indexes": missing_geometry,
        "cases": compact,
    }


def build_summary(document, report_sha256, provenance_validation=None):
    if document.get("format") != "biyahe-cebu-offline-coverage-v1" or document.get("complete") is not True:
        raise EvidenceError("coverage evidence is incomplete or uses an unsupported format")
    source = document.get("source") or {}
    graph = document.get("graph_validation") or {}
    if source.get("graph_source_sha256") != EXPECTED_SOURCE_SHA256:
        raise EvidenceError("source PBF identity differs from the pinned snapshot")
    if source.get("transport_backup_sha256") != EXPECTED_BACKUP_SHA256:
        raise EvidenceError("transport backup identity differs from the pinned dump")
    if graph.get("artifact_sha256") != EXPECTED_GRAPH_SHA256:
        raise EvidenceError("graph artifact identity differs from the pinned SQLite artifact")
    if (source.get("graph_fingerprint") != EXPECTED_GRAPH_FINGERPRINT
        or graph.get("graph_fingerprint") != EXPECTED_GRAPH_FINGERPRINT):
        raise EvidenceError("graph content fingerprint differs from the pinned artifact")
    if (graph.get("nodes") != 76685 or graph.get("edges") != 78225
        or graph.get("restriction_relations") != 619):
        raise EvidenceError("graph row or restriction counts differ from the pinned snapshot")
    if graph.get("routing_ready") is not True or graph.get("restriction_inventory_complete") is not True:
        raise EvidenceError("pinned evidence does not establish routing and restriction readiness")
    restriction_counts = graph.get("restriction_counts") or {}
    if (restriction_counts.get("total") != 619 or restriction_counts.get("pedestrian_applicable") != 0
        or restriction_counts.get("ambiguous") != 0 or restriction_counts.get("unsupported_applicable") != 0):
        raise EvidenceError("restriction inventory differs from the pinned Batch031 classification")
    stops = records_for(document, "stops")
    places = records_for(document, "places")
    coverage = document.get("coverage")
    transfers = coverage.get("transfers") if isinstance(coverage, dict) else None
    if not isinstance(transfers, dict) or not isinstance(transfers.get("records"), list):
        raise EvidenceError("coverage.transfers.records is missing")
    validate_record_ids(transfers["records"], "transfers")
    for record in transfers["records"]:
        if record.get("graph_route_status") not in {"ROUTE_FOUND", "ROUTE_UNAVAILABLE"}:
            raise EvidenceError(f"transfer {record['id']} has unsupported graph-route status")
        if record.get("graph_route_status") == "ROUTE_UNAVAILABLE" and not isinstance(record.get("graph_route_reason_code"), str):
            raise EvidenceError(f"transfer {record['id']} has an unavailable graph route without a reason")
    snap_radius = (document.get("snap_policy") or {}).get("maximum_distance_meters")
    if isinstance(snap_radius, bool) or not isinstance(snap_radius, (int, float)) or snap_radius != 25:
        raise EvidenceError("coverage snap policy must retain the pinned 25 m radius")
    quality = document.get("quality_evidence") or {}
    if quality.get("source_sha256") != EXPECTED_SOURCE_SHA256 or quality.get("graph_fingerprint") != EXPECTED_GRAPH_FINGERPRINT:
        raise EvidenceError("quality evidence is not bound to the pinned source and graph fingerprint")
    counts = quality.get("counts") or {}
    discrepancies = quality_discrepancies(document.get("quality_summary") or {}, counts, provenance_validation)
    restrictions = restriction_counts
    capabilities = graph.get("routing_capabilities") or {}
    return {
        "format": "biyahe-offline-coverage-diagnostics-v1",
        "mode": "CARRIED_FORWARD_FROM_PINNED_EVIDENCE",
        "recomputed_from_graph": False,
        "analysis_checkpoint": {"phase": "PINNED_EVIDENCE_SUMMARY", "complete": True,
                                "restart_policy": "RESTART_FROM_IMMUTABLE_INPUT_ON_INTERRUPTION", "maximum_input_bytes": MAX_INPUT_BYTES},
        "source": {"coverage_report_sha256": report_sha256, "graph_sha256": EXPECTED_GRAPH_SHA256,
                   "source_pbf_sha256": EXPECTED_SOURCE_SHA256, "transport_backup_sha256": EXPECTED_BACKUP_SHA256,
                   "graph_fingerprint": graph.get("graph_fingerprint")},
        "snap_policy": document.get("snap_policy"),
        "evidence_provenance": provenance_validation,
        "stops": summarize_points(stops, snap_radius),
        "places": summarize_points(places, snap_radius),
        "transfers": {
            "total": len(transfers["records"]),
            "graph_route_connected": sum(row.get("graph_route_status") == "ROUTE_FOUND" for row in transfers["records"]),
            "graph_route_unavailable": sum(row.get("graph_route_status") != "ROUTE_FOUND" for row in transfers["records"]),
            "verification_state_counts": count_by(transfers["records"], lambda row: row.get("trust_status")),
            "unverified_transfer_ids": [row.get("id") for row in transfers["records"] if row.get("trust_status") != "VERIFIED_RECORD"],
            "graph_unavailable_transfer_pairs": [
                {"id": row.get("id"), "from_stop_id": row.get("from_stop_id"), "to_stop_id": row.get("to_stop_id"),
                 "reason_code": row.get("graph_route_reason_code")}
                for row in transfers["records"] if row.get("graph_route_status") != "ROUTE_FOUND"
            ],
            "field_verification": "NOT_ESTABLISHED_BY_GRAPH_CONNECTIVITY",
            "graph_connectivity_is_field_verification": False,
        },
        "journey_corpus": summarize_corpus(document.get("journey_corpus") or {}),
        "graph_quality": {
            "detailed_evidence_counts": counts,
            "summary_discrepancies": discrepancies,
            "counts_source": "batch031_graph_quality_review_and_hash_bound_quality_evidence",
            "quality_reconciliation_status": "RESOLVED_ZERO_FIELDS_ARE_UNMEASURED_DEFAULTS"
                if provenance_validation and provenance_validation.get("status") == "PASS"
                else "BLOCKED_PROVENANCE_REGISTRY_NOT_VALIDATED",
            "field_review_required": True,
        },
        "routing_capability": {
            "routing_ready_for_pinned_snapshot": graph.get("routing_ready"),
            "restriction_inventory_complete": graph.get("restriction_inventory_complete"),
            "restriction_counts": restrictions,
            "capabilities": capabilities,
            "count_reconciliation": restriction_count_reconciliation(provenance_validation),
        },
        "evidence_boundaries": {
            "field_safety": "UNKNOWN",
            "fare_verification": "NOT_INCLUDED_IN_COVERAGE_EVIDENCE",
            "graph_connectivity_proves_physical_transfer_safety": False,
            "source_mismatch_detected": False,
        },
    }


def render_markdown(summary):
    stops, places, transfers, corpus = summary["stops"], summary["places"], summary["transfers"], summary["journey_corpus"]
    discrepancy_rows = summary["graph_quality"]["summary_discrepancies"]
    restriction_reconciliation = summary["routing_capability"]["count_reconciliation"]
    administrative_lines = [
        "## Administrative city-ID grouping (not geographic proof)", "",
        "The pinned source report groups records by `city_id`; it does not provide evidence that the ID corresponds to current boundaries or pedestrian access.", "",
        "| Record type | City ID | Snappable | Total | Fraction |", "|---|---:|---:|---:|---:|",
    ]
    for record_type, points in (("Stops", stops), ("Places", places)):
        for city_id, counts in points["administrative_city_id_coverage"].items():
            administrative_lines.append(
                f"| {record_type} | {city_id} | {counts['snapped']} | {counts['total']} | {counts['snappable_fraction']:.1%} |"
            )
    component_lines = ["", "Snapped stop records by stored walkable component:", ""]
    if stops["walkable_component_distribution"]:
        component_lines.extend(
            f"- Component {component_id}: {counts['total']} snapped stop record(s); {counts['largest_component']} marked as in the largest component."
            for component_id, counts in stops["walkable_component_distribution"].items()
        )
    else:
        component_lines.append("- No snapped stop records are available in the pinned evidence.")
    grid_lines = ["", "Observed-record grid cells (0.01 degree; candidate means at least three records and under 50% snapped):", ""]
    candidates = stops["coordinate_grid_coverage"]["low_snap_candidate_cells"]
    if candidates:
        grid_lines.extend(
            f"- Stops in cell ({cell['latitude_min_degrees']:.2f}, {cell['longitude_min_degrees']:.2f}): {cell['snapped']}/{cell['total']} snapped; record IDs {cell['record_ids']}."
            for cell in candidates
        )
    else:
        grid_lines.append("- No candidate cell met the stated threshold in the stored stop records.")
    grid_lines.append("- This is a queue for data review, not a statement about empty cells, population, sidewalks, or the whole geographic area.")
    lines = [
        "# Cebu offline graph coverage diagnostics", "",
        "Mode: `CARRIED_FORWARD_FROM_PINNED_EVIDENCE`. This report summarizes the hash-pinned Batch032 report; it does not reopen or recompute the SQLite graph.", "",
        f"- Graph: `{summary['source']['graph_sha256']}`", f"- PBF: `{summary['source']['source_pbf_sha256']}`",
        f"- Evidence registry SHA-256: `{(summary.get('evidence_provenance') or {}).get('registry_sha256', 'UNVERIFIED')}`",
        f"- Stops: {stops['snappable_within_pinned_policy']}/{stops['total']} snappable at the pinned 25 m policy; {len(stops['unsnappable_ids_and_reasons'])} unsnappable.",
        f"- Places: {places['snappable_within_pinned_policy']}/{places['total']} snappable; {len(places['unsnappable_ids_and_reasons'])} unsnappable.",
        f"- Transfer records: {transfers['graph_route_connected']} graph-connected; {len(transfers['unverified_transfer_ids'])} not verified for physical interchange.",
        f"- Stored route corpus: {corpus['routes_found']} found, {corpus['routes_unavailable']} unavailable, {len(corpus['component_disconnected_cases'])} explicitly component-disconnected.",
        f"- Restriction inventory: {restriction_reconciliation.get('source_tag_scanned_relations', 'UNVERIFIED')} source relations = {restriction_reconciliation.get('graph_stored_relations', 'UNVERIFIED')} stored + {restriction_reconciliation.get('source_only_omitted_relations', 'UNVERIFIED')} omitted, status `{restriction_reconciliation.get('status', 'UNVERIFIED')}`.",
        "", "## Coverage and confidence", "",
        "Counts describe stored transit records only. Snapping and component membership describe graph coverage, not geographic, sidewalk, population, or field coverage. Trust/source counts are preserved separately. Administrative city-ID groupings do not establish geographic coverage or field access.", "",
        *administrative_lines, *component_lines, *grid_lines, "",
        "## Graph quality evidence discrepancies", "",
    ]
    if discrepancy_rows:
        lines += ["| Legacy summary field | Legacy meaning | Detailed graph evidence |", "|---|---|---:|"]
        for row in discrepancy_rows:
            lines.append(f"| `{row['summary_field']}`: {row['summary_value']} | `{row['summary_interpretation']}` | `{row['detailed_evidence_field']}`: {row['detailed_evidence_value']} ({row['detailed_evidence_status']}) |")
        lines.append("")
        lines.append("The historical zero values came from metadata lookups with `?? 0` before the detailed audit values were attached. They are not measured zero counts. Detailed counts come from the hash-bound graph quality audit and do not establish field safety.")
    else:
        lines.append("No compared quality-summary discrepancy was detected.")
    lines += ["", "## Unsnapppable stop records", ""]
    lines += [f"- ID {row['id']}: `{row['reason_code'] or 'unknown'}`, trust `{row['trust_status'] or 'unknown'}`" for row in stops["unsnappable_ids_and_reasons"]]
    lines += ["", "## Unsnapppable place records", ""]
    lines += [f"- ID {row['id']}: `{row['reason_code'] or 'unknown'}`, trust `{row['trust_status'] or 'unknown'}`" for row in places["unsnappable_ids_and_reasons"]]
    lines += ["", "## Disconnected stored corpus cases", ""]
    lines += [f"- Case {row['case_index']} `{row['case_id']}`: {row['origin_type_id']} → {row['destination_type_id']}" for row in corpus["component_disconnected_cases"]]
    lines += ["", "## Limits", "", "This stored evidence contains no field-safety verification and no fare verification. A graph path does not prove sidewalk continuity, crossing safety, legal access, current conditions, or a safe transfer. The ten stored Cebu cases are carried forward, not replayed by this command.", ""]
    return "\n".join(lines)


def write_bundle(output_dir: Path, summary):
    if output_dir.exists() or output_dir.with_name(output_dir.name + ".building").exists():
        raise EvidenceError("output directory or its .building path already exists")
    output_dir.parent.mkdir(parents=True, exist_ok=True)
    staging = output_dir.with_name(output_dir.name + ".building")
    staging.mkdir()
    try:
        json_path = staging / "coverage-diagnostics.json"
        md_path = staging / "coverage-diagnostics.md"
        json_path.write_text(json.dumps(summary, ensure_ascii=False, sort_keys=True, indent=2) + "\n", encoding="utf-8")
        md_path.write_text(render_markdown(summary), encoding="utf-8")
        for path in (json_path, md_path):
            with path.open("rb") as handle:
                os.fsync(handle.fileno())
        if output_dir.exists():
            raise EvidenceError("output directory appeared before publication")
        os.rename(staging, output_dir)
    except Exception:
        shutil.rmtree(staging, ignore_errors=True)
        raise


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--input", type=Path, default=Path(__file__).resolve().parents[2] / "docs/reports/batch032-cebu-coverage.json")
    parser.add_argument("--provenance-registry", type=Path, default=Path(__file__).resolve().parents[2] / "docs/reports/batch041-evidence-provenance.json")
    parser.add_argument("--expected-report-sha256", default=EXPECTED_REPORT_SHA256)
    parser.add_argument("--output-dir", type=Path, required=True, help="New directory; existing output is never overwritten")
    args = parser.parse_args(argv)
    try:
        data = args.input.read_bytes()
        if len(data) > MAX_INPUT_BYTES:
            raise EvidenceError("coverage evidence exceeds the 5 MB bounded input limit")
        digest = sha256_bytes(data)
        if digest != args.expected_report_sha256 or digest != EXPECTED_REPORT_SHA256:
            raise EvidenceError("coverage report SHA-256 does not match the pinned Batch032 evidence")
        document = json.loads(data)
        provenance_validation = validate_registry_file(args.provenance_registry, Path(__file__).resolve().parents[2])
        summary = build_summary(document, digest, provenance_validation)
        write_bundle(args.output_dir, summary)
        print(f"COVERAGE_DIAGNOSTICS=CARRIED_FORWARD_FROM_PINNED_EVIDENCE")
        print(f"REPORT_SHA256={digest}")
        print(f"STOPS={summary['stops']['snappable_within_pinned_policy']}/{summary['stops']['total']}")
        print(f"PLACES={summary['places']['snappable_within_pinned_policy']}/{summary['places']['total']}")
        print(f"QUALITY_DISCREPANCIES={len(summary['graph_quality']['summary_discrepancies'])}")
        print(f"EVIDENCE_REGISTRY_SHA256={provenance_validation['registry_sha256']}")
        print(f"RESTRICTION_RECONCILIATION={summary['routing_capability']['count_reconciliation']['status']}")
        print(f"OUTPUT_DIR={args.output_dir}")
        return 0
    except (OSError, json.JSONDecodeError, EvidenceError, ProvenanceError) as error:
        print(f"COVERAGE_DIAGNOSTICS_ERROR={error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
