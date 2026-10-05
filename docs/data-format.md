# Dataset format and import

Biyahe stores reviewed source data as human-readable JSON under
api/database/data. The current format identifier is
biyahe-transport-dataset, version 1.

## Document shape

The document contains:

- dataset_id;
- license metadata with name and attribution;
- sources;
- cities, places, aliases, stops, operators, routes, patterns, transfers,
  and fares.

Every record has a stable_id. Non-demo records have verification_state,
is_demo=false, and source_id. A VERIFIED record must refer to a source with
checked_at and verified_by. Pattern records contain a GeoJSON
MultiLineString and an ordered nested stops list. Nested pattern stops also
have stable IDs and positive contiguous sequence values. Transfer records
connect two stops only when a bounded walking relationship is explicitly
supported or intentionally marked UNVERIFIED for review; they do not create a
general pedestrian graph.

Points use an object with lat and lng. GeoJSON geometry coordinates use the
standard [longitude, latitude] order. All geographic data is WGS 84, SRID
4326. Route geometry is MultiLineString, not a guessed single line.

The Cebu example is:

    api/database/data/cebu-central-network-2026-10-05.json

## Commands

From api:

    php artisan biyahe:data:validate cebu-central-network-2026-10-05.json
    php artisan biyahe:data:import cebu-central-network-2026-10-05.json --dry-run
    php artisan biyahe:data:import cebu-central-network-2026-10-05.json

Validation checks JSON shape, stable IDs, references, statuses, dates,
coordinates, geometry, pattern order, duplicate stops, transfer bounds, and
fare values. A validation error returns a non-zero exit code.

Dry-run performs the same validation and writes no records. A normal import
opens one database transaction only after validation passes. Any runtime
failure rolls the complete import back. The importer sorts records by stable
ID, upserts by stable ID, and uses documented natural keys for records from
older installations that do not yet have one. Repeating an unchanged import
skips unchanged rows; changed rows are updated. Omitted records are not
deleted, so retirement is an explicit reviewed action.

## Review rules

Do not encode a route by editing a migration. Add a reviewed dataset record,
run validation and dry-run, inspect the diff and map, then import it. Keep
uncertain service associations UNVERIFIED. Do not add a fare without evidence
and effective dates. Keep source licensing and attribution in the document
and in docs/data-sources.md.
