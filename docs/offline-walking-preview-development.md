# Local offline walking preview

The preview is a development aid for inspecting walking legs on an existing Biyahe journey. It does not plan transit, change ranking, replace the live API, verify field access, or activate production routing.

## Requirements and opt-in

- Use the exact Batch030 SQLite graph named by `BIYAHE_OFFLINE_GRAPH_PATH` (default: `$HOME/.cache/biyahe/batch030/cebu-walk-central-visayas-20261007-v3.sqlite`).
- Keep `APP_ENV=local` and `APP_DEBUG=true` in the Laravel development server.
- Set `BIYAHE_OFFLINE_WALKING_PREVIEW=true` in `api/.env`.
- Set `NEXT_PUBLIC_OFFLINE_WALKING_PREVIEW=true` in `web/.env.local`, then restart the Next.js development server.
- Keep the committed coverage report at `docs/reports/batch032-cebu-coverage.json` unchanged. The adapter pins its hash, graph SHA-256, graph content fingerprint, source SHA-256, restriction capability, and 25 m snap policy.

The preview route is registered only for a local, debug Laravel process with the opt-in enabled, and accepts loopback hosts and clients only. The controller repeats those checks at request time, so a route cache copied from a local machine still returns 404 outside that environment. Each route request needs three one-second-spaced readings at or above 512 MiB `MemAvailable` and 128 MiB `SwapFree`; a failed sample returns `resource_budget_exceeded` before graph search. The request body is limited to 2 MB and a journey may contain at most twelve legs and five walking legs. The synchronous graph search is bounded by route distance, visited-node and edge limits. The adapter passes an 8-second cooperative deadline to `OfflinePedestrianGraphRouter`, which checks time between SQL phases and bounded PHP loops; PDO SQLite cannot interrupt a statement while it is running. A deadline exceeded during SQLite work is therefore detected after that statement returns, and the preview is not a hard real-time service.

## Use it

1. Start the ordinary local Laravel and Next.js development servers. The preview needs the local API process, but it does not connect to PostgreSQL.
2. Open a journey result and select it to show the detail card.
3. Choose **Preview walking from local graph**. The existing transit legs and ranking remain unchanged. The selected journey, detail card, and MapLibre overlay receive the graph result.
4. Check the snapshot label and each walking leg’s status. `PEDESTRIAN_NETWORK_WITH_APPROXIMATE_CONNECTORS` includes endpoint distance estimates; the drawn line contains only source graph geometry. If network and connector distances are present separately, the card labels both. Legacy responses without a breakdown are labeled as an approximate-access total. Unavailable paths have no line.
5. Remove the overlay with **Remove local preview**. Route identity is checked before the response is applied, and changing origin, destination, stop, route or direction invalidates an in-flight response. Local preview evidence is rejected by the saved-journey persistence function.

An API response error includes a reason code such as `invalid_request`, `invalid_journey`, `invalid_endpoint`, `graph_unavailable`, `source_mismatch`, `unsupported_routing_capability`, or `resource_budget_exceeded`. A per-leg failure includes its route reason such as `snap_unavailable`, `component_disconnected`, or `path_unavailable`.

| Reason | Meaning | UI behavior |
|---|---|---|
| `invalid_request` | Request is not JSON | Do not retry without correcting the request |
| `invalid_journey` | Journey payload or leg types are malformed | Keep current journey and show contract error |
| `invalid_endpoint` | Missing, invalid or identical walking endpoints | No path geometry or distance is attached |
| `graph_unavailable` | File, metadata, evidence, geometry or serialization is invalid/unavailable | No path geometry; keep the existing route summary |
| `source_mismatch` | Graph or pinned report differs from the expected source identity | Do not use the local result |
| `unsupported_routing_capability` | Schema, restriction inventory or limits are unsupported | Fail closed; do not infer a route |
| `resource_budget_exceeded` | Resource, work, time or byte budget was exceeded | Stop applying preview and allow a later explicit retry |
| `snap_unavailable` | Endpoint did not meet snap policy or barrier connector rule | Show no connector or walking line |
| `component_disconnected` | Endpoints are in different walkable components | Explain that no graph path was established |
| `path_unavailable` | No bounded path was found | Show no walking line |

A graph path is OSM-derived evidence, not a field-safety observation. Transfer status remains unverified.

## Replay the stored Cebu cases

This command reads the ten coordinates and expected routes from the hash-pinned Batch032 coverage report and queries only the SQLite graph. It never reads the PBF or starts PostgreSQL. Choose a new output path; the command refuses to overwrite an existing file.

```sh
cd api
mkdir -p "$HOME/.cache/biyahe/batch039"
php artisan biyahe:pedestrian:verify-offline-corpus \
  --output="$HOME/.cache/biyahe/batch040/offline-corpus.json"
```

The JSON records a stable case index/key (case labels can repeat), source stop/place IDs and trust labels, status/reason parity, distance tolerance (0.02 m), exact stored geometry and edge identity parity, snapped endpoint and component parity, direction, coordinate continuity, and duration. The source PBF SHA-256 is bound to the committed report and is not recomputed during this replay. Compare a replay report against the same pinned corpus with:

```sh
python3 api/scripts/compare-offline-corpus.py \
  --actual="$HOME/.cache/biyahe/batch040/offline-corpus.json" \
  --output="$HOME/.cache/biyahe/batch040/corpus-comparison.json"
```

The carried-forward coverage summary can be regenerated without opening the SQLite graph:

```sh
python3 api/scripts/summarize-offline-coverage.py \
  --output-dir="$HOME/.cache/biyahe/batch040/coverage-diagnostics"
```

The command pins and checks the stored report before emitting machine-readable and Markdown summaries. It reports an existing mismatch between the old `quality_summary` zeros and detailed source-bound counts; it does not silently revise the historical report.

## Fail-closed behavior

The preview returns no geometry when the graph or its pinned evidence is missing, altered, incomplete, or incompatible. It does not draw a straight-line fallback. Do not enable the local UI flag in a production build, and do not treat successful routing as proof of safe access, crossings, boarding areas, or transfer locations.
