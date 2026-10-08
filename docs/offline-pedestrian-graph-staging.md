# Offline pedestrian graph staging

The SQLite path separates OSM preparation from PostgreSQL. It consumes a complete OSM XML stream (`XMLReader` accepts a file or stdin), verifies the local PBF's SHA-256 and source URL identity, writes source indexes and the graph into a private `.building` SQLite file, validates that file, then atomically publishes the final `.sqlite` artifact. The builder itself never opens Laravel's database connection.

## Coverage and topology

All source node coordinates are indexed on disk so a candidate way can retain its full ordered reference list, including nodes outside the configured bounds. A way is a candidate only when an adjacent reference pair lies inside the coverage rectangle. Graph edges are emitted only for consecutive, in-bounds, walkable nodes; geometry is WGS84 and follows OSM reference order and `oneway:foot` direction. The process does not clip ways by drawing replacement edges.

The shared pedestrian access policy is used by the existing JSON importer and the SQLite graph builder. Candidate references are checked for missing nodes. Duplicate element IDs, invalid coordinates, malformed XML, unsupported access tags, duplicate edge segments, endpoint mismatches and altered graph rows reject validation. Component counts are weakly connected components, which describe connectivity without implying routeability in both directions.

The stream reader counts OSM relations and flags restriction relations that affect pedestrians or have no explicit `except=foot`. Turn restrictions are not represented by the current graph schema or router. Such artifacts may pass structural integrity with `WARN`, but `routing_ready=false` and the database staging importer refuses them. Relation data is never silently treated as supported routing evidence.

## Restart behavior

The output path must be new. In-progress work stays at `<output>.building`, which validators never accept as a completed artifact. Source ingestion commits bounded progress into SQLite. After a fully parsed source, graph generation can resume without rereading XML. An interrupted source pass is cleared and replayed from the verified input stream; a partial graph pass is rebuilt from the source tables. The final path appears only after SQLite integrity, provenance, endpoints, geometry, access directions, component count and content fingerprint pass.

The SQLite page cache is capped at 8 MiB, transactions are bounded by source elements and generated edge batches, and the source stream holds one OSM element/way at a time. A single way can contain at most 100,000 references. Use `scripts/run-bounded-process.sh` to observe the complete producer/parser process tree RSS, available memory and disk. The builder does not infer safety from swap alone.

## Commands

For an already complete XML file, `biyahe:pedestrian:stage-offline` can stage and verify it against a local PBF source. For a PBF producer, use the wrapper so it waits for both XML parsing and Osmium's clean exit before finalization; no large XML intermediate is written:

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

After a source phase completes, `--resume --producer-exit-confirmed` can finish graph generation without rereading XML. That confirmation is required for the pipeline wrapper because its producer exit status is observed after parsing. If interruption occurred during source parsing or producer exit was not observed, preserve the `.building` file and use a new output path for a fresh bounded stream. Validate with `biyahe:pedestrian:validate-offline <artifact> --source-path=<source>`.

`biyahe:pedestrian:import-offline` accepts only a structurally and routing-ready artifact. It requires an explicit exact PostgreSQL staging/test database identity (or the isolated SQLite in-memory test database), creates the dataset inactive inside one rollback-safe transaction, inserts bounded row batches, and never changes active-graph state. Graph activation remains a separate operation requiring a healthy database, verified backup, staged validation and rollback plan.

## Current device limitation

The verified Central Visayas PBF is present, but the installed `osmium` executable currently fails at dynamic linking (`_ZNSt6__ndk113__hash_memoryEPKvm` is unresolved), and no alternative PBF reader is installed. No real-PBF stream or graph is claimed from synthetic XML fixtures. Once the Osmium runtime is repaired by a supported package update, the bounded producer stream above is the next safe source proof; measure it with the resource runner before attempting any larger graph.
