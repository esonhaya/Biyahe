# Cebu sources and licensing

## Current review slice

The checked dataset is
cebu-central-network-2026-10-05.json. Its source record is
osm-cebu-transport-2026-10-05:

- source type: COMMUNITY_OPEN_DATA;
- checked date: 2026-10-05;
- license: OpenStreetMap ODbL;
- verification method: direct inspection of the cited OSM objects and
  geometry, with route-member paths retained in the dataset;
- reviewer: BIYAHE Batch 002 data review.

The source record links to the OpenStreetMap copyright page and records the
OSM object references in its notes. Route relations used for the initial
review slice are:

- relation 3169782, Jeepney 12L: Ayala - Escario - Labangon;
- relation 3177015, Jeepney 17B: Apas - Lahug - Carbon.

The reviewed place and stop coordinates are OSM-derived geographic facts.
Route geometry is derived from member ways of the cited relations. The
dataset deliberately marks route records, route patterns, and their
pattern-stop service associations UNVERIFIED: the current evidence is
appropriate for a review network, but does not by itself prove every
boarding, direction, or currently operating service claim. No fare is
invented in this slice.

## ODbL responsibilities

OpenStreetMap data is © OpenStreetMap contributors and is available under the
Open Data Commons Open Database License (ODbL). Any distribution of
OSM-derived database contents or a derivative database must preserve the
license obligations, attribution, and share-alike requirements that apply to
the distributed result. Keep the OSM attribution visible in product and
documentation surfaces, and review the current ODbL terms before publishing a
larger extract.

Do not copy proprietary operator, mapping, or timetable datasets unless their
terms permit redistribution in an AGPL project. Record the license and
attribution for every imported source. A source without a URL can still be
recorded, but the notes must explain how it was checked and who checked it.

## Contributor research checklist

1. Prefer primary official, open, or directly observed evidence.
2. Record the exact object, document, notice, or observation and checked date.
3. Separate geographic existence from service operation and route association.
4. Use UNVERIFIED when the evidence supports a lead but not a trusted claim.
5. Never infer a jeepney path from a map line or name alone.
6. Check license, attribution, and redistribution terms before committing data.
7. Validate, dry-run, inspect the map, and run Doctor before import.
