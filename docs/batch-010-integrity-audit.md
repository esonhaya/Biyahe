# Batch 010 trust-integrity audit

## Diagnosis

The starting Doctor V9 report showed 13 raw `VERIFIED` place-location claims
without scoped provenance in the installed database. Each was linked to the
same source, `source-osm-cebu-batch004`. The runtime source row was older than
the checked-in production source dataset: its evidence-scope metadata had not
been synchronized after the source definition was expanded to include
`PLACE_LOCATION`.

The reviewed source definition scopes this OSM record to `PLACE_LOCATION`,
`ROUTE_IDENTITY`, `ROUTE_PATTERN`, `ROUTE_GEOMETRY`, and `STOP_LOCATION`. The
13 affected facts are specifically place locations, so the matching scope
supports those claims. This audit does not use that source to verify actual
service, boarding or alighting practice, transfer validity, or fares. The
pedestrian OSM extract is a separate source and has no transport-trust scope.

## Claim-by-claim result

| Fact | Before | Existing evidence | Classification | Action | After |
| --- | --- | --- | --- | --- | --- |
| `PLACE_LOCATION:place-banilad-town-center` | `VERIFIED`, source scope stale | `source-osm-cebu-batch004`; mapped place coordinate | `VALID_WITH_EXISTING_SCOPED_EVIDENCE` | Sync reviewed `PLACE_LOCATION` scope; no new evidence/link | `VERIFIED`, scoped |
| `PLACE_LOCATION:place-cebu-business-park` | `VERIFIED`, source scope stale | `source-osm-cebu-batch004`; mapped place coordinate | `VALID_WITH_EXISTING_SCOPED_EVIDENCE` | Sync reviewed `PLACE_LOCATION` scope; no new evidence/link | `VERIFIED`, scoped |
| `PLACE_LOCATION:place-cebu-capitol` | `VERIFIED`, source scope stale | `source-osm-cebu-batch004`; mapped place coordinate | `VALID_WITH_EXISTING_SCOPED_EVIDENCE` | Sync reviewed `PLACE_LOCATION` scope; no new evidence/link | `VERIFIED`, scoped |
| `PLACE_LOCATION:place-cebu-it-park` | `VERIFIED`, source scope stale | `source-osm-cebu-batch004`; mapped place coordinate | `VALID_WITH_EXISTING_SCOPED_EVIDENCE` | Sync reviewed `PLACE_LOCATION` scope; no new evidence/link | `VERIFIED`, scoped |
| `PLACE_LOCATION:place-cebu-normal-university` | `VERIFIED`, source scope stale | `source-osm-cebu-batch004`; mapped place coordinate | `VALID_WITH_EXISTING_SCOPED_EVIDENCE` | Sync reviewed `PLACE_LOCATION` scope; no new evidence/link | `VERIFIED`, scoped |
| `PLACE_LOCATION:place-colon` | `VERIFIED`, source scope stale | `source-osm-cebu-batch004`; mapped place coordinate | `VALID_WITH_EXISTING_SCOPED_EVIDENCE` | Sync reviewed `PLACE_LOCATION` scope; no new evidence/link | `VERIFIED`, scoped |
| `PLACE_LOCATION:place-gaisano-country-mall` | `VERIFIED`, source scope stale | `source-osm-cebu-batch004`; mapped place coordinate | `VALID_WITH_EXISTING_SCOPED_EVIDENCE` | Sync reviewed `PLACE_LOCATION` scope; no new evidence/link | `VERIFIED`, scoped |
| `PLACE_LOCATION:place-guadalupe` | `VERIFIED`, source scope stale | `source-osm-cebu-batch004`; mapped place coordinate | `VALID_WITH_EXISTING_SCOPED_EVIDENCE` | Sync reviewed `PLACE_LOCATION` scope; no new evidence/link | `VERIFIED`, scoped |
| `PLACE_LOCATION:place-jones-avenue` | `VERIFIED`, source scope stale | `source-osm-cebu-batch004`; mapped place coordinate | `VALID_WITH_EXISTING_SCOPED_EVIDENCE` | Sync reviewed `PLACE_LOCATION` scope; no new evidence/link | `VERIFIED`, scoped |
| `PLACE_LOCATION:place-mabolo` | `VERIFIED`, source scope stale | `source-osm-cebu-batch004`; mapped place coordinate | `VALID_WITH_EXISTING_SCOPED_EVIDENCE` | Sync reviewed `PLACE_LOCATION` scope; no new evidence/link | `VERIFIED`, scoped |
| `PLACE_LOCATION:place-north-reclamation-area` | `VERIFIED`, source scope stale | `source-osm-cebu-batch004`; mapped place coordinate | `VALID_WITH_EXISTING_SCOPED_EVIDENCE` | Sync reviewed `PLACE_LOCATION` scope; no new evidence/link | `VERIFIED`, scoped |
| `PLACE_LOCATION:place-sm-city-cebu` | `VERIFIED`, source scope stale | `source-osm-cebu-batch004`; mapped place coordinate | `VALID_WITH_EXISTING_SCOPED_EVIDENCE` | Sync reviewed `PLACE_LOCATION` scope; no new evidence/link | `VERIFIED`, scoped |
| `PLACE_LOCATION:place-talamban` | `VERIFIED`, source scope stale | `source-osm-cebu-batch004`; mapped place coordinate | `VALID_WITH_EXISTING_SCOPED_EVIDENCE` | Sync reviewed `PLACE_LOCATION` scope; no new evidence/link | `VERIFIED`, scoped |

