#!/usr/bin/env python3
"""Verify Batch041 evidence report hashes, metric definitions, and reconciliations."""

from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import sys

from evidence_provenance import ProvenanceError, validate_registry_file


def write_new(path: Path, document: dict) -> None:
    if path.exists() or path.with_name(path.name + ".building").exists():
        raise ProvenanceError("output path or .building path already exists")
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(path.name + ".building")
    owns_temporary = False
    try:
        with temporary.open("x", encoding="utf-8") as handle:
            owns_temporary = True
            handle.write(json.dumps(document, sort_keys=True, ensure_ascii=False, indent=2) + "\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.link(temporary, path)
        temporary.unlink()
        owns_temporary = False
    except Exception:
        if owns_temporary:
            temporary.unlink(missing_ok=True)
        raise


def main(argv=None) -> int:
    repo = Path(__file__).resolve().parents[2]
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--registry", type=Path, default=repo / "docs/reports/batch041-evidence-provenance.json")
    parser.add_argument("--output", type=Path, help="Create a new machine-readable verification report; existing files are never overwritten.")
    args = parser.parse_args(argv)
    try:
        result = validate_registry_file(args.registry, repo)
        if args.output:
            write_new(args.output, result)
        print(f"EVIDENCE_PROVENANCE={result['status']}")
        print(f"SOURCES_VERIFIED={result['sources_verified']}")
        print(f"METRIC_CLAIMS_VERIFIED={result['metric_claims_verified']}")
        print(f"UNRESOLVED_CONFLICTS={len(result['unresolved_conflicts'])}")
        if args.output:
            print(f"REPORT={args.output}")
        return 0
    except (OSError, ProvenanceError) as error:
        print(f"EVIDENCE_PROVENANCE_ERROR={error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
