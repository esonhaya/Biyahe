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

EXPECTED_REPORT_SHA256 = "6caf101177904bee6fda1b12cab9c5cda36ddaf7ecc08b0d9778c0517123c81b"
EXPECTED_GRAPH_SHA256 = "29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8"
EXPECTED_SOURCE_SHA256 = "787cac20ecc586b754b1a464643a040566d486457c6a37bc0b11ce683bc66d4d"
EXPECTED_BACKUP_SHA256 = "e55af400fde88d0a3434b194e0625e204eb0a2726374eabb8286c152b11a4a92"
EXPECTED_GRAPH_FINGERPRINT = "a0d9f1cb8289cf1f9bde3905d1ad8a57124ee41120153b4efc1f5b31e4c03ecd"
BATCH039_NARRATIVE_REPORTED_RESTRICTIONS = 648
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
    records = document.get("coverage", {}).get(key, {}).get("records")
    if not isinstance(records, list):
        raise EvidenceError(f"coverage.{key}.records is missing")
    return records


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


def summarize_points(records):
    if any(not isinstance(row, dict) for row in records):
        raise EvidenceError("coverage point records must be objects")
    snapped = [row for row in records if isinstance(row.get("snap"), dict) and row["snap"].get("status") == "SNAPPED"]
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
        "total": len(records),
        "with_usable_coordinates": sum(valid_coordinates(row) for row in records),
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
        "coordinate_grid_coverage": coordinate_grid_coverage(records),
    }


def quality_discrepancies(summary, evidence_counts):
    comparisons = {
        "long_edges_over_250m": "long_edges_over_250m",
        "boundary_nodes_within_0_0005_degrees": "boundary_nodes",
        "crossing_tagged_nodes": "crossing_tagged_nodes",
    }
    return [
        {"summary_field": summary_key, "summary_value": summary.get(summary_key),
         "detailed_evidence_field": detail_key, "detailed_evidence_value": evidence_counts.get(detail_key)}
        for summary_key, detail_key in comparisons.items()
        if summary.get(summary_key) != evidence_counts.get(detail_key)
    ]


def summarize_corpus(corpus):
    cases = corpus.get("cases")
    if not isinstance(cases, list) or len(cases) != 10:
        raise EvidenceError("the pinned real Cebu corpus must contain exactly ten stored cases")
    compact = []
    for index, case in enumerate(cases):
        if not isinstance(case, dict):
            raise EvidenceError(f"corpus case {index} is not an object")
        route = case.get("route") or {}
        source = case.get("source_records") or {}
        origin, destination = source.get("origin") or {}, source.get("destination") or {}
        geometry = route.get("geometry") or {}
        coordinates = geometry.get("coordinates") or []
        edges = route.get("edges") or []
        found = route.get("status") == "ROUTE_FOUND"
        compact.append({
            "case_index": index,
            "case_id": case.get("case_id"),
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
            "geometry_edge_count_continuous": not found or (len(coordinates) >= 2 and len(edges) == len(coordinates) - 1),
            "source_geometry_is_field_safety_evidence": False,
        })
    disconnected = [row for row in compact if row["reason_code"] == "component_disconnected"]
    missing_geometry = [row["case_index"] for row in compact if row["status"] == "ROUTE_FOUND" and row["geometry_points"] < 2]
    return {
        "cases_total": len(compact),
        "routes_found": sum(row["status"] == "ROUTE_FOUND" for row in compact),
        "routes_unavailable": sum(row["status"] == "ROUTE_UNAVAILABLE" for row in compact),
        "component_disconnected_cases": disconnected,
        "missing_geometry_case_indexes": missing_geometry,
        "cases": compact,
    }


