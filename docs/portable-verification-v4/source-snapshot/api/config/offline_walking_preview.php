<?php

return [
    'enabled' => (bool) env('BIYAHE_OFFLINE_WALKING_PREVIEW', false),
    'graph_path' => env('BIYAHE_OFFLINE_GRAPH_PATH', rtrim((string) getenv('HOME'), '/').'/.cache/biyahe/batch030/cebu-walk-central-visayas-20261007-v3.sqlite'),
    'graph_format' => 'biyahe-pedestrian-sqlite-v3',
    'graph_schema_version' => 3,
    'graph_sha256' => env('BIYAHE_OFFLINE_GRAPH_SHA256', '29d7aaf9c45a596f4065c44d13f983f4a7edeec5ac2ea41b718b1c52ca865bf8'),
    'source_sha256' => env('BIYAHE_OFFLINE_SOURCE_SHA256', '787cac20ecc586b754b1a464643a040566d486457c6a37bc0b11ce683bc66d4d'),
    'source_snapshot' => '2026-10-07T01:00:21Z',
    'graph_fingerprint' => 'a0d9f1cb8289cf1f9bde3905d1ad8a57124ee41120153b4efc1f5b31e4c03ecd',
    'graph_nodes' => 76685,
    'graph_edges' => 78225,
    'restriction_relations' => 619,
    'coverage_report_path' => env('BIYAHE_OFFLINE_COVERAGE_REPORT', dirname(base_path()).'/docs/reports/batch032-cebu-coverage.json'),
    'coverage_report_sha256' => '6caf101177904bee6fda1b12cab9c5cda36ddaf7ecc08b0d9778c0517123c81b',
    'maximum_snap_distance_meters' => 25.0,
    'maximum_route_distance_meters' => 2_000.0,
    'maximum_walking_legs' => 5,
    'maximum_geometry_points' => 2_048,
    'maximum_request_seconds' => 8,
    'maximum_request_bytes' => 2_000_000,
    'maximum_response_bytes' => 2_000_000,
];
