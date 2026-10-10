#!/usr/bin/env python3
"""Verify graph identity after guard preflight, then run and compare selected Cebu cases."""

from __future__ import annotations

import argparse
import hashlib
import os
from pathlib import Path
import re
import sqlite3
import subprocess
import sys
import time

MEM_FLOOR_KIB = 524288
SWAP_FLOOR_KIB = 131072
MAX_RSS_KIB = 262144
CASE_COUNT = 10
ROOT = Path(__file__).resolve().parents[2]
API_ROOT = ROOT / "api"
EXPECTED_REPORT = ROOT / "docs/reports/batch032-cebu-coverage.json"
EXPECTED_GRAPH = "29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8"


def parse_case_indices(value: str | None) -> list[int] | None:
    if value is None:
        return None
    parts = value.split(",")
    if not parts or any(not re.fullmatch(r"(?:0|[1-9][0-9]*)", part, flags=re.ASCII) for part in parts):
        raise ValueError("case indices must be comma-separated zero-based integers from 0 through 9")
    indices = [int(part) for part in parts]
    if any(index < 0 or index >= CASE_COUNT for index in indices):
        raise ValueError("case indices must be between 0 and 9")
    if len(set(indices)) != len(indices):
        raise ValueError("case indices must not be repeated")
    return sorted(indices)