def build_summary(document, report_sha256):
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
    transfers = document.get("coverage", {}).get("transfers") or {}
    if not isinstance(transfers.get("records"), list) or any(not isinstance(row, dict) for row in transfers["records"]):
        raise EvidenceError("coverage.transfers.records is missing")
    quality = document.get("quality_evidence") or {}
    if quality.get("source_sha256") != EXPECTED_SOURCE_SHA256 or quality.get("graph_fingerprint") != EXPECTED_GRAPH_FINGERPRINT:
        raise EvidenceError("quality evidence is not bound to the pinned source and graph fingerprint")
    counts = quality.get("counts") or {}
    discrepancies = quality_discrepancies(document.get("quality_summary") or {}, counts)
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
        "stops": summarize_points(stops),
        "places": summarize_points(places),
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
        },
        "journey_corpus": summarize_corpus(document.get("journey_corpus") or {}),
        "graph_quality": {
            "detailed_evidence_counts": counts,
            "summary_discrepancies": discrepancies,
            "counts_source": "quality_evidence.counts",
            "field_review_required": True,
        },
        "routing_capability": {
            "routing_ready_for_pinned_snapshot": graph.get("routing_ready"),
            "restriction_inventory_complete": graph.get("restriction_inventory_complete"),
            "restriction_counts": restrictions,
            "capabilities": capabilities,
            "count_reconciliation": {
                "pinned_coverage_report_count": restrictions["total"],
                "batch039_narrative_reported_count": BATCH039_NARRATIVE_REPORTED_RESTRICTIONS,
                "counts_match": restrictions["total"] == BATCH039_NARRATIVE_REPORTED_RESTRICTIONS,
                "reconciled": False,
            },
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
        f"- Stops: {stops['snappable_within_pinned_policy']}/{stops['total']} snappable at the pinned 25 m policy; {len(stops['unsnappable_ids_and_reasons'])} unsnappable.",
        f"- Places: {places['snappable_within_pinned_policy']}/{places['total']} snappable; {len(places['unsnappable_ids_and_reasons'])} unsnappable.",
        f"- Transfer records: {transfers['graph_route_connected']} graph-connected; {len(transfers['unverified_transfer_ids'])} not verified for physical interchange.",
        f"- Stored route corpus: {corpus['routes_found']} found, {corpus['routes_unavailable']} unavailable, {len(corpus['component_disconnected_cases'])} explicitly component-disconnected.",
        f"- Restriction count: the pinned coverage report stores {summary['routing_capability']['count_reconciliation']['pinned_coverage_report_count']}; the Batch039 narrative reports {summary['routing_capability']['count_reconciliation']['batch039_narrative_reported_count']}. Reconciliation was not performed.",
        "", "## Coverage and confidence", "",
        "Snapping and component membership describe graph coverage only. Trust/source counts are preserved separately. Administrative city-ID groupings do not establish geographic coverage or field access.", "",
        *administrative_lines, *component_lines, *grid_lines, "",
        "## Graph quality evidence discrepancies", "",
    ]
    if discrepancy_rows:
        lines += ["| Summary value | Detailed evidence value |", "|---|---:|"]
        for row in discrepancy_rows:
            lines.append(f"| `{row['summary_field']}`: {row['summary_value']} | `{row['detailed_evidence_field']}`: {row['detailed_evidence_value']} |")
        lines.append("")
        lines.append("The discrepancy is surfaced without editing the historical input. Detailed `quality_evidence.counts` is retained as the carried-forward evidence source.")
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
        summary = build_summary(document, digest)
        write_bundle(args.output_dir, summary)
        print(f"COVERAGE_DIAGNOSTICS=CARRIED_FORWARD_FROM_PINNED_EVIDENCE")
        print(f"REPORT_SHA256={digest}")
        print(f"STOPS={summary['stops']['snappable_within_pinned_policy']}/{summary['stops']['total']}")
        print(f"PLACES={summary['places']['snappable_within_pinned_policy']}/{summary['places']['total']}")
        print(f"QUALITY_DISCREPANCIES={len(summary['graph_quality']['summary_discrepancies'])}")
        print(f"OUTPUT_DIR={args.output_dir}")
        return 0
    except (OSError, json.JSONDecodeError, EvidenceError) as error:
        print(f"COVERAGE_DIAGNOSTICS_ERROR={error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