## Repair and safeguards

The repair is a deterministic re-import of the already reviewed production
datasets, not a trust migration. The import synchronizes the source's
fact-scoped metadata in the same transaction as the data import. No evidence
rows or place claims were created, and no raw verification state changed.
Thirteen existing place-to-source links became valid for their
`PLACE_LOCATION` fact after the reviewed source scope was synchronized. The
source record itself is the checked-in source row; no replacement source was
created. Repeating the import is idempotent under the existing stable IDs and
dataset import rules.

On the validation PostGIS database, both complete checked-in production
datasets imported transactionally. The existing default SQLite developer
database has a legacy `fares.route_id NOT NULL` schema even though its nullable
fare-scope migration is marked as applied. The expansion import therefore
rolled back on SQLite; the checked-in canonical OSM source record was synced
alone through the same `DatasetImporter`, after which Doctor's scoped-claim
check passed there as well. No fare or transport row was partially written.

The independent Doctor V9 scoped-evidence check remains enabled. After source
synchronization, the pre-graph Doctor V10 run on PostGIS reported 110 passes,
8 warnings, 0 failures, and 0 blocked checks; those warnings are coverage
gaps, including absent real field observations and unknown boarding
precision. This repair does not make OSM place geography evidence for
transport operations.

## Batch 010 closure

The recovered live PostgreSQL/PostGIS database now has one active bounded OSM
pedestrian dataset, `osm-cebu-walk-07555e8a0e284772e2c16120c1d66075`, with
43,720 nodes and 45,104 edges. Its source is the OpenStreetMap France Central
Visayas extract, retained with its URL, MD5, 2026-10-05 snapshot timestamp,
complete-way extraction strategy, ODbL license, and attribution. Repeating the
same import reused the stable dataset ID and left exactly one active dataset.

The final Doctor V10 run reported 116 PASS, 5 WARN, 0 FAIL, and 0 BLOCKED.
The warnings are existing evidence-coverage gaps: incomplete pattern-stop
evidence, undeclared precision on some boarding points, four unverified current
general fare rules, and zero real field observations. None is a fabricated
transport promotion. A schema-repair migration restored the missing
`location_precision` columns in the existing PostgreSQL field-survey tables;
it created no observations or claims.
