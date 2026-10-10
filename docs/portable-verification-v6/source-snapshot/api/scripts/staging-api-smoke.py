#!/usr/bin/env python3
"""Read-only, machine-readable smoke checks for an already running staging API."""

from __future__ import annotations

import argparse
import ctypes
from datetime import datetime, timezone
import errno
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import tempfile
import time
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode, urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener

SENSITIVE_KEYS = {"authorization", "email", "phone", "password", "secret", "token", "lat", "lng", "latitude", "longitude", "contact"}


class RejectRedirects(HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, new_url):
        return None


def normalize_origin(base_url: str, allow_external: bool = False) -> str:
    parsed = urlsplit(base_url)
    if parsed.scheme not in {"http", "https"} or not parsed.hostname or parsed.username or parsed.password or parsed.query or parsed.fragment or parsed.path not in {"", "/"}:
        raise ValueError("base URL must be an HTTP(S) origin without credentials, path, query, or fragment")
    hostname = parsed.hostname.lower().rstrip(".")
    if hostname == "biyahe.ph" or hostname.endswith(".biyahe.ph"):
        raise ValueError("Biyahe public domains are never valid staging smoke targets")
    try:
        loopback = ipaddress.ip_address(hostname).is_loopback
    except ValueError:
        loopback = hostname == "localhost"
    if not loopback and not allow_external:
        raise ValueError("non-loopback staging requires --confirm-isolated-staging")
    return f"{parsed.scheme}://{parsed.netloc}".rstrip("/")


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z")


def redact(value: Any) -> Any:
    if isinstance(value, dict):
        return {key: "[REDACTED]" if key.lower() in SENSITIVE_KEYS else redact(child) for key, child in value.items()}
    if isinstance(value, list):
        return [redact(child) for child in value]
    return value