def require_guard_preflight() -> tuple[int, int]:
    required = {
        "BIYAHE_RESOURCE_GUARD_PREFLIGHT": "PASS",
        "BIYAHE_RESOURCE_GUARD_BREACH_SAMPLES": "1",
        "BIYAHE_RESOURCE_GUARD_CONFIGURED_MIN_MEMAVAILABLE_KB": str(MEM_FLOOR_KIB),
        "BIYAHE_RESOURCE_GUARD_CONFIGURED_MIN_SWAPFREE_KB": str(SWAP_FLOOR_KIB),
        "BIYAHE_RESOURCE_GUARD_PREFLIGHT_SPACING_SECONDS": "5",
        "BIYAHE_RESOURCE_GUARD_MAX_PROCESS_TREE_RSS_KB": str(MAX_RSS_KIB),
    }
    for name, expected in required.items():
        if os.environ.get(name) != expected:
            raise RuntimeError(f"guarded resource preflight evidence is missing or differs: {name}")
    sample_count = int(os.environ.get("BIYAHE_RESOURCE_GUARD_PREFLIGHT_SAMPLES", "0"))
    minimum_memory = int(os.environ.get("BIYAHE_RESOURCE_GUARD_MIN_MEMAVAILABLE_KB", "0"))
    minimum_swap = int(os.environ.get("BIYAHE_RESOURCE_GUARD_MIN_SWAPFREE_KB", "0"))
    if sample_count < 5 or minimum_memory < MEM_FLOOR_KIB or minimum_swap < SWAP_FLOOR_KIB:
        raise RuntimeError("guarded preflight did not pass all five required resource samples")
    return minimum_memory, minimum_swap


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def sqlite_readonly_integrity(path: Path) -> tuple[str, float]:
    """Run SQLite quick_check using an immutable read-only connection."""
    started = time.monotonic_ns()
    uri = f"file:{path.resolve().as_posix()}?mode=ro&immutable=1"
    connection = sqlite3.connect(uri, uri=True, timeout=5.0)
    try:
        connection.execute("PRAGMA query_only=ON")
        result = connection.execute("PRAGMA quick_check(1)").fetchone()
        if result is None:
            return "EMPTY_RESULT", (time.monotonic_ns() - started) / 1_000_000
        return str(result[0]), (time.monotonic_ns() - started) / 1_000_000
    finally:
        connection.close()


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--graph", type=Path, required=True)
    parser.add_argument("--actual-output", type=Path, required=True)
    parser.add_argument("--comparison-output", type=Path, required=True)
    parser.add_argument("--case-indices", help="optional comma-separated case indices")
    args = parser.parse_args(argv)

    try:
        minimum_memory, minimum_swap = require_guard_preflight()
        selected_indices = parse_case_indices(args.case_indices)
    except (RuntimeError, ValueError) as error:
        print(f"BLOCKED_RESOURCE={error}", file=sys.stderr)
        return 4

    for output_path in (args.actual_output, args.comparison_output):
        if not output_path.parent.is_dir() or output_path.exists() or output_path.is_symlink() or output_path.with_name(output_path.name + ".building").exists():
            print(f"OUTPUT_NOT_WRITABLE_OR_NO_CLOBBER={output_path}", file=sys.stderr)
            return 2

    if not args.graph.is_file() or not os.access(args.graph, os.R_OK):
        print(f"BLOCKED_DEPENDENCY=PINNED_GRAPH_MISSING:{args.graph}", file=sys.stderr)
        return 20
    if not shutil_which("php") or not shutil_which("python3") or not (API_ROOT / "vendor/autoload.php").is_file():
        print("BLOCKED_DEPENDENCY=PHP_LARAVEL_OR_PYTHON_RUNTIME_MISSING", file=sys.stderr)
        return 20
    pdo_sqlite = subprocess.run(
        ["php", "-r", "exit(extension_loaded('pdo_sqlite') ? 0 : 1);"],
        cwd=API_ROOT, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False,
    )
    if pdo_sqlite.returncode != 0:
        print("BLOCKED_DEPENDENCY=PHP_PDO_SQLITE_MISSING", file=sys.stderr)
        return 20

    graph_path = args.graph.expanduser().resolve()
    hash_started = time.monotonic_ns()
    actual_graph_sha = sha256_file(graph_path)
    hash_milliseconds = (time.monotonic_ns() - hash_started) / 1_000_000
    print(f"GRAPH_SHA256={actual_graph_sha}")
    print(f"GRAPH_HASH_MILLISECONDS={hash_milliseconds:.3f}")
    print(f"GRAPH_IDENTITY={'PASS' if actual_graph_sha == EXPECTED_GRAPH else 'FAIL'}")
    if actual_graph_sha != EXPECTED_GRAPH:
        print(f"BLOCKED_DEPENDENCY=GRAPH_SHA256_MISMATCH expected={EXPECTED_GRAPH} actual={actual_graph_sha}", file=sys.stderr)
        return 20

    try:
        integrity, integrity_milliseconds = sqlite_readonly_integrity(graph_path)
    except sqlite3.Error as error:
        print(f"GRAPH_INTEGRITY=FAIL:{type(error).__name__}", file=sys.stderr)
        return 21
    print(f"GRAPH_INTEGRITY={'PASS' if integrity == 'ok' else 'FAIL'}")
    print(f"GRAPH_INTEGRITY_RESULT={integrity}")
    print(f"GRAPH_INTEGRITY_MILLISECONDS={integrity_milliseconds:.3f}")
    if integrity != "ok":
        return 21

    case_label = "FULL" if selected_indices is None else ",".join(str(index) for index in selected_indices)
    print(f"RESOURCE_PREFLIGHT_MINIMUM_MEMAVAILABLE_KIB={minimum_memory}")
    print(f"RESOURCE_PREFLIGHT_MINIMUM_SWAPFREE_KIB={minimum_swap}")
    print(f"CORPUS_SCOPE={case_label}")

    php_command = [
        "php", "artisan", "biyahe:pedestrian:verify-offline-corpus",
        f"--output={args.actual_output}",
    ]
    if selected_indices is not None:
        php_command.append("--cases=" + ",".join(str(index) for index in selected_indices))
    started = time.monotonic_ns()
    child_environment = os.environ.copy()
    child_environment["BIYAHE_OFFLINE_GRAPH_PATH"] = str(graph_path)
    child_environment["BIYAHE_OFFLINE_GRAPH_SHA256"] = actual_graph_sha
    result = subprocess.run(php_command, cwd=API_ROOT, env=child_environment, text=True, stdout=subprocess.PIPE,
                            stderr=subprocess.STDOUT, check=False)
    elapsed_milliseconds = (time.monotonic_ns() - started) / 1_000_000
    print(result.stdout, end="" if result.stdout.endswith("\n") else "\n")
    print(f"CHILD_COMMAND={php_command!r} EXIT={result.returncode} ELAPSED_MILLISECONDS={elapsed_milliseconds:.3f}")
    if result.returncode != 0:
        if "resource_budget_exceeded" in result.stdout:
            print("BLOCKED_RESOURCE=ADAPTER_INTERNAL_RESOURCE_GATE", file=sys.stderr)
            return 4
        if "could not find driver" in result.stdout.lower() or "class \"pdo\" not found" in result.stdout.lower():
            print("BLOCKED_DEPENDENCY=SQLITE_DRIVER_MISSING", file=sys.stderr)
            return 20
        return result.returncode

    comparator_command = [
        "python3", str(ROOT / "api/scripts/compare-offline-corpus.py"),
        "--expected", str(EXPECTED_REPORT),
        "--actual", str(args.actual_output),
        "--output", str(args.comparison_output),
    ]
    if selected_indices is not None:
        comparator_command.extend(["--case-indices", ",".join(str(index) for index in selected_indices)])
    started = time.monotonic_ns()
    comparison = subprocess.run(comparator_command, cwd=ROOT, text=True, stdout=subprocess.PIPE,
                                stderr=subprocess.STDOUT, check=False)
    elapsed_milliseconds = (time.monotonic_ns() - started) / 1_000_000
    print(comparison.stdout, end="" if comparison.stdout.endswith("\n") else "\n")
    print(f"CHILD_COMMAND={comparator_command!r} EXIT={comparison.returncode} ELAPSED_MILLISECONDS={elapsed_milliseconds:.3f}")
    print(f"ACTUAL_REPORT={args.actual_output}")
    print(f"COMPARISON_REPORT={args.comparison_output}")
    return comparison.returncode


def shutil_which(command: str) -> str | None:
    from shutil import which

    return which(command)


if __name__ == "__main__":
    raise SystemExit(main())
