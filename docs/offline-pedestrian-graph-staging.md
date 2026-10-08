# Offline pedestrian graph staging

The SQLite path separates OSM preparation from PostgreSQL. It verifies the local PBF's SHA-256 and source URL identity, then builds a versioned staging file without opening Laravel's database connection. The first producer pass streams the complete OSM XML, retaining coordinates only for in-scope nodes while indexing complete ordered refs for candidate pedestrian ways and all source relation counts. After that producer exits cleanly, Osmium `getid` scans the same verified PBF for every node referenced by those ways. The second XML stream must resolve every requested ID and also exit cleanly before source completeness is set.

## Coverage and topology

The first pass stores only coordinates inside the coverage rectangle. A way becomes a candidate only when two adjacent refs are both in that rectangle. Every ref for each candidate way is retained, including refs outside the boundary; a second targeted source pass resolves and verifies those nodes. Graph edges are emitted only for consecutive, in-bounds, walkable nodes; geometry is WGS84 and follows OSM reference order and `oneway:foot` direction. The process does not clip ways by drawing replacement edges.

The shared pedestrian access policy is used by the existing JSON importer and the SQLite graph builder. Candidate references are checked for missing nodes against the verified second pass. Duplicate in-scope or referenced node IDs, duplicate way/relation IDs, invalid coordinates, malformed XML, unsupported access tags, duplicate edge segments, endpoint mismatches and altered graph rows reject validation. Component counts are weakly connected components, which describe connectivity without implying routeability in both directions.

The stream reader counts OSM relations and flags restriction relations that affect pedestrians or have no explicit `except=foot`. Turn restrictions are not represented by the current graph schema or router. Such artifacts may pass structural integrity with `WARN`, but `routing_ready=false` and the database staging importer refuses them. Relation data is never silently treated as supported routing evidence.

## Restart behavior

The output path must be new. In-progress work stays at `<output>.building`, which validators never accept as a completed artifact. Version 2 checkpoints distinguish the way-index producer pass, targeted reference-resolution pass and graph build. A first pass is reusable only after its producer clean exit is confirmed. A reference pass is reusable only after every requested ID was returned and its producer clean exit is confirmed. Interrupted reference resolution clears only its partial resolved-node rows and can replay from the verified way index. Interrupted graph generation is rebuilt from the committed source tables. A first pass without a confirmed clean producer exit must use a fresh output path. The final path appears only after both source passes and SQLite integrity, provenance, endpoints, geometry, access directions, component count and content fingerprint pass.

The SQLite page cache is capped at 8 MiB, transactions are bounded by source elements and generated edge batches, and the source stream holds one OSM element/way at a time. A single way can contain at most 100,000 references. The offline path has a separate configurable in-scope source-node cap (`BIYAHE_OSM_OFFLINE_MAX_SCOPE_NODES`, default 200,000, hard maximum 500,000); this does not raise the legacy JSON import limit. `scripts/run-bounded-process.sh` records process-tree RSS, MemAvailable, Cached, SReclaimable, SwapFree, disk free, SQLite main/journal/WAL bytes and parser phase progress when the corresponding watch/progress paths are configured. Cache and swap observations are diagnostic only and do not by themselves identify Android process pressure.

## Commands

For an already complete XML file, `biyahe:pedestrian:stage-offline` reads the file twice and resolves the scoped candidate refs. For a PBF producer, use the wrapper so it performs both the complete way/relation scan and the targeted `getid` scan, waits for both XML consumers and both Osmium commands to exit cleanly, and then finalizes. No large XML intermediate is written:

```sh
mkdir -p "$HOME/.cache/biyahe/batch028"
api/scripts/run-bounded-process.sh \
  --operation offline-graph-stage \
  --disk-path "$HOME/.cache/biyahe/batch028" \
  --cancel-pid-file "$HOME/.cache/biyahe/batch028/producer.pid" \
  --cancel-match osmium \
  --log "$HOME/.cache/biyahe/batch028/graph-stage.log" \
  -- api/scripts/prepare-offline-pedestrian-graph.sh \
      /path/central_visayas.osm.pbf \
      /path/cebu-walk.sqlite \
      https://download.openstreetmap.fr/extracts/asia/philippines/central_visayas.osm.pbf \
      EXPECTED_SHA256 2026-10-07T01:00:21Z INSTALLED_VERSION \
      "$HOME/.cache/biyahe/batch028/producer.pid"
```

Set `BIYAHE_RESOURCE_WATCH_FILE` to the intended `<output>.building` path when invoking the resource runner to record SQLite and rollback-journal growth. Existing complete way-index checkpoints can export their required IDs with `--resume --producer-exit-confirmed --export-required-node-ids=PATH`. Feed that file to `osmium getid --id-file PATH`; run the consumer with `--resume --resolve-required-nodes --await-producer-exit --stop-after-source`; only after the producer and parser both exit zero may `--resume --producer-exit-confirmed` complete and validate the graph. A partial first pass has no safe continuation point and is restarted with a new output path.

Set `BIYAHE_OSM_STOP_AFTER_SOURCE=1` to stop after both full source passes are complete and producer-verified but before graph generation. The resulting `.building` checkpoint can then be inspected before resuming the deterministic graph-build phase with `biyahe:pedestrian:stage-offline - --resume`.

The `--producer-exit-confirmed` confirmation is required because the producer's exit status is observed after parsing. The stager also rejects an incomplete targeted node response, source mismatch or mismatched checkpoint schema. Validate with `biyahe:pedestrian:validate-offline <artifact> --source-path=<source>`.

`biyahe:pedestrian:import-offline` accepts only a structurally and routing-ready artifact. It requires an explicit exact PostgreSQL staging/test database identity (or the isolated SQLite in-memory test database), creates the dataset inactive inside one rollback-safe transaction, inserts bounded row batches, and never changes active-graph state. Graph activation remains a separate operation requiring a healthy database, verified backup, staged validation and rollback plan.

## Termux runtime selection

On Termux, source `scripts/osmium-runtime.sh` and invoke `run_osmium` for version checks and PBF reads. It points Osmium at the package-managed `$PREFIX/lib` runtime and drops an inherited `LD_LIBRARY_PATH` from another app. The Codex CLI Termux environment can prepend its own `libc++_shared.so`, which lacks symbols required by the packaged Osmium binary. Do not copy or replace shared libraries; verify the package runtime with `run_osmium --version` and then prove actual PBF parsing under `scripts/run-bounded-process.sh`.