def atomic_no_clobber(path: Path, value: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary: str | None = None
    try:
        with tempfile.NamedTemporaryFile("w", encoding="utf-8", dir=path.parent, prefix=f".{path.name}.", delete=False) as stream:
            temporary = stream.name
            json.dump(value, stream, indent=2, sort_keys=True)
            stream.write("\n")
            stream.flush()
            os.fsync(stream.fileno())
        publish_no_clobber(Path(temporary), path)
    except FileExistsError as error:
        raise FileExistsError(f"refusing to overwrite smoke evidence: {path}") from error
    finally:
        if temporary is not None:
            try:
                os.unlink(temporary)
            except FileNotFoundError:
                pass


def publish_no_clobber(source: Path, destination: Path) -> None:
    if hasattr(os, "link"):
        os.link(source, destination)
    else:
        libc = ctypes.CDLL(None, use_errno=True)
        if hasattr(libc, "renameat2"):
            renameat2 = libc.renameat2
            renameat2.argtypes = (ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint)
            renameat2.restype = ctypes.c_int
            if renameat2(-100, os.fsencode(source), -100, os.fsencode(destination), 1) == 0:
                directory_fd = os.open(destination.parent, os.O_RDONLY)
                try:
                    os.fsync(directory_fd)
                finally:
                    os.close(directory_fd)
                return
            error_number = ctypes.get_errno()
            if error_number == errno.EEXIST:
                raise FileExistsError(error_number, os.strerror(error_number), str(destination))
            if error_number not in {errno.ENOSYS, errno.EINVAL, getattr(errno, "EOPNOTSUPP", errno.ENOSYS)}:
                raise OSError(error_number, os.strerror(error_number), str(destination))
        link = libc.link
        link.argtypes = (ctypes.c_char_p, ctypes.c_char_p)
        link.restype = ctypes.c_int
        if link(os.fsencode(source), os.fsencode(destination)) != 0:
            error_number = ctypes.get_errno()
            if error_number == errno.EEXIST:
                raise FileExistsError(error_number, os.strerror(error_number), str(destination))
            raise OSError(error_number, os.strerror(error_number), str(destination))
    directory_fd = os.open(destination.parent, os.O_RDONLY)
    try:
        os.fsync(directory_fd)
    finally:
        os.close(directory_fd)


def request_json(url: str, method: str = "GET", timeout: float = 10.0) -> tuple[int, Any, str]:
    request = Request(url, method=method, headers={"Accept": "application/json", "User-Agent": "Biyahe-Staging-Smoke/1"})
    try:
        with build_opener(RejectRedirects).open(request, timeout=timeout) as response:
            status, raw = response.status, response.read(1_000_001)
    except HTTPError as error:
        status, raw = error.code, error.read(1_000_001)
    parsed: Any
    try:
        parsed = json.loads(raw.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError):
        parsed = {"body_format": "non_json", "body_bytes": len(raw)}
    return status, parsed, hashlib.sha256(raw).hexdigest()


class Smoke:
    def __init__(self, base_url: str, timeout: float = 10.0, requester=request_json, allow_external: bool = False):
        self.origin = normalize_origin(base_url, allow_external)
        self.api = self.origin + "/api/v1"
        self.timeout = timeout
        self.requester = requester
        self.results: list[dict[str, Any]] = []

    def check(self, name: str, path: str, *, expected: set[int], method: str = "GET", query: dict[str, Any] | None = None, predicate=None) -> None:
        url = self.origin + path
        if query:
            url += "?" + urlencode(query)
        started = time.monotonic()
        try:
            status, body, digest = self.requester(url, method=method, timeout=self.timeout)
            try:
                passed = status in expected and (predicate(body) if predicate else True)
            except (AttributeError, TypeError, KeyError):
                passed = False
            self.results.append({"name": name, "status": "PASS" if passed else "FAIL", "http_status": status,
                                 "duration_ms": round((time.monotonic() - started) * 1000, 3),
                                 "response_sha256": digest, "response_redacted": redact(body),
                                 "expected_http_statuses": sorted(expected)})
        except (URLError, TimeoutError, OSError) as error:
            self.results.append({"name": name, "status": "BLOCKED_DEPENDENCY", "duration_ms": round((time.monotonic() - started) * 1000, 3),
                                 "failure": type(error).__name__})

    @staticmethod
    def is_json_object(value: Any) -> bool:
        return isinstance(value, dict)

    def run(self, query: str, from_id: str | None, to_id: str | None, route: str | None) -> list[dict[str, Any]]:
        self.check("liveness", "/up", expected={200})
        self.check("database-postgis-health", "/api/v1/health", expected={200}, predicate=lambda body: (
            isinstance(body, dict) and body.get("status") == "ok" and
            body.get("database", {}).get("status") == "PASS" and body.get("postgis", {}).get("status") == "PASS"))
        self.check("readiness", "/api/v1/ready", expected={200}, predicate=lambda body: isinstance(body, dict) and body.get("status") == "PASS")
        self.check("search", "/api/v1/places/search", expected={200}, query={"q": query},
                   predicate=lambda body: isinstance(body, dict) and isinstance(body.get("data"), list))
        self.check("invalid-journey-request", "/api/v1/journeys", expected={422},
                   predicate=lambda body: isinstance(body, dict) and body.get("error", {}).get("code") == "INVALID_INPUT")
        self.check("invalid-can-i-ride-request", "/api/v1/can-i-ride", expected={422},
                   predicate=lambda body: isinstance(body, dict) and body.get("error", {}).get("code") == "INVALID_INPUT")
        self.check("development-walking-route-restricted", "/api/v1/dev/offline-walking-preview", expected={404}, method="POST")
        self.check("unknown-place-handling", "/api/v1/places/__batch044_missing_record__", expected={404})

        if from_id and to_id and from_id != to_id:
            for max_transfers, label in ((0, "direct-journey-contract"), (1, "transfer-journey-contract")):
                self.check(label, "/api/v1/journeys", expected={200}, query={
                    "from_place_id": from_id, "to_place_id": to_id, "max_transfers": max_transfers,
                }, predicate=lambda body: isinstance(body, dict) and isinstance(body.get("data"), list) and isinstance(body.get("meta"), dict))
        else:
            self.results.extend([
                {"name": "direct-journey-contract", "status": "NOT_RUN", "reason": "supply two canonical staging place IDs"},
                {"name": "transfer-journey-contract", "status": "NOT_RUN", "reason": "supply two canonical staging place IDs"},
            ])

        if from_id and to_id and from_id != to_id and route:
            key = "route_code" if not route.isdecimal() else "route_id"
            self.check("can-i-ride-contract", "/api/v1/can-i-ride", expected={200}, query={
                key: route, "from_place_id": from_id, "to_place_id": to_id,
            }, predicate=lambda body: isinstance(body, dict) and isinstance(body.get("data"), dict) and isinstance(body.get("meta"), dict))
        else:
            self.results.append({"name": "can-i-ride-contract", "status": "NOT_RUN", "reason": "supply two canonical staging place IDs and a route code or ID"})
        return self.results


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-url", default=os.environ.get("BIYAHE_STAGING_BASE_URL", "http://127.0.0.1:8080"))
    parser.add_argument("--query", default="Cebu", help="Search query; result presence is not assumed.")
    parser.add_argument("--from-place-id")
    parser.add_argument("--to-place-id")
    parser.add_argument("--route", help="A staging route code or ID for the optional Can-I-Ride request.")
    parser.add_argument("--confirm-isolated-staging", action="store_true", help="Required for non-loopback targets; never use with production.")
    parser.add_argument("--timeout", type=float, default=10.0)
    parser.add_argument("--output", type=Path, required=True, help="New evidence path; existing files are never overwritten.")
    args = parser.parse_args(argv)
    if args.timeout <= 0:
        parser.error("--timeout must be greater than zero")
    try:
        started_at = utc_now()
        smoke = Smoke(args.base_url, timeout=args.timeout, allow_external=args.confirm_isolated_staging)
        results = smoke.run(args.query, args.from_place_id, args.to_place_id, args.route)
        statuses = {row["status"] for row in results}
        aggregate = "FAIL" if "FAIL" in statuses else "BLOCKED_DEPENDENCY" if "BLOCKED_DEPENDENCY" in statuses else "NOT_RUN" if "NOT_RUN" in statuses else "PASS"
        document = {"format": "biyahe-staging-api-smoke-v1", "status": aggregate,
                    "started_at": started_at, "ended_at": utc_now(), "base_url": smoke.origin, "checks": results,
                    "target_scope": "operator-supplied staging origin"}
        atomic_no_clobber(args.output, document)
        print(f"API_SMOKE={aggregate}")
        print(f"CHECKS={len(results)}")
        print(f"EVIDENCE={args.output}")
        return 1 if aggregate == "FAIL" else 0
    except (OSError, ValueError) as error:
        print(f"API_SMOKE_ERROR={type(error).__name__}: {error}")
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
