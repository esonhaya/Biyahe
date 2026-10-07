# Batch 017 — Metro Cebu route atlas and release notes

## What changed

The real production transport inventory was expanded through the existing
versioned dataset importer. The atlas adds OSM-mapped route identities,
directional PTv2 patterns, ordered platform candidates, and named commuter
place anchors. The importer is transactional and idempotent; rerunning the
same dataset must produce zero imports and zero updates after the first
successful import.

The source is the reviewed [Metro Cebu OSM transport index](https://wiki.openstreetmap.org/wiki/Public_transportation_in_Metro_Cebu)
plus the explicit OSM API relation snapshots named in the generated pattern
metadata. OSM-derived geography is kept separate from service evidence.

## Current production characterization

The Batch 017 import reached 30 active route identities, 58 active directional
patterns, 499 active stops, 1,124 pattern-stop associations, 34 commuter
places, and 47 active place aliases. The active pedestrian graph was not
rebuilt or reimported and remains 43,720 nodes / 45,104 edges.

New route and pattern records are conservative UNVERIFIED candidates. New
mapped stops use UNKNOWN boarding precision and UNVERIFIED boarding facts.
The route planner can use them as bounded candidates, but the UI must retain
the route, sequence, boarding, alighting, transfer, fare, and freshness
warnings already defined by the evidence model.

## Source limitations

No current official Cebu route-and-stop dataset was available in the bounded
research pass. The existing LTFRB FOI request remains a lead, not evidence.
No route was promoted to VERIFIED from geometry alone. No new fare,
operator, exact signboard, driver phrase, or transfer was fabricated.

Relations that did not provide a usable ordered platform sequence and geometry
were skipped by the generator. Their absence is a coverage gap, not evidence
that the route does not operate.

## Validation commands

    cd api
    php scripts/build-batch017-osm-route-atlas.php --cache-dir=/path/outside/repository/biyahe-batch017-osm-cache --output=database/data/cebu-network-atlas-2026-10-07.json
    php artisan biyahe:data:validate database/data/cebu-network-atlas-2026-10-07.json
    php artisan biyahe:data:import database/data/cebu-network-atlas-2026-10-07.json --dry-run
    php artisan biyahe:data:import database/data/cebu-network-atlas-2026-10-07.json
    php artisan biyahe:batch017:audit --pairs=30 --json
    php artisan biyahe:doctor

Use a real PostgreSQL/PostGIS connection for the import, route audit, nearby
queries, and final Doctor gate. SQLite remains useful for fast relational
tests only.

## Product boundaries

- route geometry is not proof of current service;
- platform location is not an exact boarding point;
- OSM data does not verify fares, transfers, signboards, driver language, or
  schedules;
- pedestrian graph connectivity does not verify a transport interchange;
- no GPS, field observation, or synthetic fixture is promoted into evidence;
- unknown facts remain visible in Commute Cards, Can I Ride, and Ride Mode.
